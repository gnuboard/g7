<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송정책 계산 API 응답 형식
 *
 * 외부 배송비 계산 API 의 응답에서 배송비 값을 추출하는 방식의 SSoT 입니다.
 * 검증(Rule::in)·계산 로직·UI 옵션이 모두 이 enum 을 참조합니다.
 */
enum ShippingApiResponseType: string
{
    /** JSON 응답 — 점표기 경로(response_path)로 중첩 값 추출 */
    case JSON = 'json';

    /** 텍스트 응답 — 본문에서 숫자/소수점만 추출 */
    case TEXT = 'text';

    /**
     * 전체 값(문자열) 목록을 반환합니다.
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
        return __('sirsoft-ecommerce::enums.shipping_api_response_type.'.$this->value);
    }

    /**
     * UI 후보 선택용 {value, label} 목록을 반환합니다.
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
