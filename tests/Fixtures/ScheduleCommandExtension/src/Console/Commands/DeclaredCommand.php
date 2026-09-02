<?php

namespace Tests\Fixtures\ScheduleCommandExtension\src\Console\Commands;

use Illuminate\Console\Command;

/**
 * 확장이 `getSchedules()` 로 선언하는 커맨드.
 */
class DeclaredCommand extends Command
{
    protected $signature = 'fixture-ext:declared {--scheduled}';

    protected $description = '픽스처 — 선언된 예약 커맨드';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
