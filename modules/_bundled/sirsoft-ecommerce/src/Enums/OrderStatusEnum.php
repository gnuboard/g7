<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 주문 상태 Enum
 */
enum OrderStatusEnum: string
{
    case PENDING_ORDER = 'pending_order';           // 주문대기
    case PENDING_PAYMENT = 'pending_payment';       // 결제대기
    case PAYMENT_COMPLETE = 'payment_complete';     // 결제완료
    case SHIPPING_HOLD = 'shipping_hold';           // 배송보류
    case PREPARING = 'preparing';                   // 상품준비중
    case SHIPPING_READY = 'shipping_ready';         // 배송준비완료
    case SHIPPING = 'shipping';                     // 배송중
    case DELIVERED = 'delivered';                   // 배송완료
    case CONFIRMED = 'confirmed';                   // 구매확정
    case PARTIAL_CANCELLED = 'partial_cancelled';   // 부분취소
    case CANCELLED = 'cancelled';                   // 주문취소

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.order_status.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * @return string
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.order_status.'.$this->value;
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string
     */
    public function variant(): string
    {
        return match ($this) {
            self::PENDING_ORDER => 'secondary',
            self::PENDING_PAYMENT => 'warning',
            self::PAYMENT_COMPLETE => 'info',
            self::SHIPPING_HOLD => 'warning',
            self::PREPARING => 'info',
            self::SHIPPING_READY => 'info',
            self::SHIPPING => 'primary',
            self::DELIVERED => 'success',
            self::CONFIRMED => 'success',
            self::PARTIAL_CANCELLED => 'warning',
            self::CANCELLED => 'danger',
        };
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }

    /**
     * 결제 전 상태인지 확인합니다.
     *
     * @return bool
     */
    public function isBeforePayment(): bool
    {
        return in_array($this, [self::PENDING_ORDER, self::PENDING_PAYMENT]);
    }

    /**
     * 배송 전 상태인지 확인합니다.
     *
     * @return bool
     */
    public function isBeforeShipping(): bool
    {
        return in_array($this, [
            self::PENDING_ORDER,
            self::PENDING_PAYMENT,
            self::PAYMENT_COMPLETE,
            self::SHIPPING_HOLD,
            self::PREPARING,
            self::SHIPPING_READY,
        ]);
    }

    /**
     * 배송 정보(택배사/송장번호)가 필수인 상태인지 확인합니다.
     *
     * @return bool
     */
    public function requiresShippingInfo(): bool
    {
        return in_array($this, self::shippingInfoRequiredStatuses());
    }

    /**
     * 배송 정보 필수 상태 목록을 반환합니다.
     *
     * @return array<self>
     */
    public static function shippingInfoRequiredStatuses(): array
    {
        return [self::SHIPPING_READY, self::SHIPPING];
    }

    /**
     * 배송 정보 필수 상태 값 배열을 반환합니다.
     *
     * @return array<string>
     */
    public static function shippingInfoRequiredValues(): array
    {
        return array_map(fn ($case) => $case->value, self::shippingInfoRequiredStatuses());
    }

    /**
     * 발송 이후 상태인지 확인합니다. (배송중, 배송완료, 구매확정)
     *
     * @return bool
     */
    public function isShipped(): bool
    {
        return in_array($this, self::shippedStatuses());
    }

    /**
     * 발송 이후 상태 목록을 반환합니다.
     *
     * @return array<self>
     */
    public static function shippedStatuses(): array
    {
        return [self::SHIPPING, self::DELIVERED, self::CONFIRMED];
    }

    /**
     * 완료 상태인지 확인합니다.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return in_array($this, [self::DELIVERED, self::CONFIRMED]);
    }
}
