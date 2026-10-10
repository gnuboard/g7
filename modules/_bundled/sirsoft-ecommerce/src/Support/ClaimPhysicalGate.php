<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;

/**
 * 관리자 직권 접수의 물리 게이트 (D5 — 단일 출처)
 *
 * 운영자가 고객을 대신해 접수할 때는 고객용 제한(신청 가능 기간 · 신청 가능 상태 · 취소 가능 상태
 * 설정)을 따르지 않는다. 대신 **물리적으로 가능한 것**만 받는다:
 *
 *  - 교환·반품 — 물건이 고객에게 간 상태(배송중·배송완료·구매확정).
 *  - 취소·환불 — 아직 보내지 않은 상태(미결제 주문대기·결제대기 포함, 결제완료·배송보류·상품준비중·배송준비중).
 *
 * 클레임 코스 상태(교환·반품 진행중)와 종결(교환완료·반품완료·취소)은 두 목록 어디에도 없으므로
 * 자연히 거절된다. 수량 잠금(`ClaimQuantityGuard` — 다른 신청서가 잡은 수량)은 그대로 적용된다.
 */
final class ClaimPhysicalGate
{
    /**
     * 유형별로 직권 접수를 받는 주문 옵션 상태 값을 반환합니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @return array<int, string> 주문 옵션 상태 값
     */
    public static function statuses(ClaimTypeEnum $type): array
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE, ClaimTypeEnum::RETURN => [
                OrderStatusEnum::SHIPPING->value,
                OrderStatusEnum::DELIVERED->value,
                OrderStatusEnum::CONFIRMED->value,
            ],
            default => [
                OrderStatusEnum::PENDING_ORDER->value,
                OrderStatusEnum::PENDING_PAYMENT->value,
                OrderStatusEnum::PAYMENT_COMPLETE->value,
                OrderStatusEnum::SHIPPING_HOLD->value,
                OrderStatusEnum::PREPARING->value,
                OrderStatusEnum::SHIPPING_READY->value,
            ],
        };
    }

    /**
     * 주문 옵션 상태가 그 유형의 직권 접수를 받는지 판정합니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  OrderStatusEnum|string|null  $status  주문 옵션 상태
     * @return bool 받으면 true
     */
    public static function allows(ClaimTypeEnum $type, OrderStatusEnum|string|null $status): bool
    {
        $value = $status instanceof OrderStatusEnum ? $status->value : $status;

        return $value !== null && in_array($value, self::statuses($type), true);
    }
}
