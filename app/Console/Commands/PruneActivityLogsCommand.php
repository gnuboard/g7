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
    protected $signature = 'activity-log:prune {--days= : 보존 기간(일). 생략하면 환경설정 > 보안 「활동 로그 보관 기간」, 어느 쪽이든 최소 365일}';

    /**
     * The console command description.
     */
    protected $description = '보존 기간이 지난 활동 로그를 삭제합니다';

    /**
     * Execute the console command.
     *
     * 파기 자체는 도메인 서비스가 수행합니다 — 커맨드는 옵션만 전달합니다.
     * 환경설정 판독·법정 하한(365일)·역할 변경 기록 3년 보관·확장 훅 발행은 그 계층이 소유합니다.
     * 예약 실행은 `--days` 없이 부르므로 환경설정 값이 적용됩니다.
     *
     * @param  ActivityLogService  $service  활동 로그 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(ActivityLogService $service): int
    {
        $option = $this->option('days');
        $requested = ($option === null || $option === '') ? null : (int) $option;
        $days = $service->effectiveRetentionDays($requested);
        $deleted = $service->prune($requested);

        $this->info("활동 로그 {$deleted}건이 삭제되었습니다. (보존 {$days}일)");

        return self::SUCCESS;
    }
}
