<?php

namespace App\Enums;

/**
 * 알림 수신자별 발송 기록 상태 (#139)
 *
 * notification_deliveries.status 의 SSoT.
 */
enum NotificationDeliveryStatus: string
{
    /**
     * 발송 중 — 한 사본이 이 수신자를 선점했다(리스). 리스가 지나면 다른 사본이 넘겨받는다.
     */
    case Sending = 'sending';

    /**
     * 보냄 — 다시 보내지 않는다.
     */
    case Sent = 'sent';

    /**
     * 건너뜀 — 탈퇴 회원·채널 꺼짐 등으로 보내지 않기로 확정했다. 다시 판정하지 않는다.
     */
    case Skipped = 'skipped';

    /**
     * 모든 상태 값을 배열로 반환합니다.
     *
     * @return array<int, string> 상태 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 처리가 끝난(다시 손대지 않는) 상태인지 반환합니다.
     *
     * @return bool 보냄·건너뜀이면 true
     */
    public function isDone(): bool
    {
        return $this !== self::Sending;
    }
}
