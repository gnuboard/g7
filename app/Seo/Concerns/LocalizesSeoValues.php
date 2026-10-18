<?php

namespace App\Seo\Concerns;

/**
 * 다국어 JSON array / scalar 를 현재 로케일 string 으로 정규화.
 *
 * MariaDB 환경에서 다국어 JSON 컬럼이 array 로 전달될 때 (string) 캐스팅 시
 * "Array to string conversion" 회귀를 차단하는 SSoT.
 */
trait LocalizesSeoValues
{
    /**
     * 다국어 array 또는 scalar 를 현재 로케일 string 으로 변환.
     *
     * - string: 그대로 반환
     * - 다국어 array: 현재 로케일 → fallback_locale 순서로 추출
     * - 그 외: (string) 캐스팅
     *
     * @param  mixed  $value  대상 값 (string / 다국어 array / scalar)
     * @return string 현재 로케일 string
     */
    public function resolveLocalizedValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $locale = app()->getLocale();
            if (isset($value[$locale])) {
                return (string) $value[$locale];
            }
            $fallbackLocale = config('app.fallback_locale', 'en');
            if (isset($value[$fallbackLocale])) {
                return (string) $value[$fallbackLocale];
            }

            return '';
        }

        return (string) ($value ?? '');
    }

    /**
     * array 가 다국어 형태(string 키)인지 판별.
     */
    protected function isLocalizedArray(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (is_string($key)) {
                return true;
            }
        }

        return false;
    }
}
