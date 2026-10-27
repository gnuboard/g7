<?php

namespace Plugins\Sirsoft\Sso\Bootstrap;

use Plugins\Sirsoft\Sso\Bootstrap\Tables\Core;
use Plugins\Sirsoft\Sso\Bootstrap\Tables\Policy;
use Plugins\Sirsoft\Sso\Bootstrap\Tables\Provisioning;

/**
 * 플러그인 표 목록을 영역별 파일에서 모읍니다 (`Plugin::getDynamicTables()` 가 부른다).
 *
 * 순서가 곧 삭제 순서다 — 기반 표(IdP, 연결)를 가리키는 영역(프로비저닝, 정책)을 먼저 둔다.
 *
 * @since 1.0.0
 */
final class Tables
{
    /** @var array<int, class-string> 영역 목록 (삭제 순서) */
    public const AREAS = [
        Provisioning::class,
        Policy::class,
        Core::class,
    ];

    /**
     * 모든 영역의 표를 중복 없이 합칩니다.
     *
     * @return array<int, string> 표 이름
     */
    public static function all(): array
    {
        $tables = [];
        foreach (self::AREAS as $area) {
            foreach ($area::all() as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }
}
