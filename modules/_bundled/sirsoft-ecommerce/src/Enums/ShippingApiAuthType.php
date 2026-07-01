<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송정책 계산 API 인증 방식
 *
 * 외부 배송비 계산 API 호출 시 인증 헤더 부착 방식의 SSoT 입니다.
 * 검증(Rule::in)·계산 로직·UI 옵션이 모두 이 enum 을 참조합니다.
 */
enum ShippingApiAuthType: string
{
    /** 인증 없음 */
    case NONE = 'none';

    /** Authorization: Bearer {token} */
    case BEARER = 'bearer';

    /** 커스텀 헤더명 + 값 (API-Key 등) */
    case CUSTOM_HEADER = 'custom_header';

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
        return __('sirsoft-ecommerce::enums.shipping_api_auth_type.'.$this->value);
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
