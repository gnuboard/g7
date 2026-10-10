<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

/**
 * 다국어 이름의 필수 언어(사이트 기본 언어) 해석 Trait
 *
 * 코어 `LocaleRequiredTranslatable` 은 요청 언어가 아니라 사이트 기본 언어(`app.site_locale`)를 필수로 본다.
 * 규칙 밖에서 이름 필수를 따로 판정하는 자리(은행·택배사·배송유형·클레임 사유·직접 배송명)도 같은 언어를
 * 써야 한다 — 요청 언어를 쓰면 다른 언어로 보는 관리자에게 「사이트 언어 + 보는 언어」 둘 다 필수가 된다.
 * `app.site_locale` 이 없는 코어에서는 `app.locale` 로 내려간다.
 */
trait ResolvesSiteLocale
{
    /**
     * 다국어 이름에서 반드시 채워야 하는 언어를 반환합니다.
     *
     * 코어 규칙과 같이, 사이트 기본 언어가 다국어 허용 목록(`app.translatable_locales`)에 없으면 첫 언어를 쓴다.
     *
     * @return string 언어 코드
     */
    protected function requiredNameLocale(): string
    {
        $allowed = config('app.translatable_locales', ['ko', 'en']);
        $locale = config('app.site_locale', config('app.locale'));

        return in_array($locale, $allowed, true) ? $locale : ($allowed[0] ?? 'ko');
    }
}
