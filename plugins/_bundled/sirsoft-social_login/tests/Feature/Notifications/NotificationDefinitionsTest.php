<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Notifications;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener;
use App\Models\NotificationDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\SocialLogin\Plugin;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;
use Tests\Helpers\MocksChannelReadiness;

/**
 * 알림 정의 5종 — 정의 형태(본문 자리 = 변수 목록), 수신 규칙(회원 2 · 관리자 3), 치환 값, 관리자 강제 해제 사이트 알림 실제 발송,
 * 선택 채널(관리자 해제 메일) 기본 꺼짐. 사이트명 자리 시험은 트러블슈팅 `[case:backend-65]` 의 회귀 그물이다.
 *
 * @since 1.0.0
 */
class NotificationDefinitionsTest extends PluginTestCase
{
    use MocksChannelReadiness;

    /** 알림 유형 → 수신 규칙 */
    private const AUDIENCE = [
        'social_login_last_login_method_removed' => 'trigger_user',
        'social_login_link_removed_by_admin' => 'trigger_user',
        'social_login_provider_failing' => 'role',
        'social_login_signup_surge' => 'role',
        'social_login_webhook_failed' => 'role',
    ];

    /**
     * 플러그인 정의를 시험 DB 에 맞춰 넣고 알림 훅을 다시 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        $this->enableKakao();

        $helper = app(NotificationSyncHelper::class);
        foreach ((new Plugin)->getNotificationDefinitions() as $data) {
            $definition = $helper->syncDefinition($data + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-social_login']);
            foreach ($data['templates'] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }
        app(NotificationHookListener::class)->registerDynamicHooks();
    }

    /**
     * 정의는 5종이고, 본문·제목의 자리(`{x}`)는 모두 변수 목록에 있거나 코어 공통 자리다. 회원 대상 2종은 대상 회원, 운영 3종은
     * 관리자 역할에게 간다. 관리자 해제의 메일 채널은 기본으로 꺼져 있다(사이트 알림만).
     *
     * @scenario definition=all
     *
     * @effects five_definitions_seeded, placeholders_declared, recipients_by_audience, optional_mail_off_by_default
     *
     * @return void 반환값 없음
     */
    public function test_definition_shape(): void
    {
        $definitions = (new Plugin)->getNotificationDefinitions();
        $this->assertSame(array_keys(self::AUDIENCE), array_column($definitions, 'type'));

        foreach ($definitions as $definition) {
            // 코어가 스스로 채우는 자리는 수신자 이름(`{recipient_name}`) 하나뿐이다 — 사이트명(`{app_name}`)은 정의가 변수로 선언하고
            // 데이터 리스너가 채워야 한다(빠지면 메일 제목에 「[{app_name}]」이 그대로 나간다).
            $declared = array_merge(array_column($definition['variables'], 'key'), ['recipient_name']);
            foreach ($definition['templates'] as $template) {
                $text = json_encode([$template['subject'], $template['body'], $template['click_url'] ?? ''], JSON_UNESCAPED_UNICODE);
                preg_match_all('/\{([a-z_]+)\}/', (string) $text, $m);
                $this->assertSame([], array_values(array_diff(array_unique($m[1]), $declared)), $definition['type'].' '.$template['channel']);
                $this->assertSame(self::AUDIENCE[$definition['type']], $template['recipients'][0]['type'], $definition['type']);
            }
            $this->assertTrue(NotificationDefinition::query()->where('type', $definition['type'])->exists());
        }

        $mail = NotificationDefinition::query()->where('type', 'social_login_link_removed_by_admin')->firstOrFail()
            ->templates()->where('channel', 'mail')->firstOrFail();
        $this->assertFalse((bool) $mail->is_active);
    }

