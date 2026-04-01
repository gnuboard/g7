<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Traits\HasProgressBar;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateTemplateCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:update
        {identifier : 업데이트할 템플릿 식별자}
        {--layout-strategy=overwrite : 레이아웃 전략 (overwrite|keep)}
        {--force : 버전 비교 없이 강제 업데이트}';

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

        // 레이아웃 전략 검증
        if (! in_array($layoutStrategy, ['overwrite', 'keep'])) {
            $this->error('❌ '.__('templates.commands.update.invalid_strategy'));

            return Command::FAILURE;
        }

        try {
            $this->templateManager->loadTemplates();

            // 템플릿 존재 확인
            $template = $this->templateRepository->findByIdentifier($identifier);

            if (! $template) {
                $this->error('❌ '.__('templates.commands.update.not_installed', ['template' => $identifier]));

                return Command::FAILURE;
            }

            // 업데이트 확인
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
            if (! $force && ! $this->confirm(__('templates.commands.update.confirm_question'), false)) {
                $this->info(__('templates.commands.update.aborted'));

                return Command::SUCCESS;
            }

            // 업데이트 실행
            $onProgress = $this->createProgressCallback(TemplateManager::UPDATE_STEPS);
            try {
                $updateResult = $this->templateManager->updateTemplate($identifier, $layoutStrategy, $force, $onProgress);
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
