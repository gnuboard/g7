<?php

namespace Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\Identity\IdentityNotificationType;
use App\Services\IdentityMessageDispatcher;
use App\Services\NotificationDefinitionService;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 본인인증 메시지 디스패처 테스트 (#699 — 알림 시스템 경유)
 *
 * 3단 폴백(정책 → 목적 → 기본)을 알림 type 후보로 해석하고, GenericNotification 으로 발송하며,
 * 채널 토글·발송 로그·옛 발송 훅(deprecated)·회원/게스트 수신자를 잠근다.
 */
class IdentityMessageDispatcherTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;

    private IdentityMessageDispatcher $dispatcher;

    /** @var array<string, int> 옛 발송 훅 발화 횟수 */
    private array $fired = [];

    /**
     * 본인인증 알림 정의를 시딩하고 array 메일러로 발송을 수집합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationDefinitionSeeder::class);
        $this->flushNotificationCaches();
        $this->useArrayMailer();
        $this->dispatcher = $this->app->make(IdentityMessageDispatcher::class);

        foreach (['resolve_failed', 'before_send', 'after_send', 'send_failed'] as $hook) {
            $this->fired[$hook] = 0;
            HookManager::addAction('core.identity.message.'.$hook, function () use ($hook) {
                $this->fired[$hook]++;
            });
        }
    }

    /**
     * 테스트가 등록한 훅과 캐시를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (['resolve_failed', 'before_send', 'after_send', 'send_failed'] as $hook) {
            HookManager::clearAction('core.identity.message.'.$hook);
        }
        $this->flushNotificationCaches();

        parent::tearDown();
    }

    /**
     * 목적 정의가 있으면 그 문구로 발송하고 true·발송 로그·옛 발송 훅을 남긴다.
     *
     * @scenario surface=dispatcher
     *
     * @effects purpose_definition_used, notification_log_sent_row, deprecated_send_hooks_fired
     *
     * @return void 반환값 없음
     */
    public function test_purpose_definition_sends_through_notification_pipeline(): void
    {
        $sent = $this->send('login', null, 'admin-login@example.test');

        $this->assertTrue($sent);
        $mails = $this->sentMails();
        $this->assertCount(1, $mails);
        $this->assertSame('admin-login@example.test', $mails[0]['to']);
        $this->assertStringContainsString('로그인 인증번호 안내', $mails[0]['subject']);
        $this->assertStringContainsString('654321', $mails[0]['html']);

        $log = NotificationLog::where('notification_type', 'identity.purpose.login')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status->value ?? $log->status);
        $this->assertSame(1, $this->fired['before_send']);
        $this->assertSame(1, $this->fired['after_send']);
        $this->assertSame(0, $this->fired['send_failed']);
    }

    /**
     * 정책 정의가 활성이면 목적 정의보다 먼저 쓰인다.
     *
     * @scenario surface=dispatcher
     *
     * @effects policy_definition_preferred
     *
     * @return void 반환값 없음
     */
    public function test_policy_definition_is_preferred(): void
    {
        $this->createPolicyDefinition('admin.board.delete', '[정책] 게시판 삭제 확인 {code}');

        $this->assertTrue($this->send('sensitive_action', 'admin.board.delete'));

        $this->assertStringContainsString('[정책] 게시판 삭제 확인', $this->sentMails()[0]['subject']);
    }

    /**
     * 정책 정의가 비활성이면 목적 정의로, 목적 템플릿이 비활성이면 기본 정의로 내려간다.
     *
     * @scenario surface=dispatcher
     *
     * @effects inactive_candidate_falls_through
     *
     * @return void 반환값 없음
     */
    public function test_inactive_candidates_fall_through_to_default(): void
    {
        $policy = $this->createPolicyDefinition('admin.board.delete', '[정책] {code}');
        $policy->update(['is_active' => false]);

        NotificationTemplate::query()
            ->whereHas('definition', fn ($q) => $q->where('type', 'identity.purpose.sensitive_action'))
            ->where('channel', 'mail')
            ->update(['is_active' => false]);
        $this->flushNotificationCaches();

        $this->assertTrue($this->send('sensitive_action', 'admin.board.delete'));

        $this->assertStringContainsString('본인 확인 인증 코드', $this->sentMails()[0]['subject']);
    }

    /**
     * 후보가 모두 비활성이면 false 와 resolve_failed 훅, 발송 0.
     *
     * @scenario surface=dispatcher
     *
     * @effects unresolved_returns_false
     *
     * @return void 반환값 없음
     */
    public function test_unresolved_returns_false(): void
    {
        NotificationDefinition::where('category', IdentityNotificationType::CATEGORY)->update(['is_active' => false]);
        $this->flushNotificationCaches();

        $this->assertFalse($this->send('signup', null));

        $this->assertSame([], $this->sentMails());
        $this->assertSame(1, $this->fired['resolve_failed']);
        $this->assertSame(0, $this->fired['before_send']);
    }

    /**
     * 「알림 채널 관리」에서 메일을 끄면 본인인증 메일도 나가지 않는다 — false, skipped 로그, send_failed 훅.
     *
     * @scenario surface=dispatcher
     *
     * @effects mail_channel_off_blocks_identity_mail, notification_log_skipped_row
     *
     * @return void 반환값 없음
     */
    public function test_mail_channel_off_blocks_identity_mail(): void
    {
        $this->setCoreMailChannelActive(false);

        $this->assertFalse($this->send('login', null, 'off@example.test'));

        $this->assertSame([], $this->sentMails());
        $log = NotificationLog::where('notification_type', 'identity.purpose.login')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('skipped', $log->status->value ?? $log->status);
        $this->assertSame(__('notification.channel_disabled_by_extension'), $log->error_message);
        $this->assertSame(1, $this->fired['send_failed']);
        $this->assertSame(0, $this->fired['after_send']);
    }

    /**
     * 챌린지 사용자 이메일과 수신 주소가 같으면 회원으로, 다르면 게스트로 보낸다.
     *
     * @scenario surface=dispatcher
     *
     * @effects user_or_guest_recipient
     *
     * @return void 반환값 없음
     */
    public function test_recipient_is_user_when_email_matches_otherwise_guest(): void
    {
        $user = User::factory()->create(['email' => 'member-idv@example.test']);

        $this->send('self_update', null, 'member-idv@example.test', ['user_id' => $user->id]);
        $this->send('self_update', null, 'new-address@example.test', ['user_id' => $user->id]);

        $logs = NotificationLog::where('notification_type', 'identity.purpose.self_update')
            ->where('status', 'sent')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($user->id, (int) $logs[0]->recipient_user_id);
        $this->assertNull($logs[1]->recipient_user_id);
        $this->assertSame('new-address@example.test', $logs[1]->recipient_identifier);
    }

    /**
     * 디스패처는 메일 파사드로 직접 보내지 않는다 (알림 경유 고정).
     *
     * @scenario surface=dispatcher
     *
     * @effects no_direct_mail_send
     *
     * @return void 반환값 없음
     */
    public function test_dispatcher_source_has_no_direct_mail_send(): void
    {
        $source = file_get_contents(app_path('Services/IdentityMessageDispatcher.php'));

        $this->assertDoesNotMatchRegularExpression('/\bMail::/', $source);
        $this->assertStringContainsString('notifyNow(', $source);
    }

    /**
     * 디스패처로 메일을 보냅니다.
     *
     * @param  string  $purpose  인증 목적
     * @param  string|null  $policyKey  정책 키
     * @param  string  $target  수신 이메일
     * @param  array  $context  추가 컨텍스트
     * @return bool 발송 여부
     */
    private function send(string $purpose, ?string $policyKey, string $target = 'user@example.test', array $context = []): bool
    {
        return $this->dispatcher->dispatch(
            providerId: 'g7:core.mail',
            purpose: $purpose,
            policyKey: $policyKey,
            renderHint: 'text_code',
            channel: 'mail',
            target: $target,
            data: ['code' => '654321', 'expire_minutes' => 15, 'app_name' => 'TestApp', 'site_url' => 'http://localhost'],
            context: $context,
        );
    }

    /**
     * 운영자 정책 정의를 만듭니다 (알림 설정 화면의 「정책별 문구 추가」와 같은 소유 규약).
     *
     * @param  string  $policyKey  정책 키
     * @param  string  $subject  제목 (ko)
     * @return NotificationDefinition 생성된 정의
     */
    private function createPolicyDefinition(string $policyKey, string $subject): NotificationDefinition
    {
        $definition = NotificationDefinition::create([
            'type' => IdentityNotificationType::forPolicy($policyKey),
            'hook_prefix' => 'core.identity',
            'extension_type' => 'core',
            'extension_identifier' => 'admin',
            'category' => IdentityNotificationType::CATEGORY,
            'name' => ['ko' => '정책 문구', 'en' => 'Policy message'],
            'variables' => IdentityNotificationType::commonVariables(),
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => false,
        ]);
        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => $subject, 'en' => $subject],
            'body' => ['ko' => '<p>{code}</p>', 'en' => '<p>{code}</p>'],
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->flushNotificationCaches();

        return $definition;
    }

    /**
     * 알림 정의·템플릿 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function flushNotificationCaches(): void
    {
        // 정의·템플릿 캐시는 같은 'notification' 태그를 쓴다 — 한 번에 비운다.
        app(NotificationDefinitionService::class)->invalidateAllCache();
    }
}
