<?php

namespace App\CoreUpdate;

use App\Exceptions\CoreUpdateOperationException;
use App\Execution\CommandRunnerResolver;
use App\Execution\CommandRunResult;
use App\Execution\Exceptions\CommandExecutionException;
use App\Execution\InProcessCommandRunner;
use App\Extension\CoreVersionChecker;
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use App\Support\ConfigCacheHelper;
use App\Support\ExecutionEnvironment;
use App\Support\RouteCacheHelper;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 웹 코어 업데이트 오케스트레이터 — **한 요청이 한 단계만** 수행한다 (A-7).
 *
 * 이 클래스는 업데이트의 **일**을 하지 않는다. 일은 전부 `CoreUpdateService` 와 기존
 * 콘솔 커맨드(`core:execute-upgrade-steps`·`core:execute-bundled-updates`)가 이미 갖고
 * 있고, 여기서는 **순서·재개·되돌리기**만 맡는다 (설계: "오케스트레이션만 신설한다").
 * 로직을 복제하면 CLI 경로와 웹 경로가 서로 다른 업데이트가 되고, 그 차이는 오류가
 * 아니라 「어느 한쪽에서만 나는 결함」으로만 드러난다.
 *
 * 요청 경계가 안전 요건인 이유(§7 R25): 파일을 교체한 프로세스는 사라진 클래스를
 * 참조해 fatal 이 난다. 특히 업그레이드 스텝은 **파일 적용 다음 요청**에서 돌아야
 * 하며, 그 요청은 신버전 코드로 새로 부팅된 프로세스다 — 무CLI 환경에서 spawn 격리를
 * 대신하는 것이 바로 이 성질이다.
 *
 * 웹 동선에 없는 선택은 이 클래스에도 없다 (D27) — 백업 필수 · 3-way 증분 고정 ·
 * 유지보수 모드 필수 · `--force`/`--prune`/`--no-backup` 미노출.
 *
 * @since 7.1.0
 */
class CoreUpdateOrchestrator
{
    /** 업그레이드 스텝 핸드오프를 이 횟수까지만 이어 받는다 (무한 왕복 차단). */
    public const MAX_HANDOFF_CONTINUATIONS = 10;

    /** 웹 경로가 업그레이드 스텝 단계에 강제하는 스폰 실패 모드 (A-7 설계 3 · §7 R26). */
    public const SPAWN_FAILURE_MODE = 'fallback';

    /** 실행 활동 로그 훅 — 착수. */
    public const HOOK_STARTED = 'core.core_update.started';

    /** 실행 활동 로그 훅 — 성공. */
    public const HOOK_COMPLETED = 'core.core_update.completed';

    /** 실행 활동 로그 훅 — 실패(되돌리기 결과 포함). */
    public const HOOK_FAILED = 'core.core_update.failed';

    /**
     * @var bool 이번 단계를 완료로 표시하지 않고 다음 요청에서 다시 수행할지 여부
     *
     * 업그레이드 스텝 핸드오프가 유일한 소비처다 — 그 신호는 「같은 단계를 새 프로세스에서
     * 다시」라는 뜻이고, 웹에서는 다음 요청이 곧 새 프로세스다.
     */
    private bool $repeatCurrentStep = false;

    /**
     * @param  CoreUpdateService  $service  코어 업데이트 서비스 (모든 실제 작업의 소유자)
     * @param  CoreUpdateRunState  $state  요청 경계를 넘는 진행 상태
     * @param  CoreUpdatePreflight  $preflight  사전 진단 (D26)
     * @param  CommandRunnerResolver  $runners  실행 모드 해석기 (무CLI 는 in-process)
     */
    public function __construct(
        private readonly CoreUpdateService $service,
        private readonly CoreUpdateRunState $state,
        private readonly CoreUpdatePreflight $preflight,
        private readonly CommandRunnerResolver $runners,
    ) {}

    /**
     * 화면이 소비하는 현재 상태를 반환합니다 (W6 4상태의 근거).
     *
     * @return array<string, mixed> 상태 표현
     */
    public function status(): array
    {
        $this->state->refresh();
        $raw = $this->state->all();

        $steps = array_map(fn (CoreUpdateStep $step): array => [
            'key' => $step->value,
            'label' => (string) __($step->labelKey()),
            'position' => $step->position(),
        ], CoreUpdateStep::sequence());

        if ($raw === []) {
            return [
                'run' => null,
                'steps' => $steps,
                'total_steps' => count($steps),
                'manual_sources' => $this->manualSources(),
            ];
        }

        $completed = $this->state->completedSteps();
        $next = $this->state->nextStep();

        return [
            'run' => [
                'run_id' => $raw['run_id'] ?? null,
                'status' => $raw['status'] ?? null,
                'from_version' => $raw['from_version'] ?? null,
                'to_version' => $raw['to_version'] ?? null,
                'options' => $raw['options'] ?? null,
                'next_step' => $next?->value,
                'completed_steps' => $completed,
                'completed_count' => count($completed),
                'current_step' => $next?->value,
                'current_step_label' => $next !== null ? (string) __($next->labelKey()) : null,
                'logs' => $this->state->logs(),
                'warnings' => $raw['warnings'] ?? [],
                'notices' => $raw['notices'] ?? [],
                'error' => $raw['error'] ?? null,
                'rollback' => $raw['rollback'] ?? null,
                'started_at' => $raw['started_at'] ?? null,
                'updated_at' => $raw['updated_at'] ?? null,
            ],
            'steps' => $steps,
            'total_steps' => count($steps),
            'manual_sources' => $this->manualSources(),
        ];
    }

    /**
     * 운영자가 올려 둔 수동 업데이트 ZIP 목록을 반환합니다 (㉯ — D18).
     *
     * @return list<array{name: string, size: int, modified_at: string}> ZIP 파일 목록
     */
    public function manualSources(): array
    {
        $directory = (string) config('app.update.pending_path');

        if ($directory === '' || ! is_dir($directory)) {
            return [];
        }

        $sources = [];

        foreach ((array) @scandir($directory) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (! is_file($path) || strtolower((string) pathinfo($entry, PATHINFO_EXTENSION)) !== 'zip') {
                continue;
            }

            $sources[] = [
                'name' => $entry,
                'size' => (int) @filesize($path),
                'modified_at' => date('c', (int) @filemtime($path)),
            ];
        }

        usort($sources, static fn (array $a, array $b): int => strcmp($b['modified_at'], $a['modified_at']));

        return $sources;
    }

