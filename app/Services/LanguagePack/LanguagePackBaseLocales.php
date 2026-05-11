<?php

namespace App\Services\LanguagePack;

/**
 * 코어/번들 확장 base locale 판정 헬퍼.
 *
 * G7 코어가 직접 지원하는 base locale 집합을 정의합니다. base locale 의 lang/ 디렉토리는
 * 가상 보호 행으로 자동 합성되며 사용자가 install/uninstall/activate/deactivate 할 수 없습니다.
 *
 * 미래에 새 base locale 추가 시 본 헬퍼의 BASE_LOCALES 만 수정하면 됩니다.
 */
class LanguagePackBaseLocales
{
    /**
     * 코어 base locale 목록.
     *
     * @var array<int, string>
     */
    private const BASE_LOCALES = ['ko', 'en'];

    /**
     * 주어진 locale 이 base locale 인지 판정합니다.
     *
     * @param  string  $locale  locale 코드 (예: 'ko', 'en', 'ja')
     * @return bool base locale 이면 true
     */
    public static function isBaseLocale(string $locale): bool
    {
        return in_array($locale, self::BASE_LOCALES, true);
    }

    /**
     * 모든 base locale 목록을 반환합니다.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return self::BASE_LOCALES;
    }
}
