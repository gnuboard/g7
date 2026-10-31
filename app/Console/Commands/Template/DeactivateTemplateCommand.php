<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\DeactivationReason;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeactivateTemplateCommand extends Command
{
    use ReportsLifecycleWarnings;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:deactivate {identifier : 템플릿 식별자}';

    /**
     * The console command description.
     */
    protected $description = '템플릿을 비활성화합니다';

    /**
     * 템플릿 관리자 및 리포지토리를 주입합니다.
     *
     * @param  TemplateManager  $templateManager  템플릿 관리자
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 리포지토리
     */
    public function __construct(
        private TemplateManager $templateManager,
        private TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct();
    }

    /**
     * 템플릿을 비활성화합니다.
     *
     * @return int 종료코드 (성공 0, 미설치·비활성·실패 1)
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

            // 템플릿이 활성화되어 있는지 확인
            if ($template->status !== 'active') {
                $this->warn('⚠️  '.__('templates.commands.deactivate.not_active', ['template' => $identifier]));

                return Command::FAILURE;
            }

            // 템플릿 비활성화
            // audit:allow extension-deactivate-reason-required reason: 운영자가 직접 친 비활성화(manual) — 템플릿은 reason 이 2번째 인자라 명시했다
            $result = $this->templateManager->deactivateTemplate($identifier, DeactivationReason::Manual->value);

            if ($result) {
                // 성공 메시지
                $this->info($this->lifecycleDoneIcon().' '.__('templates.commands.deactivate.success', ['template' => $identifier]));

                // 경고 메시지
                $this->warn('⚠️  '.__('templates.commands.deactivate.no_active_warning', [
                    'type' => __('templates.types.'.$template->type),
                ]));

                Log::info(__('templates.commands.deactivate.success', ['template' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('템플릿 비활성화 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
