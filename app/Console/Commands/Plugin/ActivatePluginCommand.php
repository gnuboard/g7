<?php

namespace App\Console\Commands\Plugin;

use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ActivatePluginCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:activate {identifier : 활성화할 플러그인 식별자} {--force : 의존성 미충족 시 강제 활성화}';

    /**
     * The console command description.
     */
    protected $description = '플러그인을 활성화합니다';

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
                $this->error('❌ '.__('plugins.commands.activate.not_installed', ['plugin' => $identifier]));

                return Command::FAILURE;
            }

            // 이미 활성화된 플러그인인지 확인
            if ($pluginRecord->status === ExtensionStatus::Active->value) {
                $this->warn('⚠️  '.__('plugins.commands.activate.already_active', ['plugin' => $identifier]));

                return Command::FAILURE;
            }

            // 플러그인 활성화
            $force = $this->option('force');
            $result = $this->pluginManager->activatePlugin($identifier, $force);

            // 경고 응답인 경우 (의존성 미충족)
            if (isset($result['warning']) && $result['warning'] === true) {
                $this->warn('⚠️  '.$result['message']);
                $this->line('');

                if (! empty($result['missing_modules'])) {
                    $this->info('필요한 모듈:');
                    foreach ($result['missing_modules'] as $module) {
                        $statusLabel = $module['status'] === 'not_installed' ? '미설치' : '비활성';
                        $this->line("   - {$module['identifier']} ({$module['name']}) [{$statusLabel}]");
                    }
                }

                if (! empty($result['missing_plugins'])) {
                    $this->info('필요한 플러그인:');
                    foreach ($result['missing_plugins'] as $plugin) {
                        $statusLabel = $plugin['status'] === 'not_installed' ? '미설치' : '비활성';
                        $this->line("   - {$plugin['identifier']} ({$plugin['name']}) [{$statusLabel}]");
                    }
                }

                $this->line('');
                $this->info('강제로 활성화하려면 --force 옵션을 사용하세요.');

                return Command::FAILURE;
            }

            if ($result['success']) {
                $this->info('✅ '.__('plugins.commands.activate.success', ['plugin' => $identifier]));
                $this->info('   - '.__('plugins.commands.activate.layouts_registered', ['count' => $result['layouts_registered']]));
                Log::info(__('plugins.commands.activate.success', ['plugin' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 활성화 실패', [
                'plugin' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
