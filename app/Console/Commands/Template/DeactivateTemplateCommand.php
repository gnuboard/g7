<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\ReportsIntegrationNotices;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\DeactivationReason;
use App\Extension\Helpers\TemplateLastActiveGuard;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeactivateTemplateCommand extends Command
{
    use LocalizesDescription;
    use ReportsIntegrationNotices;
    use ReportsLifecycleWarnings;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:deactivate
        {identifier : 템플릿 식별자}
        {--force : 유형의 마지막 활성 템플릿도 끈다 (그 화면이 그려지지 않는다)}';

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

            // 유형의 마지막 활성 템플릿은 기본으로 끄지 않는다(설계 확정 2026-11-08) — 끄면 그 화면(관리자·사이트) 전체가 그려지지
            // 않는다. 판정은 관리자 화면 경로와 같다(TemplateLastActiveGuard). 복구 작업처럼 의도한 경우만 --force 로 끈다
            if (! $this->option('force') && TemplateLastActiveGuard::isLastActiveOfType($template, $this->templateRepository)) {
                $this->error('❌ '.TemplateLastActiveGuard::message($template, 'templates.errors.last_active_deactivate'));
                $this->line(__('templates.commands.deactivate.last_active_force_hint', ['template' => $identifier]));

                return Command::FAILURE;
            }

            // 템플릿 비활성화
            // audit:allow extension-deactivate-reason-required reason: 운영자가 직접 친 비활성화(manual) — 템플릿은 reason 이 2번째 인자라 명시했다
            $result = $this->templateManager->deactivateTemplate($identifier, DeactivationReason::Manual->value);

            if ($result) {
                // 성공 메시지
                $this->info($this->lifecycleDoneIcon().' '.__('templates.commands.deactivate.success', ['template' => $identifier]));
                $this->reportIntegratedBy('template', $identifier);

                // 경고 메시지 — 같은 유형에 다른 활성 템플릿이 없을 때(--force 로 마지막 활성 템플릿을 끈 경우)만
                if (! $this->templateRepository->getActiveByType((string) $template->type)->contains(fn ($active) => $active->identifier !== $identifier)) {
                    $this->warn('⚠️  '.__('templates.commands.deactivate.no_active_warning', [
                        'type' => __('templates.types.'.$template->type),
                    ]));
                }

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
