<?php

namespace App\Console\Commands\Module;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ActivateModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:activate {identifier : 활성화할 모듈 식별자} {--force : 의존성 미충족 시 강제 활성화}';

    /**
     * The console command description.
     */
    protected $description = '모듈을 활성화합니다';

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
            // 모듈 디렉토리 스캔 및 로드
            $this->moduleManager->loadModules();

            // 모듈이 설치되어 있는지 확인
            $moduleRecord = $this->moduleRepository->findByIdentifier($identifier);
            if (! $moduleRecord) {
                $this->error('❌ '.__('modules.commands.activate.not_installed', ['module' => $identifier]));

                return Command::FAILURE;
            }

            // 이미 활성화된 모듈인지 확인
            if ($moduleRecord->status === ExtensionStatus::Active->value) {
                $this->warn('⚠️  '.__('modules.commands.activate.already_active', ['module' => $identifier]));

                return Command::FAILURE;
            }

            // 모듈 활성화
            $force = $this->option('force');
            $result = $this->moduleManager->activateModule($identifier, $force);

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
                $this->info('✅ '.__('modules.commands.activate.success', ['module' => $identifier]));
                $this->info('   - '.__('modules.commands.activate.layouts_registered', ['count' => $result['layouts_registered']]));
                Log::info(__('modules.commands.activate.success', ['module' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 활성화 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
