<?php

namespace App\Console\Commands\Template;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ActivateTemplateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:activate {identifier : 템플릿 식별자} {--force : 의존성 미충족 시 강제 활성화}';

    /**
     * The console command description.
     */
    protected $description = '템플릿을 활성화합니다';

    /**
     * 템플릿 관리자 및 리포지토리
     */
    public function __construct(
        private TemplateManager $templateManager,
        private TemplateRepositoryInterface $templateRepository
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
            // 템플릿 디렉토리 스캔 및 로드
            $this->templateManager->loadTemplates();

            // 템플릿 존재 확인
            $template = $this->templateRepository->findByIdentifier($identifier);

            if (! $template) {
                $this->error('❌ '.__('templates.commands.activate.not_installed', ['template' => $identifier]));

                return Command::FAILURE;
            }

            // 기존 활성 템플릿 조회
            $previousActive = $this->templateRepository->findActiveByType($template->type);

            // 템플릿 활성화
            $force = $this->option('force');
            $result = $this->templateManager->activateTemplate($identifier, $force);

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
                // 기존 템플릿 비활성화 메시지
                if ($previousActive && $previousActive->identifier !== $identifier) {
                    $this->warn('ℹ️  '.__('templates.commands.activate.deactivated_previous', [
                        'type' => __('templates.types.'.$template->type),
                        'previous' => $previousActive->identifier,
                    ]));
                }

                // 성공 메시지
                $this->info('✅ '.__('templates.commands.activate.success', ['template' => $identifier]));

                Log::info(__('templates.commands.activate.success', ['template' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('템플릿 활성화 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
