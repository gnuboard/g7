<?php

namespace App\Analytics;

use Locale;

/**
 * 언어 라벨 번역
 *
 * 브라우저 언어(`ko-KR`)·사이트 언어(`ko`)는 코드로 저장하고 화면에만 보는 사람의
 * 언어로 이름을 붙입니다(`GeoLabelTranslator` 와 같은 원칙). 이름을 못 찾으면 코드를
 * 그대로 보여 줍니다.
 */
class LanguageLabelTranslator
{
    /**
     * 언어 코드를 사람이 읽는 이름으로 바꿉니다.
     *
     * @param  string|null  $code  언어 태그 (BCP 47, 예: `ko-KR`)
     * @param  string|null  $locale  표시 로케일 (기본: 현재 앱 로케일)
     * @return string|null 언어 이름 (값이 없으면 null, 번역이 없으면 코드)
     */
    public static function language(?string $code, ?string $locale = null): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $code = trim($code);

        if (! class_exists(Locale::class) || preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $code) !== 1) {
            return $code;
        }

        $locale ??= app()->getLocale();
        $primary = strtolower((string) preg_split('/[-_]/', $code)[0]);

        // intl 은 모르는 언어를 코드 그대로 돌려준다 — 그때는 이름이 없는 것이다.
        $language = Locale::getDisplayLanguage($primary, $locale);

        if (! is_string($language) || $language === '' || strtolower($language) === $primary) {
            return $code;
        }

        $display = Locale::getDisplayName($code, $locale);

        return is_string($display) && $display !== '' ? $display : $language;
    }
}
