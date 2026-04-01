<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 쿠폰 적용 범위 Enum
 */
enum CouponTargetScope: string
{
    case ALL = 'all';               // 전체 상품
    case PRODUCTS = 'products';     // 특정 상품
    case CATEGORIES = 'categories'; // 특정 카테고리

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.coupon_target_scope.'.$this->value);
    }

    /**
     * 배지 색상을 반환합니다.
     *
     * @return string
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::ALL => 'gray',
            self::PRODUCTS => 'blue',
            self::CATEGORIES => 'teal',
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
