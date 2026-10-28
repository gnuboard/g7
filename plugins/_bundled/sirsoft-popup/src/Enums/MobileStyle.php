<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 모바일 레이어 형태 — 화면 중앙 / 하단 시트형.
 */
enum MobileStyle: string
{
    case Center = 'center';
    case Sheet = 'sheet';

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
