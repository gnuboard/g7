<?php

namespace App\Console\Commands\Template;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckTemplateUpdatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:check-updates {identifier? : 특정 템플릿만 확인 (선택)}';

    /**
     * The console command description.
     */
    protected $description = '템플릿 업데이트를 확인합니다';

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
            $this->templateManager->loadTemplates();

            if ($identifier) {
                return $this->checkSingle($identifier);
            }

            return $this->checkAll();
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('템플릿 업데이트 확인 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 단일 템플릿 업데이트를 확인합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     */
    private function checkSingle(string $identifier): int
    {
        $template = $this->templateRepository->findByIdentifier($identifier);

        if (! $template) {
            $this->error('❌ '.__('templates.commands.check_updates.not_installed', ['template' => $identifier]));

            return Command::FAILURE;
        }

        $result = $this->templateManager->checkTemplateUpdate($identifier);

        // 단일 체크는 DB 갱신이 안 되므로 커맨드에서 직접 갱신
        $this->templateRepository->updateByIdentifier($identifier, [
            'update_available' => $result['update_available'],
            'latest_version' => $result['latest_version'],
            'update_source' => $result['update_source'],
        ]);

        if ($result['update_available']) {
            $this->info('🔄 '.__('templates.commands.check_updates.single_update_available', [
                'template' => $identifier,
                'current' => $result['current_version'],
                'latest' => $result['latest_version'],
                'source' => $result['update_source'],
            ]));
        } else {
            $this->info('✅ '.__('templates.commands.check_updates.single_up_to_date', [
                'template' => $identifier,
                'version' => $result['current_version'],
            ]));
        }

        return Command::SUCCESS;
    }

    /**
     * 모든 설치된 템플릿의 업데이트를 확인합니다.
     */
    private function checkAll(): int
    {
        $installedTemplates = $this->templateManager->getInstalledTemplatesWithDetails();

        if (empty($installedTemplates)) {
            $this->info(__('templates.commands.check_updates.no_installed'));

            return Command::SUCCESS;
        }

        // checkAllTemplatesForUpdates는 내부에서 DB 갱신 포함
        $result = $this->templateManager->checkAllTemplatesForUpdates();

        $tableData = [];
        $updateCount = 0;

        foreach ($result['details'] as $detail) {
            $isUpdate = $detail['update_available'] ?? false;
            if ($isUpdate) {
                $updateCount++;
            }

            $tableData[] = [
                $detail['identifier'],
                $detail['current_version'] ?? '-',
                $detail['latest_version'] ?? '-',
                $detail['update_source'] ?? '-',
                $isUpdate
                    ? '🔄 '.__('templates.commands.check_updates.update_available')
                    : '✅ '.__('templates.commands.check_updates.up_to_date'),
            ];
        }

        $headers = [
            __('templates.commands.check_updates.headers.identifier'),
            __('templates.commands.check_updates.headers.current_version'),
            __('templates.commands.check_updates.headers.latest_version'),
            __('templates.commands.check_updates.headers.source'),
            __('templates.commands.check_updates.headers.status'),
        ];

        $this->table($headers, $tableData);
        $this->newLine();
        $this->info(__('templates.commands.check_updates.summary', [
            'total' => count($result['details']),
            'updates' => $updateCount,
        ]));

        return Command::SUCCESS;
    }
}
