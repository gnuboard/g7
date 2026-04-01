<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 주문 검색 일자 타입 Enum
 */
enum OrderDateTypeEnum: string
{
    case ORDERED_AT = 'ordered_at';           // 주문일
    case PAID_AT = 'paid_at';                 // 결제일
    case CONFIRMED_AT = 'confirmed_at';       // 구매확정일
    case DELIVERED_AT = 'delivered_at';       // 배송완료일
    case CANCELLED_AT = 'cancelled_at';       // 취소일

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.order_date_type.'.$this->value);
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
