<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 닫기 옵션 — 오늘 하루 보지 않기 / N일간 보지 않기 / 닫기만.
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
}
