<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송비 부과정책 Enum
 */
enum ChargePolicyEnum: string
{
    case FREE = 'free';                           // 무료
    case FIXED = 'fixed';                         // 고정
    case CONDITIONAL_FREE = 'conditional_free';   // 조건부 무료
    case RANGE_AMOUNT = 'range_amount';           // 구간별(금액)
    case RANGE_QUANTITY = 'range_quantity';       // 구간별(수량)
    case RANGE_WEIGHT = 'range_weight';           // 구간별(무게)
    case RANGE_VOLUME = 'range_volume';           // 구간별(부피)
    case RANGE_VOLUME_WEIGHT = 'range_volume_weight'; // 구간별(부피+무게)
    case API = 'api';                             // 계산 API
    case PER_QUANTITY = 'per_quantity';             // 수량당
    case PER_WEIGHT = 'per_weight';                 // 무게당
    case PER_VOLUME = 'per_volume';                 // 부피당
    case PER_VOLUME_WEIGHT = 'per_volume_weight';   // 부피무게당
    case PER_AMOUNT = 'per_amount';                 // 금액당

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.charge_policy.'.$this->value);
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
     * 기본 배송비 필드가 필요한 정책인지 확인합니다.
     *
     * @return bool
     */
    public function requiresBaseFee(): bool
    {
        return in_array($this, [
            self::FIXED,
            self::CONDITIONAL_FREE,
            self::PER_QUANTITY,
            self::PER_WEIGHT,
            self::PER_VOLUME,
            self::PER_VOLUME_WEIGHT,
            self::PER_AMOUNT,
        ]);
    }

    /**
     * 무료 기준금액 필드가 필요한 정책인지 확인합니다.
     *
     * @return bool
     */
    public function requiresFreeThreshold(): bool
    {
        return $this === self::CONDITIONAL_FREE;
    }

    /**
     * 구간 설정이 필요한 정책인지 확인합니다.
     *
     * @return bool
     */
    public function requiresRanges(): bool
    {
        return in_array($this, [
            self::RANGE_AMOUNT,
            self::RANGE_QUANTITY,
            self::RANGE_WEIGHT,
            self::RANGE_VOLUME,
            self::RANGE_VOLUME_WEIGHT,
        ]);
    }

    /**
     * API 엔드포인트가 필요한 정책인지 확인합니다.
     *
     * @return bool
     */
    public function requiresApiEndpoint(): bool
    {
        return $this === self::API;
    }

    /**
     * 구간별 정책 목록을 반환합니다.
     *
     * @return array
     */
    public static function rangePolicies(): array
    {
        return [
            self::RANGE_AMOUNT,
            self::RANGE_QUANTITY,
            self::RANGE_WEIGHT,
            self::RANGE_VOLUME,
            self::RANGE_VOLUME_WEIGHT,
        ];
    }

    /**
     * 단위당 배송비 설정(unit_value)이 필요한 정책인지 확인합니다.
     *
     * @return bool
     */
    public function requiresUnitValue(): bool
    {
        return in_array($this, self::perUnitPolicies());
    }

    /**
     * 단위당 정책 목록을 반환합니다.
     *
     * @return array
     */
    public static function perUnitPolicies(): array
    {
        return [
            self::PER_QUANTITY,
            self::PER_WEIGHT,
            self::PER_VOLUME,
            self::PER_VOLUME_WEIGHT,
            self::PER_AMOUNT,
        ];
    }
}
