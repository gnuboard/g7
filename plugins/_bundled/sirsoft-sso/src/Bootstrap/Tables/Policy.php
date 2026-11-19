<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Tables;

/**
 * 플러그인 표(삭제 순서 — 자식 표 먼저) — 정책 표 영역.
 *
 * `Bootstrap\Tables::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Policy
{
    /**
     * 이 영역의 플러그인 표(삭제 순서 — 자식 표 먼저) 목록을 반환합니다.
     *
     * @return array<int, string> 표 이름
     */
    public static function all(): array
    {
        return [
            'sso_site_policies',
        ];
    }
}
