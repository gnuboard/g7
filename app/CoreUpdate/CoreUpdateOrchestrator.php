<?php

namespace App\CoreUpdate;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LanguagePackStatus;
use App\Exceptions\CoreUpdateOperationException;
use App\Exceptions\PackageIntegrityException;
use App\Execution\CommandRunnerResolver;
use App\Execution\CommandRunResult;
use App\Execution\Exceptions\CommandExecutionException;
use App\Execution\InProcessCommandRunner;
use App\Extension\CoreVersionChecker;
use App\Extension\Custom\CustomUpdatePreflight;
use App\Extension\Helpers\BundledShippedManifest;
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\Helpers\StuckUpdateRecovery;
use App\Extension\HookManager;
use App\Extension\Integrity\PackageVerification;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\ExtensionBatchUpdateContext;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Listeners\ExtensionUpdateQueueListener;
use App\Models\User;
use App\Services\Core\BundledPlacementPlan;
use App\Services\Core\BundledSourcePlanner;
use App\Services\Core\PhpRequirementPreflight;
use App\Services\CoreUpdateService;
use App\Services\ExtensionStaticCacheService;
use App\Services\LanguagePackService;
use App\Support\ComposerInstallInfo;
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

    /**
     * 적용 대상 산출을 이 횟수까지만 나눠 이어 받는다 (무한 왕복 차단).
     *
     * 매 요청이 적어도 한 항목은 진행하므로 끝나지 않을 수는 없지만, 항목 하나가 요청
     * 하나를 다 쓰는 서버에서 수만 번 왕복하는 것은 진행이 아니라 고착이다.
     */
    public const MAX_PLAN_SLICES = 500;

    /**
     * 시간 초과 등으로 끊긴 되돌리기를 다시 시도하는 최대 횟수.
     *
     * 되돌리기(백업 덮어쓰기)는 같은 일을 다시 해도 결과가 같다 — 그래서 끊기면 다음 요청이
     * 처음부터 다시 한다. 다만 매번 같은 자리에서 끊기는 서버에서 무한히 반복하면 사이트가
     * 점검 모드에 갇힌 채 아무도 모른다. 이 횟수를 넘기면 되돌리기 실패로 확정하고 수동
     * 복구 안내를 연다.
     */
    public const MAX_ROLLBACK_ATTEMPTS = 3;

    /**
     * 끊긴 번들 항목의 자식 프로세스를 기다리는 상한(초).
     *
     * 확장 하나의 업데이트는 보통 수십 초, 큰 마이그레이션이 있어도 몇 분이다. 이 시간을 넘겨도 끝나지 않으면 멈춘 것으로 보고
     * 더 기다리지 않는다 — 사이트를 점검 모드에 무한히 가두지 않는다(그 항목은 「완료 여부 확인 불가」로 알린다).
     */
    public const BUNDLED_CHILD_WAIT_SECONDS = 900;

    /** 점유에 기록하는 되돌리기 구간 이름 (단계 값과 겹치지 않는다). */
    public const PHASE_ROLLBACK = 'rollback';

    /** 마무리 단계의 구간 순서 — 화면 진행량(`step_progress`)의 구간 수로만 쓴다. */
    private const FINALIZE_PHASES = ['prepare', 'cleanup', 'ownership', 'link'];

    /** 되돌리기의 구간 순서 — 화면 진행량(`step_progress`)의 구간 수로만 쓴다. */
    private const ROLLBACK_PHASES = ['vendor', 'restore', 'ownership', 'cleanup'];

    /** 진행 중 선택 종류 — 새로 들어온 미배치 공식 번들 배치 (7.2.1, 명령줄 `--bundled-sources` 와 같은 질문). */
    public const SELECTION_BUNDLED_SOURCES = 'bundled_sources';

    /** 진행 중 선택 종류 — 번들 확장·언어팩 업데이트 항목과 확장별 레이아웃 전략 (7.2.1). */
    public const SELECTION_BUNDLED_UPDATES = 'bundled_updates';

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

    /** 이 요청의 실행 시간 예산 (점유 만료 창 · 나눠 처리하는 단계의 몫). */
    private readonly CoreUpdateTimeBudget $budget;

    /**
     * @param  CoreUpdateService  $service  코어 업데이트 서비스 (모든 실제 작업의 소유자)
     * @param  CoreUpdateRunState  $state  요청 경계를 넘는 진행 상태
     * @param  CoreUpdatePreflight  $preflight  사전 진단 (D26)
     * @param  CommandRunnerResolver  $runners  실행 모드 해석기 (무CLI 는 in-process)
     * @param  CoreUpdateTimeBudget|null  $budget  실행 시간 예산 (테스트 주입용, 기본은 이 요청의 ini 값)
     */
    public function __construct(
        private readonly CoreUpdateService $service,
        private readonly CoreUpdateRunState $state,
        private readonly CoreUpdatePreflight $preflight,
        private readonly CommandRunnerResolver $runners,
        ?CoreUpdateTimeBudget $budget = null,
    ) {
        $this->budget = $budget ?? new CoreUpdateTimeBudget;
    }

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

        // 화면이 단계 요청 응답을 얼마나 기다릴지 — 서버 요청 수명 + 여유. 화면 API 기본 제한
        // (30초)으로 부르면 그보다 긴 단계는 서버가 계속 도는데 화면만 끊겨 멈춘다.
        $requestSeconds = $this->budget->probe();
        $timeBudget = [
            'request_seconds' => $requestSeconds,
            'client_wait_seconds' => $requestSeconds > 0
                ? $requestSeconds + CoreUpdateTimeBudget::CLIENT_GRACE_SECONDS
                : CoreUpdateRunState::CLAIM_STALE_SECONDS + CoreUpdateTimeBudget::CLIENT_GRACE_SECONDS,
        ];

        if ($raw === []) {
            return [
                'run' => null,
                'steps' => $steps,
                'total_steps' => count($steps),
                'manual_sources' => $this->manualSources(),
                'current_version' => CoreVersionChecker::getCoreVersion(),
                'time_budget' => $timeBudget,
            ];
        }

        $completed = $this->state->completedSteps();
        $next = $this->state->nextStep();
        $currentItem = $this->bundledCurrentItem($raw, $next);

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
                'log_seq' => $this->state->logSequence(),
                'warnings' => $raw['warnings'] ?? [],
                'notices' => $raw['notices'] ?? [],
                'error' => $raw['error'] ?? null,
                'rollback' => $raw['rollback'] ?? null,
                'started_at' => $raw['started_at'] ?? null,
                'updated_at' => $raw['updated_at'] ?? null,
                // 번들 단계 결과(7.2.1) — 성공·실패·건너뜀 수, 실패 항목과 다시 시도할 명령, 검색 인덱스 결과. 번들 단계 전이면 null
                'bundled_result' => $this->bundledResultFor($raw),
                // 점검 모드가 켜진 채 남았는지(7.2.1) — 되돌리기 실패·점검 해제 실패
                'maintenance_left_on' => $this->maintenanceLeftOn($raw),
                // 업데이트를 시작한 관리자(7.2.1) — 다른 관리자가 진행 중인지 화면이 알린다
                'started_by' => $this->startedByFor($raw),
                // 실행이 끝날 때 남긴 실행 기록 파일(7.2.1, 사이트 루트 기준 상대 경로) — [기록 정리] 뒤에도 남는다
                'log_file' => is_string($raw['log_file'] ?? null) ? $raw['log_file'] : null,
                // 서버가 아직 어떤 단계를 돌리는 중인지 — 화면은 응답이 끊겨도 이 값으로 점유가
                // 풀릴 때까지 기다렸다가 스스로 다시 부른다.
                'claim' => $this->state->claimSummary(),
                // 운영자의 선택을 기다리는 중이면 그 내용(7.2.1) — 화면은 다음 단계를 부르지 않고 선택 창을 그린다.
                'awaiting' => $this->state->context('awaiting'),
                // 번들 단계가 다음에 처리할 항목(7.2.1) — 그 밖에는 null
                'current_item' => $currentItem,
                // 지금 단계가 여러 요청에 걸쳐 어디까지 갔는지(7.2.1) — 그 밖에는 null
                'step_progress' => $this->stepProgressFor($raw, $next, $currentItem),
            ],
            'steps' => $steps,
            'total_steps' => count($steps),
            'manual_sources' => $this->manualSources(),
            'current_version' => $this->currentVersionFor($raw),
            'time_budget' => $timeBudget,
        ];
    }

    /**
     * 화면의 「현재 버전」 칸에 보일 버전.
     *
     * 이 값을 업데이트 상태 응답에 싣는 이유: 그 칸이 서버 정보 응답(`system-info`)에만 기대면
     * 그 응답이 느린 서버에서 완료 화면이 「현재 버전 -」 아래에 「현재 버전은 7.x 입니다」를
     * 함께 보여 준다. 완료된 실행은 대상 버전이 곧 현재 버전이다 — 마무리 단계를 수행한 요청은
     * 옛 설정을 들고 있으므로 설정값으로 읽으면 한 판 늦다.
     *
     * @param  array<string, mixed>  $raw  상태 원본
     * @return string 버전
     */
    private function currentVersionFor(array $raw): string
    {
        $installed = CoreVersionChecker::getCoreVersion();

        if (($raw['status'] ?? null) === CoreUpdateRunState::STATUS_COMPLETED
            && is_string($raw['to_version'] ?? null) && $raw['to_version'] !== '') {
            // 완료 기록이 남아 있는 동안 명령줄·같은 DB 의 다른 서버로 더 높은 판이 올라갔을 수 있다 —
            // 둘 중 높은 판을 고른다(기록 7.2.1, 실제 7.2.3).
            return version_compare($raw['to_version'], $installed, '>=') ? $raw['to_version'] : $installed;
        }

        return $installed;
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
     * @throws PackageIntegrityException 배포본 서명이 맞지 않거나·미신뢰 키거나·미서명 확인이 필요한 경우 (착수 전, 7.2.0)
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

            // 패키지 서명 검증(7.2.0) — 유지보수 모드를 켜기 전에 미서명 확인(409)·불일치(422)로 멈춘다.
            // 서명 파일은 같은 폴더의 `{zip}.sig.json` 을 자동으로 쓴다. 내려받기 단계에서 한 번 더 확인한다.
            $this->service->verifyCoreZipIntegrity($zipPath, $options->integrityOptions());
        } else {
            $updateInfo = $this->service->checkForUpdates();

            if (! empty($updateInfo['check_failed'])) {
                throw new CoreUpdateOperationException('settings.core_update.check_failed');
            }

            if (empty($updateInfo['update_available'])) {
                throw new CoreUpdateOperationException('settings.core_update.no_update');
            }

            $toVersion = (string) $updateInfo['latest_version'];

            // 릴리스에 서명 자산이 없으면 착수 전에 미서명 정책을 묻는다(7.2.0) — 실제 검증은 내려받은 뒤
            $this->service->preflightReleaseIntegrity($toVersion, $options->integrityOptions());
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

        // 실행 시간 제한을 이 단계 기준으로 다시 걸고, 실제로 적용된 수명을 점유에 선언한다 —
        // 선언이 실제보다 길면 시간 초과로 죽은 요청의 점유가 그만큼 남아 사이트가 점검
        // 모드에 갇힌다(종전 고정 15분).
        $this->budget->begin();

        $phase = $this->state->status() === CoreUpdateRunState::STATUS_ROLLING_BACK
            ? self::PHASE_ROLLBACK
            : $this->state->nextStep()?->value;

        if (! $this->state->claim($token, $phase, $this->budget->claimStaleSeconds())) {
            throw new CoreUpdateOperationException('settings.core_update.web.step_in_progress');
        }

        $reclaimed = $this->state->lastReclaimedClaim();

        // 시간 초과·메모리 초과 같은 치명 오류는 아래 `finally` 를 건너뛴다. 그대로 두면 그
        // 단계는 실패로도 성공으로도 기록되지 않고 점유만 남아, 자동 되돌리기가 돌지 않는다.
        register_shutdown_function(function () use ($token): void {
            $this->handleFatalShutdown($token);
        });

        // 확장 호환성 자동 비활성화를 이 요청에서도 막는다 — 코어와 확장의 버전이 잠시
        // 어긋나는 구간이 업데이트의 정상 상태다. 서브프로세스 러너는 `$_ENV` 를 자식에
        // 물려주므로 이 한 줄이 자식까지 닿는다.
        $_ENV['G7_UPDATE_IN_PROGRESS'] = '1';
        $_SERVER['G7_UPDATE_IN_PROGRESS'] = '1';

        if (ExecutionEnvironment::hasFunction('putenv')) {
            putenv('G7_UPDATE_IN_PROGRESS=1');
        }

        try {
            if ($reclaimed !== null && $phase !== null && ($reclaimed['step'] ?? null) === $phase) {
                // 앞 요청이 이 구간을 돌던 중에 기록 없이 사라졌다(프로세스가 신호로 죽어 종료
                // 처리도 돌지 못한 경우). 같은 단계를 다시 돌리면 같은 자리에서 또 죽으므로
                // 실패로 기록하고 되돌리기로 넘긴다 — 되돌리기 구간이면 재시도 횟수를 센다.
                $this->promoteInterruptedPhase($phase, (string) __('settings.core_update.web.step_interrupted_unknown'));
            } elseif ($this->state->status() === CoreUpdateRunState::STATUS_ROLLING_BACK) {
                // 되돌리기도 **파일을 쓰는 구간**이다 — 단계 수행과 같은 잠금을 잡아
                // 동시에 도는 CLI `core:update` 와 겹치지 않게 한다. 형제 구간
                // (`runStepGuarded`)만 잠그면 같은 디스크를 두 프로세스가 반대 방향으로
                // 바꾸는 창이 남는다.
                $this->runRollbackGuarded();
            } elseif (($step = $this->state->nextStep()) === null) {
                $this->finishSuccessfully();
            } else {
                $this->runStepGuarded($step);
            }
        } finally {
            $this->state->releaseClaim($token);
        }

        // 점유를 놓은 **뒤에** 상태를 읽는다 — 앞에서 읽으면 응답이 이 요청 자신의 점유를
        // 「아직 진행 중」으로 싣는다.
        return $this->status();
    }

    /**
     * 요청 종료 처리 — 치명 오류로 끊긴 단계를 실패로 기록하고 점유를 놓습니다.
     *
     * `advance()` 가 단계마다 등록한다. 정상 종료에는 할 일이 없다(점유는 이미 놓였다).
     * 실행 시간 초과·메모리 초과 같은 치명 오류로 끝났으면 그 단계를 실패로 기록해
     * 다음 호출이 백업본으로 되돌리게 하고(D10), 점유를 바로 놓아 화면이 기다리지 않게 한다.
     * 되돌리기 도중에 끊겼으면 재시도 횟수를 센다.
     *
     * 이 처리가 돌지 못하는 종료(프로세스를 신호로 죽이는 서버 설정)는 다음 요청이 점유의
     * 만료 창을 보고 같은 판정을 한다(`advance()` 의 회수 갈래).
     *
     * @param  string  $token  이 요청의 점유 토큰
     * @param  array<string, mixed>|null  $lastError  마지막 오류 (null 이면 `error_get_last()`) — 테스트 주입용
     * @return void 반환값 없음
     */
    public function handleFatalShutdown(string $token, ?array $lastError = null): void
    {
        $lastError ??= error_get_last();

        if (! is_array($lastError)
            || ! in_array((int) ($lastError['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        // 치명 오류 직후에는 남은 시간이 없다 — 기록에 쓸 시간을 따로 잡는다. 제한이 없는
        // 실행(명령줄)에는 걸지 않는다 — 걸면 없던 제한이 새로 생긴다.
        if (ExecutionEnvironment::hasFunction('set_time_limit')
            && ExecutionEnvironment::hasFunction('ini_get')
            && (int) ini_get('max_execution_time') > 0) {
            @set_time_limit(30);
        }

        try {
            $this->state->refresh();

            if (! $this->state->holdsClaim($token)) {
                // 다른 요청이 이미 이어받았다 — 그 진행 위에 실패를 덮지 않는다.
                return;
            }

            $phase = $this->state->status() === CoreUpdateRunState::STATUS_ROLLING_BACK
                ? self::PHASE_ROLLBACK
                : $this->state->nextStep()?->value;

            if ($phase !== null) {
                $message = (string) ($lastError['message'] ?? '');
                $timedOut = $this->budget->limitSeconds() > 0 && str_contains($message, 'Maximum execution time');

                $this->recordInterruptedPhase($phase, $timedOut
                    ? (string) __('settings.core_update.web.step_interrupted', [
                        'seconds' => (string) $this->budget->limitSeconds(),
                        'error' => $message,
                    ])
                    : (string) __('settings.core_update.web.step_fatal', ['error' => $message]));
            }

            $this->state->releaseClaim($token);
        } catch (\Throwable $e) {
            Log::error('웹 코어 업데이트: 치명 종료 기록 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 기록 없이 사라진 앞 요청의 구간을 실패로 확정합니다 (다음 요청이 수행).
     *
     * 실행 잠금을 먼저 잡는다 — 잡히지 않으면 앞 요청이 실제로는 아직 살아 있다는 뜻이다
     * (요청 수명보다 오래 도는 서버). 그때는 아무것도 기록하지 않고 「진행 중」으로 답한다.
     *
     * @param  string  $phase  끊긴 구간 (단계 값 또는 `rollback`)
     * @param  string  $reason  기록할 사유
     * @return void 반환값 없음
     *
     * @throws CoreUpdateOperationException 앞 요청이 아직 살아 있는 경우
     */
    private function promoteInterruptedPhase(string $phase, string $reason): void
    {
        if (! $this->service->acquireRunLock()) {
            throw new CoreUpdateOperationException('settings.core_update.web.step_in_progress');
        }

        try {
            $this->recordInterruptedPhase($phase, $reason);
        } finally {
            $this->service->releaseRunLock();
        }
    }

    /**
     * 끊긴 구간을 상태에 기록합니다 (종료 처리와 다음 요청이 같은 규칙을 쓴다).
     *
     * - 단계가 끊겼으면: 그 단계의 실패로 기록하고 되돌리기 대기로 넘긴다 — 다음 호출이
     *   백업본으로 되돌린다(D10). 백업 전이면 되돌릴 파일이 없어 점검 모드만 푼다.
     * - 되돌리기가 끊겼으면: 재시도 횟수를 세고, 상한에 닿으면 되돌리기 실패로 확정해
     *   수동 복구 안내를 연다(점검 모드는 켜 둔 채 — 사이트가 반쯤 되돌려진 상태일 수 있다).
     *
     * @param  string  $phase  끊긴 구간 (단계 값 또는 `rollback`)
     * @param  string  $reason  기록할 사유
     * @return void 반환값 없음
     */
    private function recordInterruptedPhase(string $phase, string $reason): void
    {
        if ($phase === self::PHASE_ROLLBACK) {
            if ($this->state->status() !== CoreUpdateRunState::STATUS_ROLLING_BACK) {
                return;
            }

            // 같은 자리에서 다시 끊길 때만 센다 — 나눠 하는 되돌리기는 끊길 때마다 진행분이 앞으로 가 있을 수 있고,
            // 그때는 다음 요청이 그 자리에서 이어 가면 된다.
            $marker = $this->rollbackProgressMarker();
            $attempts = $marker === $this->state->context('rollback_interrupt_marker')
                ? (int) $this->state->context('rollback_attempts', 0) + 1
                : 1;
            $this->state->mergeContext(['rollback_attempts' => $attempts, 'rollback_interrupt_marker' => $marker]);
            $this->state->appendLog((string) __('settings.core_update.web.log_rollback_interrupted', [
                'attempt' => (string) $attempts,
                'max' => (string) self::MAX_ROLLBACK_ATTEMPTS,
                'reason' => $reason,
            ]));

            if ($attempts >= self::MAX_ROLLBACK_ATTEMPTS) {
                $progress = (array) ($this->state->context('rollback_progress') ?? []);

                if (in_array($progress['phase'] ?? null, ['ownership', 'cleanup'], true) && ($progress['restored'] ?? false) === true) {
                    // 파일은 이미 백업본으로 되돌렸다 — 남은 것은 소유권·권한 복원과 임시 파일 정리뿐이다. 그 구간이
                    // 끝나지 않는다고 사이트를 점검 모드에 가두지 않는다(사이트는 스스로 정상으로). 건너뛴 사실은 알린다.
                    $this->state->appendWarnings([(string) __('settings.core_update.web.rollback_cleanup_skipped')]);
                    $this->disableMaintenanceIfEnabled();
                    $this->state->mergeContext(['rollback_progress' => null]);
                    $this->finishRollback(true, array_values((array) ($progress['failed_targets'] ?? [])));

                    return;
                }

                $this->finishRollback(false, array_values((array) ($progress['failed_targets'] ?? [])));
            }

            return;
        }

        $step = CoreUpdateStep::tryFromValue($phase);

        if ($step === null || $this->state->status() !== CoreUpdateRunState::STATUS_RUNNING) {
            return;
        }

        // 번들 확장 한 항목이 끊긴 것은 코어를 되돌릴 사유가 아니다(그 단계의 실패 규칙과 같다) — 코어는
        // 이미 새 판으로 정상 반영됐다. 그 항목을 건너뛰고 다음 항목으로 넘어간다.
        //
        // 끊긴 것은 **이 요청**이지 항목 자식 프로세스가 아니다 — 자식은 부모가 죽은 뒤에도 끝까지 돌아 그 확장을 새 판으로
        // 반영할 수 있다(30초 호스팅에서 「업데이트되지 않았습니다」 안내 뒤 DB·활성 폴더는 새 판). 그래서 여기서는
        // 실패로 세지 않고 「끊긴 항목」으로만 적어 두고, 단계 끝에서 실제 판을 확인해 성공·확인 불가로 가른다.
        if ($step === CoreUpdateStep::Bundled && is_array($progress = $this->state->context('bundled_progress'))) {
            $items = array_values((array) ($progress['items'] ?? []));
            $index = (int) ($progress['index'] ?? 0);
            if (isset($items[$index]) && is_array($items[$index])) {
                $progress['interrupted'] = array_values(array_merge((array) ($progress['interrupted'] ?? []), [$items[$index]]));
            } else {
                $progress['failed'] = (int) ($progress['failed'] ?? 0) + 1;
            }
            $progress['index'] = $index + 1;
            $this->state->mergeContext(['bundled_progress' => $progress]);
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_item_interrupted', [
                'identifier' => (string) ($items[$index]['identifier'] ?? '?'),
                'reason' => $reason,
            ]));

            return;
        }

        // 마무리는 구간 진행분이 남아 있어 다시 해도 결과가 같다 — 끝난 업데이트를 바로 되돌리지 않고
        // 같은 자리에서 정해진 횟수만 다시 한다.
        if ($step === CoreUpdateStep::Finalize) {
            $finalizePhase = (string) (($this->state->context('finalize_progress') ?? [])['phase'] ?? 'prepare');
            $marker = (string) json_encode([$finalizePhase, $this->state->context('ownership_progress')]);
            $attempts = $marker === $this->state->context('finalize_interrupt_marker')
                ? (int) $this->state->context('finalize_attempts', 0) + 1
                : 1;
            $this->state->mergeContext(['finalize_attempts' => $attempts, 'finalize_interrupt_marker' => $marker]);

            // 정리 구간에 들어간 뒤로는 백업을 지우기 시작했다 — 되돌릴 원본이 없거나 반쪽이다. 새 판은 이미
            // 다 들어가 있으므로 되돌리지 않는다. 상한에 닿으면 남은 정리(백업·임시 파일 지우기, 소유권 복원)를
            // 건너뛰고 링크 점검 · 점검 모드 해제로 넘어가 마무리를 끝낸다.
            if ($finalizePhase !== 'prepare' && $attempts >= self::MAX_ROLLBACK_ATTEMPTS) {
                // 상한에 닿을 때마다 한 구간씩 앞으로 건너뛴다 — 정리·소유권 → 링크 점검 → 마무리 완료(점검 모드 해제·캐시
                // 굽기). 마지막 구간까지 끊기면 점검 모드를 여기서 풀고 완료로 기록한다 — 끝나는 상태가 늘 있다.
                if ($finalizePhase === 'finish') {
                    $this->state->appendWarnings([(string) __('settings.core_update.web.finalize_cleanup_skipped')]);
                    $this->disableMaintenanceIfEnabled();
                    $this->state->mergeContext(['finalize_progress' => null, 'ownership_progress' => null]);
                    $completed = $this->state->completedSteps();
                    $completed[] = $step->value;
                    $this->state->patch(['completed_steps' => array_values(array_unique($completed)), 'next_step' => null]);
                    $this->finishSuccessfully();

                    return;
                }

                $this->state->appendWarnings([(string) __('settings.core_update.web.finalize_cleanup_skipped')]);
                $this->state->mergeContext([
                    'finalize_progress' => ['phase' => $finalizePhase === 'link' ? 'finish' : 'link'],
                    'ownership_progress' => null,
                ]);

                return;
            }

            if ($finalizePhase !== 'prepare' || $attempts < self::MAX_ROLLBACK_ATTEMPTS) {
                $this->state->appendLog((string) __('settings.core_update.web.log_step_retry', [
                    'step' => (string) __($step->labelKey()),
                    'attempt' => (string) $attempts,
                    'max' => (string) self::MAX_ROLLBACK_ATTEMPTS,
                    'reason' => $reason,
                ]));

                return;
            }
        }

        $this->enterRollback($step, new \RuntimeException($reason));
    }

    /**
     * 되돌리기가 어디까지 갔는지를 한 문자열로 줄입니다 (같은 자리에서 반복해 끊기는지 판정용).
     *
     * @return string 진행 표식
     */
    private function rollbackProgressMarker(): string
    {
        $progress = (array) ($this->state->context('rollback_progress') ?? []);
        $restore = (array) ($progress['restore'] ?? []);
        $ownership = (array) ($this->state->context('ownership_progress') ?? []);

        return (string) json_encode([
            $progress['phase'] ?? null,
            $restore['phase'] ?? null,
            $restore['priority_index'] ?? null,
            $restore['target'] ?? null,
            $restore['cursor'] ?? null,
            $ownership['phase'] ?? null,
            $ownership['index'] ?? null,
            $ownership['cursor'] ?? null,
        ]);
    }

    /**
     * 진행 중에 물은 선택을 받습니다 (7.2.1 — 명령줄의 코어 적용 뒤 질문과 같은 자리).
     *
     * 받는 것은 둘이다.
     *  - `bundled_sources`: 새 판에 처음 들어온 미배치 공식 번들 중 배치할 것(`--bundled-sources` 와 같은 뜻). 항목마다
     *    `selected` 만 본다.
     *  - `bundled_updates`: 번들 확장·언어팩 중 올릴 것과 확장별 레이아웃 전략. 항목마다 `selected`·`strategy`.
     *
     * 기다리는 선택과 종류가 다르거나, 그 목록에 없던 항목은 받지 않는다(목록에 없던 항목은 버린다). 단계가 도는 중이면
     * 거절한다 — 선택을 기다리는 동안 화면은 단계를 부르지 않는다.
     *
     * @param  string  $kind  선택 종류 (`bundled_sources`|`bundled_updates`)
     * @param  array<int, array{key?: mixed, selected?: mixed, strategy?: mixed}>  $items  항목별 선택
     * @return array<string, mixed> 받은 뒤 상태
     *
     * @throws CoreUpdateOperationException 진행 중인 실행이 없거나 그 선택을 기다리지 않는 경우
     */
    public function submitSelection(string $kind, array $items): array
    {
        $this->state->refresh();

        if (! $this->state->exists() || ! $this->state->isActive()) {
            throw new CoreUpdateOperationException('settings.core_update.web.no_active_run');
        }

        if (($this->state->claimSummary()['held'] ?? false) === true) {
            throw new CoreUpdateOperationException('settings.core_update.web.step_in_progress');
        }

        $awaiting = $this->state->context('awaiting');

        if (! is_array($awaiting) || ($awaiting['kind'] ?? null) !== $kind) {
            throw new CoreUpdateOperationException('settings.core_update.web.selection_not_awaited');
        }

        $known = [];

        foreach ((array) ($awaiting['items'] ?? []) as $candidate) {
            if (is_array($candidate) && is_string($candidate['key'] ?? null)) {
                $known[$candidate['key']] = $candidate;
            }
        }

        $decision = [];

        foreach ($items as $item) {
            $key = is_array($item) && is_string($item['key'] ?? null) ? $item['key'] : null;

            if ($key === null || ! isset($known[$key])) {
                continue;
            }

            $strategy = ($item['strategy'] ?? null) === CoreUpdateOptions::LAYOUT_KEEP
                ? CoreUpdateOptions::LAYOUT_KEEP
                : CoreUpdateOptions::LAYOUT_OVERWRITE;

            $decision[$key] = ['selected' => filter_var($item['selected'] ?? false, FILTER_VALIDATE_BOOLEAN), 'strategy' => $strategy];
        }

        $selections = (array) ($this->state->context('selections') ?? []);
        $selections[$kind] = $decision;
        $this->state->mergeContext(['awaiting' => null, 'selections' => $selections]);

        $selectedCount = count(array_filter($decision, static fn (array $row): bool => $row['selected']));
        $this->state->appendLog((string) __('settings.core_update.web.log_selection_received', [
            'kind' => (string) __('settings.core_update.web.selection_kinds.'.$kind),
            'selected' => (string) $selectedCount,
            'total' => (string) count($known),
        ]));

        return $this->status();
    }

    /**
     * 운영자의 선택을 기다리는 상태로 둡니다 — 화면은 다음 단계를 부르지 않고 선택 창을 그린다.
     *
     * @param  string  $kind  선택 종류
     * @param  array<int, array<string, mixed>>  $items  고를 항목 (항목마다 `key` 필수)
     * @param  array<string, mixed>  $extra  화면에 함께 줄 값 (기본 레이아웃 전략 등)
     * @return void 반환값 없음
     */
    private function awaitSelection(string $kind, array $items, array $extra = []): void
    {
        $this->state->mergeContext(['awaiting' => ['kind' => $kind, 'items' => array_values($items)] + $extra]);
        $this->state->appendLog((string) __('settings.core_update.web.log_selection_waiting', [
            'kind' => (string) __('settings.core_update.web.selection_kinds.'.$kind),
            'count' => (string) count($items),
        ]));
        $this->repeatCurrentStep = true;
    }

    /**
     * 받은 선택을 돌려줍니다.
     *
     * @param  string  $kind  선택 종류
     * @return array<string, array{selected: bool, strategy: string}>|null 받은 선택 (아직 없으면 null)
     */
    private function selection(string $kind): ?array
    {
        $selections = (array) ($this->state->context('selections') ?? []);

        return is_array($selections[$kind] ?? null) ? $selections[$kind] : null;
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
     * @return void 반환값 없음
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

            // 단계가 끝났다 — 그 단계의 진행량은 더 쓰지 않는다
            if ($this->state->context('step_progress') !== null) {
                $this->state->mergeContext(['step_progress' => null]);
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    private function stepPreflight(): void
    {
        // 하위 항목 훑기는 이 요청의 몫 안에서 멈춘다 — 훑기의 목적은 명백한 차단을 드러내는
        // 것이고, 시간 제한이 짧은 서버에서 끝까지 훑으려다 요청이 죽으면 업데이트를 시작할 수
        // 없다(파일이 많은 Windows 서버에서는 수 분이 걸린다).
        $this->preflight->limitScanTime($this->budget->workSeconds());

        $report = $this->preflight->run();

        if ($this->preflight->scanWasTruncated()) {
            $this->state->appendLog((string) __('settings.core_update.web.log_preflight_partial'));
        }

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
     * @return void 반환값 없음
     */
    private function stepMaintenance(): void
    {
        // 우회 비밀값은 실행 기록에 남기지 않는다 — 우회 쿠키는 점검 모드 파일에서 바로 읽는다
        // (`maintenanceBypassCookie()`). 실행 기록은 저장소 폴더 안에 있어, 보호 규칙이 빠진
        // 웹 서버에서는 그대로 열리고 그 값으로 점검 중인 사이트에 들어갈 수 있다.
        $this->service->enableMaintenanceMode();

        $this->state->mergeContext([
            'maintenance_enabled' => true,
        ]);

        $this->state->appendLog((string) __('settings.core_update.web.log_maintenance_on'));
    }

    /**
     * 새 버전 내려받기 또는 운영자 ZIP 추출 (㉯ — D18).
     *
     * @return void 반환값 없음
     */
    private function stepDownload(): void
    {
        $options = $this->state->options();
        $progress = (array) ($this->state->context('download_progress') ?? []);
        $shouldPause = $this->budget->pauser();

        // 배포본(수만 항목)을 한 요청에 다 풀면 요청 시간 제한에 걸린다(큰 배포본은 수 분). 내려받기와
        // 풀기를 다른 요청으로 나누고, 풀기는 항목 순번 커서로 이어 간다. 격리 디렉토리는 처음에 정해
        // `pending_path` 에 바로 기록한다 — 중간에 실패하면 되돌리기가 그 디렉토리를 정리한다.
        if (! is_string($progress['pending_root'] ?? null)) {
            if ($options->usesManualZip()) {
                $zipPath = $this->resolveManualZipPath($options);

                if ($zipPath === null) {
                    throw new CoreUpdateOperationException('settings.core_update.zip_file_not_found', [
                        'path' => (string) $options->zipFile,
                    ]);
                }

                // 착수 뒤 파일이 바뀌지 않았는지 해제 전에 다시 검증한다(7.2.0)
                $verification = $this->service->verifyCoreZipIntegrity($zipPath, $options->integrityOptions());

                $root = $this->service->createPendingDirectory();
                $this->state->mergeContext(['pending_path' => $root]);
                $progress = ['pending_root' => $root, 'archive' => $zipPath, 'from' => 0, 'manual' => true, 'integrity' => $verification->toArray()];
            } else {
                $toVersion = (string) ($this->state->all()['to_version'] ?? '');
                $root = $this->service->createPendingDirectory();
                $this->state->mergeContext(['pending_path' => $root]);
                // 서명 자산이 있으면 그것을 받아 해제 전에 검증한다(7.2.0) — 결과는 다 푼 뒤 버전 대조에 쓴다
                $acquired = $this->service->fetchVerifiedReleaseArchive($toVersion, $root, $options->integrityOptions());
                $progress = [
                    'pending_root' => $root,
                    'archive' => $acquired->zipPath,
                    'from' => 0,
                    'manual' => false,
                    'integrity' => $acquired->verification->toArray(),
                ];
            }

            $this->state->mergeContext(['download_progress' => $progress]);

            if ($shouldPause()) {
                $this->repeatCurrentStep = true;

                return;
            }
        }

        $chunk = $this->service->extractArchiveChunk(
            (string) $progress['archive'],
            (string) $progress['pending_root'],
            (int) ($progress['from'] ?? 0),
            $shouldPause,
        );

        if (! $chunk['done']) {
            $progress['from'] = $chunk['next'];
            $this->state->mergeContext(['download_progress' => $progress]);
            $this->noteStepProgress(CoreUpdateStep::Download->value, (int) $chunk['next'], (int) $chunk['total'], 'entries');
            $this->state->appendLog((string) __('settings.core_update.web.log_extract_continues', [
                'done' => (string) $chunk['next'],
                'total' => (string) $chunk['total'],
            ]));
            $this->repeatCurrentStep = true;

            return;
        }

        $pendingPath = (string) $chunk['source_path'];
        $this->state->appendLog((string) __('settings.core_update.web.log_extract_done', [
            'total' => (string) $chunk['total'],
        ]));

        // 다 풀었다 — 서명된 버전과 푼 배포본의 config/app.php 버전을 대조한다(7.2.0). 불일치면 이 단계가 실패해 되돌린다.
        if (is_array($progress['integrity'] ?? null)) {
            $this->service->confirmCoreIntegrity(PackageVerification::fromArray($progress['integrity']), $pendingPath, $options->integrityOptions());
            $this->state->appendLog($this->describeArchiveIntegrity($progress['integrity']));
        }

        if (($progress['manual'] ?? false) !== true && is_file((string) $progress['archive'])) {
            @unlink((string) $progress['archive']);
        }

        $this->state->mergeContext(['pending_path' => $pendingPath, 'download_progress' => null]);

        // 새 코어에 필요한 PHP 확장이 서버에 있는지 — 터미널 업데이트의 시작 전 점검과 같은 판정·문구. 파일을 바꾸기 전에 멈춘다.
        $this->guardPhpRequirements($pendingPath);

        if (($progress['manual'] ?? false) === true) {
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

            $this->guardCustomPreflight($pendingPath, $toVersion);

            return;
        }

        $toVersion = (string) ($this->state->all()['to_version'] ?? '');
        $this->state->appendLog((string) __('settings.core_update.web.log_downloaded', ['version' => $toVersion]));

        $this->guardCustomPreflight($pendingPath, $toVersion);
    }

    /**
     * custom 자리 사전 검사 — 받은 배포본이 custom 예약 자리에 파일을 싣거나 개발자 패키지(custom/composer.lock)가
     * 새 코어와 맞지 않으면 백업과 파일 적용 전에 멈춘다 (CLI `core:update` 와 같은 판정).
     *
     * 멈춘 사실은 기록 파일에 남겨 관리자 업데이트 화면이 같은 새 버전의 중단 사유를 보여 준다. 아직 아무 파일도 바꾸지
     * 않았으므로 되돌리기는 받은 배포본 정리와 유지보수 해제뿐이다(`performRollback` 의 백업 없음 분기).
     *
     * @param  string  $pendingPath  받은 배포본 경로
     * @param  string  $toVersion  새 코어 버전
     * @return void 반환값 없음
     *
     * @throws CoreUpdateOperationException 검사가 업데이트를 막을 때
     */
    private function guardCustomPreflight(string $pendingPath, string $toVersion): void
    {
        $result = app(CustomUpdatePreflight::class)->inspectDirectory($pendingPath);

        if (CustomUpdatePreflight::blocks($result)) {
            CustomUpdatePreflight::record($toVersion, $result);

            if ($result['reserved_files'] !== []) {
                $this->state->appendLog((string) __('settings.core_update.reserved_paths_in_release', [
                    'paths' => implode(', ', $result['reserved_files']),
                ]));
            }
            foreach ($result['conflicts'] as $conflict) {
                $this->state->appendLog(sprintf(
                    '%s (%s): %s',
                    (string) ($conflict['package'] ?? ''),
                    (string) ($conflict['installed'] ?? ''),
                    (string) ($conflict['reason'] ?? ''),
                ));
            }

            throw new CoreUpdateOperationException('settings.core_update.custom_preflight_blocked', ['version' => $toVersion]);
        }

        CustomUpdatePreflight::clearRecord();
    }

    /**
     * 공식 번들 배치 판정 (공개 #146) — 받은 배포본의 `_bundled` 중 이 사이트가 설치했거나 디스크에 둔 번들과 그 의존만
     * 배치하고, 나머지(미배치)는 파일 적용, 신규 파일 기록, 증분 목록에서 건너뛴다.
     *
     * 묻지 않는 화면(표식 `ask_bundled_selection` 없음)은 CLI 의 비대화 기본값(`--bundled-sources` 없음)과 같은 판정이다. 미배치
     * 번들은 관리자 확장 목록 안내와 `core:bundled-sources` 로 나중에 배치한다. 고르는 화면(7.2.1)이면 미배치 번들이 있을 때
     * 운영자의 선택을 기다리고(null 반환), 받은 뒤에는 고른 것까지 배치한다. 계획 단계와 적용 단계가 같은 인자로 따로 부르지만
     * 그 사이 설치 상태·선택이 바뀌지 않으므로 같은 결과다.
     *
     * @param  string  $pendingPath  받은 배포본 경로
     * @param  CoreUpdateOptions|null  $options  착수 선택 (null 이면 묻지 않는 판정)
     * @return BundledPlacementPlan|null 판정 결과 (운영자의 선택을 기다리면 null)
     */
    private function planBundledPlacement(string $pendingPath, ?CoreUpdateOptions $options = null): ?BundledPlacementPlan
    {
        $planner = app(BundledSourcePlanner::class);
        $inventory = $planner->readInventoryFromDirectory($pendingPath);

        // 종전(묻지 않는 화면) — 새로 들어온 공식 번들은 배치하지 않는다(명령줄 비대화형 `--bundled-sources=none` 과 같다)
        if ($options === null || ! $options->askBundledSelection) {
            return $planner->plan($inventory);
        }

        $selection = $this->selection(self::SELECTION_BUNDLED_SOURCES);

        if ($selection !== null) {
            $keys = array_keys(array_filter($selection, static fn (array $row): bool => $row['selected']));

            return $planner->plan($inventory, array_values($keys));
        }

        $plan = $planner->plan($inventory);

        // 확장 언어팩은 대상 확장을 따라가므로 묻지 않는다(대상을 배치하면 함께 배치된다) — 터미널과 같은 판정
        $candidates = $plan->askableUnplaced();

        if ($candidates === []) {
            return $plan;
        }

        $locale = app()->getLocale();
        $items = [];

        foreach ($candidates as $key => $entry) {
            $name = $entry['name'] ?? ($entry['identifier'] ?? $key);
            $items[] = [
                'key' => (string) $key,
                'type' => (string) ($entry['type'] ?? ''),
                'identifier' => (string) ($entry['identifier'] ?? ''),
                'name' => is_array($name) ? (string) ($name[$locale] ?? reset($name) ?: $key) : (string) $name,
                'version' => (string) ($entry['version'] ?? ''),
            ];
        }

        $this->awaitSelection(self::SELECTION_BUNDLED_SOURCES, $items);

        return null;
    }

    /**
     * 운영자가 고른 번들의 의존 번들이 함께 배치되면 실행 기록에 알립니다 — 터미널 `dependency_added` 안내와 같은 계산.
     *
     * 운영자가 배치할 번들을 고른 경우에만 남깁니다(고르지 않았으면 함께 배치될 것도 없다).
     *
     * @param  string  $pendingPath  받은 배포본 경로
     * @param  BundledPlacementPlan  $plan  운영자 선택을 반영한 판정
     * @return void 반환값 없음
     */
    private function logBundledDependencyAdditions(string $pendingPath, BundledPlacementPlan $plan): void
    {
        if ($this->selection(self::SELECTION_BUNDLED_SOURCES) === null || $plan->placedBy('dependency') === []) {
            return;
        }

        $planner = app(BundledSourcePlanner::class);
        $base = $planner->plan($planner->readInventoryFromDirectory($pendingPath));
        $added = array_map(
            static fn (array $row): string => $row['entry']['type'].':'.$row['entry']['identifier'],
            $plan->addedByDependencySince($base),
        );

        if ($added !== []) {
            $this->state->appendLog((string) __('settings.bundled_sources.dependency_added', [
                'items' => implode(', ', array_values($added)),
            ]));
        }
    }

    /**
     * 백업 생성 + 소유권 스냅샷 (웹은 백업 필수 — D27, D10 의 전제).
     *
     * @return void 반환값 없음
     */
    private function stepBackup(): void
    {
        $progress = (array) ($this->state->context('backup_progress') ?? []);
        $shouldPause = $this->budget->pauser();

        // 현재 트리 전체를 복사하는 단계라 한 요청에 끝까지 하면 요청 시간 제한에 걸린다(큰 트리는
        // 수 분). target 순번 + target 안 경로 커서로 이어 간다. 백업 경로는 다 만든 뒤에만 `backup_path`
        // 에 싣는다 — 반쪽 백업을 되돌리기가 복원 원본으로 쓰면 안 된다.
        if (($progress['phase'] ?? 'copy') === 'copy') {
            if (! is_string($progress['copy']['backup_path'] ?? null)) {
                // 지난 실행이 남긴 번들 출하 목록 사본을 버린다 — 이번 실행이 파일 적용 전에 실패해 되돌릴 때 목록이 후퇴하지
                // 않게(실행 잠금 안, 파일 적용 전).
                BundledShippedManifest::beginRun();

                // 백업 폴더 경로를 복사 전에 저장한다 — 첫 요청이 복사 중에 끊기면 그 반쪽 폴더를 되돌리기가 지워야 한다.
                $progress = ['phase' => 'copy', 'copy' => ['backup_path' => CoreBackupHelper::prepareBackupDirectory()]];
                $this->state->mergeContext(['backup_progress' => $progress]);
            }

            $chunk = $this->service->createBackupChunk((array) ($progress['copy'] ?? []), $shouldPause);

            if (! $chunk['done']) {
                $this->state->mergeContext(['backup_progress' => ['phase' => 'copy', 'copy' => $chunk]]);
                $this->state->appendLog((string) __('settings.core_update.web.log_backup_continues'));
                $this->repeatCurrentStep = true;

                return;
            }

            $progress = ['phase' => 'ownership', 'backup_path' => $chunk['backup_path']];
            $this->state->mergeContext(['backup_progress' => $progress]);

            if ($shouldPause()) {
                $this->repeatCurrentStep = true;

                return;
            }
        }

        $backupPath = (string) $progress['backup_path'];

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
            'backup_progress' => null,
        ]);

        $this->state->appendLog((string) __('settings.core_update.web.log_backup', ['path' => basename($backupPath)]));
    }

    /**
     * `_pending` 의 vendor 구성 — 무CLI 는 번들 모드로 자동 판정된다 (A-4 와 같은 규율).
     *
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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

        // 이 단계는 새 판 트리 전체를 백업과 비교한다(같은 크기의 파일은 양쪽 내용을 다
        // 읽는다). 한 요청에 끝까지 하면 요청 시간 제한을 넘겨 치명 오류로 끊기므로, 이
        // 요청의 몫이 다하면 커서를 남기고 다음 요청이 이어 간다. 진행분은 사이드카 파일에
        // 둔다 — 목록이 수천 줄이 될 수 있어 상태 파일에 실으면 매 쓰기가 느려진다.
        $progress = $this->state->loadPlanProgress() ?? [];
        $slices = (int) ($progress['slices'] ?? 0) + 1;

        if ($slices > self::MAX_PLAN_SLICES) {
            $this->state->storePlanProgress(null);

            throw new CoreUpdateOperationException('settings.core_update.web.plan_slices_exhausted', [
                'count' => (string) self::MAX_PLAN_SLICES,
            ]);
        }

        if (! is_array($progress['skip_paths'] ?? null)) {
            $bundledPlan = $this->planBundledPlacement($pendingPath, $this->state->options());

            // 새로 들어온 미배치 공식 번들을 배치할지 운영자에게 묻는 중 — 고를 때까지 이 단계에 머문다
            if ($bundledPlan === null) {
                return;
            }

            $progress = ['phase' => 'manifest', 'skip_paths' => $bundledPlan->skipPaths(), 'manifest' => [], 'apply' => []];
            $placementLine = (string) __('settings.core_update.web.log_bundled_placement', [
                'placed' => (string) count($bundledPlan->placed),
                'unplaced' => (string) count($bundledPlan->unplaced),
            ]);
            $this->state->appendLog($placementLine);
            // 기록에만 있던 요약을 알림에도 싣는다 — 완료 화면이 「어떻게 배치했는지」를 보인다(7.2.1)
            $this->state->appendNotices([$placementLine]);
            $this->logBundledDependencyAdditions($pendingPath, $bundledPlan);
        }

        $progress['slices'] = $slices;
        $skipPaths = array_values(array_map('strval', (array) $progress['skip_paths']));
        $shouldPause = $this->budget->pauser();

        if (($progress['phase'] ?? 'manifest') === 'manifest') {
            try {
                $chunk = CoreBackupHelper::collectNewFilesChunk(
                    $backupPath,
                    $pendingPath,
                    $targets,
                    $protected,
                    $excludes,
                    $skipPaths,
                    (array) ($progress['manifest'] ?? []),
                    $shouldPause,
                );

                if (! $chunk['done']) {
                    $progress['manifest'] = $chunk;
                    $this->pausePlan($progress, (int) $chunk['visited']);

                    return;
                }

                $manifestStats = CoreBackupHelper::writeNewFilesManifestFromLists(
                    $backupPath,
                    $chunk['new_files'],
                    $chunk['new_dirs'],
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

            $progress['phase'] = 'apply';
            $progress['manifest'] = [];
            // 화면의 「n개 확인」이 구간마다 0 으로 돌아가지 않게 끝낸 구간의 확인 수를 쌓아 둔다(표시용)
            $progress['checked_base'] = (int) ($progress['checked_base'] ?? 0) + (int) (isset($chunk) ? ($chunk['visited'] ?? 0) : 0);
            unset($chunk);

            if ($shouldPause()) {
                $this->pausePlan($progress, 0);

                return;
            }
        }

        if (($progress['phase'] ?? 'apply') === 'apply') {
            try {
                $chunk = CoreBackupHelper::computeApplyListChunk(
                    $backupPath,
                    $pendingPath,
                    $targets,
                    $protected,
                    $excludes,
                    $skipPaths,
                    (array) ($progress['apply'] ?? []),
                    $shouldPause,
                );

                if (! $chunk['done']) {
                    $progress['apply'] = $chunk;
                    $this->pausePlan($progress, (int) $chunk['visited']);

                    return;
                }

                $this->state->storeApplyList($chunk['apply']);
                $this->state->mergeContext(['apply_stats' => [
                    'added_count' => count($chunk['added']),
                    'changed_count' => count($chunk['changed']),
                    'total' => count($chunk['apply']),
                ]]);

                $this->state->appendLog((string) __('settings.core_update.web.log_apply_list', [
                    'added' => (string) count($chunk['added']),
                    'changed' => (string) count($chunk['changed']),
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

            $progress['phase'] = 'shipped';
            $progress['apply'] = [];
            $progress['checked_base'] = (int) ($progress['checked_base'] ?? 0) + (int) (isset($chunk) ? ($chunk['visited'] ?? 0) : 0);
            unset($chunk);

            if ($shouldPause()) {
                $this->pausePlan($progress, 0);

                return;
            }
        }

        // 이번 배포본의 번들 출하 목록(경로·본문 해시) — 적용 단계가 사이트의 직전 목록과 대조해 새 판이 지운 번들 파일을
        // 정리하고 이 목록을 기록한다. 수만 파일의 해시라 요청 몫 안에서 나눠 만든다. 실패해도 업데이트는 종전처럼 간다.
        try {
            $shipped = BundledShippedManifest::collectChunk($pendingPath, (array) ($progress['shipped'] ?? []), $shouldPause, $skipPaths);

            if (! $shipped['done']) {
                $progress['shipped'] = $shipped;
                $this->pausePlan($progress, (int) $shipped['index'], count((array) ($shipped['paths'] ?? [])));

                return;
            }

            $this->state->storeShippedFiles($shipped['files']);
        } catch (\Throwable $shippedError) {
            $this->state->storeShippedFiles(null);
            Log::warning('웹 코어 업데이트: 번들 출하 목록 산출 실패 (정리 없이 진행)', ['error' => $shippedError->getMessage()]);
        }

        $this->state->storePlanProgress(null);
    }

    /**
     * 내려받은 배포본의 무결성 검증 결과를 실행 기록 한 줄로 만듭니다.
     *
     * @param  array<string, mixed>  $integrity  PackageVerification::toArray() 결과
     * @return string 기록 줄
     */
    private function describeArchiveIntegrity(array $integrity): string
    {
        $status = (string) ($integrity['status'] ?? '');

        if ($status === 'verified') {
            return (string) __('settings.core_update.web.log_integrity_verified', [
                'key' => (string) ($integrity['key_id'] ?? '-'),
                'signer' => (string) ($integrity['signer_label'] ?? '-'),
            ]);
        }

        if ($status === 'unsigned' || $status === 'unverifiable') {
            return (string) __('settings.core_update.web.log_integrity_unsigned', [
                'reason' => (string) ($integrity['message'] ?? $status),
            ]);
        }

        return (string) __('settings.core_update.web.log_integrity_other', ['status' => $status]);
    }

    /**
     * 코어 파일 적용 완료 줄을 적용 대상 통계와 함께 만듭니다.
     *
     * @param  mixed  $stats  apply_stats 문맥 값 (산출 실패로 전체 덮어쓰기면 null)
     * @return string 기록 줄
     */
    private function describeApplied(mixed $stats): string
    {
        if (! is_array($stats) || ! isset($stats['total'])) {
            return (string) __('settings.core_update.web.log_applied_full');
        }

        return (string) __('settings.core_update.web.log_applied_detail', [
            'total' => (string) ((int) $stats['total']),
            'added' => (string) ((int) ($stats['added_count'] ?? 0)),
            'changed' => (string) ((int) ($stats['changed_count'] ?? 0)),
        ]);
    }

    /**
     * 운영 vendor 에 개발용 패키지가 섞여 있으면 터미널과 같은 문구로 알립니다.
     *
     * composer 변경이 없어 vendor 를 그대로 두면 경고로, 새 vendor 로 바꿔 끼우면 실행 기록으로 남긴다.
     * 판정 실패는 업데이트를 막지 않는다.
     *
     * @param  bool  $composerSkipped  의존성 변경이 없어 vendor 를 그대로 두는지
     * @return void 반환값 없음
     */
    private function noteDevPackages(bool $composerSkipped): void
    {
        try {
            $devPackages = ComposerInstallInfo::devPackageNames(base_path('vendor'));
        } catch (\Throwable $e) {
            return;
        }

        if ($devPackages === []) {
            return;
        }

        $count = (string) count($devPackages);

        if ($composerSkipped) {
            $message = (string) __('settings.core_update.console.dev_packages_kept', ['count' => $count]);
            $this->state->appendWarnings([$message]);
            $this->state->appendLog($message);

            return;
        }

        $this->state->appendLog((string) __('settings.core_update.console.dev_packages_replaced', ['count' => $count]));
    }

    /**
     * 마이그레이션 저장소에 기록된 실행 완료 마이그레이션 이름을 돌려줍니다 (읽기 전용).
     *
     * @return array<int, string>|null 이름 목록 (저장소가 아직 없으면 빈 배열, 조회 실패면 null)
     */
    private function ranMigrationNames(): ?array
    {
        try {
            $repository = app('migrator')->getRepository();

            if (! $repository->repositoryExists()) {
                return [];
            }

            return array_values(array_map('strval', $repository->getRan()));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 이번에 실행된 마이그레이션을 실행 기록 한 줄로 만듭니다.
     *
     * @param  array<int, string>  $names  이번에 실행된 마이그레이션 이름
     * @return string 기록 줄
     */
    private function describeMigrations(array $names): string
    {
        if ($names === []) {
            return (string) __('settings.core_update.web.log_migrations_none');
        }

        return (string) __('settings.core_update.web.log_migrations_ran', [
            'count' => (string) count($names),
            'names' => $this->summarizeNames($names),
        ]);
    }

    /**
     * 업그레이드 스텝 결과를 터미널과 같은 정보(실행·건너뜀 버전)로 기록 줄들로 만듭니다.
     *
     * @param  array<string, mixed>|null  $executed  `[STEPS_EXECUTED]` 페이로드
     * @param  string  $fromVersion  시작 버전
     * @param  string  $toVersion  대상 버전
     * @return array<int, string> 기록 줄
     */
    private function describeUpgradeSteps(?array $executed, string $fromVersion, string $toVersion): array
    {
        $count = (int) ($executed['count'] ?? 0);
        $versions = array_values(array_filter((array) ($executed['versions'] ?? []), 'is_string'));
        $skippedVersions = array_values(array_filter((array) ($executed['skippedVersions'] ?? []), 'is_string'));
        $skipped = (int) ($executed['skipped'] ?? count($skippedVersions));

        // 버전 목록을 싣지 않는 실행(구버전 명령)이면 종전 줄 하나만 남긴다
        if (! is_array($executed) || ! array_key_exists('versions', $executed)) {
            return [(string) __('settings.core_update.web.log_upgrade_steps', ['count' => (string) $count])];
        }

        $lines = [];

        if ($count > 0) {
            $lines[] = (string) __('settings.core_update.web.log_upgrade_steps_ran', [
                'count' => (string) $count,
                'versions' => $this->summarizeNames($versions),
            ]);
        }

        if ($skipped > 0) {
            $lines[] = (string) __('settings.core_update.web.log_upgrade_steps_already', [
                'count' => (string) $skipped,
                'versions' => $this->summarizeNames($skippedVersions),
            ]);
        }

        if ($lines === []) {
            $lines[] = (string) __('settings.core_update.web.log_upgrade_steps_none', [
                'from' => $fromVersion,
                'to' => $toVersion,
            ]);
        }

        return $lines;
    }

    /**
     * 이름 목록을 기록 줄에 넣을 만큼 줄여 이어 붙입니다 (앞 10개 + 「외 n개」).
     *
     * @param  array<int, string>  $names  이름 목록
     * @return string 쉼표로 이은 문자열 (비면 「-」)
     */
    private function summarizeNames(array $names): string
    {
        if ($names === []) {
            return '-';
        }

        $limit = 10;
        $text = implode(', ', array_slice($names, 0, $limit));

        if (count($names) > $limit) {
            $text .= ' '.__('settings.core_update.web.log_list_more', ['count' => (string) (count($names) - $limit)]);
        }

        return $text;
    }

    /**
     * 적용 대상 산출을 멈추고 다음 요청에서 이어 가게 합니다.
     *
     * @param  array<string, mixed>  $progress  커서와 중간 결과
     * @param  int  $visited  지금까지 확인한 항목 수
     * @param  int|null  $total  이 구간의 전체 항목 수 (모르면 null)
     * @return void 반환값 없음
     */
    private function pausePlan(array $progress, int $visited, ?int $total = null): void
    {
        $this->state->storePlanProgress($progress);
        // 앞 구간(새 파일 목록·적용 대상·출하 목록)에서 확인한 수를 더한다 — 화면 수치가 줄어 보이지 않게(표시값만)
        $base = max(0, (int) ($progress['checked_base'] ?? 0));
        $this->noteStepProgress(CoreUpdateStep::Plan->value, $base + $visited, $total !== null ? $base + $total : null, 'files');
        $this->state->appendLog((string) __('settings.core_update.web.log_plan_continues', [
            'checked' => (string) ($base + $visited),
        ]));

        // 같은 단계를 다음 요청에서 다시 — 완료로 표시하지 않는다.
        $this->repeatCurrentStep = true;
    }

    /**
     * 코어 파일 적용 — 이 요청 이후의 단계는 신버전 코드로 부팅된 요청에서 돈다.
     *
     * @return void 반환값 없음
     */
    private function stepApply(): void
    {
        $pendingPath = (string) $this->state->context('pending_path', '');

        if ($pendingPath === '') {
            throw new CoreUpdateOperationException('settings.core_update.web.missing_pending_path');
        }

        // 적용 대상 산출 단계와 같은 판정이어야 한다 — 운영자가 고른 미배치 번들을 여기서도 배치한다
        $bundledPlan = $this->planBundledPlacement($pendingPath, $this->state->options());

        if ($bundledPlan === null) {
            // 산출 단계가 선택을 받은 뒤에만 이 단계에 온다 — 선택 없이 오면 판정이 갈린 것이다
            throw new CoreUpdateOperationException('settings.core_update.web.selection_not_awaited');
        }

        // 반환값이 계약이다 — 게터는 반환값을 손에 쥘 수 없는 자리의 편의일 뿐이다.
        $result = $this->service->applyUpdate(
            $pendingPath,
            null,
            prune: false,
            applyList: $this->state->loadApplyList(),
            skipPaths: $bundledPlan->skipPaths(),
            shippedFiles: $this->state->loadShippedFiles(),
        );

        $this->appendApplyWarnings((array) ($result['root_file_warnings'] ?? []));
        $this->reportBundledPrune((array) ($result['bundled_prune'] ?? []));
        $appliedLine = $this->describeApplied($this->state->context('apply_stats'));
        $this->state->appendLog($appliedLine);
        // 기록에만 있던 적용 방식 요약을 알림에도 싣는다(7.2.1)
        $this->state->appendNotices([$appliedLine]);

        // 미배치 공식 번들 기록 — 관리자 목록 안내와 `core:bundled-sources` 의 근거. 되돌리기는 이전 기록으로 돌려놓는다.
        $planner = app(BundledSourcePlanner::class);
        $recordPath = $planner->recordPath();
        $this->state->mergeContext([
            'bundled_record_written' => true,
            'bundled_record_backup' => is_file($recordPath) ? (string) file_get_contents($recordPath) : null,
        ]);
        $planner->writeRecord($bundledPlan, (string) ($this->state->all()['to_version'] ?? ''), 'core:update');

        // 컴파일 캐시를 **파일 삭제만으로** 비운다. 여기서 `clearAllCaches()` 를 부르면
        // `package:discover`·`extension:update-autoload` 가 방금 교체된 파일을 이 구버전
        // 프로세스에서 로드한다 — R25 가 말하는 바로 그 자리다. 남은 정리는 다음 요청
        // (신버전으로 부팅됨)과 마무리 단계가 맡는다.
        $this->dropCompiledCaches();
    }

    /**
     * vendor 디렉토리 복사 (_pending → 운영).
     *
     * @return void 반환값 없음
     */
    private function stepVendorCopy(): void
    {
        // 터미널과 같은 판정 — 교체 전 운영 vendor 에 개발용 패키지가 섞여 있으면 알린다(동작은 바꾸지 않는다)
        $this->noteDevPackages($this->state->context('composer_skipped') === true);

        if ($this->state->context('composer_skipped') === true) {
            $this->state->appendLog((string) __('settings.core_update.web.log_vendor_copy_skipped'));

            return;
        }

        $pendingPath = (string) $this->state->context('pending_path', '');

        if ($pendingPath === '') {
            throw new CoreUpdateOperationException('settings.core_update.web.missing_pending_path');
        }

        // vendor 는 수만 파일이라 하나씩 복사하면 요청 시간 안에 끝나지 않을 수 있고, 중간에 끊기면 다음
        // 요청이 부팅할 vendor 가 반쪽이다. 통째로 바꿔 끼우고(즉시 끝난다) 옛 vendor 는 격리 디렉토리에
        // 옮겨 두어 되돌리기가 그대로 다시 끼운다. 바꿔 끼울 수 없는 서버만 종전 복사로 간다.
        $parking = $this->stagingPathFor($pendingPath, 'vendor-previous');

        if ($this->service->swapVendorFromPending($pendingPath, $parking)) {
            $this->state->mergeContext(['vendor_swap' => ['previous' => $parking]]);
            $this->state->appendLog((string) __('settings.core_update.web.log_vendor_swapped'));

            return;
        }

        $failure = FilePermissionHelper::takeLastSwapFailure();

        if ($failure !== null) {
            // 옛 vendor 를 옮긴 뒤 새 것을 끼우지도, 옛 것을 되돌리지도 못했다 — 운영 vendor 자리가 비었을 수 있다.
            // 어느 경로가 어떤 상태인지를 실행 기록에 남기고(운영자의 수동 복구 근거), 옮겨 둔 옛 vendor 가 있으면
            // 되돌리기가 그것을 다시 끼우게 한다. 여기서 복사로 넘어가지 않는다 — 비어 있는 자리에 반쪽 복사가 쌓인다.
            $this->state->mergeContext(array_filter([
                'vendor_swap_failure' => $failure,
                'vendor_swap' => ($failure['parked_exists'] ?? false) ? ['previous' => (string) $failure['parked']] : null,
            ], static fn ($v): bool => $v !== null));

            throw new CoreUpdateOperationException('settings.core_update.web.vendor_swap_failed', [
                'live' => (string) $failure['live'],
                'parked' => (string) $failure['parked'],
                'replacement' => (string) $failure['replacement'],
            ]);
        }

        $this->service->copyVendorFromPending($pendingPath);
        $this->state->mergeContext(['vendor_copied' => true]);
        $this->state->appendLog((string) __('settings.core_update.web.log_vendor_copied_files'));
    }

    /**
     * 이번 실행의 격리 디렉토리 안 경로를 돌려줍니다 (옮겨 둔 vendor 자리).
     *
     * 프로젝트 루트가 아니라 저장소 폴더 안에 둔다 — 문서 루트가 프로젝트 루트인 배치에서 루트의
     * 보호 규칙은 `vendor` 이름만 막으므로, 이름을 바꾼 사본을 루트에 두면 그대로 열린다.
     *
     * @param  string  $pendingPath  _pending 경로
     * @param  string  $name  격리 디렉토리 안 이름
     * @return string 절대 경로
     */
    private function stagingPathFor(string $pendingPath, string $name): string
    {
        return rtrim($this->service->resolveStagingRoot($pendingPath), '/\\').DIRECTORY_SEPARATOR.$name;
    }

    /**
     * 마이그레이션 + 코어 config 재로드·권한/메뉴 재동기.
     *
     * @return void 반환값 없음
     */
    private function stepMigrate(): void
    {
        // 실행 전후의 「실행된 마이그레이션」 목록 차이로 이번에 돈 것을 기록한다(읽기만 한다)
        $ranBefore = $this->ranMigrationNames();
        $this->service->runMigrations();
        $ranAfter = $this->ranMigrationNames();

        if ($ranBefore !== null && $ranAfter !== null) {
            $this->state->appendLog($this->describeMigrations(array_values(array_diff($ranAfter, $ranBefore))));
        }

        // `syncCoreRolesAndPermissions`/`syncCoreMenus` 를 직접 부르지 않는다 — 부팅 시점에
        // 캐시된 구버전 config 로 sync 가 돌면 신규 권한·메뉴가 누락된다.
        $this->service->reloadCoreConfigAndResync();

        $this->state->appendLog((string) __('settings.core_update.web.log_migrated'));
    }

    /**
     * 업그레이드 스텝 — **파일 적용과 다른 요청**에서만 실행한다 (순서 불변식).
     *
     * @return void 반환값 없음
     */
    private function stepUpgradeSteps(): void
    {
        $raw = $this->state->all();
        $fromVersion = (string) ($this->state->context('upgrade_resume_from') ?? ($raw['from_version'] ?? ''));
        $toVersion = (string) ($raw['to_version'] ?? '');

        $this->forceSpawnFailureFallback();

        // `--force` 는 넘기지 않는다 — 실행 대장의 「이미 실행됨」을 무시하게 해, DB 를 함께 쓰는 두 번째 서버의
        // 웹 업데이트가 끝난 스텝을 다시 돌린다. 웹은 늘 from < to 라 같은 판 강제 실행도 필요 없다.
        $result = $this->runArtisan('core:execute-upgrade-steps', [
            '--from' => $fromVersion,
            '--to' => $toVersion,
            '--steps-only' => true,
            // 문서 루트 노출 통보를 표식 줄로 받는다(7.2.1) — 터미널은 콘솔에 바로 보이지만 웹은 출력을 화면에 보이지 않는다
            '--markers' => true,
        ]);

        $this->appendDocrootExposureWarnings($result->output);

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

        foreach ($this->describeUpgradeSteps($executed, $fromVersion, $toVersion) as $line) {
            $this->state->appendLog($line);
        }
    }

    /**
     * 번들 확장·언어팩 일괄 업데이트 (운영자 선택 — D28 · D29).
     *
     * @return void 반환값 없음
     */
    private function stepBundled(): void
    {
        $options = $this->state->options();

        if (! $options->updateBundledExtensions) {
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_skipped'));

            return;
        }

        // 앞 요청이 항목을 기다리다 끊겼으면 그 자식이 아직 돌 수 있다 — 끝난 것을 확인하기 전에는 다음 항목·마무리로 가지 않는다.
        if (! $this->awaitInterruptedBundledChild()) {
            $this->repeatCurrentStep = true;

            return;
        }

        // 확장 하나의 업데이트(파일 교체·마이그레이션·캐시)가 수십 초다. 수십 개를 한 요청에 돌리면 요청 시간
        // 제한에 걸리므로 한 요청에 한 항목씩 돈다 — 명령줄의 「항목마다 새 프로세스」와 같은 경계이고, 웹에서는
        // 다음 요청이 곧 새 프로세스다. 순서는 명령줄과 같은 정렬(의존 순서 → 템플릿 → 언어팩)이다.
        $progress = $this->state->context('bundled_progress');

        if (! is_array($progress)) {
            $manifest = $this->buildBundledManifest($options);

            if ($manifest === null) {
                $this->state->appendLog((string) __('settings.core_update.web.log_bundled_none'));

                return;
            }

            // 고르는 화면이면 무엇을 어떤 레이아웃 전략으로 올릴지 묻는다(명령줄의 확인 질문·확장별 전략 예외와 같은 자리)
            if ($options->askBundledSelection) {
                $selection = $this->selection(self::SELECTION_BUNDLED_UPDATES);

                if ($selection === null) {
                    $this->awaitSelection(self::SELECTION_BUNDLED_UPDATES, $this->bundledUpdateCandidates($options), [
                        'default_strategy' => $options->layoutStrategy,
                    ]);

                    return;
                }

                $manifest = self::applyBundledSelection($manifest, $selection);

                if ($manifest === null) {
                    $this->state->appendLog((string) __('settings.core_update.web.log_bundled_none_selected'));

                    return;
                }
            }

            $items = ExecuteBundledUpdatesCommand::flattenItems(
                $manifest['modules'],
                $manifest['plugins'],
                $manifest['templates'],
                $manifest['lang_packs'],
            );
            $items = self::withBundledItemVersions($items, $manifest);
            $this->logBundledTargets($items);

            // 몇 개짜리 묶음인지 항목마다 싣는다 — 2개 이상이면 항목 자식이 정적 게시·시작 재굽기·확장마다의 큐 워커 재시작을
            // 건너뛰고, 이 단계 끝(재시작)과 마무리 단계(게시)가 한 번 한다. 1개짜리는 종전과 같다.
            $progress = [
                'items' => $items, 'index' => 0, 'success' => 0, 'failed' => 0, 'planned' => count($items),
                ExtensionBatchUpdateContext::MANIFEST_KEY => ExtensionBatchUpdateContext::begin(count($items))->toManifest(),
            ];

            // 항목을 돌리기 **전에** 진행분을 저장한다 — 첫 항목이 끊기면 종료 처리가 그 항목만 건너뛰고 다음
            // 항목으로 넘어가야 하는데, 진행분이 없으면 끊김이 코어 되돌리기로 간다.
            $this->state->mergeContext(['bundled_progress' => $progress]);
        }

        $items = array_values((array) $progress['items']);
        $index = (int) $progress['index'];
        $total = count($items);

        if ($index < $total) {
            $item = (array) $items[$index];
            $marker = $this->state->newBundledChildMarkerPath();
            $manifestPath = $this->writeBundledItemManifest($item, $index === $total - 1 && $options->rebuildSearchIndex, $marker, ExtensionBatchUpdateContext::fromManifest($progress));

            // 자식이 잡을 표식을 **돌리기 전에** 실행 상태에 적는다 — 이 요청이 시간 제한으로 죽으면 다음 요청이 이것으로 기다린다.
            // 업데이트 전 확장 상태와 매니페스트 경로도 함께 — 자식이 죽은 것을 확인하면 그 확장의 `updating`·잠금을 정리하고,
            // 이 요청이 끊겨 지우지 못한 매니페스트를 치운다.
            $childContext = [
                'marker' => $marker,
                'type' => (string) ($item['type'] ?? ''),
                'identifier' => (string) ($item['identifier'] ?? '?'),
                'prior_status' => $this->bundledItemStatus((string) ($item['type'] ?? ''), (string) ($item['identifier'] ?? '')),
                'manifest' => $manifestPath,
                'started_at' => time(),
                'parent_pid' => ExecutionEnvironment::hasFunction('getmypid') ? (int) getmypid() : null,
            ];
            $this->state->mergeContext(['bundled_child' => $childContext]);
            $itemStartedAt = microtime(true);

            try {
                $result = $this->runArtisan('core:execute-bundled-updates', [
                    '--manifest' => $manifestPath,
                    '--force' => true,
                ]);

                $summary = $this->parseLineProtocol($result->output, '[BUNDLED-RESULT] ') ?? [];
                $progress['success'] += (int) ($summary['success'] ?? 0);
                $progress['failed'] += isset($summary['failed'])
                    ? (int) $summary['failed']
                    : ($result->successful() ? 0 : 1);
                // 완료 화면용 결과(7.2.1) — 건너뜀 수, 실패 항목(사유·다시 시도할 명령), 검색 인덱스 결과(마지막 항목 자식이 싣는다)
                $progress['skipped'] = (int) ($progress['skipped'] ?? 0) + (int) ($summary['skipped'] ?? 0);
                $progress['failed_items'] = [...(array) ($progress['failed_items'] ?? []), ...self::bundledFailedItems($item, $summary, $result->exitCode, $result->successful())];
                if (is_array($summary['search_index'] ?? null)) {
                    $progress['search_index'] = $summary['search_index'];
                }
            } finally {
                if (is_file($manifestPath)) {
                    @unlink($manifestPath);
                }

                // 돌아왔으면 자식 프로세스는 끝났다. 표식에 완료 없이 잠금 기록이 남았으면 도중에 죽은 것이다(강제 종료·신호) —
                // 다음 요청의 고아 판정과 같은 정리를 지금 한다(같은 함수 — 두 경로가 갈라지지 않게). 결과 줄이 없어도 표식이
                // 없거나 완료가 적혔으면(표식을 잡기 전에 끝남·스스로 정리하고 끝남) 그 확장의 잠금·상태가 자식 것이라는 근거가
                // 없어 건드리지 않는다.
                $probe = BundledChildMarker::inspect($marker, null, $childContext['parent_pid']);

                if ($probe['state'] === BundledChildMarker::ORPHAN) {
                    $this->recoverOrphanedBundledItem($childContext, $probe['locks']);
                }

                $this->state->mergeContext(['bundled_child' => null]);
            }

            // 항목마다 한 줄 — 무엇을 몇 판에서 몇 판으로 올렸는지, 실패면 사유와 다시 시도할 명령(명령줄 화면과 같은 정보)
            $this->state->appendLog(self::bundledItemLogLine($item, $index + 1, $total, $summary, $result->exitCode, microtime(true) - $itemStartedAt));

            $progress['index'] = ++$index;
        }

        if ($index < $total) {
            $this->state->mergeContext(['bundled_progress' => $progress]);
            $this->repeatCurrentStep = true;

            return;
        }

        $unconfirmed = $this->resolveInterruptedBundledItems($progress);

        // 일괄(2개 이상)의 항목 자식은 확장마다의 큐 워커 재시작을 미뤘다 — 모든 항목을 돈 지금 한 번 보낸다(실패한 항목이 있어도).
        // 마무리 단계도 보내지만, 그 전에 실패해 되돌리기로 가도 업데이트된 확장의 새 코드를 워커가 물도록 여기서 먼저 보낸다.
        $batch = ExtensionBatchUpdateContext::fromManifest($progress);
        if ($batch !== null && $batch->isBatch()) {
            app(ExtensionUpdateQueueListener::class)->restartAfterBatch($batch);
        }

        $this->state->mergeContext([
            'bundled_progress' => null,
            'bundled_result' => [
                'success' => (int) $progress['success'],
                'failed' => (int) $progress['failed'],
                'unconfirmed' => count($unconfirmed),
                'planned' => (int) $progress['planned'],
                'skipped' => (int) ($progress['skipped'] ?? 0),
                'failed_items' => array_values((array) ($progress['failed_items'] ?? [])),
                'search_index' => is_array($progress['search_index'] ?? null) ? $progress['search_index'] : null,
            ],
        ]);

        $this->state->appendLog((string) __('settings.core_update.web.log_bundled', [
            'success' => (string) ((int) $progress['success']),
            'failed' => (string) ((int) $progress['failed']),
        ]));

        if ((int) $progress['failed'] > 0) {
            // 확장 일괄 업데이트의 실패는 코어 적용을 되돌릴 사유가 아니다 — 코어는
            // 이미 정상 반영되어 있고, 되돌리면 오히려 성공한 확장 업데이트와
            // 어긋난다. 사실만 통보하고 진행한다. 나중에 코어를 되돌리면 이 문구의
            // 「코어는 정상 반영」이 거짓이 되므로 되돌리기가 바꿔 쓸 수 있게 기억한다.
            $failureWarning = trans_count('settings.core_update.web.bundled_partial_failure', [
                'failed' => (string) ((int) $progress['failed']),
                'count' => (int) $progress['failed'],
            ]);
            $this->state->appendWarnings([$failureWarning]);
            $this->state->mergeContext(['bundled_failure_warning' => ['message' => $failureWarning, 'failed' => (int) $progress['failed']]]);
        }

        if ($unconfirmed !== []) {
            $this->state->appendWarnings([trans_count('settings.core_update.web.bundled_interrupted_unconfirmed', [
                'count' => count($unconfirmed),
                'identifiers' => implode(', ', $unconfirmed),
            ])]);
        }
    }

    /**
     * 앞 요청이 기다리다 끊긴 번들 항목 자식이 끝났는지 확인합니다.
     *
     * 자식은 실행 상태 디렉토리의 표식을 잠근 채 돈다({@see BundledChildMarker}). 아직 잡고 있으면 이 요청 몫의 절반까지 기다려
     * 보고, 그래도 돌면 false — 같은 단계를 다음 요청에서 다시 한다. 처음 기다리기 시작할 때 자식 PID 와 시각을 실행 상태에 적고
     * 안내한다. 상한({@see self::BUNDLED_CHILD_WAIT_SECONDS})을 넘기면 경고를 남기고 진행한다. 표식이 없거나(끝남·옛 자식) 잡은
     * 프로세스가 없으면(고아 — 강제 종료·재부팅) 치우고 진행한다.
     *
     * @return bool 진행해도 되면 true
     */
    private function awaitInterruptedBundledChild(): bool
    {
        $child = $this->state->context('bundled_child');

        if (! is_array($child) || ! is_string($child['marker'] ?? null)) {
            return true;
        }

        $marker = $child['marker'];
        $identifier = (string) ($child['identifier'] ?? '?');
        $shouldPause = $this->budget->pauser(0.5);

        while (true) {
            $probe = BundledChildMarker::inspect(
                $marker,
                isset($child['started_at']) ? (int) $child['started_at'] : null,
                isset($child['parent_pid']) ? (int) $child['parent_pid'] : null,
            );
            $pid = $probe['pid'] ?? ($child['pid'] ?? null);

            if ($probe['state'] !== BundledChildMarker::RUNNING) {
                break;
            }

            if (! isset($child['waiting_since'])) {
                $child['waiting_since'] = time();
                $child['pid'] = $pid;
                $this->state->mergeContext(['bundled_child' => $child]);
                $this->state->appendLog((string) __('settings.core_update.web.log_bundled_child_waiting', [
                    'identifier' => $identifier,
                    'pid' => (string) ($pid ?? '?'),
                ]));
            } elseif (($child['pid'] ?? null) === null && $pid !== null) {
                $child['pid'] = $pid;
                $this->state->mergeContext(['bundled_child' => $child]);
            }

            if (time() - (int) $child['waiting_since'] >= self::BUNDLED_CHILD_WAIT_SECONDS) {
                Log::warning('웹 코어 업데이트: 끊긴 번들 항목 자식 기다림 상한 초과', ['identifier' => $identifier, 'pid' => $pid]);
                $this->state->appendWarnings([(string) __('settings.core_update.web.bundled_child_wait_timeout', [
                    'identifier' => $identifier,
                    'pid' => (string) ($pid ?? '?'),
                    'minutes' => (string) intdiv(self::BUNDLED_CHILD_WAIT_SECONDS, 60),
                ])]);
                $this->forgetInterruptedItemManifest($child);
                $this->state->mergeContext(['bundled_child' => null]);

                return true;
            }

            if ($shouldPause()) {
                return false;
            }

            usleep(500_000);
        }

        if ($probe['state'] === BundledChildMarker::ORPHAN) {
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_child_orphaned', [
                'identifier' => $identifier,
                'pid' => (string) ($pid ?? '?'),
            ]));
            $this->recoverOrphanedBundledItem($child, $probe['locks']);
        } elseif (isset($child['waiting_since'])) {
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_child_finished', [
                'identifier' => $identifier,
            ]));
        }

        $this->forgetInterruptedItemManifest($child);
        $this->state->mergeContext(['bundled_child' => null]);

        return true;
    }

    /**
     * 끊긴 요청이 지우지 못한 항목 매니페스트를 지웁니다.
     *
     * 항목을 돌린 요청은 `finally` 에서 매니페스트를 지우지만, 시간 초과·강제 종료로 죽으면 그 자리가 돌지 않는다. 자식은 시작할 때
     * 한 번 읽으므로 이 시점(자식이 끝났거나 사라졌거나 기다림을 포기함)에는 쓸모가 없다. 생산자 이름 규약의 파일만 지운다.
     *
     * @param  array<string, mixed>  $child  `context.bundled_child`
     * @return void 반환값 없음
     */
    private function forgetInterruptedItemManifest(array $child): void
    {
        $manifest = $child['manifest'] ?? null;

        if (is_string($manifest) && str_starts_with(basename($manifest), 'bundled-updates-manifest_') && is_file($manifest)) {
            @unlink($manifest);
        }
    }

    /**
     * 번들 항목 확장의 지금 상태를 읽습니다 (업데이트 전 상태 기록용).
     *
     * @param  string  $type  항목 종류 (module|plugin|template|lang_pack)
     * @param  string  $identifier  식별자
     * @return string|null 상태 (행이 없거나 읽지 못하면 null)
     */
    private function bundledItemStatus(string $type, string $identifier): ?string
    {
        try {
            $record = match ($type) {
                'module' => app(ModuleRepositoryInterface::class)->findByIdentifier($identifier),
                'plugin' => app(PluginRepositoryInterface::class)->findByIdentifier($identifier),
                'template' => app(TemplateRepositoryInterface::class)->findByIdentifier($identifier),
                'lang_pack' => app(LanguagePackRepositoryInterface::class)->findByIdentifier($identifier),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('웹 코어 업데이트: 번들 항목 상태 판독 실패', ['type' => $type, 'identifier' => $identifier, 'error' => $e->getMessage()]);

            return null;
        }

        $status = $record?->status ?? null;

        return $status instanceof \BackedEnum ? (string) $status->value : (is_string($status) ? $status : null);
    }

    /**
     * 죽은 것을 확인한 자식이 남긴 확장 작업 잠금과 `updating` 상태를 정리합니다.
     *
     * 자식은 확장 작업 잠금(10분)을 쥐고 행을 `updating` 으로 둔 채 죽었다. 그대로 두면 안내대로 개별 업데이트를 해도 잠금 만료까지
     * 거절되고, 그 뒤에도 `--force` 정리·활성화가 필요했다. 고아 판정은 그 자식이 사라졌다는 확인이므로 잠금을 풀고, 멈춘
     * 업데이트 정리({@see StuckUpdateRecovery::recover()})를 시간 조건 없이 적용한다 — 파일을 바꾸기 전이면 업데이트 전 상태로,
     * 바꾼 뒤면 시작 판·「업데이트 있음」·비활성으로 둔다(반쯤 바뀐 파일로 켜지 않는다). 언어팩은 `updating` 이면 업데이트 전
     * 상태로 되돌린다(언어팩 업데이트 실패 처리와 같다). 실패는 기록만 한다 — 업데이트 진행을 막지 않는다.
     *
     * 잠금은 자식이 표식에 적어 둔 소유자로만 푼다 — 그새 다른 작업이 같은 잠금을 잡았으면 소유자가 달라 건드리지 않는다. 그
     * 확장의 잠금 기록이 없으면(표식을 못 잡은 자식·잠금을 잡기 전에 죽은 자식) 그 확장의 `updating`·잠금이 자식 것이라는 근거가
     * 없으므로 아무것도 하지 않는다 — 다른 작업(운영자의 명령줄 업데이트 등)의 것일 수 있고, 잠금은 만료된다.
     *
     * 다음 요청의 고아 판정과, 부모가 자식의 비정상 종료(결과 없음·표식에 완료 없음)를 직접 받은 경우가 함께 쓴다.
     *
     * @param  array<string, mixed>  $child  `context.bundled_child`
     * @param  array<string, string>  $locks  자식이 표식에 적은 잠금 (키 → 소유자)
     * @return void 반환값 없음
     */
    private function recoverOrphanedBundledItem(array $child, array $locks = []): void
    {
        $type = (string) ($child['type'] ?? '');
        $identifier = (string) ($child['identifier'] ?? '');
        $priorStatus = is_string($child['prior_status'] ?? null) ? $child['prior_status'] : null;

        if ($identifier === '' || ! in_array($type, ['module', 'plugin', 'template', 'lang_pack'], true)) {
            return;
        }

        $lockType = $type === 'lang_pack' ? 'language_pack' : $type;
        $itemKey = ExtensionOperationLock::key($lockType, $identifier);

        foreach ($locks as $key => $owner) {
            if (is_string($key) && is_string($owner) && $owner !== '') {
                ExtensionOperationLock::releaseAbandonedKey($key, $owner);
            }
        }

        if (! isset($locks[$itemKey])) {
            return;
        }

        try {
            $restored = null;

            if ($type === 'lang_pack') {
                $repository = app(LanguagePackRepositoryInterface::class);
                $pack = $repository->findByIdentifier($identifier);
                $current = $pack?->status instanceof \BackedEnum ? $pack->status->value : $pack?->status;

                if ($pack !== null && $current === LanguagePackStatus::Updating->value && $priorStatus !== null && $priorStatus !== $current) {
                    $repository->update($pack, ['status' => $priorStatus]);
                    $restored = $priorStatus;
                }
            } else {
                $repository = match ($type) {
                    'module' => app(ModuleRepositoryInterface::class),
                    'plugin' => app(PluginRepositoryInterface::class),
                    default => app(TemplateRepositoryInterface::class),
                };
                $record = $repository->findByIdentifier($identifier);

                if ($record !== null) {
                    $restored = StuckUpdateRecovery::recover(
                        $type,
                        $identifier,
                        $record,
                        fn (array $attributes) => $repository->updateByIdentifier($identifier, $attributes),
                        lockHeld: true,
                        holderGone: true,
                        restoreStatus: $priorStatus,
                    );
                    StuckUpdateRecovery::takeRecovered();
                }
            }

            if ($restored !== null) {
                $this->state->appendLog((string) __('settings.core_update.web.log_bundled_child_orphan_recovered', [
                    'identifier' => $identifier,
                    'status' => $restored,
                ]));
            }
        } catch (\Throwable $e) {
            Log::warning('웹 코어 업데이트: 고아 번들 항목의 확장 상태 정리 실패', ['type' => $type, 'identifier' => $identifier, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 요청 시간 제한으로 끊긴 번들 항목이 실제로 새 판으로 반영됐는지 확인해 성공으로 세고, 확인하지 못한 항목을 돌려줍니다.
     *
     * 끊긴 것은 그 항목을 기다리던 요청이지 항목 자식 프로세스가 아니다 — 자식은 끝까지 돌 수 있다. 단계 끝(적어도 한
     * 요청 뒤)에 「번들에 아직 새 판이 남아 있는가」(코어 업데이트 화면이 업데이트 대상을 고를 때와 같은 판정)로 확인한다:
     * 남아 있지 않으면 반영된 것이므로 성공, 남아 있으면 자식이 실패했거나 아직 도는 중이라 단정할 수 없어 「확인 불가」다.
     * 판정 자체가 실패하면 모두 확인 불가로 둔다.
     *
     * @param  array<string, mixed>  $progress  번들 진행분 (`success` 를 고친다)
     * @return list<string> 확인하지 못한 항목 식별자
     */
    private function resolveInterruptedBundledItems(array &$progress): array
    {
        $interrupted = array_values(array_filter((array) ($progress['interrupted'] ?? []), 'is_array'));

        if ($interrupted === []) {
            return [];
        }

        try {
            $pending = [];
            $updates = $this->service->collectBundledExtensionUpdates();
            $updates['lang_packs'] = app(LanguagePackService::class)->collectBundledLangPackUpdates();

            foreach (['modules' => 'module', 'plugins' => 'plugin', 'templates' => 'template', 'lang_packs' => 'lang_pack'] as $key => $type) {
                foreach ((array) ($updates[$key] ?? []) as $entry) {
                    $pending[$type.':'.(string) (is_array($entry) ? ($entry['identifier'] ?? '') : '')] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('웹 코어 업데이트: 끊긴 번들 항목 판 확인 실패', ['error' => $e->getMessage()]);

            return array_values(array_map(static fn (array $item): string => (string) ($item['identifier'] ?? '?'), $interrupted));
        }

        $unconfirmed = [];

        foreach ($interrupted as $item) {
            $identifier = (string) ($item['identifier'] ?? '?');

            if (isset($pending[(string) ($item['type'] ?? '').':'.$identifier])) {
                $unconfirmed[] = $identifier;

                continue;
            }

            $progress['success'] = (int) ($progress['success'] ?? 0) + 1;
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_item_interrupted_applied', [
                'identifier' => $identifier,
            ]));
        }

        return $unconfirmed;
    }

    /**
     * 처리 순서로 편 번들 항목에 매니페스트의 이전 판·새 판을 붙입니다 (실행 기록·화면의 「지금 처리 중」용).
     *
     * `flattenItems()` 는 처리에 쓰는 키(type·identifier·strategy)만 남기므로 판은 여기서 다시 붙인다. 판을 모르는 항목은 null 이다.
     *
     * @param  array<int, array<string, mixed>>  $items  `ExecuteBundledUpdatesCommand::flattenItems()` 결과
     * @param  array<string, mixed>  $manifest  {@see buildBundledManifest()} 결과 (선택 반영 후)
     * @return array<int, array<string, mixed>> 판을 붙인 항목 목록 (순서는 그대로)
     */
    public static function withBundledItemVersions(array $items, array $manifest): array
    {
        $versions = [];

        foreach (['modules' => 'module', 'plugins' => 'plugin', 'templates' => 'template', 'lang_packs' => 'lang_pack'] as $group => $type) {
            foreach ((array) ($manifest[$group] ?? []) as $entry) {
                if (is_array($entry)) {
                    $versions[$type.':'.(string) ($entry['identifier'] ?? '')] = $entry;
                }
            }
        }

        return array_map(static function (array $item) use ($versions): array {
            $entry = $versions[(string) ($item['type'] ?? '').':'.(string) ($item['identifier'] ?? '')] ?? [];
            $from = (string) ($entry['from_version'] ?? '');
            $to = (string) ($entry['to_version'] ?? '');

            return $item + ['from_version' => $from !== '' ? $from : null, 'to_version' => $to !== '' ? $to : null];
        }, array_values($items));
    }

    /**
     * 번들 단계를 시작할 때 업데이트 대상 수를 종류별로 한 줄 남깁니다 (명령줄의 「감지 목록」에 해당 — 항목별 줄은 길어 수만).
     *
     * @param  array<int, array<string, mixed>>  $items  처리 순서의 항목 목록
     * @return void 반환값 없음
     */
    private function logBundledTargets(array $items): void
    {
        $counts = [];

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $parts = [];

        foreach (['module', 'plugin', 'template', 'lang_pack'] as $type) {
            if (($counts[$type] ?? 0) > 0) {
                $parts[] = (string) __('settings.core_update.web.log_bundled_targets_part', [
                    'type' => self::bundledTypeLabel($type),
                    'count' => (string) $counts[$type],
                ]);
            }
        }

        $this->state->appendLog((string) __('settings.core_update.web.log_bundled_targets', [
            'total' => (string) count($items),
            'summary' => implode(', ', $parts),
        ]));
    }

    /**
     * 번들 항목 종류의 사람이 읽는 이름 (모듈·플러그인·템플릿·언어팩, 현재 로케일).
     *
     * @param  string  $type  항목 종류 (module|plugin|template|lang_pack)
     * @return string 종류 이름 (모르는 종류면 그대로)
     */
    private static function bundledTypeLabel(string $type): string
    {
        $key = 'settings.bundled_sources.types.'.($type === 'lang_pack' ? 'language_pack' : $type);
        $label = (string) __($key);

        return $label === $key ? $type : $label;
    }

    /**
     * 번들 항목 하나를 처리한 결과를 실행 기록 한 줄로 만듭니다 — 성공(판·소요)·실패(사유·다시 시도 명령)·중단·건너뜀.
     *
     * 판정은 진행분 집계와 같다: 결과 줄의 `failed` 가 있으면 그 수, 없으면 종료 코드. 결과 줄 없이 끝났으면 사유는 종료 코드다.
     *
     * @param  array<string, mixed>  $item  항목 (type·identifier·strategy, 있으면 from_version·to_version)
     * @param  int  $position  이 항목의 차례 (1부터)
     * @param  int  $total  전체 항목 수
     * @param  array<string, mixed>  $summary  자식의 `[BUNDLED-RESULT]` 페이로드 (없으면 빈 배열)
     * @param  int  $exitCode  자식 종료 코드
     * @param  float  $elapsed  걸린 초
     * @return string 기록 줄
     */
    public static function bundledItemLogLine(array $item, int $position, int $total, array $summary, int $exitCode, float $elapsed): string
    {
        $base = [
            'position' => (string) $position,
            'total' => (string) $total,
            'type' => self::bundledTypeLabel((string) ($item['type'] ?? '')),
            'identifier' => (string) ($item['identifier'] ?? '?'),
        ];
        $seconds = (string) max(0, (int) round($elapsed));
        $failed = isset($summary['failed']) ? (int) $summary['failed'] : ($exitCode === 0 ? 0 : 1);
        $command = ExecuteBundledUpdatesCommand::retryCommand($item);

        if (($summary['aborted'] ?? false) === true) {
            return (string) __('settings.core_update.web.log_bundled_item_aborted', $base + [
                'skipped' => (string) ((int) ($summary['skipped'] ?? 0)),
                'command' => $command,
            ]);
        }

        if ($failed > 0) {
            $first = is_array($summary['failed_items'] ?? null) ? ($summary['failed_items'][0] ?? null) : null;
            $reason = is_array($first) ? trim((string) ($first['reason'] ?? '')) : '';

            if ($reason === '') {
                $reason = $summary === []
                    ? (string) __('extensions.commands.bundled_update.item_process_aborted', ['code' => $exitCode])
                    : (string) __('settings.core_update.web.log_bundled_item_reason_unknown');
            }

            return (string) __('settings.core_update.web.log_bundled_item_failed', $base + [
                'reason' => $reason,
                'command' => $command,
            ]);
        }

        if ((int) ($summary['success'] ?? 0) <= 0) {
            return (string) __('settings.core_update.web.log_bundled_item_skipped', $base + ['seconds' => $seconds]);
        }

        $from = (string) ($item['from_version'] ?? '');
        $to = (string) ($item['to_version'] ?? '');

        if ($from === '' || $to === '') {
            return (string) __('settings.core_update.web.log_bundled_item_done_unversioned', $base + ['seconds' => $seconds]);
        }

        return (string) __('settings.core_update.web.log_bundled_item_done', $base + [
            'from' => $from,
            'to' => $to,
            'seconds' => $seconds,
        ]);
    }

    /**
     * 번들 단계가 다음에 처리할 항목 (응답의 `run.current_item`).
     *
     * 번들 단계가 진행 중이고 남은 항목이 있을 때만 값이 있다 — 그 밖(다른 단계·끝남·되돌리는 중·항목 목록 전)에는 null.
     *
     * @param  array<string, mixed>  $raw  상태 원본
     * @param  CoreUpdateStep|null  $next  다음 단계
     * @return array{step: string, type: string, identifier: string, name: string|null, from_version: string|null, to_version: string|null, position: int, total: int}|null 항목
     */
    private function bundledCurrentItem(array $raw, ?CoreUpdateStep $next): ?array
    {
        if ($next !== CoreUpdateStep::Bundled || ($raw['status'] ?? null) !== CoreUpdateRunState::STATUS_RUNNING) {
            return null;
        }

        $progress = $this->state->context('bundled_progress');

        if (! is_array($progress)) {
            return null;
        }

        $items = array_values((array) ($progress['items'] ?? []));
        $index = (int) ($progress['index'] ?? 0);
        $item = $items[$index] ?? null;

        if (! is_array($item)) {
            return null;
        }

        $from = (string) ($item['from_version'] ?? '');
        $to = (string) ($item['to_version'] ?? '');

        return [
            'step' => CoreUpdateStep::Bundled->value,
            'type' => (string) ($item['type'] ?? ''),
            'identifier' => (string) ($item['identifier'] ?? ''),
            'name' => is_string($item['name'] ?? null) && $item['name'] !== '' ? $item['name'] : null,
            'from_version' => $from !== '' ? $from : null,
            'to_version' => $to !== '' ? $to : null,
            'position' => $index + 1,
            'total' => count($items),
        ];
    }

    /**
     * 지금 단계가 어디까지 갔는지 (응답의 `run.step_progress`).
     *
     * 번들 단계는 다음 항목 정보로 셈한다(끝낸 항목 수 = 순번 - 1). 그 밖의 단계는 이어 갈 때 적어 둔 값을 쓰되,
     * 적어 둔 단계가 지금 단계와 같을 때만 싣는다 — 되돌리는 중이면 `rollback` 으로 적어 둔 값만 싣는다.
     *
     * @param  array<string, mixed>  $raw  상태 원본
     * @param  CoreUpdateStep|null  $next  다음 단계
     * @param  array<string, mixed>|null  $currentItem  번들 단계의 다음 항목 (`bundledCurrentItem()`)
     * @return array{step: string, done: int, total: int|null, unit: string}|null 진행량
     */
    private function stepProgressFor(array $raw, ?CoreUpdateStep $next, ?array $currentItem): ?array
    {
        if ($currentItem !== null) {
            return [
                'step' => CoreUpdateStep::Bundled->value,
                'done' => max(0, (int) $currentItem['position'] - 1),
                'total' => (int) $currentItem['total'],
                'unit' => 'items',
            ];
        }

        $stored = $this->state->context('step_progress');

        if (! is_array($stored) || ! is_string($stored['step'] ?? null)) {
            return null;
        }

        $status = $raw['status'] ?? null;
        $expected = match ($status) {
            CoreUpdateRunState::STATUS_RUNNING => $next?->value,
            CoreUpdateRunState::STATUS_ROLLING_BACK => self::PHASE_ROLLBACK,
            default => null,
        };

        if ($expected === null || $stored['step'] !== $expected) {
            return null;
        }

        return [
            'step' => $stored['step'],
            'done' => (int) ($stored['done'] ?? 0),
            'total' => isset($stored['total']) ? (int) $stored['total'] : null,
            'unit' => (string) ($stored['unit'] ?? 'files'),
        ];
    }

    /**
     * 단계가 다음 요청으로 이어 갈 때 어디까지 갔는지 적어 둡니다 (화면 진행 막대용 — 동작에는 쓰지 않는다).
     *
     * @param  string  $step  단계 값 (되돌리기면 `rollback`)
     * @param  int  $done  끝낸 양
     * @param  int|null  $total  전체 양 (모르면 null)
     * @param  string  $unit  세는 단위 (`files`|`entries`|`items`|`tasks`)
     * @return void 반환값 없음
     */
    private function noteStepProgress(string $step, int $done, ?int $total, string $unit): void
    {
        $this->state->mergeContext(['step_progress' => [
            'step' => $step,
            'done' => max(0, $done),
            'total' => $total !== null ? max(0, $total) : null,
            'unit' => $unit,
        ]]);
    }

    /**
     * 정해진 구간 순서 안에서 지금 구간 앞까지 끝낸 구간 수를 적어 둡니다 (마무리·되돌리기).
     *
     * @param  string  $step  단계 값 (`finalize`|`rollback`)
     * @param  array<int, string>  $phases  구간 순서
     * @param  string  $phase  지금 구간
     * @return void 반환값 없음
     */
    private function notePhaseProgress(string $step, array $phases, string $phase): void
    {
        $index = array_search($phase, $phases, true);

        if ($index === false) {
            return;
        }

        $this->noteStepProgress($step, (int) $index, count($phases), 'tasks');
    }

    /**
     * 번들 확장 항목 하나를 담은 매니페스트 파일을 씁니다.
     *
     * @param  array<string, mixed>  $item  `ExecuteBundledUpdatesCommand::flattenItems()` 항목
     * @param  bool  $rebuildSearchIndex  이 항목 뒤에 검색 인덱스를 다시 만들지 (마지막 항목만)
     * @param  string|null  $childMarker  자식이 잡을 표식 경로
     * @param  ExtensionBatchUpdateContext|null  $batch  이 번들 단계의 묶음 (없으면 싣지 않음 — 1개짜리처럼 동작)
     * @return string 매니페스트 경로
     */
    private function writeBundledItemManifest(array $item, bool $rebuildSearchIndex, ?string $childMarker = null, ?ExtensionBatchUpdateContext $batch = null): string
    {
        // 항목 하나짜리 매니페스트 모양은 명령 쪽이 소유한다 — 같은 함수를 써서 두 곳이 갈리지 않게 한다.
        $manifest = ExecuteBundledUpdatesCommand::manifestForItem($item);
        $manifest['rebuild_search_index'] = $rebuildSearchIndex;
        // 항목마다 정적 게시(전 로케일 문구 병합·전 템플릿 산출물 복사)를 되풀이하지 않는다 — 마무리 단계가 한 번 예약한다
        $manifest['defer_static_publish'] = true;

        if ($childMarker !== null) {
            $manifest['child_marker'] = $childMarker;
        }

        if ($batch !== null) {
            $manifest[ExtensionBatchUpdateContext::MANIFEST_KEY] = $batch->toManifest();
        }

        $directory = storage_path('app'.DIRECTORY_SEPARATOR.'core_pending');
        File::ensureDirectoryExists($directory, 0770, true);

        // 이름 규약은 생산자 상수와 같은 것을 쓴다 — 정리 주체(`StorageLeftoverPruneService`)가
        // 그 접두사로 회수하므로, 다른 이름을 쓰면 이 파일만 영구히 남는다.
        $path = $directory.DIRECTORY_SEPARATOR.'bundled-updates-manifest_'.Str::uuid().'.json';

        File::put($path, (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * 마무리 — 버전 기록·캐시·소유권 복원·링크 복구·정리·유지보수 해제.
     *
     * @return void 반환값 없음
     */
    private function stepFinalize(): void
    {
        $raw = $this->state->all();
        $toVersion = (string) ($raw['to_version'] ?? '');
        $progress = (array) ($this->state->context('finalize_progress') ?? []);
        $phase = (string) ($progress['phase'] ?? 'prepare');
        $shouldPause = $this->budget->pauser();

        if ($phase === 'prepare') {
            if ($toVersion !== '') {
                $this->service->updateVersionInEnv($toVersion);
            }

            $this->service->clearAllCaches();

            // 상주 큐 워커는 부팅이 한 번뿐이라 옛 코드를 물고 있다 — CLI 와 같은 자리(캐시가
            // 새 코드 기준으로 정리된 직후)에서 재시작 신호를 보낸다. 신호 전송 실패는 업데이트
            // 결과를 되돌리지 않는다(서비스가 경고만 남긴다). 무CLI 호스팅처럼 워커가 없는
            // 사이트에서는 캐시 키 하나를 쓰고 끝난다.
            $this->service->signalQueueRestart();
            $this->state->appendLog((string) __('settings.core_update.web.log_finalize_prepared'));

            // 확장 오토로드·훅 캐시는 위 캐시 초기화가 새 판 코드로 다시 굽는다(이 요청은 파일 적용 뒤 새로 부팅했다).
            // 굽기에 실패하면 터미널처럼 운영자가 직접 실행할 명령을 남긴다 — 업데이트 결과는 되돌리지 않는다.
            $autoloadFailed = false;
            try {
                $autoloadFailed = $this->service->autoloadRefreshFailed();
            } catch (\Throwable) {
                $autoloadFailed = false;
            }
            if ($autoloadFailed) {
                $message = (string) __('settings.core_update.web.log_finalize_autoload_failed');
                $this->state->appendLog($message);
                $this->state->appendWarnings([$message]);
            } else {
                $this->state->appendLog((string) __('settings.core_update.web.log_finalize_autoload_rebuilt'));
            }

            $phase = 'cleanup';
            $this->state->mergeContext(['finalize_progress' => ['phase' => $phase]]);

            if ($shouldPause()) {
                $this->repeatCurrentStep = true;

                return;
            }
        }

        if ($phase === 'cleanup') {
            // 격리 디렉토리(전개본 · 옮겨 둔 옛 vendor)와 백업은 수만 파일이다 — 지우기도 요청 몫 안에서 나눈다.
            // 소유권 복원보다 **먼저** 지운다 — 복원이 훑는 대상(저장소의 백업·전개본 폴더)이 줄어든다.
            if (! $this->cleanupRunArtifacts(includeBackup: true, shouldPause: $shouldPause)) {
                $this->notePhaseProgress(CoreUpdateStep::Finalize->value, self::FINALIZE_PHASES, $phase);
                $this->state->appendLog((string) __('settings.core_update.web.log_cleanup_continues'));
                $this->repeatCurrentStep = true;

                return;
            }

            $this->state->appendLog((string) __('settings.core_update.web.log_finalize_cleaned'));

            $phase = 'ownership';
            $this->state->mergeContext(['finalize_progress' => ['phase' => $phase]]);

            if ($shouldPause()) {
                $this->repeatCurrentStep = true;

                return;
            }
        }

        if ($phase === 'ownership') {
            // 소유권·그룹 쓰기 권한 복원은 vendor·확장 트리를 다 훑는다(큰 사이트에서는 수 분 — 마무리에서 가장 긴 구간).
            if (! $this->restoreOwnershipSnapshot($shouldPause)) {
                $this->notePhaseProgress(CoreUpdateStep::Finalize->value, self::FINALIZE_PHASES, $phase);
                $this->state->appendLog((string) __('settings.core_update.web.log_ownership_continues'));
                $this->repeatCurrentStep = true;

                return;
            }

            $this->state->appendLog((string) __('settings.core_update.web.log_finalize_ownership'));

            $phase = 'link';
            $this->state->mergeContext(['finalize_progress' => ['phase' => $phase]]);

            if ($shouldPause()) {
                $this->repeatCurrentStep = true;

                return;
            }
        }

        // 링크 점검이 계속 끊겨 건너뛴 경우(`finish`)는 바로 점검 모드 해제로 간다.
        if ($phase !== 'finish') {
            StorageLinkHelper::ensurePublicStorageLink();
        }

        $this->disableMaintenanceIfEnabled();

        // 모든 파일이 안착한 뒤에만 캐시를 되살린다 — 흐름 중간에 구우면 그 시점의
        // 반쪽 상태가 박제된다.
        ConfigCacheHelper::rebuild();
        RouteCacheHelper::rebuild();

        // 번들 항목 프로세스는 정적 게시를 건너뛰었다 — 모든 확장이 안착한 지금 한 번만 예약한다(이 요청이 끝날 때 게시).
        // 운영 환경·웹 계정일 때만 예약되고, 그 밖에는 다음 웹 렌더의 자가 치유가 같은 일을 한다.
        ExtensionStaticCacheService::schedulePublishOnTerminate();
        $this->state->appendLog((string) __($phase !== 'finish'
            ? 'settings.core_update.web.log_finalize_link_cache'
            : 'settings.core_update.web.log_finalize_cache'));

        $this->state->mergeContext(['finalize_progress' => null]);
        $this->state->appendLog((string) __('settings.core_update.web.log_finalized', ['version' => $toVersion]));
    }

    /**
     * 끝나지 않은 백업(백업 단계 도중에 실패)을 요청 몫 안에서 지웁니다.
     *
     * 다 만들어지기 전의 백업은 `backup_path` 에 싣지 않으므로(복원 원본으로 쓰면 안 된다) 되돌리기의
     * 복원 대상도 아니다. 남겨 두면 실패할 때마다 트리 사본 하나가 저장소에 쌓인다.
     *
     * @param  \Closure  $shouldPause  멈출 때인지 묻는 함수
     * @return bool 다 지웠으면 true
     */
    private function cleanupPartialBackup(\Closure $shouldPause): bool
    {
        if ((string) $this->state->context('backup_path', '') !== '') {
            return true;
        }

        $progress = (array) ($this->state->context('backup_progress') ?? []);
        $partial = (string) ($progress['backup_path'] ?? ($progress['copy']['backup_path'] ?? ''));

        if ($partial === '' || ! is_dir($partial)) {
            return true;
        }

        if (! FilePermissionHelper::deleteDirectoryChunk($partial, $shouldPause)) {
            return false;
        }

        CoreBackupHelper::deleteBackup($partial);
        $this->state->mergeContext(['backup_progress' => null]);

        return true;
    }

    /**
     * 이번 실행이 남긴 격리 디렉토리(와 마무리면 백업)를 요청 몫 안에서 지웁니다.
     *
     * 다 지우면 서비스의 정리(`cleanupPending`·`deleteBackup`)를 한 번 더 불러 남은 껍데기를 걷고 기록을
     * 남긴다 — 그 함수들은 이미 비었으면 바로 끝난다.
     *
     * @param  bool  $includeBackup  백업도 지울지 (마무리는 true, 되돌리기는 복원 원본이라 false)
     * @param  \Closure  $shouldPause  멈출 때인지 묻는 함수
     * @return bool 다 지웠으면 true
     */
    private function cleanupRunArtifacts(bool $includeBackup, \Closure $shouldPause): bool
    {
        $pendingPath = (string) $this->state->context('pending_path', '');

        if ($pendingPath !== '') {
            if (! FilePermissionHelper::deleteDirectoryChunk($this->service->resolveStagingRoot($pendingPath), $shouldPause)) {
                return false;
            }

            $this->service->cleanupPending($pendingPath);
        }

        $backupPath = (string) $this->state->context('backup_path', '');

        if ($includeBackup && $backupPath !== '') {
            if (! FilePermissionHelper::deleteDirectoryChunk($backupPath, $shouldPause)) {
                return false;
            }

            CoreBackupHelper::deleteBackup($backupPath);
        }

        return true;
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
        $this->appendApplyWarnings($this->service->getLastRootFileWarnings());

        // 실패한 단계의 진행량은 더 쓰지 않는다 — 되돌리기가 자기 진행량을 새로 적는다
        $this->state->mergeContext(['step_progress' => null]);

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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    private function performRollback(): void
    {
        $backupPath = (string) $this->state->context('backup_path', '');
        $applied = in_array(CoreUpdateStep::Apply->value, $this->state->completedSteps(), true);
        $progress = (array) ($this->state->context('rollback_progress') ?? []);
        $phase = (string) ($progress['phase'] ?? 'vendor');
        $restored = (bool) ($progress['restored'] ?? false);
        $failedTargets = array_values((array) ($progress['failed_targets'] ?? []));
        $shouldPause = $this->budget->pauser();

        // 되돌리기도 여러 요청에 걸친다 — 한 요청에 트리 전체를 덮어쓰면 요청 시간 제한에 걸리고, 그러면
        // 사이트가 반쯤 되돌려진 채 점검 모드에 남는다. 각 구간의 진행분을 `rollback_progress` 에 남기므로
        // 요청이 끊겨도 다음 요청이 그 자리에서 이어 받는다(종료 처리가 재시도 횟수만 센다).
        $save = function (string $phase, array $extra = []) use (&$restored, &$failedTargets): void {
            $this->state->mergeContext(['rollback_progress' => array_merge([
                'phase' => $phase,
                'restored' => $restored,
                'failed_targets' => $failedTargets,
            ], $extra)]);
        };

        if ($phase === 'vendor') {
            // 바꿔 끼운 vendor 는 옮겨 둔 옛 vendor 를 그대로 다시 끼운다 — 수만 파일을 복사하지 않는다.
            $previous = (string) ($this->state->context('vendor_swap')['previous'] ?? '');

            if ($previous !== '' && is_dir($previous)) {
                // 옮겨 둔 자리(격리 디렉토리) 옆에 새 vendor 를 치워 둔다 — 정리 구간이 격리 디렉토리째 지운다.
                $discard = dirname($previous).DIRECTORY_SEPARATOR.'vendor-discarded-'.Str::random(6);

                $swapped = $this->service->swapVendorBack($previous, $discard);

                if ($swapped) {
                    $this->state->mergeContext(['vendor_swap' => ['previous' => null, 'restored' => true]]);
                } elseif (($failure = FilePermissionHelper::takeLastSwapFailure()) !== null) {
                    // 되돌리기의 다시 끼우기도 운영 자리를 비운 채 실패했다 — 아래 복원이 백업의 vendor 로 덮어쓴다.
                    // 그 사실과 경로를 남겨 운영자가 확인할 수 있게 한다.
                    $this->state->mergeContext(['vendor_swap_failure' => $failure]);
                    $this->state->appendWarnings([(string) __('settings.core_update.web.vendor_swap_back_failed', [
                        'live' => (string) $failure['live'],
                        'parked' => (string) $failure['parked'],
                    ])]);
                }
            } else {
                $swapped = false;
            }

            $phase = $backupPath !== '' && is_dir($backupPath) ? 'restore' : 'cleanup';

            if ($phase === 'cleanup') {
                // 아직 파일을 건드리지 않았다 — 되돌릴 것이 없는 것이 정상이다.
                $restored = ! $applied;
            }

            $save($phase);

            if ($swapped) {
                // 이 프로세스의 클래스 로더는 새 vendor 의 클래스 맵을 들고 있다 — 같은 요청에서 복원을 이어 가면
                // 옛 vendor 파일과 새 클래스 맵이 섞인다. 다음 요청(옛 vendor 로 부팅)이 이어 받는다.
                return;
            }
        }

        if ($phase === 'restore') {
            try {
                // vendor 는 이번 실행이 바꾼 채로 남아 있을 때만 백업으로 덮어쓴다 — 바꿔 끼운 vendor 는 위에서
                // 다시 끼웠고, 반영 단계 전이면 손대지 않았다. 다시 끼우지 못했으면(이름 바꾸기 실패) 덮어쓴다.
                $swap = (array) ($this->state->context('vendor_swap') ?? []);
                $vendorStillChanged = $this->state->context('vendor_copied') === true
                    || (is_string($swap['previous'] ?? null) && $swap['previous'] !== '');
                $skipTargets = $vendorStillChanged
                    ? []
                    : array_values(array_map('strval', (array) config('app.update.backup_only', [])));

                $result = $this->service->restoreFromBackupChunk(
                    $backupPath,
                    (array) ($progress['restore'] ?? []),
                    $shouldPause,
                    $this->state->loadApplyList(),
                    $skipTargets,
                );

                if (! $result['done']) {
                    $save('restore', ['restore' => $result]);
                    $this->notePhaseProgress(self::PHASE_ROLLBACK, self::ROLLBACK_PHASES, 'restore');
                    $this->state->appendLog((string) __('settings.core_update.web.log_rollback_continues'));

                    return;
                }

                $failedTargets = (array) ($result['failed_targets'] ?? []);
                $restored = $failedTargets === [];

                // 복원이 끝났다는 사실을 **가장 먼저** 저장한다 — 그 뒤 구간(캐시 파일 지우기·소유권 복원)에서
                // 요청이 끊기면 다음 요청이 복원부터 다시 해 같은 자리에서 또 끊겨 재시도 상한에 닿는다.
                $save('ownership', ['caches_dropped' => false]);

                // 적용 단계가 낸 루트 진입·보호 파일·서버 설정 조각 안내는 복원이 끝난 지금 더는 사실이 아니다 — 그 파일은
                // 백업으로 돌아갔고 `.dist` 사본과 해시 원장은 복원이 걷었다. 남겨 두면 결과 화면이 없는 `.dist` 를 합치라고
                // 한다. 복원 자신의 경고는 아래에서 새로 싣는다. 서비스의 경고 버퍼가 복원 전에 비워지는 것과 같은 규약.
                $this->withdrawApplyWarnings();
                $this->restateBundledFailureAfterRollback();
                $this->state->appendWarnings((array) ($result['root_file_warnings'] ?? []));
                $this->state->appendLog($restored
                    ? (string) __('settings.core_update.web.log_restored')
                    : (string) __('settings.core_update.web.log_restored_partial', [
                        'targets' => implode(', ', array_map('strval', $failedTargets)),
                    ]));
            } catch (\Throwable $restoreError) {
                $restored = false;
                $this->state->appendLog((string) __('settings.core_update.web.log_restore_failed', [
                    'error' => $restoreError->getMessage(),
                ]));
                Log::error('웹 코어 업데이트: 백업 복원 실패', ['error' => $restoreError->getMessage()]);
                $save('ownership', ['caches_dropped' => false]);
            }

            $phase = 'ownership';
            $progress['caches_dropped'] = false;

            if ($shouldPause()) {
                return;
            }
        }

        if ($phase === 'ownership') {
            if (($progress['caches_dropped'] ?? false) !== true) {
                // 컴파일 캐시는 **파일만 지운다** — 여기서 `clearAllCaches()` 를 부르면 그 안의
                // `package:discover`·`extension:update-autoload` 가 방금 구버전으로 되돌린 디스크를
                // 신버전 클래스를 든 프로세스에서 다시 발견한다. 파일 적용 단계가 같은 이유로
                // 커맨드를 부르지 않는 것과 같은 규율이다 (§7 R25).
                $this->dropCompiledCaches();
                $save('ownership', ['caches_dropped' => true]);
                $progress['caches_dropped'] = true;
            }

            // 되돌리기는 파일을 원래대로 돌려놓는 것으로 끝나지 않는다 — 소유권·퍼미션까지
            // 되돌려야 「사이트는 정상 동작합니다」가 성립한다 (§7 R29). 복원하지 않으면
            // php-fpm 계정이 `storage`·`bootstrap/cache` 에 쓰지 못해 되돌린 사이트가
            // 500 으로 뜬다. 성공 경로(`stepFinalize`)와 같은 강도로 적용한다 — 같은 이유로 나눠 한다.
            if (! $this->restoreOwnershipSnapshot($shouldPause)) {
                $this->notePhaseProgress(self::PHASE_ROLLBACK, self::ROLLBACK_PHASES, 'ownership');
                $this->state->appendLog((string) __('settings.core_update.web.log_ownership_continues'));

                return;
            }

            $phase = 'cleanup';
            $save($phase);

            if ($shouldPause()) {
                return;
            }
        }

        if ($phase === 'cleanup') {
            if (($progress['bundled_record_restored'] ?? false) !== true) {
                $this->restoreBundledRecord();
                $save('cleanup', ['bundled_record_restored' => true]);
                $progress['bundled_record_restored'] = true;
            }

            try {
                if (! $this->cleanupPartialBackup($shouldPause) || ! $this->cleanupRunArtifacts(includeBackup: false, shouldPause: $shouldPause)) {
                    $this->notePhaseProgress(self::PHASE_ROLLBACK, self::ROLLBACK_PHASES, 'cleanup');
                    $this->state->appendLog((string) __('settings.core_update.web.log_cleanup_continues'));

                    return;
                }
            } catch (\Throwable $cleanupError) {
                Log::warning('웹 코어 업데이트: _pending 정리 실패', ['error' => $cleanupError->getMessage()]);
            }
        }

        if ($restored) {
            $this->disableMaintenanceIfEnabled();
        }

        $this->state->mergeContext(['rollback_progress' => null, 'step_progress' => null]);
        $this->finishRollback($restored, $failedTargets);
    }

    /**
     * 번들 출하 목록 대조 결과를 진행 기록·경고에 싣습니다.
     *
     * @param  array<string, mixed>  $report  `applyUpdate()` 반환값의 `bundled_prune`
     * @return void 반환값 없음
     */
    private function reportBundledPrune(array $report): void
    {
        $removed = array_values((array) ($report['removed'] ?? []));
        $kept = array_values((array) ($report['kept_modified'] ?? []));

        if ($removed !== []) {
            $this->state->appendLog(trans_count('settings.core_update.console.bundled_pruned', ['count' => count($removed)]));
        }

        if ($kept !== []) {
            $this->state->appendWarnings([trans_count('settings.core_update.console.bundled_prune_kept_modified', ['count' => count($kept)]).' '.implode(', ', $kept)]);
        }
    }

    /**
     * 파일 적용 단계의 루트 진입·보호 파일·서버 설정 조각 안내를 경고로 싣고, 되돌리기가 걷을 수 있게 따로 기억합니다.
     *
     * @param  array<int, mixed>  $warnings  `applyUpdate()` 의 `root_file_warnings` (또는 적용이 끊겼을 때의 서비스 경고 버퍼)
     * @return void 반환값 없음
     */
    private function appendApplyWarnings(array $warnings): void
    {
        $warnings = array_values(array_filter($warnings, static fn ($w): bool => is_string($w) && $w !== ''));

        if ($warnings === []) {
            return;
        }

        $this->state->appendWarnings($warnings);

        $recorded = array_values(array_filter((array) ($this->state->context('apply_warnings') ?? []), 'is_string'));
        $this->state->mergeContext(['apply_warnings' => array_values(array_unique(array_merge($recorded, $warnings)))]);
    }

    /**
     * 되돌리기 복원이 끝난 뒤 적용 단계 안내를 경고에서 걷고, 걷었으면 그 사실을 통보로 남깁니다.
     *
     * @return void 반환값 없음
     */
    private function withdrawApplyWarnings(): void
    {
        $recorded = array_values(array_filter((array) ($this->state->context('apply_warnings') ?? []), 'is_string'));

        if ($recorded === []) {
            return;
        }

        $withdrawn = $this->state->withdrawWarnings($recorded);
        $this->state->mergeContext(['apply_warnings' => null]);

        if ($withdrawn > 0) {
            $this->state->appendNotices([trans_count('settings.core_update.rollback_apply_warnings_withdrawn', ['count' => $withdrawn])]);
        }
    }

    /**
     * 되돌린 실행의 「번들 확장 일부 실패 — 코어는 정상 반영」 경고를 「코어는 되돌렸고 이미 갱신된 확장은 그대로」로 바꿉니다.
     *
     * 번들 단계 뒤(마무리 준비 구간)에서 실패해 되돌리면 코어 파일만 백업으로 돌아가고 이미 갱신된 확장은 그대로다. 실패한
     * 확장이 있다는 사실은 남겨야 운영자가 확장 상태를 확인하므로, 거짓이 된 「코어는 정상 반영」만 고친다.
     *
     * @return void 반환값 없음
     */
    private function restateBundledFailureAfterRollback(): void
    {
        $recorded = $this->state->context('bundled_failure_warning');

        if (! is_array($recorded) || ! is_string($recorded['message'] ?? null)) {
            return;
        }

        $this->state->mergeContext(['bundled_failure_warning' => null]);

        if ($this->state->withdrawWarnings([$recorded['message']]) > 0) {
            $failed = (int) ($recorded['failed'] ?? 0);
            $this->state->appendWarnings([trans_count('settings.core_update.web.bundled_partial_failure_rolled_back', [
                'failed' => (string) $failed,
                'count' => $failed,
            ])]);
        }
    }

    /**
     * 되돌리기 결과를 확정합니다 (되돌리기 완료와, 끊긴 되돌리기의 재시도 소진이 함께 쓴다).
     *
     * @param  bool  $restored  백업본으로 되돌렸는지
     * @param  array<int, mixed>  $failedTargets  되돌리지 못한 대상
     * @return void 반환값 없음
     */
    private function finishRollback(bool $restored, array $failedTargets): void
    {
        $backupPath = (string) $this->state->context('backup_path', '');
        $applied = in_array(CoreUpdateStep::Apply->value, $this->state->completedSteps(), true);

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
        $this->recordRunLogFile(false);

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
     * 파일 적용 단계가 쓴 공식 번들 배치 기록을 이전 내용으로 되돌립니다 (CLI `restoreBundledRecord` 와 같은 규칙).
     *
     * 이전 기록이 없었으면 기록 파일을 지운다. 되돌린 사이트의 확장 목록이 새 버전 배포본 기준의 미배치 안내를
     * 보여 주지 않게 하기 위해서다.
     *
     * @return void 반환값 없음
     */
    private function restoreBundledRecord(): void
    {
        if ($this->state->context('bundled_record_written') !== true) {
            return;
        }

        $path = app(BundledSourcePlanner::class)->recordPath();
        $previous = $this->state->context('bundled_record_backup');

        try {
            if (is_string($previous)) {
                File::put($path, $previous);
            } elseif (is_file($path)) {
                File::delete($path);
            }
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_record_restored'));
        } catch (\Throwable $e) {
            Log::warning('웹 코어 업데이트: 공식 번들 배치 기록 되돌리기 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 모든 단계를 마친 실행을 완료로 표시합니다.
     *
     * @return void 반환값 없음
     */
    private function finishSuccessfully(): void
    {
        if ($this->state->status() === CoreUpdateRunState::STATUS_COMPLETED) {
            return;
        }

        $this->state->patch(['status' => CoreUpdateRunState::STATUS_COMPLETED, 'next_step' => null]);
        $this->recordRunLogFile(true);

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
     * @return void 반환값 없음
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
     * 번들 업데이트 선택 창에 줄 항목 목록을 만듭니다 — 항목마다 지금 판·새 판과 기본 레이아웃 전략.
     *
     * 판정은 {@see buildBundledManifest()} 와 같은 함수를 쓴다(두 곳이 갈리지 않게).
     *
     * @param  CoreUpdateOptions  $options  착수 선택 (기본 레이아웃 전략)
     * @return array<int, array{key: string, type: string, identifier: string, current_version: string, latest_version: string, has_layouts: bool, strategy: string|null}> 항목 목록
     */
    private function bundledUpdateCandidates(CoreUpdateOptions $options): array
    {
        $updates = $this->service->collectBundledExtensionUpdates();
        $updates['lang_packs'] = app(LanguagePackService::class)->collectBundledLangPackUpdates();
        $types = ['modules' => 'module', 'plugins' => 'plugin', 'templates' => 'template', 'lang_packs' => 'lang_pack'];
        $items = [];

        foreach ($types as $group => $type) {
            foreach ((array) ($updates[$group] ?? []) as $entry) {
                $identifier = (string) ($entry['identifier'] ?? '');

                if ($identifier === '') {
                    continue;
                }

                $items[] = [
                    'key' => $type.':'.$identifier,
                    'type' => $type,
                    'identifier' => $identifier,
                    'current_version' => (string) ($entry['current_version'] ?? ''),
                    'latest_version' => (string) ($entry['latest_version'] ?? ''),
                    // 언어팩은 레이아웃이 없어 전략을 고르지 않는다
                    'has_layouts' => $type !== 'lang_pack',
                    'strategy' => $type !== 'lang_pack' ? $options->layoutStrategy : null,
                ];
            }
        }

        return $items;
    }

    /**
     * 운영자의 번들 업데이트 선택을 매니페스트에 반영합니다 — 고른 항목만 남기고 확장별 레이아웃 전략을 적는다.
     *
     * 선택에 없는 항목(선택 창을 연 뒤 새로 생긴 항목)은 올리지 않는다 — 운영자가 보지 못한 것을 올리지 않는다.
     *
     * @param  array<string, mixed>  $manifest  {@see buildBundledManifest()} 결과
     * @param  array<string, array{selected: bool, strategy: string}>  $selection  `{type}:{identifier}` => 선택
     * @return array<string, mixed>|null 반영한 매니페스트 (남은 항목이 없으면 null)
     */
    public static function applyBundledSelection(array $manifest, array $selection): ?array
    {
        $types = ['modules' => 'module', 'plugins' => 'plugin', 'templates' => 'template', 'lang_packs' => 'lang_pack'];
        $remaining = 0;

        foreach ($types as $group => $type) {
            $kept = [];

            foreach ((array) ($manifest[$group] ?? []) as $entry) {
                $choice = $selection[$type.':'.(string) ($entry['identifier'] ?? '')] ?? null;

                if (! is_array($choice) || ($choice['selected'] ?? false) !== true) {
                    continue;
                }

                if ($type !== 'lang_pack') {
                    $entry['strategy'] = ($choice['strategy'] ?? null) === CoreUpdateOptions::LAYOUT_KEEP
                        ? CoreUpdateOptions::LAYOUT_KEEP
                        : CoreUpdateOptions::LAYOUT_OVERWRITE;
                }

                $kept[] = $entry;
            }

            $manifest[$group] = $kept;
            $remaining += count($kept);
        }

        return $remaining === 0 ? null : $manifest;
    }

    /**
     * 번들 확장 일괄 업데이트 매니페스트를 만듭니다.
     *
     * 전역 레이아웃 전략(D29)을 모든 항목에 그대로 싣는다. 고르는 화면(7.2.1 `askBundledSelection`)이면 그 뒤
     * {@see applyBundledSelection()} 이 고른 항목과 확장별 전략으로 좁힌다.
     *
     * @param  CoreUpdateOptions  $options  착수 선택
     * @return array{modules: list<array<string, string>>, plugins: list<array<string, string>>, templates: list<array<string, string>>, lang_packs: list<array<string, string>>, rebuild_search_index: bool}|null 매니페스트 (대상이 없으면 null)
     */
    private function buildBundledManifest(CoreUpdateOptions $options): ?array
    {
        $updates = $this->service->collectBundledExtensionUpdates();
        $updates['lang_packs'] = app(LanguagePackService::class)->collectBundledLangPackUpdates();

        $manifest = ['modules' => [], 'plugins' => [], 'templates' => [], 'lang_packs' => []];
        $planned = 0;

        foreach (['modules', 'plugins', 'templates'] as $type) {
            foreach ((array) ($updates[$type] ?? []) as $entry) {
                // 이전 판·새 판은 실행 기록용이다 — 명령 쪽(flattenItems·manifestForItem)은 모르는 키를 무시한다
                $manifest[$type][] = [
                    'identifier' => (string) ($entry['identifier'] ?? ''),
                    'strategy' => $options->layoutStrategy,
                    'from_version' => (string) ($entry['current_version'] ?? ''),
                    'to_version' => (string) ($entry['latest_version'] ?? ''),
                ];
                $planned++;
            }
        }

        foreach ((array) ($updates['lang_packs'] ?? []) as $entry) {
            $manifest['lang_packs'][] = [
                'identifier' => (string) ($entry['identifier'] ?? ''),
                'from_version' => (string) ($entry['current_version'] ?? ''),
                'to_version' => (string) ($entry['latest_version'] ?? ''),
            ];
            $planned++;
        }

        if ($planned === 0) {
            return null;
        }

        $manifest['rebuild_search_index'] = $options->rebuildSearchIndex;

        return $manifest;
    }

    /**
     * 시작 전 스냅샷으로 소유권·퍼미션을 되돌리고, 실패는 경고로 노출합니다 (§7 R29).
     *
     * 성공(`stepFinalize`)과 되돌리기(`performRollback`) **양쪽**이 이 한 지점을 지난다 —
     * 한쪽에만 두면 되돌린 사이트가 쓰기 권한을 잃은 채 남고, 그 상태는 오류가 아니라
     * 「되돌렸는데 사이트가 안 뜬다」로만 드러난다.
     *
     * 대상 트리(vendor·확장·백업)를 다 훑으므로 요청 몫 안에서 멈추고(`ownership_progress`) 다음 요청이 이어 간다.
     *
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수 (null 이면 끝까지)
     * @return bool 끝났으면 true (멈췄으면 false — 같은 구간을 다음 요청에서 다시)
     */
    private function restoreOwnershipSnapshot(?\Closure $shouldPause = null): bool
    {
        $ownership = (array) $this->state->context('ownership', []);
        $detailed = (array) $this->state->context('detailed_ownership', []);

        if ($ownership === [] && $detailed === []) {
            // 백업 단계 전에 실패했다 — 되돌릴 스냅샷 자체가 없다.
            return true;
        }

        try {
            $result = $this->service->restoreOwnershipChunk(
                $ownership,
                $detailed,
                (array) ($this->state->context('ownership_progress') ?? []),
                $shouldPause,
            );
        } catch (\Throwable $e) {
            $this->state->mergeContext(['ownership_progress' => null]);
            $this->state->appendWarnings([(string) __('settings.core_update.web.ownership_restore_failed', [
                'error' => $e->getMessage(),
            ])]);

            return true;
        }

        if (! $result['done']) {
            // 경고는 끝난 뒤 한 번에 싣는다 — 진행분에 모아 둔다.
            $this->state->mergeContext(['ownership_progress' => $result]);

            return false;
        }

        $this->state->mergeContext(['ownership_progress' => null]);

        foreach ((array) $result['warnings'] as $warning) {
            $this->state->appendWarnings([(string) __('settings.core_update.web.permission_warning', [
                'target' => (string) ($warning['target'] ?? '?'),
                'failed' => (string) ((int) ($warning['failed'] ?? 0)),
            ]).' '.self::permissionWarningDetail((array) $warning)]);
        }

        return true;
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
     * @return void 반환값 없음
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
        foreach (preg_split('~\r\n|\r|\n~', self::stripAnsi($output)) ?: [] as $line) {
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
     * 하위 명령 출력에서 터미널 색·커서 제어 코드를 벗깁니다.
     *
     * 하위 명령이 콘솔에 붙은 프로세스(내장 서버 등)에서 돌면 출력에 색 코드가 켜진다. 그대로 실패 사유에
     * 실으면 화면과 실행 기록에 `\u001b[32m` 같은 글자가 그대로 보이고, 표식 줄(`[HANDOFF]`)도 앞에 색 코드가
     * 붙어 읽히지 않는다.
     *
     * @param  string  $output  출력
     * @return string 제어 코드를 뺀 출력
     */
    public static function stripAnsi(string $output): string
    {
        return (string) preg_replace('/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\x1B]*(?:\x07|\x1B\\\\)|[@-Z\\\\-_])/', '', $output);
    }

    /**
     * 실패 문구에 실을 만큼만 출력 꼬리를 잘라 냅니다.
     *
     * @param  string  $output  커맨드 출력
     * @return string 마지막 몇 줄
     */
    private function tailOutput(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('~\r\n|\r|\n~', self::stripAnsi($output)) ?: [])));

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
        return CoreUpdateService::readSourceVersion($sourcePath);
    }

    /**
     * 컴파일 캐시 파일을 지웁니다 (Artisan 을 부르지 않는다).
     *
     * 파일 교체 직후의 구버전 프로세스에서 커맨드를 돌리면 사라진 클래스를 로드한다.
     * 여기서는 **파일만 지워** 다음 요청이 디스크에서 새로 부팅하게 한다.
     *
     * @return void 반환값 없음
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
     * 응답에 실을 번들 단계 결과를 만듭니다 (완료 화면의 성공·실패·건너뜀 수와 실패 항목 표).
     *
     * @param  array<string, mixed>  $raw  상태 원본
     * @return array{success: int, failed: int, skipped: int, failed_items: list<array{type: string, identifier: string, reason: string, retry_command: string}>, search_index: array{status: string, message: string|null}|null}|null 결과 (번들 단계가 끝나지 않았으면 null)
     */
    private function bundledResultFor(array $raw): ?array
    {
        $result = $raw['context']['bundled_result'] ?? null;

        if (! is_array($result)) {
            return null;
        }

        $searchIndex = is_array($result['search_index'] ?? null) ? $result['search_index'] : null;

        return [
            'success' => (int) ($result['success'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'failed_items' => array_values(array_map(static fn (array $item): array => [
                'type' => (string) ($item['type'] ?? ''),
                'identifier' => (string) ($item['identifier'] ?? ''),
                'reason' => (string) ($item['reason'] ?? ''),
                'retry_command' => (string) ($item['retry_command'] ?? ExecuteBundledUpdatesCommand::retryCommand($item)),
            ], array_filter((array) ($result['failed_items'] ?? []), 'is_array'))),
            'search_index' => $searchIndex === null ? null : [
                'status' => (string) ($searchIndex['status'] ?? 'skipped'),
                'message' => isset($searchIndex['message']) ? (string) $searchIndex['message'] : null,
            ],
        ];
    }

    /**
     * 번들 항목 하나의 결과에서 실패 항목 목록을 만듭니다 (다시 시도할 명령은 명령줄과 같은 함수로 만든다).
     *
     * @param  array<string, mixed>  $item  처리한 항목 (`type`·`identifier`·`strategy`)
     * @param  array<string, mixed>  $summary  자식의 `[BUNDLED-RESULT]` 페이로드 (없으면 빈 배열)
     * @param  int  $exitCode  자식 종료 코드
     * @param  bool  $successful  자식이 성공으로 끝났는지
     * @return list<array{type: string, identifier: string, reason: string, retry_command: string}> 실패 항목 (실패가 없으면 빈 배열)
     */
    public static function bundledFailedItems(array $item, array $summary, int $exitCode, bool $successful): array
    {
        $failed = array_values(array_filter((array) ($summary['failed_items'] ?? []), 'is_array'));

        // 결과 줄 없이 실패로 끝났으면(자식이 죽음) 그 항목 하나를 실패로 적는다 — 명령줄의 「프로세스 중단」과 같은 사유
        if ($failed === [] && ((int) ($summary['failed'] ?? 0) > 0 || (! isset($summary['failed']) && ! $successful))) {
            $failed = [$item + ['reason' => (string) __('extensions.commands.bundled_update.item_process_aborted', ['code' => $exitCode])]];
        }

        return array_map(static fn (array $entry): array => [
            'type' => (string) ($entry['type'] ?? ($item['type'] ?? '')),
            'identifier' => (string) ($entry['identifier'] ?? ($item['identifier'] ?? '')),
            'reason' => (string) ($entry['reason'] ?? ''),
            'retry_command' => ExecuteBundledUpdatesCommand::retryCommand($entry + $item),
        ], $failed);
    }

    /**
     * 점검 모드가 켜진 채 남았는지 답합니다 (되돌리기 실패, 끝난 실행의 점검 해제 실패).
     *
     * @param  array<string, mixed>  $raw  상태 원본
     * @return bool 남았으면 true
     */
    private function maintenanceLeftOn(array $raw): bool
    {
        if (($raw['rollback']['maintenance_left_on'] ?? false) === true) {
            return true;
        }

        $finished = in_array($raw['status'] ?? null, [
            CoreUpdateRunState::STATUS_COMPLETED,
            CoreUpdateRunState::STATUS_ROLLED_BACK,
            CoreUpdateRunState::STATUS_ROLLBACK_FAILED,
        ], true);

        return $finished && ($raw['context']['maintenance_enabled'] ?? false) === true;
    }

    /**
     * 업데이트를 시작한 관리자를 찾습니다.
     *
     * @param  array<string, mixed>  $raw  상태 원본
     * @return array{id: int, name: string}|null 관리자 (기록이 없거나 찾지 못하면 null)
     */
    private function startedByFor(array $raw): ?array
    {
        $id = $raw['started_by'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        try {
            $user = User::query()->find((int) $id, ['id', 'name']);
        } catch (\Throwable) {
            // 업데이트 도중(마이그레이션 전후) 사용자 표를 읽지 못해도 상태 응답은 내보낸다
            return null;
        }

        return $user === null ? null : ['id' => (int) $user->id, 'name' => (string) $user->name];
    }

    /**
     * 받은 배포본이 요구하는 PHP 확장이 서버에 있는지 점검합니다 (터미널 `core:update` 의 시작 전 점검과 같은 판정·문구).
     *
     * 확장(모듈·플러그인)이 요구하는 PHP 확장이 없으면 경고만 남기고, 코어가 요구하는 확장이 없으면 이 단계에서 멈춘다.
     * 아직 파일을 바꾸지 않았으므로 되돌리기는 받은 배포본 정리와 점검 모드 해제뿐이다.
     *
     * @param  string  $pendingPath  받은 배포본 경로
     * @return void 반환값 없음
     *
     * @throws CoreUpdateOperationException 코어가 요구하는 PHP 확장이 없을 때
     */
    private function guardPhpRequirements(string $pendingPath): void
    {
        $result = app(PhpRequirementPreflight::class)->inspectDirectory($pendingPath);

        foreach ((array) ($result['extension_missing'] ?? []) as $extension => $missing) {
            $this->state->appendWarnings([(string) __('settings.core_update.console.php_extension_extension_warning', [
                'extension' => (string) $extension,
                'extensions' => implode(', ', (array) $missing),
            ])]);
        }

        if (($result['core_checked'] ?? false) !== true || ($result['core_missing'] ?? []) === []) {
            return;
        }

        throw new CoreUpdateOperationException('settings.core_update.console.php_extension_core_missing', [
            'extensions' => implode(', ', (array) $result['core_missing']),
        ]);
    }

    /**
     * 업그레이드 스텝 출력의 `[DOCROOT_EXPOSURE]` 표식을 읽어 경고로 옮깁니다 (터미널과 같은 머리말·항목).
     *
     * @param  string  $output  업그레이드 스텝 명령 출력
     * @return void 반환값 없음
     */
    private function appendDocrootExposureWarnings(string $output): void
    {
        $payload = $this->parseLineProtocol($output, '[DOCROOT_EXPOSURE] ');
        $items = array_values(array_filter(array_map('strval', (array) ($payload['warnings'] ?? [])), static fn (string $line): bool => $line !== ''));

        if ($items === []) {
            return;
        }

        $this->state->appendWarnings([
            (string) __('settings.core_update.console.docroot_exposure_header')."\n- ".implode("\n- ", $items),
        ]);
    }

    /**
     * 권한 정상화 실패 경고에 덧붙일 실패 경로(앞 5개)와 복구 명령 예시를 만듭니다 (터미널 안내와 같은 정보).
     *
     * @param  array<string, mixed>  $warning  `restoreOwnershipChunk()` 경고 항목 (`target`·`kind`·`failed`·`failed_paths`)
     * @return string 덧붙일 문구
     */
    public static function permissionWarningDetail(array $warning): string
    {
        $target = (string) ($warning['target'] ?? '<path>');
        $paths = array_values(array_map('strval', (array) ($warning['failed_paths'] ?? [])));
        $failed = max((int) ($warning['failed'] ?? 0), count($paths));
        $shown = array_slice($paths, 0, 5);
        $listed = $shown === [] ? $target : implode(', ', $shown);

        if ($failed > count($shown) && $shown !== []) {
            $listed .= ' '.__('settings.core_update.web.permission_warning_more', ['count' => (string) ($failed - count($shown))]);
        }

        $command = ($warning['kind'] ?? 'chown') === 'chown'
            ? "sudo chown -R <owner>:<group> {$target}"
            : "sudo chmod -R g+w {$target}";

        return (string) __('settings.core_update.web.permission_warning_detail', ['paths' => $listed, 'command' => $command]);
    }

    /**
     * 끝난 실행의 기록 전체를 업데이트 보고서 파일로 남기고 그 경로를 상태에 적습니다.
     *
     * 실행 상태는 [기록 정리]로 지워지지만 이 파일은 남는다. 실패해도 업데이트 결과는 바꾸지 않는다.
     *
     * @param  bool  $success  업데이트가 성공했는지
     * @return void 반환값 없음
     */
    private function recordRunLogFile(bool $success): void
    {
        try {
            $raw = $this->state->all();
            $path = self::writeUpdateReport(
                $this->state->logs(),
                (string) ($raw['from_version'] ?? ''),
                (string) ($raw['to_version'] ?? ''),
                $success,
            );
            $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
            $normalized = str_replace('\\', '/', $path);
            $this->state->patch(['log_file' => str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $normalized]);
        } catch (\Throwable $e) {
            Log::warning('웹 코어 업데이트: 실행 기록 파일 저장 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 업데이트 보고서 파일을 씁니다 (터미널 `core:update` 의 업데이트 로그와 같은 위치·형식).
     *
     * @param  array<int, string>  $entries  기록 줄
     * @param  string  $fromVersion  시작 버전
     * @param  string  $toVersion  대상 버전
     * @param  bool  $success  성공 여부
     * @return string 쓴 파일의 절대 경로
     */
    public static function writeUpdateReport(array $entries, string $fromVersion, string $toVersion, bool $success): string
    {
        // 이름은 생산자 단일 지점이 만든다 — 정리 주체가 같은 규칙으로 이 파일을 회수한다.
        $logPath = CoreUpdateService::updateReportPath($success ? 'success' : 'failed');

        $header = implode("\n", [
            '=== 그누보드7 코어 업데이트 로그 ===',
            '상태: '.($success ? '성공' : '실패'),
            // audit:allow datetime-display-user-timezone reason: 서버 로그·리포트 파일 — UTC 기록
            '날짜: '.date('Y-m-d H:i:s'),
            "시작 버전: {$fromVersion}",
            "대상 버전: {$toVersion}",
            '',
            '=== 실행 로그 ===',
        ]);

        File::ensureDirectoryExists(dirname($logPath));
        file_put_contents($logPath, $header."\n".implode("\n", $entries)."\n");
        // 웹 요청이 만든 파일도 부모(storage/logs) 소유권을 물려받는다(멱등, 권한 없으면 조용히 건너뜀)
        FilePermissionHelper::inheritOwnershipFromParent($logPath);

        return $logPath;
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
