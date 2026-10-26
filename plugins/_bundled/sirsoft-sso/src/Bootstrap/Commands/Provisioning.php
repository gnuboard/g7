<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Commands;

use Plugins\Sirsoft\Sso\Console\Commands\Provisioning\DeprovisionCheckCommand;
use Plugins\Sirsoft\Sso\Console\Commands\Provisioning\PruneCommand;
use Plugins\Sirsoft\Sso\Console\Commands\Provisioning\WithdrawDeprovisionedCommand;

/**
 * artisan 명령 — 프로비저닝(주기 점검, 퇴사 후 탈퇴, 보존 정리) 영역.
 *
 * `Bootstrap\Commands::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Provisioning
{
    /**
     * 이 영역의 artisan 명령 목록을 반환합니다.
     *
     * @return array<int, class-string> 명령 클래스 목록
     */
    public static function all(): array
    {
        return [
            DeprovisionCheckCommand::class,
            WithdrawDeprovisionedCommand::class,
            PruneCommand::class,
        ];
    }
}
