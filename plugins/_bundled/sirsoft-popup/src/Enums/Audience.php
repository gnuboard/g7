<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 노출 대상 — 전체 / 비회원만 / 회원만.
 */
enum Audience: string
{
    case All = 'all';
    case Guest = 'guest';
    case Member = 'member';

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
