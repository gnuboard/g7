<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 상품 가격 타입 Enum
 */
enum ProductPriceType: string
{
    case SELLING_PRICE = 'selling_price'; // 판매가
    case SUPPLY_PRICE = 'supply_price';   // 공급가
    case LIST_PRICE = 'list_price';       // 정가

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.price_type.'.$this->value);
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
