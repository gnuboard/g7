<?php

namespace App\Console\Commands\Module;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeactivateModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:deactivate {identifier : 비활성화할 모듈 식별자} {--force : 의존 템플릿이 있어도 강제 비활성화}';

    /**
     * The console command description.
     */
    protected $description = '모듈을 비활성화합니다';

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
                $this->error('❌ '.__('modules.commands.deactivate.not_installed', ['module' => $identifier]));

                return Command::FAILURE;
            }

            // 모듈이 활성화되어 있는지 확인
            if ($moduleRecord->status !== ExtensionStatus::Active->value) {
                $this->warn('⚠️  '.__('modules.commands.deactivate.not_active', ['module' => $identifier]));

                return Command::FAILURE;
            }

            // 모듈 비활성화
            $force = $this->option('force');
            $result = $this->moduleManager->deactivateModule($identifier, $force);

            // 경고 응답인 경우 (의존 템플릿 존재)
            if (isset($result['warning']) && $result['warning'] === true) {
                $this->warn('⚠️  '.$result['message']);
                $this->line('');
                $this->info('의존하는 템플릿 목록:');
                foreach ($result['dependent_templates'] as $template) {
                    $this->line("   - {$template['identifier']} ({$template['name']})");
                }
                $this->line('');
                $this->info('강제로 비활성화하려면 --force 옵션을 사용하세요.');

                return Command::FAILURE;
            }

            if ($result['success']) {
                $this->info('✅ '.__('modules.commands.deactivate.success', ['module' => $identifier]));
                $this->info('   - '.__('modules.commands.deactivate.layouts_deleted', ['count' => $result['layouts_deleted']]));
                $this->warn('⚠️  '.__('modules.commands.deactivate.warning'));
                Log::info(__('modules.commands.deactivate.success', ['module' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 비활성화 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
