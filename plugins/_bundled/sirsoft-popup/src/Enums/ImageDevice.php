<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 이미지 기기 — PC·모바일 각 1장(모바일이 없으면 PC 이미지를 쓴다).
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
}
