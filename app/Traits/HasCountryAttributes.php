<?php

namespace App\Traits;

/**
 * 국가 관련 속성 변환 기능을 제공하는 트레이트.
 *
 * ISO alpha-2 국가 코드를 국기 이모지와 로컬라이즈된 국가명으로 변환합니다.
 * 국가명 데이터는 config/countries.php 설정 파일에서 관리됩니다.
 *
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
     * config/countries.php의 localized_names에서 국가명을 조회합니다.
     * 현재 로케일에 국가명이 없으면 폴백 로케일을 사용하고,
     * 그마저도 없으면 원본 국가 코드를 반환합니다.
     *
     * @param  string  $countryCode  ISO alpha-2 국가 코드
     * @return string 현재 로케일에 맞는 국가명
     */
    protected function getCountryName(string $countryCode): string
    {
        $countryCode = strtoupper($countryCode);
        $locale = app()->getLocale();
        $fallbackLocale = config('app.fallback_locale', 'en');

        $countryNames = config('countries.localized_names', []);

        return $countryNames[$locale][$countryCode]
            ?? $countryNames[$fallbackLocale][$countryCode]
            ?? $countryCode;
    }
}
