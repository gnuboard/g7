<?php

namespace App\CoreUpdate;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\Exceptions\CoreUpdateOperationException;
use App\Exceptions\PackageIntegrityException;
use App\Execution\CommandRunnerResolver;
use App\Execution\CommandRunResult;
use App\Execution\Exceptions\CommandExecutionException;
use App\Execution\InProcessCommandRunner;
use App\Extension\CoreVersionChecker;
use App\Extension\Custom\CustomUpdatePreflight;
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\HookManager;
use App\Extension\Integrity\PackageVerification;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Services\Core\BundledPlacementPlan;
use App\Services\Core\BundledSourcePlanner;
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

    /** 점유에 기록하는 되돌리기 구간 이름 (단계 값과 겹치지 않는다). */
    public const PHASE_ROLLBACK = 'rollback';

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
                // 서버가 아직 어떤 단계를 돌리는 중인지 — 화면은 응답이 끊겨도 이 값으로 점유가
                // 풀릴 때까지 기다렸다가 스스로 다시 부른다.
                'claim' => $this->state->claimSummary(),
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
        if (($raw['status'] ?? null) === CoreUpdateRunState::STATUS_COMPLETED
            && is_string($raw['to_version'] ?? null) && $raw['to_version'] !== '') {
            return $raw['to_version'];
        }

        return CoreVersionChecker::getCoreVersion();
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
        // 이미 새 판으로 정상 반영됐다. 그 항목을 실패로 세고 다음 항목으로 넘어간다.
        if ($step === CoreUpdateStep::Bundled && is_array($progress = $this->state->context('bundled_progress'))) {
            $items = array_values((array) ($progress['items'] ?? []));
            $index = (int) ($progress['index'] ?? 0);
            $progress['failed'] = (int) ($progress['failed'] ?? 0) + 1;
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
            $this->state->appendLog((string) __('settings.core_update.web.log_extract_continues', [
                'done' => (string) $chunk['next'],
                'total' => (string) $chunk['total'],
            ]));
            $this->repeatCurrentStep = true;

            return;
        }

        $pendingPath = (string) $chunk['source_path'];

        // 다 풀었다 — 서명된 버전과 푼 배포본의 config/app.php 버전을 대조한다(7.2.0). 불일치면 이 단계가 실패해 되돌린다.
        if (is_array($progress['integrity'] ?? null)) {
            $this->service->confirmCoreIntegrity(PackageVerification::fromArray($progress['integrity']), $pendingPath, $options->integrityOptions());
        }

        if (($progress['manual'] ?? false) !== true && is_file((string) $progress['archive'])) {
            @unlink((string) $progress['archive']);
        }

        $this->state->mergeContext(['pending_path' => $pendingPath, 'download_progress' => null]);

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
     * 새 코어와 맞지 않으면 백업과 파일 적용 전에 멈춘다 (#792, CLI `core:update` 와 같은 판정).
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
     * 공식 번들 배치 판정 (#146) — 받은 배포본의 `_bundled` 중 이 사이트가 설치했거나 디스크에 둔 번들과 그 의존만
     * 배치하고, 나머지(미배치)는 파일 적용, 신규 파일 기록, 증분 목록에서 건너뛴다.
     *
     * 웹에는 운영자에게 물을 자리가 없으므로 CLI 의 비대화 기본값(`--bundled-sources` 없음)과 같은 판정이다. 미배치 번들은
     * 관리자 확장 목록 안내와 `core:bundled-sources` 로 나중에 배치한다. 계획 단계와 적용 단계가 따로 부르지만 그 사이
     * 설치 상태가 바뀌지 않으므로 같은 결과다.
     *
     * @param  string  $pendingPath  받은 배포본 경로
     * @return BundledPlacementPlan 판정 결과
     */
    private function planBundledPlacement(string $pendingPath): BundledPlacementPlan
    {
        $planner = app(BundledSourcePlanner::class);

        return $planner->plan($planner->readInventoryFromDirectory($pendingPath));
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
            $bundledPlan = $this->planBundledPlacement($pendingPath);
            $progress = ['phase' => 'manifest', 'skip_paths' => $bundledPlan->skipPaths(), 'manifest' => [], 'apply' => []];
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_placement', [
                'placed' => (string) count($bundledPlan->placed),
                'unplaced' => (string) count($bundledPlan->unplaced),
            ]));
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

            if ($shouldPause()) {
                $this->pausePlan($progress, 0);

                return;
            }
        }

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

        $this->state->storePlanProgress(null);
    }

    /**
     * 적용 대상 산출을 멈추고 다음 요청에서 이어 가게 합니다.
     *
     * @param  array<string, mixed>  $progress  커서와 중간 결과
     * @param  int  $visited  지금까지 확인한 항목 수
     * @return void 반환값 없음
     */
    private function pausePlan(array $progress, int $visited): void
    {
        $this->state->storePlanProgress($progress);
        $this->state->appendLog((string) __('settings.core_update.web.log_plan_continues', [
            'checked' => (string) $visited,
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

        $bundledPlan = $this->planBundledPlacement($pendingPath);

        // 반환값이 계약이다 — 게터는 반환값을 손에 쥘 수 없는 자리의 편의일 뿐이다.
        $result = $this->service->applyUpdate(
            $pendingPath,
            null,
            prune: false,
            applyList: $this->state->loadApplyList(),
            skipPaths: $bundledPlan->skipPaths(),
        );

        $this->state->appendWarnings((array) ($result['root_file_warnings'] ?? []));
        $this->state->appendLog((string) __('settings.core_update.web.log_applied'));

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
            $this->state->appendLog((string) __('settings.core_update.web.log_vendor_copied'));

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
        $this->state->appendLog((string) __('settings.core_update.web.log_vendor_copied'));
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
        $this->service->runMigrations();

        // `syncCoreRolesAndPermissions`/`syncCoreMenus` 를 직접 부르지 않는다 — 부팅 시점에
        // 캐시된 구버전 config 로 sync 가 돌면 신규 권한·메뉴가 누락된다 (#326 회귀).
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
     * @return void 반환값 없음
     */
    private function stepBundled(): void
    {
        $options = $this->state->options();

        if (! $options->updateBundledExtensions) {
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_skipped'));

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

            $items = ExecuteBundledUpdatesCommand::flattenItems(
                $manifest['modules'],
                $manifest['plugins'],
                $manifest['templates'],
                $manifest['lang_packs'],
            );

            $progress = ['items' => $items, 'index' => 0, 'success' => 0, 'failed' => 0, 'planned' => count($items)];

            // 항목을 돌리기 **전에** 진행분을 저장한다 — 첫 항목이 끊기면 종료 처리가 그 항목만 건너뛰고 다음
            // 항목으로 넘어가야 하는데, 진행분이 없으면 끊김이 코어 되돌리기로 간다.
            $this->state->mergeContext(['bundled_progress' => $progress]);
        }

        $items = array_values((array) $progress['items']);
        $index = (int) $progress['index'];
        $total = count($items);

        if ($index < $total) {
            $item = (array) $items[$index];
            $manifestPath = $this->writeBundledItemManifest($item, $index === $total - 1 && $options->rebuildSearchIndex);

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
            } finally {
                if (is_file($manifestPath)) {
                    @unlink($manifestPath);
                }
            }

            $progress['index'] = ++$index;
        }

        if ($index < $total) {
            $this->state->mergeContext(['bundled_progress' => $progress]);
            $this->state->appendLog((string) __('settings.core_update.web.log_bundled_progress', [
                'done' => (string) $index,
                'total' => (string) $total,
            ]));
            $this->repeatCurrentStep = true;

            return;
        }

        $this->state->mergeContext([
            'bundled_progress' => null,
            'bundled_result' => [
                'success' => (int) $progress['success'],
                'failed' => (int) $progress['failed'],
                'planned' => (int) $progress['planned'],
            ],
        ]);

        $this->state->appendLog((string) __('settings.core_update.web.log_bundled', [
            'success' => (string) ((int) $progress['success']),
            'failed' => (string) ((int) $progress['failed']),
        ]));

        if ((int) $progress['failed'] > 0) {
            // 확장 일괄 업데이트의 실패는 코어 적용을 되돌릴 사유가 아니다 — 코어는
            // 이미 정상 반영되어 있고, 되돌리면 오히려 성공한 확장 업데이트와
            // 어긋난다. 사실만 통보하고 진행한다.
            $this->state->appendWarnings([(string) __('settings.core_update.web.bundled_partial_failure', [
                'failed' => (string) ((int) $progress['failed']),
            ])]);
        }
    }

    /**
     * 번들 확장 항목 하나를 담은 매니페스트 파일을 씁니다.
     *
     * @param  array<string, mixed>  $item  `ExecuteBundledUpdatesCommand::flattenItems()` 항목
     * @param  bool  $rebuildSearchIndex  이 항목 뒤에 검색 인덱스를 다시 만들지 (마지막 항목만)
     * @return string 매니페스트 경로
     */
    private function writeBundledItemManifest(array $item, bool $rebuildSearchIndex): string
    {
        // 항목 하나짜리 매니페스트 모양은 명령 쪽이 소유한다 — 같은 함수를 써서 두 곳이 갈리지 않게 한다.
        $manifest = ExecuteBundledUpdatesCommand::manifestForItem($item);
        $manifest['rebuild_search_index'] = $rebuildSearchIndex;

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
                $this->state->appendLog((string) __('settings.core_update.web.log_cleanup_continues'));
                $this->repeatCurrentStep = true;

                return;
            }

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
                $this->state->appendLog((string) __('settings.core_update.web.log_ownership_continues'));
                $this->repeatCurrentStep = true;

                return;
            }

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
                    $this->state->appendLog((string) __('settings.core_update.web.log_rollback_continues'));

                    return;
                }

                $failedTargets = (array) ($result['failed_targets'] ?? []);
                $restored = $failedTargets === [];

                // 복원이 끝났다는 사실을 **가장 먼저** 저장한다 — 그 뒤 구간(캐시 파일 지우기·소유권 복원)에서
                // 요청이 끊기면 다음 요청이 복원부터 다시 해 같은 자리에서 또 끊겨 재시도 상한에 닿는다.
                $save('ownership', ['caches_dropped' => false]);

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

        $this->state->mergeContext(['rollback_progress' => null]);
        $this->finishRollback($restored, $failedTargets);
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
     * 번들 확장 일괄 업데이트 매니페스트를 만듭니다.
     *
     * 전역 레이아웃 전략(D29)을 모든 항목에 그대로 싣는다 — 확장별 예외는 웹에 두지
     * 않고 개별 확장 업데이트 화면으로 안내한다.
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
            ])]);
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
