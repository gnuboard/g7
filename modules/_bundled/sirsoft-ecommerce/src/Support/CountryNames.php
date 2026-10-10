<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 배송 국가 이름의 언어별 사전
 *
 * 코어 국가 사전(config countries.localized_names)은 ko·en 만 담는다. 일본어 같은 다른 언어 화면에서는 이름을
 * 찾지 못해 한국어로 떨어졌다. 상점 배송국가 설정(shipping.available_countries)은 운영자가 언어별 이름을
 * 관리하고 헤더 배송국가 선택도 그 이름을 쓰므로, 사전에 없는 언어는 그 이름으로 채운다.
 * 사전에 있는 언어는 종전 이름을 그대로 둔다(주문·주소·알림 화면의 기존 표기 유지).
 */
class CountryNames
{
    /**
     * 국가 코드의 언어별 이름을 돌려줍니다.
     *
     * @param  string|null  $countryCode  ISO alpha-2 국가 코드(대소문자 무관)
     * @return array<string, string> 언어 => 이름 (모르는 국가면 빈 배열)
     */
    public static function forCode(?string $countryCode): array
    {
        if (! $countryCode) {
            return [];
        }

        $code = strtoupper($countryCode);
        $names = [];

        foreach (config('countries.localized_names', []) as $locale => $dictionary) {
            if (isset($dictionary[$code])) {
                $names[$locale] = $dictionary[$code];
            }
        }

        foreach (self::shopCountryNames($code) as $locale => $name) {
            if (! isset($names[$locale]) && is_string($name) && $name !== '') {
                $names[$locale] = $name;
            }
        }

        return $names;
    }

    /**
     * 상점 배송국가 설정에서 그 국가의 언어별 이름을 찾습니다(배송 가능 여부와 무관).
     *
     * @param  string  $code  대문자 국가 코드
     * @return array<string, mixed> 언어 => 이름
     */
    private static function shopCountryNames(string $code): array
    {
        $countries = module_setting('sirsoft-ecommerce', 'shipping.available_countries', []);

        if (! is_array($countries)) {
            return [];
        }

        foreach ($countries as $country) {
            if (is_array($country) && strtoupper((string) ($country['code'] ?? '')) === $code && is_array($country['name'] ?? null)) {
                return $country['name'];
            }
        }

        return [];
    }
}
