<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 쿠폰 적용 대상 Enum
 */
enum CouponTargetType: string
{
    case PRODUCT_AMOUNT = 'product_amount';   // 상품금액
    case ORDER_AMOUNT = 'order_amount';       // 주문금액
    case SHIPPING_FEE = 'shipping_fee';       // 배송비

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 번역 키를 반환합니다.
     *
     * @return string
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.coupon_target_type.'.$this->value;
    }

    /**
     * 짧은 다국어 라벨을 반환합니다.
     *
     * 쿠폰 드롭다운에서 "[상품] 쿠폰명" 형식으로 표시할 때 사용합니다.
     *
     * @return string
     */
    public function shortLabel(): string
    {
        return __('sirsoft-ecommerce::enums.coupon_target_type_short.'.$this->value);
    }

    /**
     * 배지 색상을 반환합니다.
     *
     * @return string
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::PRODUCT_AMOUNT => 'teal',
            self::ORDER_AMOUNT => 'blue',
            self::SHIPPING_FEE => 'orange',
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
}
