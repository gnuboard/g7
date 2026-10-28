<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * PC 레이어 위치 — 화면 중앙 / 좌상단 기준 좌표.
 */
enum PcPosition: string
{
    case Center = 'center';
    case Coords = 'coords';

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
