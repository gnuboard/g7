<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 상품 판매 상태 Enum
 */
enum ProductSalesStatus: string
{
    case ON_SALE = 'on_sale';           // 판매중
    case SUSPENDED = 'suspended';       // 판매중지
    case SOLD_OUT = 'sold_out';         // 품절
    case COMING_SOON = 'coming_soon';   // 출시예정

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
        return 'sirsoft-ecommerce::enums.sales_status.'.$this->value;
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string
     */
    public function variant(): string
    {
        return match ($this) {
            self::ON_SALE => 'success',
            self::SUSPENDED => 'warning',
            self::SOLD_OUT => 'danger',
            self::COMING_SOON => 'info',
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
