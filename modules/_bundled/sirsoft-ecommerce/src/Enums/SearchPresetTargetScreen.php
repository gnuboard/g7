<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 검색 프리셋 대상 화면 Enum
 */
enum SearchPresetTargetScreen: string
{
    case PRODUCTS = 'products';     // 상품 목록
    case ORDERS = 'orders';         // 주문 목록
    case CUSTOMERS = 'customers';   // 고객 목록

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.target_screen.'.$this->value);
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
