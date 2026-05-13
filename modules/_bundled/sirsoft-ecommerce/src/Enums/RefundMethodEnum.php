<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 환불 수단 Enum
 */
enum RefundMethodEnum: string
{
    case PG = 'pg';           // PG사를 통한 환불 (카드 취소 등)
    case BANK = 'bank';       // 무통장 계좌 이체 환불
    case POINTS = 'points';   // 마일리지/포인트 환불

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.refund_method.'.$this->value);
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
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string
     */
    public function variant(): string
    {
        return match ($this) {
            self::PG => 'info',
            self::BANK => 'secondary',
            self::POINTS => 'warning',
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
