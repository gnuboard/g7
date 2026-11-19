<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Commands;

use Plugins\Sirsoft\Sso\Console\Commands\EnforcementCommand;

/**
 * artisan 명령 — 정책(강제 해제) 영역.
 *
 * `Bootstrap\Commands::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Policy
{
    /**
     * 이 영역의 artisan 명령 목록을 반환합니다.
     *
     * @return array<int, class-string> 명령 클래스 목록
     */
    public static function all(): array
    {
        return [
            EnforcementCommand::class,
        ];
    }
}
