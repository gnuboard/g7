<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Concerns\SelectsLayoutInstallSource;
use App\Console\Commands\Traits\HasProgressBar;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\RendersLayoutSyncResult;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Exceptions\ExtensionUpdateFailedException;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateTemplateCommand extends Command
{
    use HasProgressBar;
    use HasUnifiedConfirm;
    use RendersLayoutSyncResult;
    use SelectsLayoutInstallSource;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:update
        {identifier : 업데이트할 템플릿 식별자}
        {--layout-strategy=overwrite : 레이아웃 전략 (overwrite|keep)}
        {--force : 버전 비교 없이 강제 업데이트}
        {--source=auto : 업데이트 소스 (auto|bundled|github) — bundled 는 _bundled 만 사용(GitHub 우회)}
        {--zip= : 외부 ZIP 파일 경로 (지정 시 GitHub/번들 우회 + 버전은 template.json 기준)}
        {--layout-source=auto : 레이아웃 설치 소스 (auto|json) — json 은 짝 .json 이 있으면 .g7.html 대신 원본 JSON 으로 등록(진단·복구용)}';

    /**
     * The console command description.
     */
    protected $description = '템플릿을 최신 버전으로 업데이트합니다';

    /**
     * 템플릿 관리자 및 리포지토리
     *
     * @param  TemplateManager  $templateManager  템플릿 업데이트를 수행할 매니저
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 설치 정보 조회 리포지토리
     */
    public function __construct(
        private TemplateManager $templateManager,
        private TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int 명령 실행 결과 코드 (Command::SUCCESS|Command::FAILURE)
     */
    public function handle(): int
    {
        // `--layout-source` 검증 + 런타임 반영 (알 수 없는 값이면 즉시 실패).
        if (! $this->applyLayoutInstallSource()) {
            return self::FAILURE;
        }

        $identifier = $this->argument('identifier');
        $layoutStrategy = $this->option('layout-strategy');
        $force = $this->option('force');
        $sourceOption = (string) $this->option('source');
        $zipPath = $this->option('zip');

        // 레이아웃 전략 검증
        if (! in_array($layoutStrategy, ['overwrite', 'keep'])) {
            $this->error('❌ '.__('templates.commands.update.invalid_strategy'));

            return Command::FAILURE;
        }

        if (! in_array($sourceOption, ['auto', 'bundled', 'github'], true)) {
            $this->error('❌ '.__('extensions.commands.update.invalid_source'));

            return Command::FAILURE;
        }

        if ($zipPath !== null && $sourceOption !== 'auto') {
            $this->error('❌ '.__('extensions.commands.update.zip_source_conflict'));

            return Command::FAILURE;
        }

        if ($zipPath !== null) {
            $resolvedZip = realpath($zipPath);
            if (! $resolvedZip || ! is_file($resolvedZip)) {
                $this->error('❌ '.__('extensions.commands.update.zip_not_found', ['path' => $zipPath]));

                return Command::FAILURE;
            }
            $zipPath = $resolvedZip;
        }

        $sourceOverride = $sourceOption === 'auto' ? null : $sourceOption;

        try {
            $this->templateManager->loadTemplates();

            // 템플릿 존재 확인
            $template = $this->templateRepository->findByIdentifier($identifier);

            if (! $template) {
                $this->error('❌ '.__('templates.commands.update.not_installed', ['template' => $identifier]));

                return Command::FAILURE;
            }

            // 업데이트 확인 (--zip 모드는 GitHub/번들 비교를 우회하므로 스킵)
            if ($zipPath === null) {
                $checkResult = $this->templateManager->checkTemplateUpdate($identifier, $sourceOverride);

                if (! $checkResult['update_available'] && ! $force) {
                    $this->info('✅ '.__('templates.commands.update.no_update', ['template' => $identifier]));

                    return Command::SUCCESS;
                }

                // 업데이트 정보 표시
                $this->info(__('templates.commands.update.current_version', ['version' => $checkResult['current_version']]));

                if ($force && ! $checkResult['update_available']) {
                    $this->warn('⚠️  '.__('templates.commands.update.force_mode'));
                } else {
                    $this->info(__('templates.commands.update.latest_version', ['version' => $checkResult['latest_version']]));
                    $this->info(__('templates.commands.update.update_source', ['source' => $checkResult['update_source']]));
                }
            } else {
                $this->info(__('templates.commands.update.current_version', ['version' => $template->version]));
                $this->info(__('extensions.commands.update.zip_source', ['path' => $zipPath]));
                $this->info(__('extensions.commands.update.zip_version_pending', ['manifest' => 'template.json']));
            }

            $this->info(__('templates.commands.update.layout_strategy', ['strategy' => $layoutStrategy]));

            // overwrite 전략일 때 덮어쓰일 운영자 수정본 경고 — 레이아웃 + 확장 조각을 한 판정(isOperatorModified)으로 (§5.9)
            if ($layoutStrategy === 'overwrite') {
                $this->warnModifiedLayoutsBeforeOverwrite($this->templateManager->hasModifiedLayouts($identifier), 'templates');
            }

            $this->newLine();

            // 확인 프롬프트 (--force 시 건너뜀)
            if (! $force && ! $this->unifiedConfirm(__('templates.commands.update.confirm_question'), false)) {
                $this->info(__('templates.commands.update.aborted'));

                return Command::SUCCESS;
            }

            // 업데이트 실행
            $onProgress = $this->createProgressCallback(TemplateManager::UPDATE_STEPS);
            try {
                $updateResult = $this->templateManager->updateTemplate($identifier, $force, $onProgress, $layoutStrategy, $sourceOverride, $zipPath);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($updateResult['success']) {
                $this->newLine();
                $this->info('✅ '.__('templates.commands.update.success', ['template' => $identifier]));
                $this->info('   '.__('templates.commands.update.version_change', [
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                ]));

                // 레이아웃 처리 결과 (D2) — 운영자가 고친 화면이 있을 때만 표 + 요약 한 줄
                $this->renderLayoutSyncResult($updateResult['layout_sync'] ?? null);
                $this->renderLayoutIncludeWarnings($updateResult['layout_warnings'] ?? []);
                $this->renderDependencyWarnings($updateResult);

                Log::info('템플릿 업데이트 완료', [
                    'template' => $identifier,
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                ]);

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            // 실패 뒤 실제 상태로 안내한다 — 종전에는 원인과 무관하게 「복원되었습니다」를 냈다(새 판이 남은 경우에도).
            $outcome = $e instanceof ExtensionUpdateFailedException ? $e->outcome : 'restored';
            $this->warn('💡 '.($outcome === 'restored'
                ? __('templates.commands.update.backup_restored')
                : __('extensions.commands.update.failure_outcome.'.$outcome)));

            Log::error('템플릿 업데이트 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
