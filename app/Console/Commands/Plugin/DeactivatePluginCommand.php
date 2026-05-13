<?php

namespace App\Console\Commands\Plugin;

use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeactivatePluginCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:deactivate {identifier : 비활성화할 플러그인 식별자} {--force : 의존 확장이 있어도 강제 비활성화}';

    /**
     * The console command description.
     */
    protected $description = '플러그인을 비활성화합니다';

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

        try {
            // 플러그인 디렉토리 스캔 및 로드
            $this->pluginManager->loadPlugins();

            // 플러그인이 설치되어 있는지 확인
            $pluginRecord = $this->pluginRepository->findByIdentifier($identifier);
            if (! $pluginRecord) {
                $this->error('❌ '.__('plugins.commands.deactivate.not_installed', ['plugin' => $identifier]));

                return Command::FAILURE;
            }

            // 플러그인이 활성화되어 있는지 확인
            if ($pluginRecord->status !== ExtensionStatus::Active->value) {
                $this->warn('⚠️  '.__('plugins.commands.deactivate.not_active', ['plugin' => $identifier]));

                return Command::FAILURE;
            }

            // 플러그인 비활성화
            $force = $this->option('force');
            $result = $this->pluginManager->deactivatePlugin($identifier, $force);

            // 경고 응답인 경우 (의존 확장 존재)
            if (isset($result['warning']) && $result['warning'] === true) {
                $this->warn('⚠️  '.$result['message']);
                $this->line('');

                // 의존하는 템플릿 목록
                if (! empty($result['dependent_templates'])) {
                    $this->info('의존하는 템플릿 목록:');
                    foreach ($result['dependent_templates'] as $template) {
                        $this->line("   - {$template['identifier']} ({$template['name']})");
                    }
                }

                // 의존하는 모듈 목록
                if (! empty($result['dependent_modules'])) {
                    $this->info('의존하는 모듈 목록:');
                    foreach ($result['dependent_modules'] as $module) {
                        $this->line("   - {$module['identifier']} ({$module['name']})");
                    }
                }

                // 의존하는 플러그인 목록
                if (! empty($result['dependent_plugins'])) {
                    $this->info('의존하는 플러그인 목록:');
                    foreach ($result['dependent_plugins'] as $plugin) {
                        $this->line("   - {$plugin['identifier']} ({$plugin['name']})");
                    }
                }

                $this->line('');
                $this->info('강제로 비활성화하려면 --force 옵션을 사용하세요.');

                return Command::FAILURE;
            }

            if ($result['success']) {
                $this->info('✅ '.__('plugins.commands.deactivate.success', ['plugin' => $identifier]));
                $this->info('   - '.__('plugins.commands.deactivate.layouts_deleted', ['count' => $result['layouts_deleted']]));
                $this->warn('⚠️  '.__('plugins.commands.deactivate.warning'));
                Log::info(__('plugins.commands.deactivate.success', ['plugin' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 비활성화 실패', [
                'plugin' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