    /**
     * 업데이트를 착수합니다 (아직 어떤 단계도 수행하지 않는다).
     *
     * @param  CoreUpdateOptions  $options  착수 선택
     * @param  int|null  $userId  착수한 관리자 ID
     * @return array<string, mixed> 착수 후 상태
     *
     * @throws CoreUpdateOperationException 이미 실행 중이거나 지정한 ZIP 이 없는 경우
     */
    public function start(CoreUpdateOptions $options, ?int $userId): array
    {
        $this->state->refresh();

        if ($this->state->isActive()) {
            throw new CoreUpdateOperationException('settings.core_update.already_running');
        }

        $fromVersion = CoreVersionChecker::getCoreVersion();
        $toVersion = null;

        if ($options->usesManualZip()) {
            // 파일이 실재하는지 **착수 전에** 확인한다 — 유지보수 모드를 켠 뒤에 알면
            // 사이트를 세워 놓고 되돌리는 왕복이 된다.
            $zipPath = $this->resolveManualZipPath($options);

            if ($zipPath === null) {
                throw new CoreUpdateOperationException('settings.core_update.zip_file_not_found', [
                    'path' => (string) $options->zipFile,
                ]);
            }
        } else {
            $updateInfo = $this->service->checkForUpdates();

            if (! empty($updateInfo['check_failed'])) {
                throw new CoreUpdateOperationException('settings.core_update.check_failed');
            }

            if (empty($updateInfo['update_available'])) {
                throw new CoreUpdateOperationException('settings.core_update.no_update');
            }

            $toVersion = (string) $updateInfo['latest_version'];
        }

        $runId = $this->state->start($options, $fromVersion, $toVersion, $userId);
        $this->state->appendLog((string) __('settings.core_update.web.log_started', [
            'from' => $fromVersion,
            'to' => $toVersion ?? (string) __('settings.core_update.web.version_from_zip'),
        ]));

        HookManager::doAction(self::HOOK_STARTED, $runId, $fromVersion, $toVersion, $options->toArray());

        return $this->status();
    }

    /**
     * 다음 단계를 한 번 수행합니다 (브라우저가 반복 호출한다).
     *
     * @return array<string, mixed> 수행 후 상태
     *
     * @throws CoreUpdateOperationException 진행 중인 실행이 없거나 다른 요청이 점유 중인 경우
     */
    public function advance(): array
    {
        $this->state->refresh();

        if (! $this->state->exists()) {
            throw new CoreUpdateOperationException('settings.core_update.web.no_active_run');
        }

        if (! $this->state->isActive()) {
            // 이미 끝난 실행 — 화면이 결과를 그대로 보여 주면 된다.
            return $this->status();
        }

        $token = (string) Str::uuid();

        if (! $this->state->claim($token)) {
            throw new CoreUpdateOperationException('settings.core_update.web.step_in_progress');
        }

        // 확장 호환성 자동 비활성화를 이 요청에서도 막는다 — 코어와 확장의 버전이 잠시
        // 어긋나는 구간이 업데이트의 정상 상태다. 서브프로세스 러너는 `$_ENV` 를 자식에
        // 물려주므로 이 한 줄이 자식까지 닿는다.
        $_ENV['G7_UPDATE_IN_PROGRESS'] = '1';
        $_SERVER['G7_UPDATE_IN_PROGRESS'] = '1';

        if (ExecutionEnvironment::hasFunction('putenv')) {
            putenv('G7_UPDATE_IN_PROGRESS=1');
        }

        try {
            if ($this->state->status() === CoreUpdateRunState::STATUS_ROLLING_BACK) {
                // 되돌리기도 **파일을 쓰는 구간**이다 — 단계 수행과 같은 잠금을 잡아
                // 동시에 도는 CLI `core:update` 와 겹치지 않게 한다. 형제 구간
                // (`runStepGuarded`)만 잠그면 같은 디스크를 두 프로세스가 반대 방향으로
                // 바꾸는 창이 남는다.
                $this->runRollbackGuarded();

                return $this->status();
            }

            $step = $this->state->nextStep();

            if ($step === null) {
                $this->finishSuccessfully();

                return $this->status();
            }

            $this->runStepGuarded($step);

            return $this->status();
        } finally {
            $this->state->releaseClaim($token);
        }
    }

    /**
     * 끝난 실행의 기록을 지웁니다 (운영자가 결과를 확인한 뒤).
     *
     * @return array<string, mixed> 지운 뒤 상태
     *
     * @throws CoreUpdateOperationException 아직 진행 중인 경우
     */
    public function dismiss(): array
    {
        $this->state->refresh();

        if ($this->state->isActive()) {
            throw new CoreUpdateOperationException('settings.core_update.web.still_running');
        }

        $this->state->clear();

        return $this->status();
    }

    /**
     * 한 단계를 수행하고, 실패하면 되돌리기 대기 상태로 전이합니다 (D10).
     *
     * @param  CoreUpdateStep  $step  수행할 단계
     * @return void
     */
    private function runStepGuarded(CoreUpdateStep $step): void
    {
        // 같은 디스크를 동시에 바꾸는 CLI 실행과 겹치지 않도록 이 요청 구간에서도 잠금을
        // 잡는다. `flock` 은 프로세스 수명에 묶여 요청 사이를 잇지 못하므로 요청 경계를
        // 넘는 직렬화는 상태 파일의 점유가 맡고, 이 잠금은 **동시에 도는 CLI** 를 막는다.
        $locked = $this->service->acquireRunLock();

        if (! $locked) {
            throw new CoreUpdateOperationException('settings.core_update.already_running');
        }

        $this->state->appendWarnings(CoreUpdateService::takeRunLockWarnings());

        try {
            $this->repeatCurrentStep = false;

            $this->runStep($step);

            if ($this->repeatCurrentStep) {
                // 같은 단계를 다음 요청에서 다시 — 완료로 표시하지 않는다.
                $this->state->patch(['next_step' => $step->value]);

                return;
            }

            $completed = $this->state->completedSteps();
            $completed[] = $step->value;

            $this->state->patch([
                'completed_steps' => array_values(array_unique($completed)),
                'next_step' => $step->next()?->value,
            ]);

            if ($step->next() === null) {
                $this->finishSuccessfully();
            }
        } catch (\Throwable $e) {
            $this->enterRollback($step, $e);
        } finally {
            $this->service->releaseRunLock();
        }
    }

