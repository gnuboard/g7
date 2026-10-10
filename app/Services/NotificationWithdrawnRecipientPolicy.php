<?php

namespace App\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;

/**
 * 탈퇴·삭제 회원 수신 정책 (단일 판정 지점)
 *
 * 알림이 **회원을 지목한 사건**에서 그 회원이 탈퇴했거나 계정이 삭제된 경우를 어떻게
 * 다룰지 한 곳에서 정합니다. 수신자 해석({@see NotificationRecipientResolver})과
 * 발송 루프(`NotificationHookListener`)가 이 판정을 함께 씁니다 —
 * 두 곳이 각자 판정하면 한쪽은 "보내지 않는다", 다른 쪽은 "다른 주소로 대신 보낸다" 로
 * 갈라지고, 그 차이는 어디에도 드러나지 않습니다.
 *
 * 정책:
 * - 회원이 지목된 사건(`trigger_user_id` 있음)은 **그 회원에게만** 갑니다. 계정이 삭제되어
 *   찾을 수 없어도 확장이 실은 게스트 재료(`guest_recipient` — 주문서 연락처 등)로
 *   **대신 보내지 않습니다.** 게스트 재료는 회원이 지목되지 않은 사건(비회원)에서만 씁니다.
 * - 탈퇴 회원(행은 남고 연락처가 익명화됨)에게는 바깥 채널(mail·sms·alimtalk 등)로 보내지
 *   않습니다. 알림함(`database`)은 바깥으로 나가지 않으므로 막지 않습니다.
 */
class NotificationWithdrawnRecipientPolicy
{
    /**
     * @param  UserRepositoryInterface  $userRepository  사용자 조회 Repository
     */
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * 사건이 회원을 지목했는지 판정합니다.
     *
     * @param  array  $context  extract_data 가 실은 컨텍스트
     * @return bool 회원 유발자(`trigger_user_id`)가 있으면 true
     */
    public function namesMember(array $context): bool
    {
        return ! empty($context['trigger_user_id']);
    }

    /**
     * 게스트 재료(`guest_recipient`)로 수신자를 만들어도 되는지 판정합니다.
     *
     * 회원이 지목된 사건에서는 그 회원이 삭제되었더라도 허용하지 않습니다 — 삭제 회원을
     * 다른 주소로 대신 부르면, 탈퇴 회원에게 바깥 채널을 막은 정책과 정면으로 어긋납니다.
     *
     * @param  array  $context  extract_data 가 실은 컨텍스트
     * @return bool 게스트 폴백을 허용하면 true
     */
    public function allowsGuestFallback(array $context): bool
    {
        return ! $this->namesMember($context);
    }

    /**
     * 사건이 지목한 회원을 찾습니다.
     *
     * 컨텍스트가 모델을 함께 실었으면 그것을, 아니면 ID 로 조회합니다.
     *
     * @param  array  $context  extract_data 가 실은 컨텍스트
     * @return object|null 회원 (지목되지 않았거나 계정이 없으면 null)
     */
    public function findTriggerMember(array $context): ?object
    {
        if (! $this->namesMember($context)) {
            return null;
        }

        $user = $context['trigger_user'] ?? null;
        if (is_object($user)) {
            return $user;
        }

        return $this->userRepository->findById((int) $context['trigger_user_id']);
    }

    /**
     * 사건이 지목한 회원의 계정이 삭제되어 없는지 판정합니다.
     *
     * @param  array  $context  extract_data 가 실은 컨텍스트
     * @return bool 회원이 지목됐는데 계정을 찾을 수 없으면 true
     */
    public function isTriggerMemberMissing(array $context): bool
    {
        return $this->namesMember($context) && $this->findTriggerMember($context) === null;
    }

    /**
     * 수신자에게 해당 채널로 보내지 않아야 하는지 판정합니다.
     *
     * 탈퇴 처리는 이름·이메일·닉네임을 익명화하므로 그 주소는 존재하지 않는 우편함입니다.
     * 회원이 아닌 수신자(비회원 등)는 탈퇴 개념이 없어 판정 대상이 아닙니다.
     *
     * @param  object  $notifiable  수신자 (User 또는 GuestNotifiable 등)
     * @param  string  $channel  채널
     * @return bool 발송을 건너뛰어야 하면 true
     */
    public function blocksChannel(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }

        if ($channel === 'database') {
            return false;
        }

        return $notifiable->isWithdrawn();
    }
}
