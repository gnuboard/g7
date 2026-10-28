<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 닫기 옵션 — 오늘 하루 보지 않기 / N일간 보지 않기 / 닫기만.
 *
 * `label()` 은 두지 않는다 — API 는 값만 싣고 화면이 값으로 프런트 다국어 키(`sirsoft-popup.…`)를 고른다.
 */
enum DismissMode: string
{
    case Today = 'today';
    case Days = 'days';
    case Close = 'close';

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