    /**
     * 치환 값: 회원 대상은 이름·제공자 이름·비밀번호 찾기 주소와 대상 회원 문맥, 운영 대상은 제공자 이름·수치·진단/통계 주소.
     * 대상 회원이 없으면 발송을 건너뛴다.
     *
     * @scenario type=last_method|link_removed|provider_failing|signup_surge|webhook_failed|missing_member
     *
     * @effects member_data_and_context, admin_data_and_urls, missing_member_skips
     *
     * @return void 반환값 없음
     */
    public function test_extract_data(): void
    {
        $user = User::factory()->create(['name' => '연결회원', 'email' => $this->uniqueEmail('nt')]);
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
        $extract = fn (string $type, array $args) => HookManager::applyFilters('sirsoft-social_login.notification.extract_data', $default, $type, $args);

        $last = $extract('social_login_last_login_method_removed', [$user, 'kakao']);
        $this->assertSame('연결회원', $last['data']['name']);
        $this->assertSame('카카오', $last['data']['provider_label']);
        $this->assertStringEndsWith('/forgot-password', $last['data']['reset_url']);
        $this->assertSame($user->id, $last['context']['trigger_user_id']);

        $failing = $extract('social_login_provider_failing', ['kakao', 12, 'KOE010']);
        $this->assertSame(['12', 'KOE010'], [$failing['data']['failure_count'], $failing['data']['last_error_code']]);
        $this->assertStringEndsWith('/admin/plugins/sirsoft-social_login/diagnostics', $failing['data']['diagnostics_url']);

        $surge = $extract('social_login_signup_surge', ['kakao', 120, 100]);
        $this->assertStringEndsWith('/admin/plugins/sirsoft-social_login/stats', $surge['data']['stats_url']);

        $webhook = $extract('social_login_webhook_failed', [['provider_key' => 'kakao', 'event_type' => 'unlink', 'error' => 'boom']]);
        $this->assertSame(['카카오', 'boom'], [$webhook['data']['provider_label'], $webhook['data']['error']]);
        $this->assertNotSame('unlink', $webhook['data']['event_type']);

        $this->assertTrue($extract('social_login_link_removed_by_admin', [null, 'kakao'])['context']['skip'] ?? false);
    }

    /**
     * 제목의 사이트명 자리는 5종 모두 사이트 이름으로 채워진다 — 실제 발송 메일 제목에 자리 표시가 남지 않는다.
     *
     * @scenario type=last_method|link_removed|provider_failing|signup_surge|webhook_failed, channel=mail
     *
     * @effects app_name_filled_in_every_notification
     *
     * @return void 반환값 없음
     */
    public function test_site_name_is_filled_in_every_notification(): void
    {
        config(['app.name' => '측정사이트']);
        $user = User::factory()->create(['email' => $this->uniqueEmail('an')]);
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
        $args = [
            'social_login_last_login_method_removed' => [$user, 'kakao'],
            'social_login_link_removed_by_admin' => [$user, 'kakao'],
            'social_login_provider_failing' => ['kakao', 3, 'KOE010'],
            'social_login_signup_surge' => ['kakao', 120, 100],
            'social_login_webhook_failed' => [['provider_key' => 'kakao', 'event_type' => 'unlink', 'error' => 'boom']],
        ];

        foreach ($args as $type => $hookArgs) {
            $data = HookManager::applyFilters('sirsoft-social_login.notification.extract_data', $default, $type, $hookArgs)['data'];
            $this->assertSame('측정사이트', $data['app_name'] ?? null, $type);
        }

        // 시험 환경은 발신 주소가 없어 메일 채널이 준비 안 됨으로 건너뛴다 — 준비 상태를 고정하고 array 메일러로 보내 기록만 남긴다.
        $this->mockAllChannelsReady();
        config(['mail.default' => 'array']);
        HookManager::doAction('sirsoft-social_login.account.unlinked_last_method', $user, 'kakao');
        $subject = (string) DB::table('notification_logs')->where('recipient_user_id', $user->id)->latest('id')->value('subject');
        $this->assertStringStartsWith('[측정사이트] ', $subject);
        $this->assertStringNotContainsString('{', $subject);
    }

    /**
     * 관리자 강제 해제 훅이 발화하면 대상 회원의 사이트 알림함에 1건이 쌓인다(메일 채널은 꺼져 있어 보내지 않는다).
     *
     * @scenario channel=database
     *
     * @effects admin_unlink_database_notice_delivered
     *
     * @return void 반환값 없음
     */
    public function test_admin_unlink_notice_is_delivered(): void
    {
        $user = User::factory()->create(['email' => $this->uniqueEmail('dn')]);

        HookManager::doAction('sirsoft-social_login.account.unlinked_by_admin', $user, 'kakao');

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());
    }
}
