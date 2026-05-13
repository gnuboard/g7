<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 클래임 사유 유형 Enum
 */
enum ClaimReasonTypeEnum: string
{
    case REFUND = 'refund';      // 환불/취소
    // 추후 확장: case EXCHANGE = 'exchange'; case RETURN = 'return';

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_reason_type.'.$this->value);
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
