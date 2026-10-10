<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * 국가 코드를 화면 언어의 국가 이름으로 바꾸는 단일 해석기.
 *
 * 코어가 이름을 들고 있는 로케일(ko·en)은 `config/countries.php` 의 `localized_names` 가 정본이다.
 * 그 밖의 로케일은 **언어팩이 채운다** — 언어팩의 백엔드 번역 파일 `backend/{locale}/countries.php`
 * (키 = ISO alpha-2 국가 코드, 값 = 그 언어의 국가 이름)가 `countries.{코드}` 번역 키로 올라온다.
 * 둘 다 없으면 기본 폴백 로케일의 설정 이름, 그마저 없으면 코드를 돌려준다.
 *
 * 회원 응답(`country_name`)과 방문자 통계 지역 라벨이 같은 해석을 거쳐야 한 화면 안에서 같은 나라가
 * 서로 다른 이름으로 보이지 않는다.
 */
class CountryName
{
    /**
     * 국가 코드를 지정한 로케일의 국가 이름으로 바꿉니다.
     *
     * @param  string  $code  ISO alpha-2 국가 코드 (대소문자 무관)
     * @param  string|null  $locale  표시 로케일 (기본: 현재 앱 로케일)
     * @return string 국가 이름 (어디에도 없으면 대문자 코드)
     */
    public static function resolve(string $code, ?string $locale = null): string
    {
        $code = strtoupper(trim($code));
        $locale ??= app()->getLocale();

        return self::resolveOrNull($code, $locale)
            ?? self::configured(config('app.fallback_locale', 'en'), $code)
            ?? $code;
    }

    /**
     * 지정한 로케일에서만 국가 이름을 찾습니다 (폴백 로케일로 내려가지 않음).
     *
     * 호출부가 다른 폴백(예: intl 표기)을 끼워 넣을 때 씁니다.
     *
     * @param  string  $code  ISO alpha-2 국가 코드 (대소문자 무관)
     * @param  string  $locale  표시 로케일
     * @return string|null 국가 이름 (그 로케일에 없으면 null)
     */
    public static function resolveOrNull(string $code, string $locale): ?string
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        return self::configured($locale, $code) ?? self::fromLanguagePack($locale, $code);
    }

    /**
     * 코어 설정에 적힌 국가 이름을 읽습니다.
     *
     * @param  string  $locale  로케일
     * @param  string  $code  대문자 국가 코드
     * @return string|null 국가 이름 (없으면 null)
     */
    private static function configured(string $locale, string $code): ?string
    {
        $name = config('countries.localized_names.'.$locale.'.'.$code);

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * 언어팩이 올린 번역 키 `countries.{코드}` 를 그 로케일에서만 읽습니다.
     *
     * 폴백 로케일로 내려가지 않게 `Lang::has(..., false)` 로 확인한다 — 내려가면 ko 이름이
     * 「번역이 있는 것」 으로 잡혀 설정 폴백보다 먼저 나간다.
     *
     * @param  string  $locale  로케일
     * @param  string  $code  대문자 국가 코드
     * @return string|null 국가 이름 (없으면 null)
     */
    private static function fromLanguagePack(string $locale, string $code): ?string
    {
        $key = 'countries.'.$code;

        if (! Lang::has($key, $locale, false)) {
            return null;
        }

        $name = Lang::get($key, [], $locale, false);

        return is_string($name) && $name !== '' && $name !== $key ? $name : null;
    }
}
