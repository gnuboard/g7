<?php

namespace App\Analytics;

use App\Support\CountryName;
use Locale;

/**
 * 지역 라벨 번역
 *
 * 저장은 코드(`KR`)로 하고 화면에만 보는 사람의 언어로 이름을 붙입니다. 이름을
 * 저장하면 로케일마다 다른 값이 쌓여 같은 나라가 여러 줄로 갈립니다.
 *
 * 도시·지역 이름은 GeoIP 사전이 주는 언어만 있습니다 — 사전에 그 언어가 없으면
 * 영문으로 되돌리고, 그것도 없으면 코드를 그대로 보여 줍니다.
 */
class GeoLabelTranslator
{
    /**
     * 국가 코드를 사람이 읽는 이름으로 바꿉니다.
     *
     * @param  string|null  $code  국가 코드 (ISO 3166-1 alpha-2)
     * @param  string|null  $locale  표시 로케일 (기본: 현재 앱 로케일)
     * @return string 국가 이름 (번역이 없으면 코드)
     */
    public static function country(?string $code, ?string $locale = null): string
    {
        if ($code === null || trim($code) === '') {
            return '';
        }

        $code = strtoupper(trim($code));
        $locale ??= app()->getLocale();

        // 코어 설정(ko·en) → 언어팩 번역(`countries.{코드}`) 순 — 회원 화면의 국가 이름과 같은 해석이다.
        $named = CountryName::resolveOrNull($code, $locale);

        if ($named !== null) {
            return $named;
        }

        if (class_exists(Locale::class)) {
            $display = Locale::getDisplayRegion('-'.$code, $locale);

            // intl 은 못 읽으면 코드를 그대로 돌려준다 — 그 경우는 번역이 없는 것이다.
            if (is_string($display) && $display !== '' && $display !== $code) {
                return $display;
            }
        }

        return $code;
    }

    /**
     * 지역 라벨(`KR/11`)을 사람이 읽는 이름으로 바꿉니다.
     *
     * @param  string  $label  지역 라벨 (국가코드/지역코드)
     * @param  string|null  $locale  표시 로케일
     * @return string 표시 이름
     */
    public static function region(string $label, ?string $locale = null): string
    {
        [$countryCode, $regionCode] = self::splitLabel($label);

        $country = self::country($countryCode, $locale);

        if ($regionCode === null || $regionCode === '') {
            return $country;
        }

        return $country.' / '.$regionCode;
    }

    /**
     * 도시 라벨(`KR/Seoul`)을 사람이 읽는 이름으로 바꿉니다.
     *
     * @param  string  $label  도시 라벨 (국가코드/도시명)
     * @param  string|null  $locale  표시 로케일
     * @param  array<string, string>  $cityNames  GeoIP 사전의 로케일별 도시명
     * @return string 표시 이름
     */
    public static function city(string $label, ?string $locale = null, array $cityNames = []): string
    {
        [$countryCode, $cityName] = self::splitLabel($label);

        $country = self::country($countryCode, $locale);

        if ($cityName === null || $cityName === '') {
            return $country;
        }

        $locale ??= app()->getLocale();
        $translated = $cityNames[$locale] ?? $cityNames['en'] ?? $cityName;

        return $country.' / '.$translated;
    }

    /**
     * `국가코드/나머지` 라벨을 둘로 나눕니다.
     *
     * @param  string  $label  라벨
     * @return array{0: string|null, 1: string|null} 국가 코드와 나머지
     */
    private static function splitLabel(string $label): array
    {
        $label = trim($label);

        if ($label === '') {
            return [null, null];
        }

        $pieces = explode('/', $label, 2);

        return [$pieces[0], $pieces[1] ?? null];
    }
}
