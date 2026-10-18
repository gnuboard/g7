<?php

namespace App\Console\Commands;

use App\Services\IdentityLogService;
use Illuminate\Console\Command;

/**
 * 보존 기간이 지난 본인인증 이력을 파기하는 커맨드
 *
 * 관리자 화면의 수동 파기 버튼과 동일한 기본 보관주기(180일)를 사용합니다.
 */
class PruneIdentityLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'identity:prune-logs {--days=180 : 보관 기간(일)}';

    /**
     * The console command description.
     */
    protected $description = '보관 기간이 지난 본인인증 이력을 파기합니다';

    /**
     * Execute the console command.
     *
     * 파기 자체는 도메인 서비스가 수행합니다 — 커맨드는 옵션만 전달하고,
     * 보존 기간 하한은 그 계층이 소유합니다.
     *
     * @param  IdentityLogService  $service  본인인증 이력 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(IdentityLogService $service): int
    {
        $days = (int) $this->option('days');
        $purged = $service->purge($days);

        $this->info("본인인증 이력 {$purged}건이 파기되었습니다. (보관 {$days}일)");

        return self::SUCCESS;
    }
}
