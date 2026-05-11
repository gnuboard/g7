<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Traits\HasProgressBar;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateTemplateCommand extends Command
{
    use HasProgressBar;
    use HasUnifiedConfirm;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:update
        {identifier : 업데이트할 템플릿 식별자}
        {--layout-strategy=overwrite : 레이아웃 전략 (overwrite|keep)}
        {--force : 버전 비교 없이 강제 업데이트}
        {--source=auto : 업데이트 소스 (auto|bundled|github) — bundled 는 _bundled 만 사용(GitHub 우회)}
        {--zip= : 외부 ZIP 파일 경로 (지정 시 GitHub/번들 우회 + 버전은 template.json 기준)}';

    /**
     * The console command description.
     */
    protected $description = '템플릿을 최신 버전으로 업데이트합니다';

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
            $this->error('❌ --source 는 auto, bundled, github 중 하나여야 합니다.');

            return Command::FAILURE;
        }

        if ($zipPath !== null && $sourceOption !== 'auto') {
            $this->error('❌ --zip 은 --source 와 동시에 지정할 수 없습니다.');

            return Command::FAILURE;
        }

        if ($zipPath !== null) {
            $resolvedZip = realpath($zipPath);
            if (! $resolvedZip || ! is_file($resolvedZip)) {
                $this->error('❌ 지정된 ZIP 파일이 존재하지 않습니다: '.$zipPath);

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
                $checkResult = $this->templateManager->checkTemplateUpdate($identifier);

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
                $this->info('업데이트 소스: ZIP ('.$zipPath.')');
                $this->info('업데이트 버전: (template.json 추출 후 판별)');
            }

            $this->info(__('templates.commands.update.layout_strategy', ['strategy' => $layoutStrategy]));

            // overwrite 전략일 때 수정된 레이아웃 경고
            if ($layoutStrategy === 'overwrite') {
                $modifiedResult = $this->templateManager->hasModifiedLayouts($identifier);

                if ($modifiedResult['has_modified_layouts']) {
                    $this->newLine();
                    $this->warn('⚠️  '.__('templates.commands.update.modified_layouts_warning', [
                        'count' => $modifiedResult['modified_count'],
                    ]));

                    foreach ($modifiedResult['modified_layouts'] as $layout) {
                        $this->warn(__('templates.commands.update.modified_layout_item', [
                            'name' => $layout['name'],
                            'date' => $layout['updated_at'],
                        ]));
                    }
                }
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
            $this->warn('💡 '.__('templates.commands.update.backup_restored'));

            Log::error('템플릿 업데이트 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
