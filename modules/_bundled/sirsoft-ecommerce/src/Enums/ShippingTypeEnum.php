<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송 유형 Enum
 */
enum ShippingTypeEnum: string
{
    case DOMESTIC_PARCEL = 'domestic_parcel';           // 국내택배
    case DOMESTIC_EXPRESS = 'domestic_express';         // 국내특급
    case DOMESTIC_QUICK = 'domestic_quick';             // 퀵서비스
    case INTERNATIONAL_EMS = 'international_ems';       // 국제EMS
    case INTERNATIONAL_STANDARD = 'international_standard'; // 국제일반
    case PICKUP = 'pickup';                             // 방문수령
    case CVS = 'cvs';                                   // 편의점택배
    case DIGITAL = 'digital';                           // 디지털상품

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.shipping_type.'.$this->value);
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

    /**
     * 국내 배송 유형 목록을 반환합니다.
     *
     * @return array
     */
    public static function domesticTypes(): array
    {
        return [
            self::DOMESTIC_PARCEL,
            self::DOMESTIC_EXPRESS,
            self::DOMESTIC_QUICK,
        ];
    }

    /**
     * 국제 배송 유형 목록을 반환합니다.
     *
     * @return array
     */
    public static function internationalTypes(): array
    {
        return [
            self::INTERNATIONAL_EMS,
            self::INTERNATIONAL_STANDARD,
        ];
    }
}
