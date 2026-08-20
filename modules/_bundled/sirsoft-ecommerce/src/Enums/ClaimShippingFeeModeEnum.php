<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 클레임 배송비 산정 모드 Enum
 *
 * 배송정책 국가별 설정(`ecommerce_shipping_policy_country_settings.claim_fee_mode`)이
 * 선언하고, 신청 시점에 클레임 헤더(`shipping_fee_mode`)로 동결된다.
 *
 * - FIXED: 정책에 지정된 고정액(반품 편도 `return_fixed_fee` / 교환 왕복 `exchange_fixed_fee`)
 * - ORIGINAL_POLICY: 원 주문의 배송비 정책을 스냅샷 기준으로 재계산
 */
enum ClaimShippingFeeModeEnum: string
{
    case FIXED = 'fixed';                        // 고정액
    case ORIGINAL_POLICY = 'original_policy';    // 원 배송정책 재계산

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.claim_shipping_fee_mode.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<string> 모드 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<array{value: string, label: string}> 셀렉트 옵션 배열
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
