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

    /**
     * 아무 일도 하지 않고 성공 코드를 돌려준다 (해석 대상 표본일 뿐 실행 내용은 없다).
     *
     * @return int 성공 종료 코드
     */
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
