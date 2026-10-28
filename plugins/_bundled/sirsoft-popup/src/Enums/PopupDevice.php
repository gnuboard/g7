<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 노출 기기 — 전체 / PC / 모바일(폭 767px 이하).
 */
enum PopupDevice: string
{
    case All = 'all';
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
}
