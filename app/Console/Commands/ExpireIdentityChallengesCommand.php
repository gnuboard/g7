<?php

namespace App\Console\Commands;

use App\Services\IdentityLogService;
use Illuminate\Console\Command;

/**
 * 만료 시각이 지난 본인인증 challenge 를 만료 상태로 전환하는 커맨드
 *
 * 상태 전환만 수행하며 행을 삭제하지 않습니다 (물리 파기는 identity:prune-logs 담당).
 */
class ExpireIdentityChallengesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'identity:expire-challenges';

    /**
     * The console command description.
     */
    protected $description = '만료 시각이 지난 본인인증 challenge 를 만료 처리합니다';

    /**
     * Execute the console command.
     *
     * @param  IdentityLogService  $service  본인인증 이력 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(IdentityLogService $service): int
    {
        $expired = $service->expirePastDue();

        $this->info("본인인증 challenge {$expired}건이 만료 처리되었습니다.");

        return self::SUCCESS;
    }
}
