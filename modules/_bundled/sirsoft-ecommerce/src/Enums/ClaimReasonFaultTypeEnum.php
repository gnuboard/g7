<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 클래임 사유 귀책 유형 Enum
 */
enum ClaimReasonFaultTypeEnum: string
{
    case CUSTOMER = 'customer';  // 고객 귀책
    case SELLER = 'seller';      // 판매자 귀책
    case CARRIER = 'carrier';    // 배송사 귀책

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_reason_fault_type.'.$this->value);
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
