<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\ReportsUpdateCheckErrors;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use App\Support\RuntimeRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckTemplateUpdatesCommand extends Command
{
    use LocalizesDescription;
    use ReportsUpdateCheckErrors;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:check-updates {identifier? : 특정 템플릿만 확인 (선택)}';

    /**
     * 템플릿 관리자 및 리포지토리를 주입받는다.
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
     * 템플릿 업데이트를 확인한다 — 식별자를 주면 그 템플릿만, 없으면 설치된 전부.
     *
     * @return int 종료 코드
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
     * @return int 종료 코드 (미설치·GitHub 조회 실패면 1)
     */
    private function checkSingle(string $identifier): int
    {
        $template = $this->templateRepository->findByIdentifier($identifier);

        if (! $template) {
            $this->error('❌ '.__('templates.commands.check_updates.not_installed', ['template' => $identifier]));

            return Command::FAILURE;
        }

        $result = $this->templateManager->checkTemplateUpdate($identifier);

        // GitHub 조회에 실패했으면(check_error) DB 의 업데이트 정보를 덮지 않는다 — 번들 대체 판정은 출력으로만 보인다
        $checkFailed = isset($result['check_error']);

        // 단일 체크는 DB 갱신이 안 되므로 커맨드에서 직접 갱신
        // 검수 서버(stage)는 공유 DB 의 업데이트 정보를 쓰지 않는다 — 결과만 출력한다(전체 확인과 같은 규칙)
        if (! RuntimeRole::isStage() && ! $checkFailed) {
            $this->templateRepository->updateByIdentifier($identifier, [
                'update_available' => $result['update_available'],
                'latest_version' => $result['latest_version'],
                'update_source' => $result['update_source'],
            ]);
        }

        if ($result['update_available']) {
            $this->info('🔄 '.__('templates.commands.check_updates.single_update_available', [
                'template' => $identifier,
                'current' => $result['current_version'],
                'latest' => $result['latest_version'],
                'source' => $result['update_source'],
            ]));
        } elseif (! $checkFailed) {
            $this->info('✅ '.__('templates.commands.check_updates.single_up_to_date', [
                'template' => $identifier,
                'version' => $result['current_version'],
            ]));
        }

        return $this->updateCheckFailuresExitCode($checkFailed ? [['identifier' => $identifier, 'reason' => (string) $result['check_error']]] : []);
    }

    /**
     * 모든 설치된 템플릿의 업데이트를 확인합니다.
     *
     * @return int 종료 코드 (GitHub 조회에 실패한 확장이 하나라도 있으면 1)
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
            $isUpdate = (bool) ($detail['update_available'] ?? true);
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
            'total' => $result['checked_count'] ?? count($result['details']),
            'updates' => $updateCount,
        ]));

        // GitHub 조회에 실패한 확장이 있으면 실패 표 + 종료 코드 1 (최신으로 보이지 않게)
        return $this->updateCheckFailuresExitCode($result['failures'] ?? []);
    }
}
