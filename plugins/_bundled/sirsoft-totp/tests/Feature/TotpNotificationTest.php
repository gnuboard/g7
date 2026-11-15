<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener;
use App\Models\NotificationDefinition;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\AuthService;
use App\Services\NotificationDefinitionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Listeners\TotpNotificationDataListener;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Plugin;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;
use Plugins\Sirsoft\Totp\Support\Totp;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 인증 앱 보안 알림 3종 테스트 (1.0.3, KVE-2026-3194).
 *
 * 세션을 훔친 쪽이 인증 앱을 바꾸거나 끄거나 백업 코드를 새로 받아도 계정 주인이 알 길이 없었다. 등록 확정·본인 해제·
 * 관리자 강제 해제·백업 코드 재발급이 각각 계정 주인에게 메일로 가는지, 운영자가 끈 알림은 가지 않는지,
 * 비밀번호 재설정이 인증 앱 등록을 지우지 않는지를 실제 훅 체인(`NotificationHookListener` 동적 구독)으로 확인한다.
 *
 * @since 1.0.3
 */
class TotpNotificationTest extends PluginTestCase
{
    /** 등록 시작 재확인에 쓰는 시험 비밀번호 */
    private const PASSWORD = 'totp-notify-pass';

    private User $user;

    /**
     * 알림 정의를 동기화하고 데이터 리스너·동적 훅을 붙입니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['telescope.enabled' => false]);

        $this->user = User::factory()->create(['email' => 'totp-notify@test.com', 'password' => Hash::make(self::PASSWORD)]);
        RateLimiter::clear('password-reconfirm:'.$this->user->id);
        RateLimiter::clear('sirsoft-totp:ownership:'.$this->user->id);
    }

    /**
     * 플러그인 선언으로 알림 정의를 동기화하고 구독을 붙입니다.
     *
     * 운영에서는 플러그인 설치·갱신이 정의를 넣고 코어 부트가 동적 훅을 붙인다 — 테스트 부트는 둘 다 하지 않는다.
     *
     * @param  array<int, string>  $inactiveTypes  꺼 둘 알림 유형
     * @return void 반환값 없음
     */
    private function wireNotifications(array $inactiveTypes = []): void
    {
        $helper = app(NotificationSyncHelper::class);

        foreach ((new Plugin)->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'plugin';
            $data['extension_identifier'] = 'sirsoft-totp';
            $definition = $helper->syncDefinition($data);

            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }

            if (in_array($data['type'], $inactiveTypes, true)) {
                $definition->forceFill(['is_active' => false])->save();
            }
        }

        app(NotificationDefinitionService::class)->invalidateAllCache();
        // 메일 채널 준비(SMTP 호스트) — 비어 있으면 코어가 발송을 건너뛰고 기록만 남긴다
        $this->enableMailChannelReadiness();

        $listener = app(TotpNotificationDataListener::class);
        foreach (TotpNotificationDataListener::getSubscribedHooks() as $hook => $meta) {
            HookManager::addFilter($hook, [$listener, $meta['method']], $meta['priority']);
        }

