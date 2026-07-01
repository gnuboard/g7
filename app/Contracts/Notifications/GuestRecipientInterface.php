<?php

namespace App\Contracts\Notifications;

/**
 * 비회원(게스트) 알림 수신자 계약
 *
 * user_id 없이 이메일/이름/로케일만 가진 익명 수신자를 1급 수신자로 표현합니다.
 * 알림 채널 게이트(GenericNotification::via)는 구체 타입(User 등) 검사 대신
 * 이 계약으로 게스트 여부를 판별하여, 채널별 게스트 발송 허용 정책을 적용합니다.
 *
 * 구현체는 Laravel Notifiable 트레잇을 함께 사용해 회원과 동일한
 * `$notifiable->notify()` 발송 경로를 공유합니다.
 */
interface GuestRecipientInterface
{
    /**
     * 게스트(비회원) 수신자 여부를 반환합니다.
     *
     * @return bool true = 비회원 수신자
     */
    public function isGuest(): bool;
}
