<?php

namespace App\Seo\Concerns;

use App\Support\LocalizedValue;

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
     * - 다국어 array: 현재 로케일 → 사이트 기본 언어 → 영어 순서로 추출 (`LocalizedValue`)
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
            // 현재 로케일 → 사이트 기본 언어 → 영어 (#148)
            return LocalizedValue::pick($value, anyAsLast: false) ?? '';
        }

        return (string) ($value ?? '');
    }

    /**
     * array 가 다국어 형태(string 키)인지 판별.
     *
     * @param  array<mixed>  $value  판별할 배열
     * @return bool 문자열 키 다국어 배열이면 true
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