    /**
     * 단계별 실제 작업.
     *
     * @param  CoreUpdateStep  $step  수행할 단계
     * @return void
     */
    private function runStep(CoreUpdateStep $step): void
    {
        match ($step) {
            CoreUpdateStep::Preflight => $this->stepPreflight(),
            CoreUpdateStep::Maintenance => $this->stepMaintenance(),
            CoreUpdateStep::Download => $this->stepDownload(),
            CoreUpdateStep::Backup => $this->stepBackup(),
            CoreUpdateStep::Vendor => $this->stepVendor(),
            CoreUpdateStep::Plan => $this->stepPlan(),
            CoreUpdateStep::Apply => $this->stepApply(),
            CoreUpdateStep::VendorCopy => $this->stepVendorCopy(),
            CoreUpdateStep::Migrate => $this->stepMigrate(),
            CoreUpdateStep::UpgradeSteps => $this->stepUpgradeSteps(),
            CoreUpdateStep::Bundled => $this->stepBundled(),
            CoreUpdateStep::Finalize => $this->stepFinalize(),
        };
    }

    /**
     * 사전 진단 — 쓰기 가능성·여유 공간·요구사항 (D26).
     *
     * @return void
     */
    private function stepPreflight(): void
    {
        $report = $this->preflight->run();

        $this->state->mergeContext(['preflight' => [
            'writable' => $report['writable'],
            'disk' => $report['disk'],
            'available_methods' => $report['requirements']['available_methods'] ?? [],
        ]]);

        if (! $report['ok']) {
            $this->state->appendLog((string) __('settings.core_update.web.log_preflight_failed'));

            throw new CoreUpdateOperationException('settings.core_update.web.preflight_failed', [
                'reasons' => implode(' / ', $report['errors']),
            ]);
        }

        $this->state->appendLog((string) __('settings.core_update.web.log_preflight_ok', [
            'checked' => (string) $report['writable']['checked'],
        ]));
    }

    /**
     * 유지보수 모드 활성화 (웹은 필수 — D27).
     *
     * @return void
     */
    private function stepMaintenance(): void
    {
        $secret = $this->service->enableMaintenanceMode();

        $this->state->mergeContext([
            'maintenance_enabled' => true,
            'maintenance_secret' => $secret,
        ]);

        $this->state->appendLog((string) __('settings.core_update.web.log_maintenance_on'));
    }

    /**
     * 새 버전 내려받기 또는 운영자 ZIP 추출 (㉯ — D18).
     *
     * @return void
     */
    private function stepDownload(): void
    {
        $options = $this->state->options();

        if ($options->usesManualZip()) {
            $zipPath = $this->resolveManualZipPath($options);

            if ($zipPath === null) {
                throw new CoreUpdateOperationException('settings.core_update.zip_file_not_found', [
                    'path' => (string) $options->zipFile,
                ]);
            }

            $pendingPath = $this->service->extractZipToPending($zipPath);
            $this->state->mergeContext(['pending_path' => $pendingPath]);

            $toVersion = $this->readSourceVersion($pendingPath);

            if ($toVersion === null) {
                throw new CoreUpdateOperationException('settings.core_update.invalid_package_not_g7');
            }

            // 자동 경로와 같은 버전 비교 규칙을 적용한다 (D27 — 웹에는 `--force` 가 없다).
            $fromVersion = (string) ($this->state->all()['from_version'] ?? '');

            if (version_compare($toVersion, $fromVersion, '<=')) {
                throw new CoreUpdateOperationException('settings.core_update.web.zip_not_newer', [
                    'from' => $fromVersion,
                    'to' => $toVersion,
                ]);
            }

            $this->state->patch(['to_version' => $toVersion]);
            $this->state->appendLog((string) __('settings.core_update.web.log_zip_extracted', [
                'file' => (string) $options->zipFile,
                'version' => $toVersion,
            ]));

            return;
        }

        $toVersion = (string) ($this->state->all()['to_version'] ?? '');
        $pendingPath = $this->service->downloadUpdate($toVersion);

        $this->state->mergeContext(['pending_path' => $pendingPath]);
        $this->state->appendLog((string) __('settings.core_update.web.log_downloaded', ['version' => $toVersion]));
    }

    /**
     * 백업 생성 + 소유권 스냅샷 (웹은 백업 필수 — D27, D10 의 전제).
     *
     * @return void
     */
    private function stepBackup(): void
    {
        $backupPath = $this->service->createBackup();

        $ownership = $this->service->snapshotOwnership();

        // 이번 실행이 방금 만든 격리 디렉토리(`core_{ts}`)는 스냅샷에서 제외한다 — 이 단계는
        // 내려받기(Download) **뒤**라 그 디렉토리가 이미 있고, 제외하지 않으면 이번 실행이
        // 만든 추출본이 「원본」으로 기록되어 복원이 그 잔존물의 소유권을 되돌린다. CLI 가
        // 같은 이유로 같은 제외를 건다 (7.0.0~7.0.9 실사례) — 두 경로의 강도를 맞춘다.
        $pendingPath = (string) $this->state->context('pending_path', '');
        $excludes = $pendingPath === '' ? [] : [$this->service->resolveStagingRoot($pendingPath)];

        $detailed = $this->service->snapshotOwnershipDetailed([
            'storage/logs',
            'storage/framework',
            'storage/app/core_pending',
            'bootstrap/cache',
        ], excludes: $excludes);

        $this->state->mergeContext([
            'backup_path' => $backupPath,
            'ownership' => $ownership,
            'detailed_ownership' => $detailed,
        ]);

        $this->state->appendLog((string) __('settings.core_update.web.log_backup', ['path' => basename($backupPath)]));
    }

    /**
     * `_pending` 의 vendor 구성 — 무CLI 는 번들 모드로 자동 판정된다 (A-4 와 같은 규율).
     *
     * @return void
     */
    private function stepVendor(): void
    {
        $pendingPath = (string) $this->state->context('pending_path', '');

        if ($pendingPath === '') {
            throw new CoreUpdateOperationException('settings.core_update.web.missing_pending_path');
        }

        if ($this->service->isComposerUnchangedForCore($pendingPath)) {
            $this->state->mergeContext(['composer_skipped' => true]);
            $this->state->appendLog((string) __('settings.core_update.web.log_vendor_skipped'));

            return;
        }

        $result = $this->service->runVendorInstallInPending($pendingPath, VendorMode::Auto);

        $this->state->mergeContext(['composer_skipped' => false]);
        $this->state->appendLog((string) __('settings.core_update.web.log_vendor', [
            'strategy' => $result->strategy,
            'packages' => (string) $result->packageCount,
        ]));
    }

