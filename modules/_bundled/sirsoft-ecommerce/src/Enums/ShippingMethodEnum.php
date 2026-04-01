<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송방법 Enum
 * 배송정책에서 사용 (ShippingTypeEnum과 별개)
 */
enum ShippingMethodEnum: string
{
    case PARCEL = 'parcel';       // 택배
    case FREIGHT = 'freight';     // 화물
    case EXPRESS = 'express';     // 특급
    case ECONOMY = 'economy';     // 이코노미
    case EMS = 'ems';             // EMS
    case COLLECT = 'collect';     // 착불
    case QUICK = 'quick';         // 퀵서비스
    case DIRECT = 'direct';       // 직접배송
    case PICKUP = 'pickup';       // 방문수령
    case OTHER = 'other';         // 기타

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.shipping_method.'.$this->value);
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
