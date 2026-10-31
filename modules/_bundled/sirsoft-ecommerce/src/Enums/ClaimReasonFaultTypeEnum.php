<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 클레임 사유 귀책 유형 Enum
 */
enum ClaimReasonFaultTypeEnum: string
{
    case CUSTOMER = 'customer';  // 고객 귀책
    case SELLER = 'seller';      // 판매자 귀책
    case CARRIER = 'carrier';    // 배송사 귀책

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_reason_fault_type.'.$this->value);
    }

    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * 활동 로그는 라벨이 아니라 키를 저장해 표시 시점 로케일로 번역한다(라벨 박제 금지).
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.claim_reason_fault_type.'.$this->value;
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array 모든 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array 프론트엔드용 옵션 배열
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
