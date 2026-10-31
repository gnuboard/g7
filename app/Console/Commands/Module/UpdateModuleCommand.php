<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Concerns\SelectsLayoutInstallSource;
use App\Console\Commands\Traits\HasProgressBar;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\RebuildsSearchIndex;
use App\Console\Commands\Traits\RendersLayoutSyncResult;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Console\Commands\Traits\ReportsStuckUpdateRecovery;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Exceptions\ExtensionUpdateFailedException;
use App\Extension\ModuleManager;
use App\Extension\Vendor\VendorMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateModuleCommand extends Command
{
    use HasProgressBar;
    use HasUnifiedConfirm;
    use RebuildsSearchIndex;
    use RendersLayoutSyncResult;
    use ReportsLifecycleWarnings;
    use ReportsStuckUpdateRecovery;
    use SelectsLayoutInstallSource;

    /**
     * 커맨드 이름 및 시그니처
     */
    protected $signature = 'module:update
        {identifier : 업데이트할 모듈 식별자}
        {--force : 버전 비교 없이 강제 업데이트}
        {--vendor-mode=auto : Vendor 설치 모드 (auto|composer|bundled)}
        {--layout-strategy=overwrite : 레이아웃 전략 (overwrite|keep)}
        {--source=auto : 업데이트 소스 (auto|bundled|github) — bundled 는 _bundled 만 사용(GitHub 우회)}
        {--zip= : 외부 ZIP 파일 경로 (지정 시 GitHub/번들 우회 + 버전은 module.json 기준)}
        {--layout-source= : 레이아웃 설치 소스 (auto|json) — 생략하면 설정(LAYOUT_INSTALL_SOURCE)을 따름. json 은 짝 .json 이 있으면 .g7.html 대신 원본 JSON 으로 등록(진단·복구용)}
        {--rebuild-search-index : 완료 후 색인이 누락된 검색 인덱스를 재생성 (인덱스가 잠기거나 재색인됩니다 — 운영 중에는 유지보수 시간에 수행하세요)}';

    /**
     * 커맨드 설명
     */
    protected $description = '모듈을 최신 버전으로 업데이트합니다';

    /**
     * 모듈 관리자 및 리포지토리
     *
     * @param  ModuleManager  $moduleManager  모듈 업데이트를 수행할 매니저
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 설치 정보 조회 리포지토리
     */
    public function __construct(
        private ModuleManager $moduleManager,
        private ModuleRepositoryInterface $moduleRepository
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
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
        $force = $this->option('force');
        $layoutStrategy = (string) $this->option('layout-strategy');
        $sourceOption = (string) $this->option('source');
        $zipPath = $this->option('zip');

        if (! in_array($layoutStrategy, ['overwrite', 'keep'], true)) {
            $this->error('❌ '.__('extensions.commands.update.invalid_layout_strategy'));

            return Command::FAILURE;
        }

        if (! in_array($sourceOption, ['auto', 'bundled', 'github'], true)) {
            $this->error('❌ '.__('extensions.commands.update.invalid_source'));

            return Command::FAILURE;
        }

        // --zip 과 --source 동시 지정 금지 (zip 이 우선 대상이므로)
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
            $this->moduleManager->loadModules();

            // 모듈 존재 확인
            $module = $this->moduleRepository->findByIdentifier($identifier);

            if (! $module) {
                $this->error('❌ '.__('modules.commands.update.not_installed', ['module' => $identifier]));

                return Command::FAILURE;
            }

            // 멈춘·진행 중 업데이트는 업데이트 확인(「업데이트 없음」)보다 먼저 안내한다(7.1.1 출시 전)
            if ($this->refuseInProgressUpdate($module, $identifier, (bool) $force)) {
                return Command::FAILURE;
            }

            // 업데이트 확인 (--zip 모드는 GitHub/번들 비교를 우회하므로 스킵)
            if ($zipPath === null) {
                $checkResult = $this->moduleManager->checkModuleUpdate($identifier, $sourceOverride);

                if (! $checkResult['update_available'] && ! $force) {
                    $this->info('✅ '.__('modules.commands.update.no_update', ['module' => $identifier]));

                    return Command::SUCCESS;
                }

                // 업데이트 정보 표시
                $this->info(__('modules.commands.update.current_version', ['version' => $checkResult['current_version']]));

                if ($force && ! $checkResult['update_available']) {
                    $this->warn('⚠️  '.__('modules.commands.update.force_mode'));
                } else {
                    $this->info(__('modules.commands.update.latest_version', ['version' => $checkResult['latest_version']]));
                    $this->info(__('modules.commands.update.update_source', ['source' => $checkResult['update_source']]));
                }
            } else {
                $this->info(__('modules.commands.update.current_version', ['version' => $module->version]));
                $this->info(__('extensions.commands.update.zip_source', ['path' => $zipPath]));
                $this->info(__('extensions.commands.update.zip_version_pending', ['manifest' => 'module.json']));
            }

            $this->info(__('modules.commands.update.layout_strategy', ['strategy' => $layoutStrategy]));

            // overwrite 전략일 때 덮어쓰일 운영자 수정본 경고 — 레이아웃 + 확장 조각을 한 판정(isOperatorModified)으로 (§5.9)
            if ($layoutStrategy === 'overwrite') {
                $this->warnModifiedLayoutsBeforeOverwrite($this->moduleManager->hasModifiedLayouts($identifier), 'modules');
            }

            $this->newLine();

            // 확인 프롬프트 (--force 시 건너뜀)
            if (! $force && ! $this->unifiedConfirm(__('modules.commands.update.confirm_question'), false)) {
                $this->info(__('modules.commands.update.aborted'));

                return Command::SUCCESS;
            }

            // 업데이트 실행
            $vendorMode = VendorMode::fromStringOrAuto((string) $this->option('vendor-mode'));
            $onProgress = $this->createProgressCallback(ModuleManager::UPDATE_STEPS);

            // upgrade step 콘솔 출력 콜백: progress bar 를 잠시 지우고 별도 줄에 출력
            $onUpgradeStep = function (string $version) use ($identifier): void {
                if ($this->progressBar) {
                    $this->progressBar->clear();
                }
                $this->line(__('extensions.commands.update.upgrade_step_running', ['identifier' => $identifier, 'version' => $version]));
                if ($this->progressBar) {
                    $this->progressBar->display();
                }
            };

            try {
                $updateResult = $this->moduleManager->updateModule(
                    $identifier,
                    $force,
                    $onProgress,
                    $vendorMode,
                    $layoutStrategy,
                    $onUpgradeStep,
                    $sourceOverride,
                    $zipPath,
                );
                $this->finishProgress();
                $this->reportStuckUpdateRecovery(suggestActivate: true);
            } catch (\Exception $e) {
                // 아무것도 바꾸기 전에 거절됐으면(다른 업데이트 진행 중·상태 가드·소스 없음) 진행 표시줄을 끝까지 채우지 않고 지운다 —
                // 종전에는 「11/11」로 다 된 것처럼 끝났다. 업데이트 도중 실패는 Manager 가 ExtensionUpdateFailedException 으로 감싼다.
                if (! $e instanceof ExtensionUpdateFailedException && $this->progressBar) {
                    $this->progressBar->clear();
                    $this->progressBar = null;
                }
                $this->finishProgress();
                $this->reportStuckUpdateRecovery(suggestActivate: true, updateFailed: true);
                throw $e;
            }

            if ($updateResult['success']) {
                $this->newLine();
                $this->info($this->lifecycleDoneIcon().' '.__('modules.commands.update.success', ['module' => $identifier]));
                $this->info('   '.__('modules.commands.update.version_change', [
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                ]));

                // 레이아웃 처리 결과 (D2) — 운영자가 고친 화면이 있을 때만 표 + 요약 한 줄
                $this->renderLayoutSyncResult($updateResult['layout_sync'] ?? null);
                $this->renderLayoutIncludeWarnings($updateResult['layout_warnings'] ?? []);
                $this->renderDependencyWarnings($updateResult);

                Log::info('모듈 업데이트 완료', [
                    'module' => $identifier,
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                    'layout_strategy' => $layoutStrategy,
                ]);

                // 검색 인덱스 재생성은 운영자가 선택했을 때만 수행한다 (인덱스 잠금·재색인 비용)
                $this->handleSearchIndexRebuild();

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            // 실패 뒤 실제 상태로 안내한다 — 종전에는 원인과 무관하게 「복원되었습니다」를 냈다(새 판이 남은 경우에도).
            // 실패 뒤 상태 안내는 업데이트를 시작한 뒤의 실패(ExtensionUpdateFailedException)에만 붙인다 — 시작 전에 거절된 경우
            // (다른 업데이트 진행 중 등)는 아무것도 바꾸지 않았으므로 거절 사유만 남긴다. 종전에는 「복원되었습니다」가 붙었다.
            if ($e instanceof ExtensionUpdateFailedException) {
                $this->warn('💡 '.($e->outcome === 'restored'
                    ? __('modules.commands.update.backup_restored')
                    : __('extensions.commands.update.failure_outcome.'.$e->outcome)));
            }

            Log::error('모듈 업데이트 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
