<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\ReportsUpdateCheckErrors;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Extension\ModuleManager;
use App\Support\RuntimeRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckModuleUpdatesCommand extends Command
{
    use LocalizesDescription;
    use ReportsUpdateCheckErrors;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:check-updates {identifier? : 특정 모듈만 확인 (선택)}';

    /**
     * 모듈 관리자 및 리포지토리를 주입받는다.
     *
     * @param  ModuleManager  $moduleManager  모듈 관리자
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 리포지토리
     */
    public function __construct(
        private ModuleManager $moduleManager,
        private ModuleRepositoryInterface $moduleRepository
    ) {
        parent::__construct();
    }

    /**
     * 모듈 업데이트를 확인한다 — 식별자를 주면 그 모듈만, 없으면 설치된 전부.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        try {
            $this->moduleManager->loadModules();

            if ($identifier) {
                return $this->checkSingle($identifier);
            }

            return $this->checkAll();
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 업데이트 확인 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 단일 모듈 업데이트를 확인합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return int 종료 코드 (미설치·GitHub 조회 실패면 1)
     */
    private function checkSingle(string $identifier): int
    {
        $module = $this->moduleRepository->findByIdentifier($identifier);

        if (! $module) {
            $this->error('❌ '.__('modules.commands.check_updates.not_installed', ['module' => $identifier]));

            return Command::FAILURE;
        }

        $result = $this->moduleManager->checkModuleUpdate($identifier);

        // GitHub 조회에 실패했으면(check_error) DB 의 업데이트 정보를 덮지 않는다 — 번들 대체 판정은 출력으로만 보인다
        $checkFailed = isset($result['check_error']);

        // 단일 체크는 DB 갱신이 안 되므로 커맨드에서 직접 갱신
        // 검수 서버(stage)는 공유 DB 의 업데이트 정보를 쓰지 않는다 — 결과만 출력한다(전체 확인과 같은 규칙)
        if (! RuntimeRole::isStage() && ! $checkFailed) {
            $this->moduleRepository->updateByIdentifier($identifier, [
                'update_available' => $result['update_available'],
                'latest_version' => $result['latest_version'],
                'update_source' => $result['update_source'],
            ]);
        }

        if ($result['update_available']) {
            $this->info('🔄 '.__('modules.commands.check_updates.single_update_available', [
                'module' => $identifier,
                'current' => $result['current_version'],
                'latest' => $result['latest_version'],
                'source' => $result['update_source'],
            ]));
        } elseif (! $checkFailed) {
            $this->info('✅ '.__('modules.commands.check_updates.single_up_to_date', [
                'module' => $identifier,
                'version' => $result['current_version'],
            ]));
        }

        return $this->updateCheckFailuresExitCode($checkFailed ? [['identifier' => $identifier, 'reason' => (string) $result['check_error']]] : []);
    }

    /**
     * 모든 설치된 모듈의 업데이트를 확인합니다.
     *
     * @return int 종료 코드 (GitHub 조회에 실패한 확장이 하나라도 있으면 1)
     */
    private function checkAll(): int
    {
        $installedModules = $this->moduleManager->getInstalledModulesWithDetails();

        if (empty($installedModules)) {
            $this->info(__('modules.commands.check_updates.no_installed'));

            return Command::SUCCESS;
        }

        // checkAllModulesForUpdates는 내부에서 DB 갱신 포함
        $result = $this->moduleManager->checkAllModulesForUpdates();

        $tableData = [];
        $updateCount = 0;

        foreach ($result['details'] as $detail) {
            $isUpdate = (bool) ($detail['update_available'] ?? true);
            if ($isUpdate) {
                $updateCount++;
            }

            $tableData[] = [
                $detail['identifier'],
                $detail['current_version'] ?? '-',
                $detail['latest_version'] ?? '-',
                $detail['update_source'] ?? '-',
                $isUpdate
                    ? '🔄 '.__('modules.commands.check_updates.update_available')
                    : '✅ '.__('modules.commands.check_updates.up_to_date'),
            ];
        }

        $headers = [
            __('modules.commands.check_updates.headers.identifier'),
            __('modules.commands.check_updates.headers.current_version'),
            __('modules.commands.check_updates.headers.latest_version'),
            __('modules.commands.check_updates.headers.source'),
            __('modules.commands.check_updates.headers.status'),
        ];

        $this->table($headers, $tableData);
        $this->newLine();
        $this->info(__('modules.commands.check_updates.summary', [
            'total' => $result['checked_count'] ?? count($result['details']),
            'updates' => $updateCount,
        ]));

        // GitHub 조회에 실패한 확장이 있으면 실패 표 + 종료 코드 1 (최신으로 보이지 않게)
        return $this->updateCheckFailuresExitCode($result['failures'] ?? []);
    }
}
