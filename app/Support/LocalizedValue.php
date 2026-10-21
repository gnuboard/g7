<?php

namespace App\Support;

/**
 * 다국어 값(`['ko' => '…', 'en' => '…']`)에서 보여 줄 글자를 고르는 단일 기준.
 *
 * 지금 보는 언어의 값이 없거나 비어 있으면 사이트 기본 언어(환경설정 「기본 언어」 =
 * `app.site_locale`) → 영어 순으로 대신한다. 종전에는 자리마다 `app.fallback_locale`(env 고정,
 * 번역 문구 대체용)이나 `'ko'` 를 직접 색인해, 한국어가 기본이 아닌 사이트에서도 빈 값이
 * 한국어로 채워졌다(#148 D7). 화면 쪽 짝은 `resources/js/core/support/localeTag.ts`
 * 의 `pickLocalized` 이며 두 규칙은 같아야 한다(SEO 봇 화면과 SPA 화면의 글자가 같도록).
 *
 * `app.locale` 은 요청마다 보는 사람의 언어로 바뀌므로 기준으로 쓰지 않는다.
 */
final class LocalizedValue
{
    /**
     * 값을 찾을 언어 순서를 돌려줍니다.
     *
     * @param  string|null  $locale  우선 언어 (null 이면 현재 요청 언어)
     * @return array<int, string> [우선 언어, 사이트 기본 언어, 'en'] — 중복·빈 값 제거
     */
    public static function fallbackChain(?string $locale = null): array
    {
        $chain = [
            $locale ?? app()->getLocale(),
            config('app.site_locale', config('app.locale')),
            'en',
        ];

        return array_values(array_unique(array_filter(
            $chain,
            static fn ($candidate) => is_string($candidate) && $candidate !== ''
        )));
    }

    /**
     * 다국어 값에서 보여 줄 글자를 고릅니다.
     *
     * 문자열은 그대로 돌려준다. 배열이면 `fallbackChain()` 순서로 비어 있지 않은 값(앞뒤 공백만
     * 있는 값도 빈 값)을 찾고, 모두 없으면 `$anyAsLast` 일 때 배열 순서의 첫 유효값을 쓴다.
     *
     * @param  mixed  $value  다국어 값
     * @param  string|null  $locale  우선 언어 (null 이면 현재 요청 언어)
     * @param  bool  $anyAsLast  체인에서 못 찾으면 아무 언어의 첫 유효값을 쓸지
     * @return string|null 고른 글자 (없으면 null)
     */
    public static function pick(mixed $value, ?string $locale = null, bool $anyAsLast = true): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (self::fallbackChain($locale) as $candidateLocale) {
            if (array_key_exists($candidateLocale, $value) && self::usable($value[$candidateLocale])) {
                return (string) $value[$candidateLocale];
            }
        }

        if ($anyAsLast) {
            foreach ($value as $candidate) {
                if (self::usable($candidate)) {
                    return (string) $candidate;
                }
            }
        }

        return null;
    }

    /**
     * 보여 줄 수 있는 값인지 판정합니다.
     *
     * @param  mixed  $candidate  후보 값
     * @return bool 비어 있지 않은 문자열이거나 숫자면 true
     */
    private static function usable(mixed $candidate): bool
    {
        if (is_int($candidate) || is_float($candidate)) {
            return true;
        }

        return is_string($candidate) && trim($candidate) !== '';
    }
}
