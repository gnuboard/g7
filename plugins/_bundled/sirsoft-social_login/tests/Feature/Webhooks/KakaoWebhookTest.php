<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Webhooks;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;

/**
 * 카카오 연결 끊기 웹훅 — 어드민 키·발신 IP 확인, 언제나 200, 멱등, 사유 5종, 정책 2(연결만 해제 / 회원 탈퇴), 로그인 수단이 사라진
 * 회원 안내, 가입 미완료 무안내.
 *
 * @since 1.0.0
 */
class KakaoWebhookTest extends WebhookTestCase
{
    /** 웹훅 주소 */
    private const URL = '/plugins/sirsoft-social_login/webhooks/kakao';

    /** 카카오 발신 대역 안의 주소 */
    private const KAKAO_IP = '121.53.90.40';

    /** @var array<int, array{0: int, 1: string}> 마지막 수단 안내 훅 기록 */
    private array $lastMethod = [];

    /**
     * 카카오를 어드민 키와 함께 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureProvider('kakao', ['client_id' => 'k-id', 'client_secret' => 'k-secret', 'admin_key' => 'kakao-admin-key']);
        HookManager::addAction('sirsoft-social_login.account.unlinked_last_method', function (User $user, string $provider) {
            $this->lastMethod[] = [$user->id, $provider];
        });
    }

    /**
     * 카카오 발신 대역에서 어드민 키로 보낸 알림을 보냅니다.
     *
     * @param  array<string, string>  $body  알림 값
     * @param  string  $key  어드민 키
     * @param  string  $ip  발신 IP
     * @return TestResponse 응답
     */
    private function notify(array $body, string $key = 'kakao-admin-key', string $ip = self::KAKAO_IP)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['Authorization' => 'KakaoAK '.$key])
            ->post(self::URL, $body);
    }

    /**
     * 정상 알림은 200 이고 연결을 지운다. 비밀번호도 다른 연결도 없는 회원에게는 안내 훅이 나가고, 해제는 활동 로그에 남는다.
     * 같은 알림을 다시 보내면 행이 늘지 않는다.
     *
     * @scenario provider=kakao, referrer=UNLINK_FROM_APPS, policy=unlink_only, member=passwordless_single
     *
     * @effects kakao_200, webhook_processed, link_removed_without_revoke, last_method_notice, webhook_unlink_activity_logged, resend_deduplicated
     *
     * @return void 반환값 없음
     */
    public function test_unlink_notice_removes_link_once(): void
    {
        $user = $this->linkedMember('kakao', '9001');

        $this->notify(['app_id' => '1', 'user_id' => '9001', 'referrer_type' => 'UNLINK_FROM_APPS'])->assertStatus(200);

        $event = SocialLoginWebhookEvent::query()->where('provider_key', 'kakao')->where('provider_user_id', '9001')->firstOrFail();
        $this->assertSame(['processed', 'unlink'], [$event->status, $event->event_type]);
        $this->assertSame(0, SocialAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame([[$user->id, 'kakao']], $this->lastMethod);
        $this->assertDatabaseHas('activity_logs', ['loggable_id' => $user->id, 'action' => 'account_unlinked_by_webhook']);
        $this->assertArrayNotHasKey('authorization', $event->payload);

        $this->notify(['app_id' => '1', 'user_id' => '9001', 'referrer_type' => 'UNLINK_FROM_APPS'])->assertStatus(200);
        $this->assertSame(1, SocialLoginWebhookEvent::query()->where('provider_key', 'kakao')->where('provider_user_id', '9001')->count());
    }

    /**
     * 잘못된 어드민 키·대역 밖 IP 는 200 으로 답하되 처리하지 않고 사유를 남긴다. IP 확인을 끄면 대역 밖에서도 받는다.
     *
     * @scenario auth=wrong_key|outside_ip|allowlist_off
     *
     * @effects bad_key_ignored_200, outside_ip_ignored_200, allowlist_toggle
     *
     * @return void 반환값 없음
     */
    public function test_verification_failures_are_ignored_with_200(): void
    {
        $this->linkedMember('kakao', '9002');

        $this->notify(['user_id' => '9002', 'referrer_type' => 'UNLINK_FROM_APPS'], 'wrong-key')->assertStatus(200);
        $this->notify(['user_id' => '9002', 'referrer_type' => 'UNLINK_FROM_APPS'], 'kakao-admin-key', '10.0.0.5')->assertStatus(200);

        $errors = SocialLoginWebhookEvent::query()->where('provider_key', 'kakao')->where('status', 'ignored')->orderBy('id')->pluck('error')->all();
        $this->assertSame(['webhook_auth_invalid', 'webhook_ip_not_allowed'], array_slice($errors, -2));
        $this->assertSame(1, SocialAccount::query()->where('provider_user_id', '9002')->count());

        $this->configureProvider('kakao', ['extra' => ['webhook_ip_allowlist_enabled' => false]]);
        $this->notify(['user_id' => '9002', 'referrer_type' => 'UNLINK_FROM_APPS'], 'kakao-admin-key', '10.0.0.5')->assertStatus(200);
        $this->assertSame(0, SocialAccount::query()->where('provider_user_id', '9002')->count());
    }

    /**
     * 운영자가 앱 ID 를 넣으면 다른 앱의 알림은 200 으로 답하되 처리하지 않고 `webhook_app_mismatch` 로 남긴다. 같은 앱의 알림은 처리한다.
     *
     * @scenario auth=app_id_mismatch
     *
     * @effects app_id_mismatch_ignored_200
     *
     * @return void 반환값 없음
     */
    public function test_app_id_mismatch_is_ignored_with_200(): void
    {
        $this->configureProvider('kakao', ['extra' => ['app_id' => '111']]);
        $this->linkedMember('kakao', '9010');

        $this->notify(['app_id' => '222', 'user_id' => '9010', 'referrer_type' => 'UNLINK_FROM_APPS'])->assertStatus(200);
        $this->assertSame('webhook_app_mismatch', SocialLoginWebhookEvent::query()->where('provider_key', 'kakao')->latest('id')->value('error'));
        $this->assertSame(1, SocialAccount::query()->where('provider_user_id', '9010')->count());

        $this->notify(['app_id' => '111', 'user_id' => '9010', 'referrer_type' => 'UNLINK_FROM_APPS'])->assertStatus(200);
        $this->assertSame(0, SocialAccount::query()->where('provider_user_id', '9010')->count());
    }

    /**
     * 형식이 틀린 요청(길이 초과)도 422 가 아니라 200 으로 답하고 `webhook_payload_invalid` 로 남긴다 — 실패 응답이 쌓이면
     * 카카오가 알림을 멈춘다.
     *
     * @scenario auth=malformed_payload
     *
     * @effects malformed_payload_ignored_200
     *
     * @return void 반환값 없음
     */
    public function test_malformed_request_is_ignored_with_200(): void
    {
        $this->notify(['user_id' => str_repeat('9', 300), 'referrer_type' => 'UNLINK_FROM_APPS'])->assertStatus(200);

        $this->assertSame('webhook_payload_invalid', SocialLoginWebhookEvent::query()->where('provider_key', 'kakao')->latest('id')->value('error'));
    }

    /**
     * 사유 5종의 이벤트 종류 매핑. 가입 미완료는 연결만 정리하고 안내하지 않는다. 연결이 없는 회원 알림은 `ignored`.
     *
     * @scenario referrer=ACCOUNT_DELETE|FORCED_ACCOUNT_DELETE|UNLINK_FROM_ADMIN|UNLINK_FROM_APPS|INCOMPLETE_SIGN_UP
     *
     * @effects referrer_types_mapped, incomplete_signup_no_notice, unknown_member_ignored
     *
     * @return void 반환값 없음
     */
    public function test_referrer_types(): void
    {
        $expected = [
            'ACCOUNT_DELETE' => 'account_delete',
            'FORCED_ACCOUNT_DELETE' => 'account_delete',
            'UNLINK_FROM_ADMIN' => 'unlink',
            'UNLINK_FROM_APPS' => 'unlink',
            'INCOMPLETE_SIGN_UP' => 'incomplete_signup',
        ];

        $users = [];
        foreach ($expected as $referrer => $type) {
            $uid = 'r-'.strtolower($referrer);
            $users[$referrer] = $this->linkedMember('kakao', $uid);
            $this->notify(['user_id' => $uid, 'referrer_type' => $referrer])->assertStatus(200);
            $this->assertSame($type, SocialLoginWebhookEvent::query()->where('provider_user_id', $uid)->value('event_type'), $referrer);
            $this->assertSame(0, SocialAccount::query()->where('provider_user_id', $uid)->count(), $referrer);
        }

        $noticed = array_column($this->lastMethod, 0);
        $this->assertNotContains($users['INCOMPLETE_SIGN_UP']->id, $noticed);
        $this->assertCount(4, $noticed);

        $this->notify(['user_id' => 'nobody', 'referrer_type' => 'UNLINK_FROM_APPS'])->assertStatus(200);
        $nobody = SocialLoginWebhookEvent::query()->where('provider_user_id', 'nobody')->firstOrFail();
        $this->assertSame(['ignored', 'not_linked'], [$nobody->status, $nobody->error]);
    }

    /**
     * 정책 「회원 탈퇴」: 일반 회원은 탈퇴되고, 관리자 계정은 코어가 거부해 `failed` 와 관리자 알림 훅이 남는다(연결은 지워짐).
     * 비밀번호가 있는 회원은 연결만 지워도 안내하지 않는다.
     *
     * @scenario policy=withdraw_user, member=regular|admin|password_set
     *
     * @effects withdraw_policy_withdraws_member, withdraw_refused_for_admin_marks_failed, webhook_failed_hook, password_member_no_notice
     *
     * @return void 반환값 없음
     */
    public function test_withdraw_policy(): void
    {
        $this->setPluginSettings(['webhook_unlink_policy' => 'withdraw_user']);
        $failed = [];
        HookManager::addAction('sirsoft-social_login.webhook.failed', function (array $summary) use (&$failed) {
            $failed[] = $summary;
        });

        $member = $this->linkedMember('kakao', 'w-member', false);
        $this->notify(['user_id' => 'w-member', 'referrer_type' => 'ACCOUNT_DELETE'])->assertStatus(200);
        $this->assertSame(UserStatus::Withdrawn->value, $member->refresh()->status);

        $admin = $this->makeAdminUser(['email' => $this->uniqueEmail('wadmin')]);
        SocialAccount::query()->create(['user_id' => $admin->id, 'provider_key' => 'kakao', 'provider_user_id' => 'w-admin', 'linked_at' => now()]);
        $this->notify(['user_id' => 'w-admin', 'referrer_type' => 'ACCOUNT_DELETE'])->assertStatus(200);

        $event = SocialLoginWebhookEvent::query()->where('provider_user_id', 'w-admin')->firstOrFail();
        $this->assertSame('failed', $event->status);
        $this->assertStringStartsWith('withdraw_refused', (string) $event->error);
        $this->assertSame('kakao', $failed[0]['provider_key'] ?? null);
        $this->assertSame(0, SocialAccount::query()->where('user_id', $admin->id)->count());
        $this->assertNotSame(UserStatus::Withdrawn->value, $admin->refresh()->status);

        $this->setPluginSettings(['webhook_unlink_policy' => 'unlink_only']);
        $withPassword = $this->linkedMember('kakao', 'w-pw', false);
        $this->notify(['user_id' => 'w-pw', 'referrer_type' => 'UNLINK_FROM_APPS'])->assertStatus(200);
        $this->assertNotContains([$withPassword->id, 'kakao'], $this->lastMethod);
    }
}
