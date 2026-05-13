<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Traits\HasProgressBar;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UninstallTemplateCommand extends Command
{
    use HasProgressBar;
    use HasUnifiedConfirm;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:uninstall {identifier : 템플릿 식별자}';

    /**
     * The console command description.
     */
    protected $description = '템플릿을 삭제합니다';

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

            // 삭제 확인 프롬프트
            $this->warn(__('templates.commands.uninstall.confirm_prompt', ['template' => $identifier]));
            $this->warn(__('templates.commands.uninstall.confirm_details.layouts', ['count' => $template->layouts()->count()]));
            $this->warn(__('templates.commands.uninstall.confirm_details.versions'));

            if (! $this->unifiedConfirm(__('templates.commands.uninstall.confirm_question'), false)) {
                $this->info(__('templates.commands.uninstall.aborted'));

                return Command::SUCCESS;
            }

            // 레이아웃 및 버전 개수 조회
            $layoutsCount = $template->layouts()->count();

            // 템플릿 삭제
            $onProgress = $this->createProgressCallback(TemplateManager::UNINSTALL_STEPS);
            try {
                $result = $this->templateManager->uninstallTemplate($identifier, $onProgress);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($result) {
                // 성공 메시지
                $this->info('✅ '.__('templates.commands.uninstall.success', ['template' => $identifier]));
                $this->info('   - '.__('templates.commands.uninstall.layouts_deleted', ['count' => $layoutsCount]));

                Log::info(__('templates.commands.uninstall.success', ['template' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('템플릿 삭제 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