        app(NotificationHookListener::class)->registerDynamicHooks();
    }

    /**
     * 등록을 시작하고 확정해 확정된 자격증명과 시크릿을 돌려줍니다.
     *
     * @return string 평문 시크릿
     */
    private function enrollViaApi(): string
    {
        Sanctum::actingAs($this->user);

        $secret = $this->postJson('/api/plugins/sirsoft-totp/enrollment', ['current_password' => self::PASSWORD])
            ->assertOk()->json('data.secret');

        $this->postJson('/api/plugins/sirsoft-totp/enrollment/confirm', ['code' => Totp::codeAt($secret, Totp::timestepAt())])
            ->assertOk();

        return $secret;
    }

    /**
     * 해당 유형의 메일 알림이 계정 주인에게 갔는지 확인합니다.
     *
     * @param  string  $type  알림 유형
     * @param  int  $times  기대 횟수
     * @return void 반환값 없음
     */
    private function assertNotified(string $type, int $times = 1): void
    {
        $sent = Notification::sent($this->user, GenericNotification::class)
            ->filter(fn (GenericNotification $n) => $n->getType() === $type);

        $this->assertCount($times, $sent, "{$type} 알림이 계정 주인에게 {$times}건 가야 한다.");
    }

    /**
     * 선언이 계획의 계약(메일 채널·계정 주인 수신·변수 6종)을 지키는지 확인합니다.
     *
     * @scenario case=totp_security_notifications_declared
     *
     * @effects totp_security_notifications_declared
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function definitions_declare_three_mail_notifications_to_the_account_owner(): void
    {
        $definitions = collect((new Plugin)->getNotificationDefinitions())->keyBy('type');

        $this->assertSame(['totp_enrolled', 'totp_disabled', 'totp_backup_codes_regenerated'], $definitions->keys()->all());
        $this->assertSame(['sirsoft-totp.credential.disabled', 'sirsoft-totp.credential.admin_reset'], $definitions['totp_disabled']['hooks']);

        foreach ($definitions as $definition) {
            $this->assertSame('sirsoft-totp', $definition['hook_prefix']);
            $this->assertSame(['mail'], $definition['channels']);
            $this->assertSame('customer', $definition['audience']);
            $this->assertSame([['type' => 'trigger_user']], $definition['templates'][0]['recipients']);
            $this->assertSame(
                ['name', 'app_name', 'occurred_at', 'ip_address', 'actor_label', 'security_url'],
                array_column($definition['variables'], 'key'),
            );
        }
    }

    /**
     * 등록 확정과 본인 해제가 각각 계정 주인에게 알림을 보내는지 확인합니다.
     *
     * @scenario case=totp_enrolled_notifies_owner
     *
     * @effects totp_enrolled_notifies_owner, totp_disabled_notifies_owner
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrolling_and_self_disabling_notify_the_owner(): void
    {
        $this->wireNotifications();

        $secret = $this->enrollViaApi();
        $this->assertNotified('totp_enrolled');

        $this->deleteJson('/api/plugins/sirsoft-totp/enrollment', ['code' => Totp::codeAt($secret, Totp::timestepAt() + 1)])
            ->assertOk();

        $this->assertNotified('totp_disabled');
    }

    /**
     * 백업 코드 재발급이 알림을 보내는지 확인합니다.
     *
     * @scenario case=totp_backup_codes_regenerated_notifies_owner
     *
     * @effects totp_backup_codes_regenerated_notifies_owner
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function regenerating_backup_codes_notifies_the_owner(): void
    {
        $this->wireNotifications();

        $secret = $this->enrollViaApi();

        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => Totp::codeAt($secret, Totp::timestepAt() + 1)])
            ->assertOk();

        $this->assertNotified('totp_backup_codes_regenerated');
    }

    /**
     * 관리자 강제 해제도 운영자가 아니라 해제된 회원에게 알림이 가는지 확인합니다.
     *
     * @scenario case=totp_admin_reset_notifies_owner
     *
     * @effects totp_admin_reset_notifies_owner
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_reset_notifies_the_member_not_the_operator(): void
    {
        $this->wireNotifications();
        $this->enrollViaApi();

        $admin = User::factory()->create();
        app(TotpEnrollmentService::class)->adminReset($this->user, $admin->id, '203.0.113.7');

        $this->assertNotified('totp_disabled');
        Notification::assertNotSentTo($admin, GenericNotification::class);

        $extracted = HookManager::applyFilters(
            'sirsoft-totp.notification.extract_data',
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'totp_disabled',
            [$this->user, ['actor_id' => $admin->id, 'ip_address' => '203.0.113.7']],
        );
        $this->assertSame('203.0.113.7', $extracted['data']['ip_address']);
        $this->assertSame($this->user->id, $extracted['context']['trigger_user_id']);
    }

    /**
     * 알림의 변경 시각이 UTC 가 아니라 사이트 기본 시간대로 적히는지 확인합니다.
     *
     * 수정 후 실측에서 한국 사이트의 해제 알림이 UTC(9시간 이른 시각)로 찍힌 것을 잡았다.
     *
     * @scenario case=totp_notification_time_in_site_timezone
     *
     * @effects totp_notification_time_in_site_timezone
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function notification_time_is_written_in_the_site_timezone(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        $this->wireNotifications();

        $extracted = HookManager::applyFilters(
            'sirsoft-totp.notification.extract_data',
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'totp_enrolled',
            [$this->user, ['occurred_at' => '2026-11-13T02:46:00+00:00', 'ip_address' => '203.0.113.7']],
        );

        $this->assertSame('2026-11-13 11:46', $extracted['data']['occurred_at']);
    }

    /**
     * 운영자가 끈 알림은 가지 않는지 확인합니다.
     *
     * @scenario case=inactive_totp_notification_not_sent
     *
     * @effects inactive_totp_notification_not_sent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_notification_turned_off_by_the_operator_is_not_sent(): void
    {
        $this->wireNotifications(['totp_enrolled']);

        $this->enrollViaApi();

        $this->assertNotified('totp_enrolled', 0);
        $this->assertFalse((bool) NotificationDefinition::query()->where('type', 'totp_enrolled')->value('is_active'));
    }

    /**
     * 비밀번호 재설정이 인증 앱 등록을 지우지 않는지 확인합니다 (재설정 시 인증 앱 유지).
     *
     * 재설정 메일을 가로챈 쪽이 비밀번호를 바꾸더라도 2단계 인증까지 풀리면 안 된다.
     *
     * @scenario case=password_reset_keeps_totp_credential
     *
     * @effects password_reset_keeps_totp_credential
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function password_reset_keeps_the_authenticator_enrollment(): void
    {
        $this->enrollViaApi();

        $plain = 'totp-notify-reset-token';
        app(PasswordResetTokenRepositoryInterface::class)->updateOrCreateByEmail($this->user->email, ['token' => Hash::make($plain), 'created_at' => now()]);
        app(AuthService::class)->resetPassword($plain, $this->user->email, 'Brand-new-pass-123!');

        $credential = TotpCredential::query()->where('user_id', $this->user->id)->first();
        $this->assertNotNull($credential);
        $this->assertNotNull($credential->confirmed_at);
    }
}
