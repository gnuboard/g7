<?php

namespace Plugins\Sirsoft\Popup\Enums;

/**
 * 노출 페이지 범위 — 전체 페이지 / 라우트 지정(경로 경계 하위 포함, `/` 는 홈만).
 */
enum PageScope: string
{
    case All = 'all';
    case Routes = 'routes';

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
