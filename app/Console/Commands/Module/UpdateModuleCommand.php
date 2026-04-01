<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Traits\HasProgressBar;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Extension\ModuleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateModuleCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:update
        {identifier : 업데이트할 모듈 식별자}
        {--force : 버전 비교 없이 강제 업데이트}';

    /**
     * The console command description.
     */
    protected $description = '모듈을 최신 버전으로 업데이트합니다';

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
        $force = $this->option('force');

        try {
            $this->moduleManager->loadModules();

            // 모듈 존재 확인
            $module = $this->moduleRepository->findByIdentifier($identifier);

            if (! $module) {
                $this->error('❌ '.__('modules.commands.update.not_installed', ['module' => $identifier]));

                return Command::FAILURE;
            }

            // 업데이트 확인
            $checkResult = $this->moduleManager->checkModuleUpdate($identifier);

            if (! $checkResult['update_available'] && ! $force) {
                $this->info('✅ '.__('modules.commands.update.no_update', ['module' => $identifier]));

                return Command::SUCCESS;
            }

            // 업데이트 정보 표시
            $this->info(__('modules.commands.update.current_version', ['version' => $checkResult['current_version']]));

            if ($force && ! $checkResult['update_available']) {
                $this->warn('⚠️  '.__('modules.commands.update.force_mode'));
            } else {
                $this->info(__('modules.commands.update.latest_version', ['version' => $checkResult['latest_version']]));
                $this->info(__('modules.commands.update.update_source', ['source' => $checkResult['update_source']]));
            }

            $this->newLine();

            // 확인 프롬프트 (--force 시 건너뜀)
            if (! $force && ! $this->confirm(__('modules.commands.update.confirm_question'), false)) {
                $this->info(__('modules.commands.update.aborted'));

                return Command::SUCCESS;
            }

            // 업데이트 실행
            $onProgress = $this->createProgressCallback(ModuleManager::UPDATE_STEPS);
            try {
                $updateResult = $this->moduleManager->updateModule($identifier, $force, $onProgress);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($updateResult['success']) {
                $this->newLine();
                $this->info('✅ '.__('modules.commands.update.success', ['module' => $identifier]));
                $this->info('   '.__('modules.commands.update.version_change', [
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                ]));

                Log::info('모듈 업데이트 완료', [
                    'module' => $identifier,
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                ]);

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            $this->warn('💡 '.__('modules.commands.update.backup_restored'));

            Log::error('모듈 업데이트 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
