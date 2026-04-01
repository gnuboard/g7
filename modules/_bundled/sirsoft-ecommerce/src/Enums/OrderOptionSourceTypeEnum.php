<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 주문 옵션 생성 원인 Enum
 */
enum OrderOptionSourceTypeEnum: string
{
    case ORDER = 'order';           // 최초 주문
    case EXCHANGE = 'exchange';     // 교환
    case SPLIT = 'split';           // 수량 분할

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.order_option_source_type.'.$this->value);
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
