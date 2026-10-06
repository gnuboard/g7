<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;

/**
 * 환불신청서의 「실제로 돈이 간 수단」 파생기 (S7 — 알림 · 관리자 상세 공용, 저장하지 않는다)
 *
 * 신청서의 `refund_method` 는 결제수단에서 정해진다. 카드 주문을 마일리지로만 환불(현금 0)해도 수단은 PG 라,
 * 그 라벨에 현금 + 마일리지 합계를 묶으면 「PG사 환불 15,000원」 처럼 실제와 다른 수단을 말한다.
 *
 * 규칙:
 * - 마일리지 수단이면 현금분도 마일리지로 지급되므로(결함 1) 마일리지 한 줄로 합친다.
 * - 현금이 0 이면 수단 라벨은 「마일리지」 다.
 * - 그 밖에는 현금 줄(신청서 수단)과 마일리지 줄을 따로 낸다.
 */
final class RefundBreakdown
{
    /**
     * 현금으로 돌아간 금액을 반환합니다. (마일리지 수단이면 0)
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return float 현금 환불액
     */
    public static function cashAmount(OrderRefund $refund): float
    {
        if ($refund->refund_method === RefundMethodEnum::POINTS) {
            return 0.0;
        }

        return (float) $refund->refund_amount;
    }

    /**
     * 마일리지로 돌아간 금액을 반환합니다. (마일리지 수단이면 현금분 포함)
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return float 마일리지 환불액
     */
    public static function pointsAmount(OrderRefund $refund): float
    {
        $points = (float) $refund->refund_points_amount;

        return $refund->refund_method === RefundMethodEnum::POINTS
            ? $points + (float) $refund->refund_amount
            : $points;
    }

    /**
     * 실제 수단 라벨을 반환합니다. (현금 0 이면 마일리지)
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return string 수단 라벨 (수단이 없고 현금이 있으면 빈 문자열)
     */
    public static function methodLabel(OrderRefund $refund): string
    {
        if (self::cashAmount($refund) <= 0.0 && self::pointsAmount($refund) > 0.0) {
            return RefundMethodEnum::POINTS->label();
        }

        return $refund->refund_method?->label() ?? '';
    }

    /**
     * 수단별 금액 줄을 만듭니다. (현금 줄 → 마일리지 줄, 0 원 줄은 뺀다, 줄바꿈 구분)
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  callable(float): string  $format  금액 포맷터 (호출자의 통화 규칙 — 알림은 결제 통화, 화면은 주문 통화)
     * @return string 내역 줄 (환불액이 없으면 빈 문자열)
     */
    public static function lines(OrderRefund $refund, callable $format): string
    {
        $lines = [];
        $cash = self::cashAmount($refund);
        $points = self::pointsAmount($refund);

        if ($cash > 0.0) {
            $lines[] = __('sirsoft-ecommerce::messages.claims.notifications.refund_breakdown_cash', [
                'method' => $refund->refund_method?->label() ?? '',
                'amount' => $format($cash),
            ]);
        }

        if ($points > 0.0) {
            $lines[] = __('sirsoft-ecommerce::messages.claims.notifications.refund_breakdown_points', [
                'amount' => $format($points),
            ]);
        }

        return implode("\n", $lines);
    }
}