    /**
     * 신규 파일 manifest + 3-way 증분 적용 목록 산출 (웹은 증분 고정 — D27).
     *
     * @return void
     */
    private function stepPlan(): void
    {
        $pendingPath = (string) $this->state->context('pending_path', '');
        $backupPath = (string) $this->state->context('backup_path', '');

        if ($pendingPath === '' || $backupPath === '') {
            throw new CoreUpdateOperationException('settings.core_update.web.missing_pending_path');
        }

        $raw = $this->state->all();
        $targets = (array) config('app.update.targets', []);
        $protected = (array) config('app.update.protected_paths', []);
        $excludes = (array) config('app.update.excludes', []);

        try {
            $manifestStats = CoreBackupHelper::writeNewFilesManifest(
                $backupPath,
                $pendingPath,
                $targets,
                $protected,
                $excludes,
                (string) ($raw['from_version'] ?? ''),
                (string) ($raw['to_version'] ?? ''),
            );

            $this->state->appendLog((string) __('settings.core_update.web.log_manifest', [
                'files' => (string) $manifestStats['new_files_count'],
                'dirs' => (string) $manifestStats['new_dirs_count'],
            ]));
        } catch (\Throwable $manifestError) {
            // manifest 부재는 치명이 아니다 — 되돌리기가 신규 파일 prune 없이 overlay 만
            // 수행할 뿐이다. 그 사실을 기록에 남긴다 (CLI 경로와 같은 판단).
            $this->state->appendLog((string) __('settings.core_update.web.log_manifest_failed', [
                'error' => $manifestError->getMessage(),
            ]));
            Log::warning('웹 코어 업데이트: 신규 파일 manifest 작성 실패', ['error' => $manifestError->getMessage()]);
        }

        try {
            $applyStats = CoreBackupHelper::computeApplyList($backupPath, $pendingPath, $targets, $protected, $excludes);

            $this->state->storeApplyList($applyStats['apply']);
            $this->state->mergeContext(['apply_stats' => [
                'added_count' => $applyStats['added_count'],
                'changed_count' => $applyStats['changed_count'],
                'total' => count($applyStats['apply']),
            ]]);

            $this->state->appendLog((string) __('settings.core_update.web.log_apply_list', [
                'added' => (string) $applyStats['added_count'],
                'changed' => (string) $applyStats['changed_count'],
            ]));
        } catch (\Throwable $applyError) {
            // 산출 실패 시 안전하게 전체 덮어쓰기로 회귀 (CLI 와 동일).
            $this->state->storeApplyList(null);
            $this->state->mergeContext(['apply_stats' => null]);
            $this->state->appendLog((string) __('settings.core_update.web.log_apply_list_failed', [
                'error' => $applyError->getMessage(),
            ]));
            Log::warning('웹 코어 업데이트: 증분 적용 대상 산출 실패', ['error' => $applyError->getMessage()]);
        }
    }

    /**
     * 코어 파일 적용 — 이 요청 이후의 단계는 신버전 코드로 부팅된 요청에서 돈다.
     *
     * @return void
     */
    private function stepApply(): void
    {
        $pendingPath = (string) $this->state->context('pending_path', '');

        if ($pendingPath === '') {
            throw new CoreUpdateOperationException('settings.core_update.web.missing_pending_path');
        }

        // 반환값이 계약이다 — 게터는 반환값을 손에 쥘 수 없는 자리의 편의일 뿐이다.
        $result = $this->service->applyUpdate(
            $pendingPath,
            null,
            prune: false,
            applyList: $this->state->loadApplyList(),
        );

        $this->state->appendWarnings((array) ($result['root_file_warnings'] ?? []));
        $this->state->appendLog((string) __('settings.core_update.web.log_applied'));

        // 컴파일 캐시를 **파일 삭제만으로** 비운다. 여기서 `clearAllCaches()` 를 부르면
        // `package:discover`·`extension:update-autoload` 가 방금 교체된 파일을 이 구버전
        // 프로세스에서 로드한다 — R25 가 말하는 바로 그 자리다. 남은 정리는 다음 요청
        // (신버전으로 부팅됨)과 마무리 단계가 맡는다.
        $this->dropCompiledCaches();
    }

    /**
     * vendor 디렉토리 복사 (_pending → 운영).
     *
     * @return void
     */
    private function stepVendorCopy(): void
    {
        if ($this->state->context('composer_skipped') === true) {
            $this->state->appendLog((string) __('settings.core_update.web.log_vendor_copy_skipped'));

            return;
        }

        $pendingPath = (string) $this->state->context('pending_path', '');

        if ($pendingPath === '') {
            throw new CoreUpdateOperationException('settings.core_update.web.missing_pending_path');
        }

        $this->service->copyVendorFromPending($pendingPath);
        $this->state->appendLog((string) __('settings.core_update.web.log_vendor_copied'));
    }

    /**
     * 마이그레이션 + 코어 config 재로드·권한/메뉴 재동기.
     *
     * @return void
     */
    private function stepMigrate(): void
    {
        $this->service->runMigrations();

        // `syncCoreRolesAndPermissions`/`syncCoreMenus` 를 직접 부르지 않는다 — 부팅 시점에
        // 캐시된 구버전 config 로 sync 가 돌면 신규 권한·메뉴가 누락된다 (#326 회귀).
        $this->service->reloadCoreConfigAndResync();

        $this->state->appendLog((string) __('settings.core_update.web.log_migrated'));
    }

    /**
     * 업그레이드 스텝 — **파일 적용과 다른 요청**에서만 실행한다 (순서 불변식).
     *
     * @return void
     */
    private function stepUpgradeSteps(): void
    {
        $raw = $this->state->all();
        $fromVersion = (string) ($this->state->context('upgrade_resume_from') ?? ($raw['from_version'] ?? ''));
        $toVersion = (string) ($raw['to_version'] ?? '');

        $this->forceSpawnFailureFallback();

        $result = $this->runArtisan('core:execute-upgrade-steps', [
            '--from' => $fromVersion,
            '--to' => $toVersion,
            '--steps-only' => true,
            '--force' => true,
        ]);

        $handoff = $this->parseLineProtocol($result->output, '[HANDOFF] ');

        if ($handoff !== null) {
            $this->continueAfterHandoff($handoff, $toVersion);

            return;
        }

        if (! $result->successful()) {
            throw new CoreUpdateOperationException('settings.core_update.web.upgrade_steps_failed', [
                'output' => $this->tailOutput($result->output),
            ]);
        }

        $executed = $this->parseLineProtocol($result->output, '[STEPS_EXECUTED] ');

        $this->state->mergeContext(['upgrade_resume_from' => null]);
        $this->state->appendLog((string) __('settings.core_update.web.log_upgrade_steps', [
            'count' => (string) ((int) ($executed['count'] ?? 0)),
        ]));
    }

