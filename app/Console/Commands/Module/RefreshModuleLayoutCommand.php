<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Concerns\SelectsLayoutInstallSource;
use App\Console\Commands\Traits\RendersLayoutSyncResult;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Models\Module;
use App\Services\ModuleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 모듈 레이아웃 갱신 커맨드
 *
 * 레이아웃만 수정된 경우(소스 SSoT `.g7.html` 또는 짝 `.json`), 빌드 없이 레이아웃만 갱신합니다.
 *
 * 설치 소스는 `--layout-source` 가 결정한다 — 생략하면 설정(`LAYOUT_INSTALL_SOURCE`)을 따르고, `auto` 는 `.g7.html` 우선이며
 * `json` 은 짝 `.json` 이 있으면 그것으로 뒤로 물러선다(진단·복구용).
 */
class RefreshModuleLayoutCommand extends Command
{
    use RendersLayoutSyncResult;
    use ReportsLifecycleWarnings;
    use SelectsLayoutInstallSource;

    /**
     * 커맨드 이름 및 시그니처
     */
    protected $signature = 'module:refresh-layout
        {identifier? : 특정 모듈의 식별자 (생략 시 모든 활성 모듈)}
        {--layout-source= : 레이아웃 설치 소스 (auto|json) — 생략하면 설정(LAYOUT_INSTALL_SOURCE)을 따름. json 은 짝 .json 이 있으면 .g7.html 대신 원본 JSON 으로 등록(진단·복구용)}';

    /**
     * 커맨드 설명
     */
    protected $description = '모듈 레이아웃을 갱신합니다 (빌드 없이 레이아웃만 DB에 동기화)';

    /**
     * 커맨드를 생성합니다.
     *
     * @param  ModuleService  $moduleService  레이아웃 갱신을 수행하는 서비스
     */
    public function __construct(
        private ModuleService $moduleService
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // `--layout-source` 검증 + 런타임 반영 (알 수 없는 값이면 즉시 실패).
        if (! $this->applyLayoutInstallSource()) {
            return self::FAILURE;
        }

        $identifier = $this->argument('identifier');

        try {
            if ($identifier) {
                return $this->refreshSingle($identifier);
            }

            return $this->refreshAll();
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 레이아웃 갱신 실패', [
                'module' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 특정 모듈의 레이아웃을 갱신합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return int 종료 코드
     */
    private function refreshSingle(string $identifier): int
    {
        $this->info(__('modules.commands.refresh_layout.refreshing', ['module' => $identifier]));

        $result = $this->moduleService->refreshModuleLayouts($identifier);

        if ($result) {
            $this->info($this->lifecycleDoneIcon().' '.__('modules.commands.refresh_layout.success', ['module' => $identifier]));
            $this->renderLayoutIncludeWarnings($result['layout_warnings'] ?? []);
            Log::info('모듈 레이아웃 갱신 완료', ['module' => $identifier]);

            return Command::SUCCESS;
        }

        $this->error('❌ '.__('modules.commands.refresh_layout.failed', ['module' => $identifier]));

        return Command::FAILURE;
    }

    /**
     * 모든 활성 모듈의 레이아웃을 갱신합니다.
     *
     * @return int 종료 코드
     */
    private function refreshAll(): int
    {
        $modules = Module::where('status', 'active')->pluck('identifier')->toArray();

        if (empty($modules)) {
            $this->line(__('modules.commands.refresh_layout.none_active'));

            return Command::SUCCESS;
        }

        $this->info(__('modules.commands.refresh_layout.refreshing_all'));

        $successCount = 0;
        $failCount = 0;

        foreach ($modules as $moduleIdentifier) {
            $itemMark = $this->lifecycleMark();
            $this->line('  '.__('modules.commands.refresh_layout.item_refreshing', ['identifier' => $moduleIdentifier]));

            try {
                $result = $this->moduleService->refreshModuleLayouts($moduleIdentifier);

                if ($result) {
                    $this->info('  '.$this->lifecycleDoneIcon($itemMark).' '.__('modules.commands.refresh_layout.item_done', ['identifier' => $moduleIdentifier]));
                    $this->renderLayoutIncludeWarnings($result['layout_warnings'] ?? []);
                    $successCount++;
                } else {
                    $this->warn('  ⚠️ '.__('modules.commands.refresh_layout.item_failed', ['identifier' => $moduleIdentifier]));
                    $failCount++;
                }
            } catch (\Exception $e) {
                $this->warn("  ⚠️ {$moduleIdentifier}: ".$e->getMessage());
                $failCount++;
            }
        }

        $this->newLine();
        $this->info($this->lifecycleDoneIcon().' '.__('modules.commands.refresh_layout.summary', ['success' => $successCount, 'failed' => $failCount]));

        Log::info('모듈 레이아웃 일괄 갱신 완료', [
            'success' => $successCount,
            'failed' => $failCount,
        ]);

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
