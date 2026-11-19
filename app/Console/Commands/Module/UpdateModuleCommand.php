<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Concerns\HandlesPackageIntegrity;
use App\Console\Commands\Concerns\PreviewsExtensionUpdate;
use App\Console\Commands\Concerns\SelectsLayoutInstallSource;
use App\Console\Commands\Traits\HasProgressBar;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\RebuildsSearchIndex;
use App\Console\Commands\Traits\RendersLayoutSyncResult;
use App\Console\Commands\Traits\ReportsIntegrationNotices;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Console\Commands\Traits\ReportsStuckUpdateRecovery;
use App\Console\Commands\Traits\ReportsUpdateCheckErrors;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Exceptions\ExtensionUpdateFailedException;
use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\ModuleManager;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\Vendor\VendorMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateModuleCommand extends Command
{
    use HandlesPackageIntegrity;
    use HasProgressBar;
    use HasUnifiedConfirm;
    use LocalizesDescription;
    use PreviewsExtensionUpdate;
    use RebuildsSearchIndex;
    use RendersLayoutSyncResult;
    use ReportsIntegrationNotices;
    use ReportsLifecycleWarnings;
    use ReportsStuckUpdateRecovery;
    use ReportsUpdateCheckErrors;
    use SelectsLayoutInstallSource;

    /**
     * 커맨드 이름 및 시그니처
     */
    protected $signature = 'module:update
        {identifier : 업데이트할 모듈 식별자}
        {--force : 버전 비교 없이 강제 업데이트}
        {--y|yes : 확인 질문만 생략 (--force 의 다른 효과 없이)}
        {--vendor-mode=auto : Vendor 설치 모드 (auto|composer|bundled)}
        {--layout-strategy=overwrite : 레이아웃 전략 (overwrite|keep)}
        {--source=auto : 업데이트 소스 (auto|bundled|github) — bundled 는 _bundled 만 사용(GitHub 우회)}
        {--zip= : 외부 ZIP 파일 경로 (지정 시 GitHub/번들 우회 + 버전은 module.json 기준)}
        {--allow-unsigned : 서명되지 않은 패키지(또는 이 서버에서 서명을 검증할 수 없는 패키지)를 확인 없이 계속 — 답할 수 없는 실행(cron·CI)은 이 옵션이 없으면 실패. --force·--yes 는 이 확인을 대신하지 않음. 환경설정 「미서명 패키지 차단」이 켜져 있으면 무시}
        {--signature= : --zip 의 서명 파일(.sig.json) 경로 — 생략하면 ZIP 옆 <zip>.sig.json 을 자동으로 씀}
        {--layout-source= : 레이아웃 설치 소스 (auto|json) — 생략하면 설정(LAYOUT_INSTALL_SOURCE)을 따름. json 은 짝 .json 이 있으면 .g7.html 대신 원본 JSON 으로 등록(진단·복구용)}
        {--dry-run : 업데이트하지 않고 레이아웃 판정과 지워질 선언 항목만 출력 (DB·파일 무변경)}
        {--json : --dry-run 결과를 JSON 으로 출력}
        {--decisions= : 화면별 결정 JSON 파일 — {"layout:템플릿:화면": "take-theirs|keep-ours"}}
        {--rebuild-search-index : 완료 후 색인이 누락된 검색 인덱스를 재생성 (인덱스가 잠기거나 재색인됩니다 — 운영 중에는 유지보수 시간에 수행하세요)}';

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

        // 미리보기 — 업데이트하지 않고 관리자 [업데이트] 창과 같은 판정을 낸다.
        if ($this->option('dry-run')) {
            return $this->runUpdatePreview('module', $identifier, $sourceOverride, $layoutStrategy);
        }

        // 패키지 무결성 선택(--allow-unsigned·--signature) — 서명 파일이 없으면 시작하지 않는다(7.2.0)
        $integrityOptions = $this->packageIntegrityOptionsFromInput();
        if ($integrityOptions === null) {
            return Command::FAILURE;
        }

        $layoutDecisions = $this->layoutRefreshDecisions();

        if ($layoutDecisions === null) {
            return Command::FAILURE;
        }

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

                // GitHub 를 명시했는데 조회에 실패했으면 「업데이트 없음」으로 끝내지 않는다(소스 자동이면 번들 판정 + 경고)
                if ($this->reportUpdateCheckError($checkResult, $sourceOverride, $identifier)) {
                    return Command::FAILURE;
                }

                if (! $checkResult['update_available'] && ! $force) {
                    // 이 서버의 업데이트가 끝나지 않은 채 남았으면 「업데이트 없음」으로 끝내지 않는다 — DB 판은 이미 새 판이다(#888)
                    if ($this->refuseUnfinishedUpdate('module', $identifier)) {
                        return Command::FAILURE;
                    }

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

            // 확인 프롬프트 (--force 시 건너뜀).
            // 확인에 답할 수 없는 실행(cron, CI, 원격 명령, --no-interaction)은 종전에 기본값 「아니오」로 취소 문구만 내고
            // 종료 코드 0 으로 끝나 자동화가 성공으로 읽었다. 그런 실행은 사유와 --force 안내를 남기고 실패로 끝낸다.
            // 대화형에서 운영자가 직접 「아니오」를 답한 취소는 의도이므로 종전대로 0 이다.
            if (! $force) {
                if (! $this->canAnswerConfirmation()) {
                    return $this->refuseUnanswerableConfirmation(
                        '❌ '.__('modules.commands.update.confirm_unavailable', ['module' => $identifier]),
                        __('modules.commands.update.confirm_unavailable_hint', ['module' => $identifier]),
                    );
                }

                if (! $this->unifiedConfirm(__('modules.commands.update.confirm_question'), false)) {
                    $this->info(__('modules.commands.update.aborted'));

                    return $this->confirmAbortExitCode();
                }
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
                // 패키지 서명 검증 — 미서명이면 대화형은 묻고, 답할 수 없는 실행은 --allow-unsigned 없이는 실패(7.2.0)
                $updateResult = $this->withLayoutDecisions($layoutDecisions, fn () => $this->runWithIntegrityConsent(fn (PackageIntegrityOptions $integrity) => $this->moduleManager->updateModule(
                    $identifier,
                    $force,
                    $onProgress,
                    $vendorMode,
                    $layoutStrategy,
                    $onUpgradeStep,
                    $sourceOverride,
                    $zipPath,
                    $integrity,
                ), $integrityOptions));
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
                $this->renderIntegrationNotices($updateResult['integration_notices'] ?? []);
                $this->renderPackageIntegrity($updateResult['integrity'] ?? null);

                Log::info('모듈 업데이트 완료', [
                    'module' => $identifier,
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                    'layout_strategy' => $layoutStrategy,
                ]);

                // 검색 인덱스 재생성은 운영자가 선택했을 때만 수행한다 (인덱스 잠금·재색인 비용).
                // 여기서 난 오류는 업데이트 실패가 아니다 — 업데이트는 이미 끝났다. 바깥 catch 로 보내면 ❌ 와 함께
                // 「업데이트 실패」로 끝나므로, 경고 목록에 넣어 「완료했지만 경고 있음」(종료코드 1)으로 알린다.
                try {
                    $this->handleSearchIndexRebuild();
                } catch (\Throwable $searchError) {
                    LifecycleWarnings::current()->add('search_index', $identifier, $searchError->getMessage());
                    Log::warning('모듈 업데이트 뒤 검색 인덱스 재생성 실패', ['module' => $identifier, 'error' => $searchError->getMessage()]);
                }

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
