<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Modules\Sirsoft\Ecommerce\Models\Order;
use RuntimeException;

/**
 * 재활성할 주문의 쿠폰을 다시 쓸 수 없을 때 (Q27)
 *
 * 다른 주문이 이미 쓴 쿠폰·회수된 쿠폰이 있으면 주문을 되살리지 않는다. 「쿠폰 없이 다시 계산」하면
 * 주문 금액이 고객이 낸 금액보다 커져 미수금이 생기고, 「할인 유지」는 같은 1회 쿠폰을 두 주문이 쓴
 * 상태를 남긴다. 일괄은 해당 주문을 모두 알리고 전체를 바꾸지 않는다.
 */
class ReactivationCouponConflictException extends RuntimeException
{
    /**
     * @param  string  $messageKey  다국어 키
     * @param  array<string, mixed>  $replace  치환값
     * @param  array<int, string>  $orderNumbers  막힌 주문번호
     * @param  array<int, array<string, mixed>>  $coupons  충돌 쿠폰 (단건만)
     */
    private function __construct(
        private string $messageKey,
        private array $replace,
        private array $orderNumbers,
        private array $coupons = [],
    ) {
        parent::__construct(__($messageKey, $replace));
    }

    /**
     * 단건 — 한 주문의 쿠폰 충돌
     *
     * @param  Order  $order  주문
     * @param  array<int, array<string, mixed>>  $coupons  충돌 쿠폰 (coupon_issue_id·coupon_name·reason·other_order_number)
     * @return self 예외
     */
    public static function forOrder(Order $order, array $coupons): self
    {
        $names = array_map(
            static fn (array $coupon) => (string) ($coupon['coupon_name'] ?? $coupon['coupon_issue_id'] ?? ''),
            $coupons,
        );

        return new self(
            'sirsoft-ecommerce::exceptions.reactivation.coupon_unavailable',
            ['order_number' => (string) $order->order_number, 'coupons' => implode(', ', $names)],
            [(string) $order->order_number],
            array_values($coupons),
        );
    }

    /**
     * 일괄 — 쿠폰 충돌 주문이 하나 이상일 때
     *
     * @param  array<int, string>  $orderNumbers  막힌 주문번호
     * @return self 예외
     */
    public static function forBulk(array $orderNumbers): self
    {
        return new self(
            'sirsoft-ecommerce::exceptions.reactivation.coupon_unavailable_bulk',
            ['count' => count($orderNumbers), 'order_numbers' => implode(', ', $orderNumbers)],
            array_values($orderNumbers),
        );
    }

    /**
     * 다국어 키를 돌려줍니다.
     *
     * @return string 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 치환값을 돌려줍니다.
     *
     * @return array<string, mixed> 치환값
     */
    public function getMessageParams(): array
    {
        return $this->replace;
    }

    /**
     * 막힌 주문번호를 돌려줍니다.
     *
     * @return array<int, string> 주문번호
     */
    public function getOrderNumbers(): array
    {
        return $this->orderNumbers;
    }

    /**
     * 충돌 쿠폰을 돌려줍니다.
     *
     * @return array<int, array<string, mixed>> 쿠폰
     */
    public function getCoupons(): array
    {
        return $this->coupons;
    }
}
