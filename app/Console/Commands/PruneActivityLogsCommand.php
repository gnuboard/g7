<?php

namespace App\Console\Commands;

use App\Services\ActivityLogService;
use Illuminate\Console\Command;

/**
 * 보존 기간이 지난 활동 로그를 정리하는 커맨드
 */
class PruneActivityLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'activity-log:prune {--days=365 : 보존 기간(일)}';

    /**
     * The console command description.
     */
    protected $description = '보존 기간이 지난 활동 로그를 삭제합니다';

    /**
     * Execute the console command.
     *
     * 파기 자체는 도메인 서비스가 수행합니다 — 커맨드는 옵션만 전달합니다.
     * 보존 기간 하한과 확장 훅 발행은 그 계층이 소유합니다.
     *
     * @param  ActivityLogService  $service  활동 로그 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(ActivityLogService $service): int
    {
        $days = (int) $this->option('days');
        $deleted = $service->prune($days);

        $this->info("활동 로그 {$deleted}건이 삭제되었습니다. (보존 {$days}일)");

        return self::SUCCESS;
    }
}
