<?php

namespace Tests\Fixtures\ScheduleCommandExtension\src\Console\Commands;

use Illuminate\Console\Command;

/**
 * 같은 확장 안에 있지만 `getSchedules()` 가 선언하지 **않은** 커맨드.
 *
 * 등록 범위가 「선언한 것」이 아니라 「소스에 있는 것」이 되면 이 커맨드가 매핑에 실린다.
 * 그 회귀를 잡는 것이 이 픽스처의 존재 이유다.
 */
class UndeclaredCommand extends Command
{
    protected $signature = 'fixture-ext:undeclared';

    protected $description = '픽스처 — 선언되지 않은 커맨드';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
