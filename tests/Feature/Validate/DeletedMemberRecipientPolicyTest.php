<?php

namespace Tests\Feature\Validate;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Listeners\NotificationHookListener;
use App\Models\User;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationRecipientResolver;
use App\Services\NotificationWithdrawnRecipientPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 탈퇴·삭제 회원 수신 정책의 단일화 (#599 validate 코어 major 5)
 *
 * 발송 루프는 탈퇴 회원에게 바깥 채널을 막는데, 수신자 해석은 계정이 삭제된 회원을
 * 확장이 실은 게스트 재료(주문서 연락처)로 대신 불러 메일을 보냈다. 같은 "그 회원은 더 이상
 * 받을 수 없다" 를 두 곳이 반대로 판정했다. 정책은 하나다 — **삭제 회원은 폴백하지 않는다.**
 */
class DeletedMemberRecipientPolicyTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 존재하지 않는 회원 ID 를 만듭니다.
     *
     * @return int 삭제된 회원 ID 로 쓸 값
     */
    private function missingUserId(): int
    {
        return ((int) User::query()->max('id')) + 100000;
    }

    /**
     * 수신자 해석기를 새로 만듭니다 (컨테이너 바인딩 교체 뒤에도 반영되도록).
     *
     * @return NotificationRecipientResolver 수신자 해석기
     */
    private function resolver(): NotificationRecipientResolver
    {
        $this->app->forgetInstance(NotificationRecipientResolver::class);

        return $this->app->make(NotificationRecipientResolver::class);
    }

    /**
     * 리스너의 private 메서드를 호출합니다.
     *
     * @param  string  $method  메서드명
     * @param  array  $args  인자
     * @return mixed 반환값
     */
    private function invokeListener(string $method, array $args): mixed
    {
        $listener = $this->app->make(NotificationHookListener::class);
        $reflection = new \ReflectionMethod($listener, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($listener, ...$args);
    }

    /**
     * 삭제 회원은 게스트 재료가 실려 있어도 그 주소로 대신 부르지 않는다.
     *
     * 수정 전 실패 이유: addTriggerUser 가 계정을 못 찾으면 guest_recipient 로 낙하해
     * GuestNotifiable 1명을 돌려줬다(주문서 연락처로 메일 발송).
     *
     * @scenario case=deleted_member
     *
     * @effects deleted_member_is_not_replaced_by_guest_material
     *
     * @return void 반환값 없음
     */
    public function test_deleted_member_is_not_replaced_by_guest_material(): void
    {
        $result = $this->resolver()->resolve(
            [['type' => 'trigger_user']],
            [
                'trigger_user_id' => $this->missingUserId(),
                'trigger_user' => null,
                'guest_recipient' => ['email' => 'orderer@example.com', 'name' => '주문자', 'locale' => 'ko'],
            ]
        );

        $this->assertCount(0, $result, '삭제 회원을 주문서 연락처로 대신 부르면 안 됩니다.');
    }

    /**
     * 삭제 회원 때문에 0명이 된 것은 그 사유로 이력에 남는다.
     *
     * 수정 전 실패 이유: resolveNoRecipientReason 이 trigger_user_id 가 있으면 무조건
     * no_recipient_resolved 를 돌려줘 운영자가 수신자 규칙을 의심하게 만들었다.
     *
     * @scenario case=deleted_member
     *
     * @effects deleted_member_skip_is_logged_with_its_own_reason
     *
     * @return void 반환값 없음
     */
    public function test_deleted_member_skip_is_logged_with_its_own_reason(): void
    {
        $reason = $this->invokeListener('resolveNoRecipientReason', [
            [['type' => 'trigger_user']],
            ['trigger_user_id' => $this->missingUserId(), 'guest_recipient' => ['email' => 'orderer@example.com']],
        ]);

        $this->assertSame('member_account_missing', $reason);

        foreach (['ko', 'en'] as $locale) {
            $messages = require base_path("lang/{$locale}/notification.php");
            $this->assertNotEmpty(
                $messages['skipped']['member_account_missing'] ?? null,
                "[{$locale}] 삭제 회원 사유 문구가 있어야 합니다."
            );
        }

        $ja = require base_path('lang-packs/_bundled/g7-core-ja/backend/ja/notification.php');
        $this->assertNotEmpty($ja['skipped']['member_account_missing'] ?? null, '[ja] 삭제 회원 사유 문구가 있어야 합니다.');
    }

    /**
     * 코어 사유 문구는 특정 확장의 도메인(주문 등)을 알지 않는다.
     *
     * 수정 전 실패 이유: 7.1.0 에 새로 들어간 문구가 「주문서 등에 남은 연락처」처럼
     * 쇼핑몰 도메인을 직접 언급해, 쇼핑몰이 없는 사이트에도 그대로 노출됐다.
     *
     * @scenario case=deleted_member
     *
     * @effects deleted_member_skip_reason_is_domain_neutral
     *
     * @return void 반환값 없음
     */
    public function test_deleted_member_skip_reason_is_domain_neutral(): void
    {
        $texts = [
            'ko' => (require base_path('lang/ko/notification.php'))['skipped']['member_account_missing'],
            'en' => (require base_path('lang/en/notification.php'))['skipped']['member_account_missing'],
            'ja' => (require base_path('lang-packs/_bundled/g7-core-ja/backend/ja/notification.php'))['skipped']['member_account_missing'],
        ];

        foreach ($texts as $locale => $text) {
            $this->assertDoesNotMatchRegularExpression(
                '/주문|order|注文/iu',
                $text,
                "[{$locale}] 코어 사유 문구에 쇼핑몰 도메인 단어가 없어야 합니다."
            );
        }
    }

    /**
     * 수신자 해석과 발송 루프가 한 정책 객체의 판정을 쓴다.
     *
     * 정책 객체를 바꿔 끼우면 두 경로가 함께 따라와야 한다 — 한쪽만 따라오면 판정이 다시 두 벌이다.
     *
     * 수정 전 실패 이유: NotificationWithdrawnRecipientPolicy 가 없고, 두 곳이 각자 판정했다.
     *
     * @scenario case=single_policy
     *
     * @effects resolver_and_listener_share_one_policy
     *
     * @return void 반환값 없음
     */
    public function test_resolver_and_listener_share_one_policy(): void
    {
        $fake = new class($this->app->make(UserRepositoryInterface::class)) extends NotificationWithdrawnRecipientPolicy
        {
            /**
             * 모든 게스트 폴백을 막습니다.
             *
             * @param  array  $context  컨텍스트
             * @return bool 항상 false
             */
            public function allowsGuestFallback(array $context): bool
            {
                return false;
            }

            /**
             * 모든 채널을 막습니다.
             *
             * @param  object  $notifiable  수신자
             * @param  string  $channel  채널
             * @return bool 항상 true
             */
            public function blocksChannel(object $notifiable, string $channel): bool
            {
                return true;
            }
        };

        $this->app->instance(NotificationWithdrawnRecipientPolicy::class, $fake);

        $guestOnly = $this->resolver()->resolve(
            [['type' => 'trigger_user']],
            ['guest_recipient' => ['email' => 'guest@example.com']]
        );
        $this->assertCount(0, $guestOnly, '수신자 해석이 정책 객체를 따르지 않습니다.');

        $active = User::factory()->create(['status' => UserStatus::Active->value]);
        $this->assertTrue(
            (bool) $this->invokeListener('shouldSkipWithdrawnRecipient', [$active, 'mail']),
            '발송 루프가 정책 객체를 따르지 않습니다.'
        );
    }

    /**
     * 탈퇴 회원(행은 남음)은 그 회원 자신으로 해석되고, 게스트 재료로 바뀌지 않는다.
     *
     * 알림함(database)은 남고 바깥 채널만 막힌다 — 과잉 수정(해석 단계에서 탈퇴 회원을
     * 지워 알림함 이력까지 끊는 것)을 막는다.
     *
     * @scenario case=withdrawn_member
     *
     * @effects withdrawn_member_resolves_to_self_and_only_external_channels_are_blocked
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_member_resolves_to_self_and_only_external_channels_are_blocked(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['status' => UserStatus::Withdrawn->value, 'withdrawn_at' => now()])->save();
        $user = $user->fresh();

        $result = $this->resolver()->resolve(
            [['type' => 'trigger_user']],
            [
                'trigger_user_id' => $user->id,
                'guest_recipient' => ['email' => 'orderer@example.com'],
            ]
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(User::class, $result->first());
        $this->assertSame($user->id, $result->first()->id);

        $policy = $this->app->make(NotificationWithdrawnRecipientPolicy::class);
        $this->assertTrue($policy->blocksChannel($user, 'mail'));
        $this->assertFalse($policy->blocksChannel($user, 'database'));
    }

    /**
     * 회원이 지목되지 않은 사건(비회원)은 종전대로 게스트 재료로 보낸다.
     *
     * @scenario case=guest_event
     *
     * @effects guest_event_still_uses_guest_material
     *
     * @return void 반환값 없음
     */
    public function test_guest_event_still_uses_guest_material(): void
    {
        $result = $this->resolver()->resolve(
            [['type' => 'trigger_user']],
            ['trigger_user_id' => null, 'guest_recipient' => ['email' => 'guest@example.com', 'name' => '비회원']]
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(GuestNotifiable::class, $result->first());
    }
}
