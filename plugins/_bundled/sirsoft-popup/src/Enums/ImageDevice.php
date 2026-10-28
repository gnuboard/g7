<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 이미지 기기 — PC·모바일 각 1장(모바일이 없으면 PC 이미지를 쓴다).
 *
 * `label()` 은 두지 않는다 — API 는 값만 싣고 화면이 값으로 프런트 다국어 키(`sirsoft-popup.…`)를 고른다.
 */
enum ImageDevice: string
{
    case Pc = 'pc';
    case Mobile = 'mobile';

    /**
     * 값 목록을 반환합니다.
     *
     * @return array<int, string> 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인합니다.
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
