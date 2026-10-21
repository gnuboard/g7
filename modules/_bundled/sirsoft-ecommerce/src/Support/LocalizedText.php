<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Support\LocalizedValue;

/**
 * 다국어 값(`{"ko": "...", "en": "..."}`)에서 보여 줄 문자열 하나를 고른다.
 *
 * 순서는 요청 언어 → 사이트 기본 언어 → 영어 → 나머지 언어(저장 순)이고, **빈 칸(null·빈 문자열)은
 * 건너뛴다**. 「`$v[$locale] ?? $v[$fallback] ?? 첫 값`」 은 빈 칸을 건너뛰지 못한다 — 옵션 처리기는 지원
 * 언어마다 칸을 만들어 보내므로 영어로만 입력한 옵션은 `{"ko": null, "en": "Color"}` 로 저장되고, 한국어
 * 화면에서 세 단계가 모두 null 이 돼 이름이 사라졌다(S5-D39).
 */
final class LocalizedText
{
    /**
     * 다국어 값에서 비어 있지 않은 첫 문자열을 고릅니다.
     *
     * @param  mixed  $value  다국어 배열 또는 문자열
     * @param  string|null  $locale  요청 언어 (null 이면 현재 언어)
     * @return string 고른 문자열 (모두 비면 빈 문자열)
     */
    public static function pick(mixed $value, ?string $locale = null): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        // 요청 언어 → 사이트 기본 언어 → 영어 → 나머지 (빈 칸 건너뜀, 코어 LocalizedValue 위임 — #148)
        return LocalizedValue::pick($value, $locale) ?? '';
    }
}