    /**
     * 번들 확장·언어팩 일괄 업데이트 (운영자 선택 — D28 · D29).
     *
     * @return void
     */
    private function stepBundled(): void
    {
        $options = $this->state->options();

        if (! $options->updateBundledExtensions) {
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_skipped'));

            return;
        }

        $manifest = $this->buildBundledManifest($options);

        if ($manifest === null) {
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_none'));

            return;
        }

        [$manifestPath, $payload] = $manifest;

        try {
            $result = $this->runArtisan('core:execute-bundled-updates', [
                '--manifest' => $manifestPath,
                '--force' => true,
            ]);

            $summary = $this->parseLineProtocol($result->output, '[BUNDLED-RESULT] ') ?? [];

            $this->state->mergeContext(['bundled_result' => [
                'success' => (int) ($summary['success'] ?? 0),
                'failed' => (int) ($summary['failed'] ?? 0),
                'planned' => $payload['planned'],
            ]]);

            $this->state->appendLog((string) __('settings.core_update.web.log_bundled', [
                'success' => (string) ((int) ($summary['success'] ?? 0)),
                'failed' => (string) ((int) ($summary['failed'] ?? 0)),
            ]));

            if (! $result->successful()) {
                // 확장 일괄 업데이트의 실패는 코어 적용을 되돌릴 사유가 아니다 — 코어는
                // 이미 정상 반영되어 있고, 되돌리면 오히려 성공한 확장 업데이트와
                // 어긋난다. 사실만 통보하고 진행한다.
                $this->state->appendWarnings([(string) __('settings.core_update.web.bundled_partial_failure', [
                    'failed' => (string) ((int) ($summary['failed'] ?? 0)),
                ])]);
            }
        } finally {
            if (is_file($manifestPath)) {
                @unlink($manifestPath);
            }
        }
    }

    /**
     * 마무리 — 버전 기록·캐시·소유권 복원·링크 복구·정리·유지보수 해제.
     *
     * @return void
     */
    private function stepFinalize(): void
    {
        $raw = $this->state->all();
        $toVersion = (string) ($raw['to_version'] ?? '');

        if ($toVersion !== '') {
            $this->service->updateVersionInEnv($toVersion);
        }

        $this->service->clearAllCaches();

        // 상주 큐 워커는 부팅이 한 번뿐이라 옛 코드를 물고 있다 — CLI 와 같은 자리(캐시가
        // 새 코드 기준으로 정리된 직후)에서 재시작 신호를 보낸다. 신호 전송 실패는 업데이트
        // 결과를 되돌리지 않는다(서비스가 경고만 남긴다). 무CLI 호스팅처럼 워커가 없는
        // 사이트에서는 캐시 키 하나를 쓰고 끝난다.
        $this->service->signalQueueRestart();

        $this->restoreOwnershipSnapshot();

        StorageLinkHelper::ensurePublicStorageLink();

        $pendingPath = (string) $this->state->context('pending_path', '');

        if ($pendingPath !== '') {
            $this->service->cleanupPending($pendingPath);
        }

        $backupPath = (string) $this->state->context('backup_path', '');

        if ($backupPath !== '') {
            CoreBackupHelper::deleteBackup($backupPath);
        }

        $this->disableMaintenanceIfEnabled();

        // 모든 파일이 안착한 뒤에만 캐시를 되살린다 — 흐름 중간에 구우면 그 시점의
        // 반쪽 상태가 박제된다.
        ConfigCacheHelper::rebuild();
        RouteCacheHelper::rebuild();

        $this->state->appendLog((string) __('settings.core_update.web.log_finalized', ['version' => $toVersion]));
    }

    /**
     * 업그레이드 스텝 핸드오프를 다음 요청으로 이어 받습니다.
     *
     * CLI 는 여기서 운영자에게 재실행 명령을 안내하고 끝낸다 — 새 PHP 프로세스가
     * 필요한데 그 자리에서 만들 수 없기 때문이다. 웹 단계 실행에서는 **다음 요청이
     * 곧 새 프로세스**이므로, 안내 대신 이어서 진행한다.
     *
     * @param  array<string, mixed>  $handoff  `[HANDOFF]` 페이로드
     * @param  string  $toVersion  대상 버전
     * @return void
     */
    private function continueAfterHandoff(array $handoff, string $toVersion): void
    {
        $afterVersion = (string) ($handoff['afterVersion'] ?? '');
        $reason = (string) ($handoff['reason'] ?? '');
        $count = (int) $this->state->context('handoff_count', 0) + 1;

        if ($afterVersion === '' || $count > self::MAX_HANDOFF_CONTINUATIONS) {
            throw new CoreUpdateOperationException('settings.core_update.web.handoff_exhausted', [
                'after' => $afterVersion !== '' ? $afterVersion : '-',
                'reason' => $reason,
            ]);
        }

        $this->state->mergeContext([
            'upgrade_resume_from' => $afterVersion,
            'handoff_count' => $count,
        ]);

        // 같은 단계를 다음 요청에서 다시 수행한다 (`to` 는 그대로 두고 남은 스텝만).
        $this->repeatCurrentStep = true;

        $this->state->appendLog((string) __('settings.core_update.web.log_handoff', [
            'after' => $afterVersion,
            'to' => $toVersion,
            'reason' => $reason,
        ]));
    }

