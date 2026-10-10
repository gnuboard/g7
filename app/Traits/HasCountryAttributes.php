<?php

namespace App\Traits;

use App\Support\CountryName;

/**
 * 국가 관련 속성 변환 기능을 제공하는 트레이트.
 *
 * ISO alpha-2 국가 코드를 국기 이모지와 로컬라이즈된 국가명으로 변환합니다.
 * 국가명 데이터는 config/countries.php(코어 내장 ko·en)와 언어팩의 `countries.php` 번역(그 밖의 로케일)에서 옵니다.
 */
trait HasCountryAttributes
{
    /**
     * ISO alpha-2 국가 코드를 국기 이모지로 변환합니다.
     *
     * Regional Indicator Symbol을 사용하여 국가 코드를 이모지로 변환합니다.
     * 예: KR → 🇰🇷, US → 🇺🇸, JP → 🇯🇵
     *
     * @param  string  $countryCode  ISO alpha-2 국가 코드
     * @return string 국기 이모지 또는 기본 깃발 이모지
     */
    protected function getCountryFlag(string $countryCode): string
    {
        $countryCode = strtoupper($countryCode);

        // ISO alpha-2 코드를 Regional Indicator Symbol로 변환
        // A = 0x1F1E6, B = 0x1F1E7, ..., Z = 0x1F1FF
        $flag = '';
        for ($i = 0; $i < strlen($countryCode); $i++) {
            $char = ord($countryCode[$i]);
            if ($char >= 65 && $char <= 90) { // A-Z
                $flag .= mb_chr(0x1F1E6 + ($char - 65));
            }
        }

        return $flag ?: '🏳️';
    }

    /**
     * ISO alpha-2 국가 코드를 현재 로케일에 맞는 국가명으로 변환합니다.
     *
     * 코어 내장 로케일(ko·en)은 config/countries.php 의 localized_names, 그 밖의 로케일은 언어팩이
     * 올린 번역 키 `countries.{코드}` 를 읽고, 둘 다 없으면 폴백 로케일 이름 → 원본 코드 순으로
     * 내려갑니다(`CountryName::resolve`).
     *
     * @param  string  $countryCode  ISO alpha-2 국가 코드
     * @return string 현재 로케일에 맞는 국가명
     */
    protected function getCountryName(string $countryCode): string
    {
        return CountryName::resolve($countryCode);
    }
}
