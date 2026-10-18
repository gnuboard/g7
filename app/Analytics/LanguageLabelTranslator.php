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

        if (! is_string($display) || $display === '') {
            return $language;
        }

        // 국가 부분은 국가 칸과 같은 이름을 쓴다. 국가 이름의 정본은 `config/countries.php`
        // (`GeoLabelTranslator`)인데 intl 사전은 다른 이름을 줄 수 있어(ko: 「대한민국」 ↔ 정본 「한국」),
        // 방문자 상세 한 화면에 같은 나라가 두 이름으로 섰다(7.1.0 실측 O-E3). intl 이 만든 문장
        // 틀(괄호·띄어쓰기)은 그대로 두고 국가 이름만 바꾼다.
        $region = Locale::getRegion(str_replace('-', '_', $code));

        if (is_string($region) && preg_match('/^[A-Za-z]{2}$/', $region) === 1) {
            $intlName = Locale::getDisplayRegion('-'.$region, $locale);
            $canonical = GeoLabelTranslator::country($region, $locale);

            if (is_string($intlName) && $intlName !== '' && $canonical !== '' && $intlName !== $canonical) {
                $display = str_replace($intlName, $canonical, $display);
            }
        }

        return $display;
    }
}
