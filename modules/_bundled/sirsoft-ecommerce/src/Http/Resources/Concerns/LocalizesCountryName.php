<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources\Concerns;

use Modules\Sirsoft\Ecommerce\Support\CountryNames;

/**
 * 국가 코드 → 다국어 국가명 변환 트레이트 (MP08 후속)
 *
 * 주문 목록/상세/주소 리소스가 공유한다(OrderListResource 에서 추출). config('countries.localized_names')
 * 의 로케일별 국가명 매핑을 쓰고, 그 사전에 없는 언어는 상점 배송국가 설정 이름으로 채운다(CountryNames).
 */
trait LocalizesCountryName
{
    /**
     * 국가 코드를 다국어 국가명 객체로 변환합니다.
     *
     * @param  string|null  $countryCode  ISO alpha-2 국가 코드
     * @return array<string, string>|null 다국어 객체 (예: {ko: '한국', en: 'South Korea'}), 미상이면 null
     */
    protected function getCountryLocalizedName(?string $countryCode): ?array
    {
        if (! $countryCode) {
            return null;
        }

        // 코어 사전에 없는 언어(일본어 등)는 상점 배송국가 설정 이름으로 채운다 (S4-D17)
        $result = CountryNames::forCode($countryCode);

        return ! empty($result) ? $result : null;
    }
}
