<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 노출 기간 방식 — 상시 / 기간 지정(#810 과 같은 낱말).
 */
enum DisplayPeriod: string
{
    case Always = 'always';
    case Range = 'range';

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
