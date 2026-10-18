<?php

namespace App\Console\Commands;

use App\Services\ScheduleService;
use Illuminate\Console\Command;

/**
 * 보존 기간이 지난 스케줄 실행 이력을 정리하는 커맨드
 *
 * 이력 행은 출력(longText)을 포함해 실행마다 누적되므로 정리 주체가 없으면 무한히 성장합니다.
 */
class PruneScheduleHistoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'schedules:prune-history {--days=90 : 보존 기간(일)}';

    /**
     * The console command description.
     */
    protected $description = '보존 기간이 지난 스케줄 실행 이력을 삭제합니다';

    /**
     * Execute the console command.
     *
     * 파기 자체는 도메인 서비스가 수행합니다 — 커맨드는 옵션만 전달합니다.
     * 보존 기간 하한과 확장 훅 발행은 그 계층이 소유합니다.
     *
     * @param  ScheduleService  $service  스케줄 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(ScheduleService $service): int
    {
        $days = (int) $this->option('days');
        $deleted = $service->pruneHistory($days);

        $this->info("스케줄 실행 이력 {$deleted}건이 삭제되었습니다. (보존 {$days}일)");

        return self::SUCCESS;
    }
}
