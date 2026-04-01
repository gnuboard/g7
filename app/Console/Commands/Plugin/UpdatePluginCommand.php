<?php

namespace App\Console\Commands\Plugin;

use App\Console\Commands\Traits\HasProgressBar;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePluginCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:update
        {identifier : 업데이트할 플러그인 식별자}
        {--force : 버전 비교 없이 강제 업데이트}';

    /**
     * The console command description.
     */
    protected $description = '플러그인을 최신 버전으로 업데이트합니다';

    /**
     * 플러그인 관리자 및 리포지토리
     */
    public function __construct(
        private PluginManager $pluginManager,
        private PluginRepositoryInterface $pluginRepository
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
            $this->pluginManager->loadPlugins();

            // 플러그인 존재 확인
            $plugin = $this->pluginRepository->findByIdentifier($identifier);

            if (! $plugin) {
                $this->error('❌ '.__('plugins.commands.update.not_installed', ['plugin' => $identifier]));

                return Command::FAILURE;
            }

            // 업데이트 확인
            $checkResult = $this->pluginManager->checkPluginUpdate($identifier);

            if (! $checkResult['update_available'] && ! $force) {
                $this->info('✅ '.__('plugins.commands.update.no_update', ['plugin' => $identifier]));

                return Command::SUCCESS;
            }

            // 업데이트 정보 표시
            $this->info(__('plugins.commands.update.current_version', ['version' => $checkResult['current_version']]));

            if ($force && ! $checkResult['update_available']) {
                $this->warn('⚠️  '.__('plugins.commands.update.force_mode'));
            } else {
                $this->info(__('plugins.commands.update.latest_version', ['version' => $checkResult['latest_version']]));
                $this->info(__('plugins.commands.update.update_source', ['source' => $checkResult['update_source']]));
            }

            $this->newLine();

            // 확인 프롬프트 (--force 시 건너뜀)
            if (! $force && ! $this->confirm(__('plugins.commands.update.confirm_question'), false)) {
                $this->info(__('plugins.commands.update.aborted'));

                return Command::SUCCESS;
            }

            // 업데이트 실행
            $onProgress = $this->createProgressCallback(PluginManager::UPDATE_STEPS);
            try {
                $updateResult = $this->pluginManager->updatePlugin($identifier, $force, $onProgress);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($updateResult['success']) {
                $this->newLine();
                $this->info('✅ '.__('plugins.commands.update.success', ['plugin' => $identifier]));
                $this->info('   '.__('plugins.commands.update.version_change', [
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                ]));

                Log::info('플러그인 업데이트 완료', [
                    'plugin' => $identifier,
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                ]);

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            $this->warn('💡 '.__('plugins.commands.update.backup_restored'));

            Log::error('플러그인 업데이트 실패', [
                'plugin' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
