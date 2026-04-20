<?php

namespace App\Console\Commands\Core;

use App\Services\CoreUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 코어 업그레이드 스텝을 별도 프로세스에서 실행합니다.
 *
 * CoreUpdateCommand 의 Step 10 에서 proc_open 으로 호출하여 최신 버전 클래스·
 * config 를 새 PHP 프로세스에서 로드하게 하는 진입점. 새 프로세스에서 실행되므로
 * upgrade step 이 신규 Service/Repository/Controller 등을 자유롭게 호출할 수 있다.
 *
 * 단, 이는 beta.3 이후 업그레이드 경로(경로 B)에만 적용된다. beta.1 → beta.2
 * 업그레이드는 beta.1 의 CoreUpdateCommand 가 본 커맨드를 알지 못하므로 spawn
 * 효과를 받지 못한다(경로 A) — 이 경우 upgrade step 파일 내부 로컬 로직으로
 * 후처리를 수행해야 한다. 상세는 docs/extension/upgrade-step-guide.md 참조.
 */
class ExecuteUpgradeStepsCommand extends Command
{
    protected $signature = 'core:execute-upgrade-steps
        {--from= : 시작 버전}
        {--to= : 대상 버전}
        {--force : 동일 버전 강제 실행}';

    protected $description = '코어 업그레이드 스텝을 별도 프로세스에서 실행합니다 (CoreUpdateCommand 내부용)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  CoreUpdateService  $service  코어 업데이트 서비스
     * @return int 종료 코드
     */
    public function handle(CoreUpdateService $service): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $force = (bool) $this->option('force');

        if ($from === '' || $to === '') {
            $this->error('--from 과 --to 는 필수 옵션입니다.');

            return self::INVALID;
        }

        try {
            $service->runUpgradeSteps(
                $from,
                $to,
                fn (string $version) => $this->info("upgrade step 실행: {$version}"),
                $force,
            );
        } catch (\Throwable $e) {
            Log::error('core:execute-upgrade-steps 실패', [
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
