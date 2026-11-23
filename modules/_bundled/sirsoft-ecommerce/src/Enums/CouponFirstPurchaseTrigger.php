<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 첫구매 쿠폰 발급 시점 Enum (#952)
 *
 * 「회원 주문 중 고른 상태 이상에 처음 도달한 주문」 이 첫구매다. 도달 판정은 상태 집합(`reachedStatusValues()`)으로
 * 하며, 취소·반품완료는 어느 시점에서도 도달로 보지 않는다.
 */
enum CouponFirstPurchaseTrigger: string
{
    case PAYMENT_COMPLETE = 'payment_complete'; // 결제완료
    case PREPARING = 'preparing';               // 상품준비중
    case SHIPPING = 'shipping';                 // 배송중
    case DELIVERED = 'delivered';               // 배송완료
    case CONFIRMED = 'confirmed';               // 구매확정 (기본)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 라벨
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 다국어 라벨 키를 반환합니다. (활동 로그 변경 기록이 읽는다)
     *
     * @return string 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.coupon_first_purchase_trigger.'.$this->value;
    }

    /**
     * 시점의 순서를 반환합니다. (이를수록 작다)
     *
     * @return int 순서
     */
    public function rank(): int
    {
        return match ($this) {
            self::PAYMENT_COMPLETE => 1,
            self::PREPARING => 2,
            self::SHIPPING => 3,
            self::DELIVERED => 4,
            self::CONFIRMED => 5,
        };
    }

    /**
     * 이 시점 이상에 도달한 것으로 보는 주문 상태 값 목록을 반환합니다.
     *
     * 교환·반품 진행중과 교환완료는 배송을 받은 뒤의 상태라 배송완료까지의 시점에 포함한다. 구매확정 시점은
     * 확정 상태만 보고, 확정 이력(`confirmed_at`)이 있는 주문은 `countsConfirmedAt()` 이 함께 본다.
     *
     * @return array<int, string> 주문 상태 값
     */
    public function reachedStatusValues(): array
    {
        $afterDelivery = [
            OrderStatusEnum::EXCHANGE_IN_PROGRESS->value,
            OrderStatusEnum::RETURN_IN_PROGRESS->value,
            OrderStatusEnum::EXCHANGED->value,
        ];

        return match ($this) {
            self::PAYMENT_COMPLETE => [
                OrderStatusEnum::PAYMENT_COMPLETE->value,
                OrderStatusEnum::SHIPPING_HOLD->value,
                OrderStatusEnum::PREPARING->value,
                OrderStatusEnum::SHIPPING_READY->value,
                OrderStatusEnum::SHIPPING->value,
                OrderStatusEnum::DELIVERED->value,
                OrderStatusEnum::CONFIRMED->value,
                ...$afterDelivery,
            ],
            self::PREPARING => [
                OrderStatusEnum::PREPARING->value,
                OrderStatusEnum::SHIPPING_READY->value,
                OrderStatusEnum::SHIPPING->value,
                OrderStatusEnum::DELIVERED->value,
                OrderStatusEnum::CONFIRMED->value,
                ...$afterDelivery,
            ],
            self::SHIPPING => [
                OrderStatusEnum::SHIPPING->value,
                OrderStatusEnum::DELIVERED->value,
                OrderStatusEnum::CONFIRMED->value,
                ...$afterDelivery,
            ],
            self::DELIVERED => [
                OrderStatusEnum::DELIVERED->value,
                OrderStatusEnum::CONFIRMED->value,
                ...$afterDelivery,
            ],
            self::CONFIRMED => [
                OrderStatusEnum::CONFIRMED->value,
            ],
        };
    }

    /**
     * 확정 이력(`confirmed_at`)이 있는 주문을 도달로 함께 볼지 반환합니다.
     *
     * @return bool 구매확정 시점이면 true
     */
    public function countsConfirmedAt(): bool
    {
        return $this === self::CONFIRMED;
    }

    /**
     * 그 주문이 전부 취소·반품완료되면 미사용 쿠폰을 회수하는 시점인지 반환합니다.
     *
     * 구매확정 시점의 쿠폰은 확정된 주문에서만 나가므로 회수하지 않는다.
     *
     * @return bool 구매확정보다 이른 시점이면 true
     */
    public function revocable(): bool
    {
        return $this !== self::CONFIRMED;
    }

    /**
     * 저장값을 시점으로 바꿉니다. 비어 있거나 알 수 없는 값이면 구매확정입니다.
     *
     * @param  string|null  $value  저장값
     * @return self 시점
     */
    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::CONFIRMED;
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<int, string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<int, array{value: string, label: string}> 옵션 목록
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
