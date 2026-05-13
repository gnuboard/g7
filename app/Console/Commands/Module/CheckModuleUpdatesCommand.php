<?php

namespace App\Console\Commands\Module;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Extension\ModuleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckModuleUpdatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:check-updates {identifier? : 특정 모듈만 확인 (선택)}';

    /**
     * The console command description.
     */
    protected $description = '모듈 업데이트를 확인합니다';

    /**
     * 모듈 관리자 및 리포지토리
     */
    public function __construct(
        private ModuleManager $moduleManager,
        private ModuleRepositoryInterface $moduleRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
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
     */
    private function checkSingle(string $identifier): int
    {
        $module = $this->moduleRepository->findByIdentifier($identifier);

        if (! $module) {
            $this->error('❌ '.__('modules.commands.check_updates.not_installed', ['module' => $identifier]));

            return Command::FAILURE;
        }

        $result = $this->moduleManager->checkModuleUpdate($identifier);

        // 단일 체크는 DB 갱신이 안 되므로 커맨드에서 직접 갱신
        $this->moduleRepository->updateByIdentifier($identifier, [
            'update_available' => $result['update_available'],
            'latest_version' => $result['latest_version'],
            'update_source' => $result['update_source'],
        ]);

        if ($result['update_available']) {
            $this->info('🔄 '.__('modules.commands.check_updates.single_update_available', [
                'module' => $identifier,
                'current' => $result['current_version'],
                'latest' => $result['latest_version'],
                'source' => $result['update_source'],
            ]));
        } else {
            $this->info('✅ '.__('modules.commands.check_updates.single_up_to_date', [
                'module' => $identifier,
                'version' => $result['current_version'],
            ]));
        }

        return Command::SUCCESS;
    }

    /**
     * 모든 설치된 모듈의 업데이트를 확인합니다.
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
            $isUpdate = $detail['update_available'] ?? false;
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
            'total' => count($result['details']),
            'updates' => $updateCount,
        ]));

        return Command::SUCCESS;
    }
}
