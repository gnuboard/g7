<?php

namespace App\Console\Commands\Module;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\DeactivationReason;
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
     * Execute the console command.
     *
     * @return int 종료 코드 (성공 0, 실패 1)
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
            // 운영자가 직접 실행한 비활성화다 — 시스템 자동 비활성화와 구분되도록 사유를 명시한다.
            $result = $this->moduleManager->deactivateModule($identifier, $force, DeactivationReason::Manual->value);

            // 경고 응답인 경우 (의존 확장 존재) — 관리자는 템플릿·모듈·플러그인을 모두 돌려준다.
            // 템플릿만 출력하면 플러그인이 의존할 때 빈 목록 제목만 나와 거절 사유를 알 수 없다(7.1.0 실측).
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
                $this->info('✅ '.__('modules.commands.deactivate.success', ['module' => $identifier]));
                $this->info('   - '.trans_choice('modules.commands.deactivate.layouts_deleted', (int) ($result['layouts_deleted'] ?? 0)));
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