    /**
     * 실패를 기록하고 되돌리기 대기 상태로 전이합니다 (D10 — 조작 없이 자동).
     *
     * @param  CoreUpdateStep  $step  실패한 단계
     * @param  \Throwable  $e  실패 사유
     * @return void
     */
    private function enterRollback(CoreUpdateStep $step, \Throwable $e): void
    {
        $raw = $this->state->all();

        $reportPath = null;

        try {
            $reportPath = $this->service->generateFailureReport(
                $e,
                (string) ($raw['from_version'] ?? ''),
                (string) ($raw['to_version'] ?? ''),
            );
        } catch (\Throwable $reportError) {
            Log::warning('웹 코어 업데이트: 실패 리포트 생성 실패', ['error' => $reportError->getMessage()]);
        }

        // 되돌리기 **이전**에 루트 진입·보호 파일 결과를 배수한다 — `restoreFromBackup()`
        // 이 경고 버퍼를 비우므로 그 뒤에만 읽으면 사라진다.
        $this->state->appendWarnings($this->service->getLastRootFileWarnings());

        $this->state->patch([
            'status' => CoreUpdateRunState::STATUS_ROLLING_BACK,
            'error' => [
                'step' => $step->value,
                'step_label' => (string) __($step->labelKey()),
                'message' => $e->getMessage(),
                'report_path' => $reportPath,
            ],
        ]);

        $this->state->appendLog((string) __('settings.core_update.web.log_step_failed', [
            'step' => (string) __($step->labelKey()),
            'error' => $e->getMessage(),
        ]));

        Log::error('웹 코어 업데이트 단계 실패', [
            'step' => $step->value,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * 되돌리기를 수행합니다.
     *
     * @return void
     */
    private function runRollbackGuarded(): void
    {
        $locked = $this->service->acquireRunLock();

        if (! $locked) {
            throw new CoreUpdateOperationException('settings.core_update.already_running');
        }

        $this->state->appendWarnings(CoreUpdateService::takeRunLockWarnings());

        try {
            $this->performRollback();
        } finally {
            $this->service->releaseRunLock();
        }
    }

    /**
     * 백업본으로 되돌립니다 (D10 — 운영자 조작 없이).
     *
     * @return void
     */
    private function performRollback(): void
    {
        $backupPath = (string) $this->state->context('backup_path', '');
        $applied = in_array(CoreUpdateStep::Apply->value, $this->state->completedSteps(), true);
        $restored = false;
        $failedTargets = [];

        if ($backupPath !== '' && is_dir($backupPath)) {
            try {
                $result = $this->service->restoreFromBackup($backupPath);
                $failedTargets = (array) ($result['failed_targets'] ?? []);
                $restored = $failedTargets === [];

                $this->state->appendWarnings((array) ($result['root_file_warnings'] ?? []));
                $this->state->appendLog($restored
                    ? (string) __('settings.core_update.web.log_restored')
                    : (string) __('settings.core_update.web.log_restored_partial', [
                        'targets' => implode(', ', array_map('strval', $failedTargets)),
                    ]));
            } catch (\Throwable $restoreError) {
                $this->state->appendLog((string) __('settings.core_update.web.log_restore_failed', [
                    'error' => $restoreError->getMessage(),
                ]));
                Log::error('웹 코어 업데이트: 백업 복원 실패', ['error' => $restoreError->getMessage()]);
            }

            // 컴파일 캐시는 **파일만 지운다** — 여기서 `clearAllCaches()` 를 부르면 그 안의
            // `package:discover`·`extension:update-autoload` 가 방금 구버전으로 되돌린 디스크를
            // 신버전 클래스를 든 프로세스에서 다시 발견한다. 파일 적용 단계가 같은 이유로
            // 커맨드를 부르지 않는 것과 같은 규율이다 (§7 R25).
            $this->dropCompiledCaches();

            // 되돌리기는 파일을 원래대로 돌려놓는 것으로 끝나지 않는다 — 소유권·퍼미션까지
            // 되돌려야 「사이트는 정상 동작합니다」가 성립한다 (§7 R29). 복원하지 않으면
            // php-fpm 계정이 `storage`·`bootstrap/cache` 에 쓰지 못해 되돌린 사이트가
            // 500 으로 뜬다. 성공 경로(`stepFinalize`)와 같은 강도로 적용한다.
            $this->restoreOwnershipSnapshot();
        } else {
            // 아직 파일을 건드리지 않았다 — 되돌릴 것이 없는 것이 정상이다.
            $restored = ! $applied;
        }

        $pendingPath = (string) $this->state->context('pending_path', '');

        if ($pendingPath !== '') {
            try {
                $this->service->cleanupPending($pendingPath);
            } catch (\Throwable $cleanupError) {
                Log::warning('웹 코어 업데이트: _pending 정리 실패', ['error' => $cleanupError->getMessage()]);
            }
        }

        if ($restored) {
            $this->disableMaintenanceIfEnabled();
        }

        $this->state->patch([
            'status' => $restored
                ? CoreUpdateRunState::STATUS_ROLLED_BACK
                : CoreUpdateRunState::STATUS_ROLLBACK_FAILED,
            'rollback' => [
                'restored' => $restored,
                'files_were_applied' => $applied,
                'failed_targets' => array_values(array_map('strval', $failedTargets)),
                'backup_path' => $backupPath !== '' ? $backupPath : null,
                'maintenance_left_on' => ! $restored && $this->state->context('maintenance_enabled') === true,
            ],
        ]);

        $raw = $this->state->all();

        HookManager::doAction(
            self::HOOK_FAILED,
            (string) ($raw['run_id'] ?? ''),
            (string) ($raw['from_version'] ?? ''),
            (string) ($raw['to_version'] ?? ''),
            (array) ($raw['error'] ?? []),
            (array) ($raw['rollback'] ?? []),
        );
    }

    /**
     * 모든 단계를 마친 실행을 완료로 표시합니다.
     *
     * @return void
     */
    private function finishSuccessfully(): void
    {
        if ($this->state->status() === CoreUpdateRunState::STATUS_COMPLETED) {
            return;
        }

        $this->state->patch(['status' => CoreUpdateRunState::STATUS_COMPLETED, 'next_step' => null]);

        $raw = $this->state->all();

        HookManager::doAction(
            self::HOOK_COMPLETED,
            (string) ($raw['run_id'] ?? ''),
            (string) ($raw['from_version'] ?? ''),
            (string) ($raw['to_version'] ?? ''),
            (array) ($raw['context']['bundled_result'] ?? []),
        );
    }

    /**
     * 유지보수 모드를 켜 두었으면 해제합니다.
     *
     * @return void
     */
    private function disableMaintenanceIfEnabled(): void
    {
        if ($this->state->context('maintenance_enabled') !== true) {
            return;
        }

        try {
            $this->service->disableMaintenanceMode();
            $this->state->mergeContext(['maintenance_enabled' => false]);
            $this->state->appendLog((string) __('settings.core_update.web.log_maintenance_off'));
        } catch (\Throwable $e) {
            $this->state->appendWarnings([(string) __('settings.core_update.web.maintenance_off_failed', [
                'error' => $e->getMessage(),
            ])]);
        }
    }

    /**
     * 번들 확장 일괄 업데이트 매니페스트를 만듭니다.
     *
     * 전역 레이아웃 전략(D29)을 모든 항목에 그대로 싣는다 — 확장별 예외는 웹에 두지
     * 않고 개별 확장 업데이트 화면으로 안내한다.
     *
     * @param  CoreUpdateOptions  $options  착수 선택
     * @return array{0: string, 1: array<string, mixed>}|null 매니페스트 경로와 페이로드 (대상이 없으면 null)
     */
    private function buildBundledManifest(CoreUpdateOptions $options): ?array
    {
        $updates = $this->service->collectBundledExtensionUpdates();
        $updates['lang_packs'] = app(LanguagePackService::class)->collectBundledLangPackUpdates();

        $manifest = ['modules' => [], 'plugins' => [], 'templates' => [], 'lang_packs' => []];
        $planned = 0;

        foreach (['modules', 'plugins', 'templates'] as $type) {
            foreach ((array) ($updates[$type] ?? []) as $entry) {
                $manifest[$type][] = [
                    'identifier' => (string) ($entry['identifier'] ?? ''),
                    'strategy' => $options->layoutStrategy,
                ];
                $planned++;
            }
        }

        foreach ((array) ($updates['lang_packs'] ?? []) as $entry) {
            $manifest['lang_packs'][] = ['identifier' => (string) ($entry['identifier'] ?? '')];
            $planned++;
        }

        if ($planned === 0) {
            return null;
        }

        $manifest['rebuild_search_index'] = $options->rebuildSearchIndex;

        $directory = storage_path('app'.DIRECTORY_SEPARATOR.'core_pending');
        File::ensureDirectoryExists($directory, 0770, true);

        // 이름 규약은 생산자 상수와 같은 것을 쓴다 — 정리 주체(`StorageLeftoverPruneService`)가
        // 그 접두사로 회수하므로, 다른 이름을 쓰면 이 파일만 영구히 남는다.
        $path = $directory.DIRECTORY_SEPARATOR.'bundled-updates-manifest_'.Str::uuid().'.json';

        File::put($path, (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$path, ['planned' => $planned]];
    }

    /**
     * 시작 전 스냅샷으로 소유권·퍼미션을 되돌리고, 실패는 경고로 노출합니다 (§7 R29).
     *
     * 성공(`stepFinalize`)과 되돌리기(`performRollback`) **양쪽**이 이 한 지점을 지난다 —
     * 한쪽에만 두면 되돌린 사이트가 쓰기 권한을 잃은 채 남고, 그 상태는 오류가 아니라
     * 「되돌렸는데 사이트가 안 뜬다」로만 드러난다.
     *
     * @return void
     */
    private function restoreOwnershipSnapshot(): void
    {
        $ownership = (array) $this->state->context('ownership', []);
        $detailed = (array) $this->state->context('detailed_ownership', []);

        if ($ownership === [] && $detailed === []) {
            // 백업 단계 전에 실패했다 — 되돌릴 스냅샷 자체가 없다.
            return;
        }

        try {
            $this->service->restoreOwnership($ownership, null, $detailed);
        } catch (\Throwable $e) {
            $this->state->appendWarnings([(string) __('settings.core_update.web.ownership_restore_failed', [
                'error' => $e->getMessage(),
            ])]);

            return;
        }

        foreach ($this->service->getLastPermissionWarnings() as $warning) {
            $this->state->appendWarnings([(string) __('settings.core_update.web.permission_warning', [
                'target' => (string) ($warning['target'] ?? '?'),
                'failed' => (string) ((int) ($warning['failed'] ?? 0)),
            ])]);
        }
    }

    /**
     * 업그레이드 스텝 단계에 한해 스폰 실패 모드를 `fallback` 으로 강제합니다 (A-7 설계 3 · §7 R26).
     *
     * `CoreUpdateService::runUpgradeSteps` 의 stale 메모리 가드는 `config('app.version')` 이
     * 목표 버전보다 낮으면 「부모가 구버전 코드를 들고 있다」로 판정하고, 기본 모드(`abort`)
     * 에서는 그 자리에서 핸드오프를 던진다. CLI 는 자식 프로세스 env 에 `APP_VERSION=toVersion`
     * 을 실어 그 판정을 피한다.
     *
     * 웹에서는 그 판정의 **전제 자체가 성립하지 않는다** — 이 단계는 파일 적용과 다른(이후의)
     * 요청이라 이미 신버전 코드로 부팅된 프로세스이고, `.env` 의 `APP_VERSION` 은 Finalize
     * 단계까지 옛 값으로 남는다. 강제하지 않으면 매 요청이 같은 판정을 반복해 핸드오프가
     * 상한까지 소진되고, **정상 적용된 코어가 되돌려진다**.
     *
     * `config()` 는 in-process 러너를, `$_ENV`/`$_SERVER` 는 서브프로세스 자식을 덮는다
     * (`SubprocessCommandRunner::inheritedEnvironment()` 가 `$_ENV` 를 마지막에 병합한다).
     * `putenv` 는 쓰지 않는다 — 그 값은 프로세스 수명에 남아 같은 워커의 **다음 요청**까지
     * 모드를 바꾼다.
     *
     * @return void
     */
    private function forceSpawnFailureFallback(): void
    {
        config(['app.update.spawn_failure_mode' => self::SPAWN_FAILURE_MODE]);

        $_ENV['G7_UPDATE_SPAWN_FAILURE_MODE'] = self::SPAWN_FAILURE_MODE;
        $_SERVER['G7_UPDATE_SPAWN_FAILURE_MODE'] = self::SPAWN_FAILURE_MODE;
    }

    /**
     * 러너를 경유해 Artisan 커맨드를 실행합니다.
     *
     * 스폰이 불가능한 환경에서는 **in-process 로 강제 전환**한다 (§7 R26). 웹 요청 안에서
     * 스폰 재시도는 의미가 없고, 단계 실행 자체가 이미 요청 경계 격리를 제공한다.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string, mixed>  $parameters  파라미터
     * @return CommandRunResult 실행 결과
     */
    private function runArtisan(string $command, array $parameters): CommandRunResult
    {
        try {
            return $this->runners->resolve()->run($command, $parameters);
        } catch (CommandExecutionException $e) {
            $this->state->appendLog((string) __('settings.core_update.web.log_spawn_fallback', [
                'reason' => $e->getMessage(),
            ]));

            return (new InProcessCommandRunner)->run($command, $parameters);
        }
    }

    /**
     * 출력에서 `[표식] {json}` 형태의 라인을 찾아 페이로드를 복원합니다.
     *
     * @param  string  $output  커맨드 출력
     * @param  string  $prefix  라인 접두사
     * @return array<string, mixed>|null 페이로드 (없으면 null)
     */
    private function parseLineProtocol(string $output, string $prefix): ?array
    {
        foreach (preg_split('~\r\n|\r|\n~', $output) ?: [] as $line) {
            $line = trim((string) $line);

            if (! str_starts_with($line, $prefix)) {
                continue;
            }

            $decoded = json_decode(substr($line, strlen($prefix)), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * 실패 문구에 실을 만큼만 출력 꼬리를 잘라 냅니다.
     *
     * @param  string  $output  커맨드 출력
     * @return string 마지막 몇 줄
     */
    private function tailOutput(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('~\r\n|\r|\n~', $output) ?: [])));

        return implode(' / ', array_slice($lines, -5));
    }

    /**
     * 운영자가 올린 ZIP 의 절대 경로를 돌려줍니다 (㉯ — D18).
     *
     * 디렉토리는 서버가 정하고 이름만 받는다. 해석 결과가 그 디렉토리 밖을 가리키면
     * 거부한다 — 이름만 받아도 심볼릭 링크로 밖을 가리킬 수 있다.
     *
     * @param  CoreUpdateOptions  $options  착수 선택
     * @return string|null 절대 경로 (없거나 범위를 벗어나면 null)
     */
    private function resolveManualZipPath(CoreUpdateOptions $options): ?string
    {
        if (! $options->usesManualZip()) {
            return null;
        }

        $directory = realpath((string) config('app.update.pending_path'));

        if ($directory === false) {
            return null;
        }

        $path = realpath($directory.DIRECTORY_SEPARATOR.$options->zipFile);

        if ($path === false || ! is_file($path)) {
            return null;
        }

        if (! str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    /**
     * 소스의 `config/app.php` 에서 버전을 읽습니다.
     *
     * `env()` 를 우회해 기본값 리터럴을 직접 읽는다 — 소스의 `.env` 는 이 사이트의 것이
     * 아니므로 `include` 결과가 이 사이트 값으로 오염될 수 있다.
     *
     * @param  string  $sourcePath  소스 루트
     * @return string|null 버전 (읽지 못하면 null)
     */
    private function readSourceVersion(string $sourcePath): ?string
    {
        $configPath = $sourcePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';

        if (! is_file($configPath)) {
            return null;
        }

        $contents = (string) @file_get_contents($configPath);

        if (preg_match('/[\'"]version[\'"]\s*=>\s*env\s*\(\s*[\'"]APP_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $contents, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * 컴파일 캐시 파일을 지웁니다 (Artisan 을 부르지 않는다).
     *
     * 파일 교체 직후의 구버전 프로세스에서 커맨드를 돌리면 사라진 클래스를 로드한다.
     * 여기서는 **파일만 지워** 다음 요청이 디스크에서 새로 부팅하게 한다.
     *
     * @return void
     */
    private function dropCompiledCaches(): void
    {
        $app = app();

        foreach ([
            $app->getCachedConfigPath(),
            $app->getCachedRoutesPath(),
            $app->getCachedServicesPath(),
            $app->getCachedPackagesPath(),
            $app->getCachedEventsPath(),
        ] as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        clearstatcache(true);

        if (ExecutionEnvironment::hasFunction('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * 점검 모드 우회 쿠키를 돌려줍니다 (진행 중 관리자 화면 접근 보장).
     *
     * 업데이트는 스스로 점검 모드를 켜는데, 그 상태에서 운영자가 관리자 화면을 볼 수
     * 없으면 진행도 복구도 눈으로 확인할 수 없다. 진행·되돌리기 **엔드포인트**는
     * `MaintenanceModePage` 의 면제 목록이 따로 열어 두고, 이 쿠키는 그 밖의 관리자
     * 화면(SPA 셸·다른 조회 API)까지 열어 준다 — 둘 중 하나만으로는 부족하다.
     *
     * 점검 모드가 꺼져 있거나 비밀값을 읽을 수 없으면 null 이다.
     *
     * @return Cookie|null 우회 쿠키
     */
    public function maintenanceBypassCookie(): ?Cookie
    {
        $downFile = storage_path('framework'.DIRECTORY_SEPARATOR.'down');

        if (! is_file($downFile)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($downFile), true);
        $secret = is_array($data) ? ($data['secret'] ?? null) : null;

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return MaintenanceModeBypassCookie::create($secret);
    }

    /**
     * 착수 화면이 보여 줄 사전 조건 진단 요약 (D26 · W6 「사전 조건 미충족」 상태).
     *
     * 실행 1단계(`stepPreflight`)와 **같은 판정기**를 쓴다 — 화면이 자기 기준으로 다시
     * 재면 「화면은 된다는데 눌러 보면 막히는」 상태가 성립한다. 다른 것은 결과의 쓰임뿐이다:
     * 여기서는 착수를 막지 않고 보여만 주고, 실제 차단은 1단계가 한다.
     *
     * 아카이브 추출 요건은 여기서 재지 않는다(`requiresArchiveExtraction: false`) —
     * 수동 ZIP 인지 자동 내려받기인지는 착수 시점에 정해지고, 그 전에 「추출 수단이
     * 없다」를 띄우면 수동 소스 디렉토리를 쓰는 운영자에게 거짓 경고가 된다.
     *
     * **얕게 본다**(`deep: false`). 하위 전수 순회와 트리 크기 추정은 이 저장소에서만
     * 13초가 걸려(실측) 화면을 여는 요청이 그만큼 멈춘다. 얕은 판정도 D26 이 겨냥한
     * 경우(sudo 로 올려 웹 계정이 대상 디렉토리에 못 쓰는 서버)를 그대로 드러내며,
     * 전수 판정은 착수 1단계가 소유한다 — 그쪽이 진짜 게이트다.
     *
     * @return array{ok: bool, blocked: list<string>, checked: int, disk: array<string, mixed>, errors: list<string>} 요약
     */
    public function preflightSummary(): array
    {
        $report = $this->preflight->run(requiresArchiveExtraction: false, deep: false);

        return [
            'ok' => (bool) $report['ok'],
            'blocked' => array_values(array_map('strval', $report['writable']['blocked'] ?? [])),
            'checked' => (int) ($report['writable']['checked'] ?? 0),
            'disk' => $report['disk'],
            'errors' => array_values(array_map('strval', $report['errors'] ?? [])),
        ];
    }

    /**
     * 번들 확장·언어팩 중 수정된 레이아웃 개수를 셉니다 (W6 보존 권장 안내 — D29).
     *
     * @return array{has_modified: bool, count: int, extensions: list<string>} 집계
     */
    public function modifiedLayoutSummary(): array
    {
        $updates = $this->service->collectBundledExtensionUpdates();

        $managers = [
            'modules' => app(ModuleManager::class),
            'plugins' => app(PluginManager::class),
            'templates' => app(TemplateManager::class),
        ];

        $count = 0;
        $extensions = [];

        foreach ($managers as $type => $manager) {
            foreach ((array) ($updates[$type] ?? []) as $entry) {
                $identifier = (string) ($entry['identifier'] ?? '');

                if ($identifier === '') {
                    continue;
                }

                try {
                    $result = $manager->hasModifiedLayouts($identifier);
                } catch (\Throwable) {
                    continue;
                }

                if (($result['has_modified_layouts'] ?? false) === true) {
                    $count += (int) ($result['modified_count'] ?? 0);
                    $extensions[] = $identifier;
                }
            }
        }

        return [
            'has_modified' => $extensions !== [],
            'count' => $count,
            'extensions' => $extensions,
        ];
    }
}
