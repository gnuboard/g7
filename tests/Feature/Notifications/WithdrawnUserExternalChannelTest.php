<?php

namespace Tests\Feature\Notifications;

use App\Enums\UserStatus;
use App\Listeners\NotificationHookListener;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

/**
 * 탈퇴한 회원에게는 **바깥 채널로 보내지 않는다** (SA-D13)
 *
 * 탈퇴 처리는 이름·이메일·닉네임을 익명화한다(`…@example.test_deleted_20260921_1733`).
 * 그 주소는 **더 이상 존재하지 않는 우편함**이다. 그런데도 mail·sms·alimtalk 발송을
 * 시도하면 운영에서는 매번 바운스가 나고, 발송 평판이 깎이며, 발송 이력에는 실패가 쌓인다.
 *
 * 실측(#599 세션A 2026-09-22 · 탈퇴한 회원 1733 의 `RF20260921-1613290775` 승인 전이):
 * `refund_approved` 4행이 생기고 수신자 식별자가 익명화된 주소였다. 이 환경은 SMTP 가
 * 없어 `skipped` 로 가려졌지만, SMTP 가 붙은 운영에서는 그대로 나간다.
 *
 * 계약은 「보내지 않는다」가 아니라 **「보내지 않았음을 남긴다」**이다 — 이 저장소는
 * 수신자 0명도 `skipped` 로 남겨 운영자가 "왜 안 왔지" 를 이력에서 답하게 한다
 * (`logNoRecipient` 주석). 탈퇴 제외도 같은 규율을 따른다.
 *
 * `database` 채널(알림함)은 막지 않는다 — 바깥으로 나가지 않고, 탈퇴 회원의 이력을
 * 관리자 화면에서 보는 근거가 된다.
 */
class WithdrawnUserExternalChannelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 리스너의 채널 판정 메서드를 호출합니다.
     *
     * @param  User  $user  수신자
     * @param  string  $channel  채널
     * @return bool 발송을 건너뛰면 true
     */
    private function skips(User $user, string $channel): bool
    {
        $listener = app(NotificationHookListener::class);
        $method = new \ReflectionMethod($listener, 'shouldSkipWithdrawnRecipient');
        $method->setAccessible(true);

        return (bool) $method->invoke($listener, $user, $channel);
    }

    /**
     * 활성 회원을 만듭니다.
     *
     * @return User 활성 회원
     */
    private function activeUser(): User
    {
        return User::factory()->create(['status' => UserStatus::Active->value]);
    }

    /**
     * 탈퇴 회원을 만듭니다.
     *
     * @return User 탈퇴 회원
     */
    private function withdrawnUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'status' => UserStatus::Withdrawn->value,
            'withdrawn_at' => now(),
        ])->save();

        return $user->fresh();
    }

    /**
     * 탈퇴 회원에게는 바깥 채널 발송을 건너뛴다.
     *
     * @scenario case=withdrawn_external
     *
     * @effects withdrawn_member_skips_external_channels
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_user_skips_external_channels(): void
    {
        $user = $this->withdrawnUser();

        $this->assertTrue($user->isWithdrawn(), '탈퇴 상태로 준비되어야 합니다.');

        foreach (['mail', 'sms', 'alimtalk'] as $channel) {
            $this->assertTrue(
                $this->skips($user, $channel),
                "[$channel] 탈퇴 회원에게 바깥 채널로 보내면 안 됩니다 — 익명화된 주소라 바운스가 납니다."
            );
        }
    }

    /**
     * 탈퇴 회원이어도 알림함(database)은 막지 않는다.
     *
     * @scenario case=withdrawn_database
     *
     * @effects withdrawn_member_keeps_database_channel
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_user_still_gets_the_database_channel(): void
    {
        $user = $this->withdrawnUser();

        $this->assertFalse(
            $this->skips($user, 'database'),
            'database 채널은 바깥으로 나가지 않으므로 막지 않습니다 — 관리자가 이력을 보는 근거입니다.'
        );
    }

    /**
     * 활성 회원은 어떤 채널도 막지 않는다.
     *
     * 탈퇴 제외가 과하게 걸리면 멀쩡한 회원의 알림이 조용히 사라진다.
     *
     * @scenario case=active_external
     *
     * @effects active_member_is_never_skipped
     *
     * @return void 반환값 없음
     */
    public function test_active_user_is_never_skipped(): void
    {
        $user = $this->activeUser();

        foreach (['mail', 'sms', 'alimtalk', 'database'] as $channel) {
            $this->assertFalse(
                $this->skips($user, $channel),
                "[$channel] 활성 회원의 발송을 막으면 안 됩니다."
            );
        }
    }

    /**
     * 회원이 아닌 수신자(비회원 등)는 판정 대상이 아니다.
     *
     * 비회원 주문 알림은 User 가 아닌 임시 notifiable 로 나간다 — 탈퇴 개념이 없다.
     *
     * @scenario case=non_member
     *
     * @effects non_member_recipient_is_out_of_scope
     *
     * @return void 반환값 없음
     */
    public function test_non_user_notifiable_is_not_skipped(): void
    {
        $guest = new AnonymousNotifiable;

        $listener = app(NotificationHookListener::class);
        $method = new \ReflectionMethod($listener, 'shouldSkipWithdrawnRecipient');
        $method->setAccessible(true);

        $this->assertFalse(
            (bool) $method->invoke($listener, $guest, 'mail'),
            '비회원 수신자는 탈퇴 판정 대상이 아닙니다.'
        );
    }

    /**
     * 보내지 않은 것은 **사유와 함께** 이력에 남는다.
     *
     * 이 저장소의 규율은 「보내지 않는다」가 아니라 「보내지 않았음을 남긴다」이다 —
     * 수신자 0명도 `skipped` 로 남겨 운영자가 "왜 안 왔지" 를 화면에서 답하게 한다.
     * 탈퇴 제외가 조용하면 운영자는 알림이 사라진 이유를 영영 알 수 없다.
     *
     * @scenario case=withdrawn_external
     *
     * @effects skipped_send_records_its_reason
     *
     * @return void 반환값 없음
     */
    public function test_the_skipped_send_carries_its_reason(): void
    {
        $listener = file_get_contents(app_path('Listeners/NotificationHookListener.php'));

        // 제외 자리가 skipped 기록을 남기는지 — 조용히 continue 하면 안 된다.
        $this->assertStringContainsString(
            'logWithdrawnRecipient',
            $listener,
            '탈퇴 제외는 발송 이력에 skipped 로 남겨야 합니다.'
        );
        $this->assertStringContainsString(
            'notification.skipped.withdrawn_recipient',
            $listener,
            '제외 사유 문구 키를 남겨야 합니다.'
        );

        foreach (['ko', 'en'] as $locale) {
            $messages = require base_path("lang/{$locale}/notification.php");
            $reason = $messages['skipped']['withdrawn_recipient'] ?? '';

            $this->assertNotSame('', $reason, "[{$locale}] 제외 사유 문구가 있어야 합니다.");
            $this->assertStringNotContainsString(
                'notification.skipped',
                $reason,
                "[{$locale}] 원문 키가 그대로 보이면 안 됩니다."
            );
        }
    }
}
