<?php

namespace App\Analytics;

/**
 * 저장 코드 라벨 번역
 *
 * 원장·응답은 코드(`desktop`·`search`·`analytics_visits`)를 그대로 싣고, 화면에만 언어 파일의
 * 이름을 덧붙입니다(`GeoLabelTranslator`·`LanguageLabelTranslator` 와 같은 원칙). 번역이 없는 값은
 * 확장이 더한 값일 수 있어 코드를 그대로 보여 줍니다.
 */
class CodeLabelTranslator
{
    /**
     * 저장 코드의 화면 이름을 찾습니다.
     *
     * @param  string  $group  언어 파일 묶음 (`analytics.device_type` 등)
     * @param  mixed  $value  저장 값 (문자열 또는 문자열 Enum)
     * @return string|null 화면 이름 (값이 없으면 null, 번역이 없으면 원값)
     */
    public static function label(string $group, mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = (string) $value->value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $key = $group.'.'.$value;
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : $value;
    }
}
