<?php

namespace App\Enums;

/**
 * 알림 수신자 발송 선점 결과 (#139)
 *
 * NotificationDeliveryGuard::claim() 의 반환값.
 */
enum NotificationDeliveryClaim: string
{
    /**
     * 선점했다 — 보내고 나서 markSent()/markSkipped(), 실패하면 release() 한다.
     */
    case Acquired = 'acquired';

    /**
     * 이미 보냈거나 건너뛰기로 확정됐다 — 건너뛴다.
     */
    case Done = 'done';

    /**
     * 다른 사본이 지금 보내는 중이다(리스 안) — 보류했다가 나중에 다시 본다.
     */
    case InFlight = 'in_flight';
}
