<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송정책 계산 API 요청 참고 필드
 *
 * 계산 API 정책에서 외부 API 로 전송 가능한 후보 필드의 단일 SSoT 입니다.
 * OrderCalculationService::calculateApiShippingFee() 의 $requestData 구성과 1:1 일치하며,
 * 검증(StoreShippingPolicyRequest Rule::in)·UI 후보 목록이 모두 이 enum 을 참조합니다.
 */
enum ShippingApiRequestField: string
{
    /** 배송정책 ID */
    case POLICY_ID = 'policy_id';

    /** 국가 코드 */
    case COUNTRY_CODE = 'country_code';

    /** 주문 항목 목록 (옵션/수량/금액/무게/부피) */
    case ITEMS = 'items';

    /** 그룹 합계 금액 */
    case GROUP_TOTAL = 'group_total';

    /** 그룹 총 수량 */
    case TOTAL_QUANTITY = 'total_quantity';

    /**
     * 전체 후보 필드 값(문자열) 목록을 반환합니다.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * 번역된 표시 라벨을 반환합니다.
     *
     * @return string 현재 로케일 기준 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.shipping_api_request_field.'.$this->value);
    }

    /**
     * UI 후보 선택용 {value, label} 목록을 반환합니다.
     *
     * 라벨은 백엔드 enum 다국어 SSoT(`enums.shipping_api_request_field.*`)에서
     * 번역되어 내려가므로, 프론트는 별도 번역 키 참조 없이 그대로 렌더합니다.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
