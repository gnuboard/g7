<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\ParsesDaysOption;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;

/**
 * 보존 기간이 지난 활동 로그를 정리하는 커맨드
 */
class PruneActivityLogsCommand extends Command
{
    use ParsesDaysOption;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'activity-log:prune {--days= : 보관 기간(일). 생략하면 환경설정 > 보안 「활동 로그 보관 기간」, 어느 쪽이든 최소 365일}';

    /**
     * The console command description.
     */
    protected $description = '보관 기간이 지난 활동 로그를 삭제합니다';

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
        $requested = $this->parseDaysOption();

        if ($requested === false) {
            return self::FAILURE;
        }

        $days = $service->effectiveRetentionDays($requested);
        $deleted = $service->prune($requested);

        // 법정 하한(365일)보다 작게 준 값은 다른 정리 명령과 같은 규칙으로 「최소 보관 N일로 적용」을 알린다 — 종전에는
        // 적용값만 찍혀 `--days=0` 이 기본값처럼 읽혔다(7.1.1 출시 전)
        [$shownDays, $floorNote] = $requested !== null && $requested < $days
            ? $this->retentionForDisplay($requested, $days)
            : [$days, ''];

        $this->info(__('common.console.prune_done.activity_logs', ['count' => $deleted, 'days' => $shownDays]).$floorNote);

        return self::SUCCESS;
    }
}
