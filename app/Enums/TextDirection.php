<?php

namespace App\Enums;

/**
 * 텍스트 방향 Enum.
 *
 * 언어팩 manifest 의 `text_direction` 필드와 DB `language_packs.text_direction` 컬럼이
 * 사용합니다. RTL 언어(아랍어, 히브리어 등) 지원을 위한 값.
 */
enum TextDirection: string
{
    /**
     * 좌→우 (대부분의 언어).
     */
    case Ltr = 'ltr';

    /**
     * 우→좌 (아랍어, 히브리어 등).
     */
    case Rtl = 'rtl';

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 라벨 (lang/{locale}/language_packs.php 의 text_direction 키)
     */
    public function label(): string
    {
        return __('language_packs.text_direction.'.$this->value);
    }

    /**
     * 모든 방향 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 방향 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 주어진 문자열이 유효한 방향 값인지 확인합니다.
     *
     * @param  string  $value  검사할 방향 문자열
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
