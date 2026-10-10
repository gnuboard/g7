<?php

namespace App\Analytics;

/**
 * 확장 선언의 로케일별 문구 처리
 *
 * 선언은 문자열 하나 또는 `['ko' => …, 'en' => …]` 맵으로 이름을 줍니다. 두 형태를
 * 맵 하나로 맞추고, 화면에 낼 때 현재 로케일을 고릅니다.
 */
final class LocalizedText
{
    /**
     * 선언 값을 로케일 맵으로 맞춥니다.
     *
     * @param  mixed  $value  선언 값 (문자열 또는 로케일 맵)
     * @return array<string, string> 로케일 → 문구 (형식이 아니면 빈 배열)
     */
    public static function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? [] : ['default' => $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $map[$locale] = trim($text);
            }
        }

        return $map;
    }

    /**
     * 현재 로케일의 문구를 고릅니다.
     *
     * @param  array<string, string>  $map  로케일 맵
     * @param  string  $fallback  맵이 비었을 때 돌려줄 값
     * @param  string|null  $locale  고를 로케일 (없으면 현재 앱 로케일)
     * @return string 문구 (지정 로케일 → en → 첫 값 → fallback)
     */
    public static function pick(array $map, string $fallback, ?string $locale = null): string
    {
        if ($map === []) {
            return $fallback;
        }

        $locale ??= app()->getLocale();

        return $map[$locale] ?? $map['en'] ?? reset($map);
    }
}
