<?php

namespace App\Privacy\ManualRun;

use App\Contracts\Repositories\PrivacyManualRunRepositoryInterface;
use App\Enums\PrivacyManualRunStatus;
use App\Exceptions\PrivacyManualRunConflictException;
use App\Exceptions\PrivacyManualRunDisabledException;
use App\Extension\HookManager;
use App\Jobs\RunPrivacyManualActionJob;
use App\Models\PrivacyManualRun;
use App\Models\User;
use App\Privacy\Contracts\PrivacyManualRunnerInterface;
use App\Privacy\Contracts\PrivacyManualRunReadableInterface;
use App\Privacy\Contracts\PrivacyManualRunRequiresEnabledInterface;
use App\Support\SafeExceptionMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 개인정보 조치 관리자 직권 실행 서비스
 *
 * 「지금 실행」의 접수·실행·기록을 맡는다. 흐름:
 *
 * 1. 접수({@see queue()}) — 같은 대상에 진행 중 작업이 있으면 거절, 없으면 기록(처리 대기)을
 *    만들고 큐 작업을 발행한다. 요청은 여기서 끝난다(2026-09-25 확정 — 큐 적재 방식).
 * 2. 실행({@see execute()}) — 큐 작업이 부른다. 스케줄러 배치와 공유하는 락을 잡고, 기록을
 *    처리 중으로 선점한 뒤 대상을 직권 실행하고 완료/실패를 기록한다.
 *
 * 직권 실행은 자동 실행 토글을 보지 않는다 — 권한은 접수 시점에 컨트롤러가 검사했다. 예외는
 * {@see PrivacyManualRunRequiresEnabledInterface} 를 단 대상으로, 토글이 꺼져 있으면 접수를 거절한다.
 * 접수·종료 사실은 액션 훅으로 알리고, 코어 활동 로그 리스너가 받아 기록한다.
 */
class PrivacyManualRunService
{
    /**
     * 진행 중 기록을 버려진 것으로 보는 기준(분).
     *
     * 큐 워커가 멈췄거나 작업이 비정상 종료되면 기록이 「처리 대기/처리 중」으로 남아 새 접수를
     * 영원히 막는다. 이 시간이 지나면 그 기록을 실패로 닫고 새 접수를 받는다. 작업의 최대 실행
     * 시간(`RunPrivacyManualActionJob::$timeout`)보다 충분히 길게 둔다.
     */
    public const STALE_AFTER_MINUTES = 360;

    /** 접수 원자성 락 대기 시간(초) */
    private const QUEUE_LOCK_SECONDS = 10;

    /** 실행 락 유지 시간(초) — 작업 최대 실행 시간보다 길게 */
    private const RUN_LOCK_SECONDS = 3600;

    /** 접수 훅 */
    public const QUEUED_HOOK = 'core.privacy.manual_run_queued';

    /** 종료(완료·실패) 훅 */
    public const FINISHED_HOOK = 'core.privacy.manual_run_finished';

    /**
     * @var array<int, string> 코어가 붙이는 실패 사유 — 문장이 아니라 번역 키로 저장하고, 화면에 내보낼 때
     *                         보는 관리자의 언어로 푼다({@see displayError()}). 큐 작업은 요청 언어 없이 돌므로
     *                         문장으로 저장하면 사이트 기본 언어로 굳어 다른 언어 화면에도 그 언어로 보인다.
     */
    public const SYSTEM_ERROR_KEYS = [
        'extensions.errors.internal_error',
        'privacy.manual_run.target_missing',
        'privacy.manual_run.stale',
    ];

    /**
     * @param  PrivacyManualRunRegistry  $registry  직권 실행 대상 수집기
     * @param  PrivacyManualRunRepositoryInterface  $runs  실행 기록 저장소
     */
    public function __construct(
        private PrivacyManualRunRegistry $registry,
        private PrivacyManualRunRepositoryInterface $runs,
    ) {}

    /**
     * 확인 창에 보일 건수·방식을 셉니다 (데이터를 바꾸지 않음).
     *
     * @param  PrivacyManualRunnerInterface  $runner  대상
     * @param  bool  $skipWaiting  대기기간 생략 (지원하지 않는 대상은 무시)
     * @return array{count: int, mode: string|null, enabled: bool, supports_skip_waiting: bool, skip_waiting: bool} 확인 창 데이터
     */
    public function preview(PrivacyManualRunnerInterface $runner, bool $skipWaiting = false): array
    {
        $supports = $runner->supportsSkipWaiting();
        $skipWaiting = $skipWaiting && $supports;

        return [
            'count' => $runner->count($skipWaiting),
            'mode' => $runner->mode(),
            'enabled' => $runner->isEnabled(),
            'supports_skip_waiting' => $supports,
            'skip_waiting' => $skipWaiting,
        ];
    }

    /**
     * 직권 실행을 큐에 접수합니다.
     *
     * 확인과 생성 사이에 다른 접수가 끼어들지 않도록 대상별 짧은 락으로 감싼다. 진행 중 기록이
     * 버려진 것(기준 시간 경과)이면 실패로 닫고 새로 받는다.
     *
     * @param  PrivacyManualRunnerInterface  $runner  대상
     * @param  bool  $skipWaiting  대기기간 생략 (지원하지 않는 대상은 무시)
     * @param  User  $actor  접수한 관리자
     * @return PrivacyManualRun 접수된 기록 (처리 대기)
     *
     * @throws PrivacyManualRunConflictException 같은 대상에 진행 중 작업이 있을 때
     * @throws PrivacyManualRunDisabledException 토글이 켜져 있어야 하는 대상인데 꺼져 있을 때
     */
    public function queue(PrivacyManualRunnerInterface $runner, bool $skipWaiting, User $actor): PrivacyManualRun
    {
        $key = $runner->key();

        // 처리 결과의 효력이 토글에 묶인 대상은 꺼진 채 처리하면 안내와 동작이 어긋난다(#736).
        if ($runner instanceof PrivacyManualRunRequiresEnabledInterface && ! $this->safeEnabled($runner)) {
            throw new PrivacyManualRunDisabledException($key);
        }

        $skipWaiting = $skipWaiting && $runner->supportsSkipWaiting();

        $run = Cache::lock('privacy-manual-run-queue:'.$key, self::QUEUE_LOCK_SECONDS)
            ->block(self::QUEUE_LOCK_SECONDS, function () use ($key, $skipWaiting, $actor) {
                $active = $this->runs->findActiveByTarget($key);

                if ($active !== null) {
                    if (! $this->isStale($active)) {
                        throw new PrivacyManualRunConflictException($active);
                    }

                    $this->closeStale($active);
                }

                return $this->runs->create([
                    'target' => $key,
                    'status' => PrivacyManualRunStatus::Queued->value,
                    'skip_waiting' => $skipWaiting,
                    'requested_by' => $actor->id,
                    'queued_at' => now(),
                ]);
            });

        Log::info('개인정보 조치 직권 실행 접수', [
            'run_id' => $run->id,
            'target' => $key,
            'skip_waiting' => $skipWaiting,
            'requested_by' => $actor->id,
            'scheduled' => false,
        ]);

        // 접수 기록을 발행보다 먼저 남긴다 — 동기 큐는 dispatch 안에서 작업까지 끝내므로, 뒤에 두면
        // 활동 로그에 「완료」가 「접수」보다 먼저 쌓여 일어난 순서와 거꾸로 읽힌다.
        // 구독자가 실패해도 작업은 발행한다 — 여기서 멈추면 기록이 「대기」로 남아 기준 시간이 지날 때까지
        // 같은 대상의 재접수가 충돌로 막힌다. 실패는 기록만 하고 넘어간다.
        try {
            HookManager::doAction(self::QUEUED_HOOK, $run, $this->safeLabel($runner));
        } catch (Throwable $e) {
            Log::warning('개인정보 조치 직권 실행 접수 훅 실패', [
                'run_id' => $run->id,
                'target' => $key,
                'error' => $e->getMessage(),
            ]);
        }

        RunPrivacyManualActionJob::dispatch($run->id);

        return $run;
    }

    /**
     * 큐 작업에서 직권 실행을 수행합니다.
     *
     * @param  int  $runId  실행 기록 ID
     * @return string `busy`(락 경합 — 작업이 나중에 다시 시도) · `skipped`(처리할 기록 아님) · `done`
     */
    public function execute(int $runId): string
    {
        $run = $this->runs->findById($runId);

        // 이미 시작됐거나 닫힌 기록(버려진 것으로 닫힌 뒤 늦게 도착한 작업 등)은 건드리지 않는다.
        if ($run === null || $run->status !== PrivacyManualRunStatus::Queued) {
            return 'skipped';
        }

        $runner = $this->registry->runner($run->target);

        if ($runner === null) {
            // 접수 후 확장이 비활성화됐거나 핸들러 등록이 빠졌다 — 실행할 수 없으니 사유를 남기고 닫는다.
            $this->finish($run, null, [
                'status' => PrivacyManualRunStatus::Failed->value,
                'error' => 'privacy.manual_run.target_missing',
                'finished_at' => now(),
            ], PrivacyManualRunStatus::Queued->value);

            return 'done';
        }

        $lockKey = $runner->lockKey();
        $lock = $lockKey !== null ? Cache::lock($lockKey, self::RUN_LOCK_SECONDS) : null;

        if ($lock !== null && ! $lock->get()) {
            return 'busy';
        }

        try {
            if (! $this->runs->updateIfStatus($run->id, PrivacyManualRunStatus::Queued->value, [
                'status' => PrivacyManualRunStatus::Running->value,
                'started_at' => now(),
            ])) {
                return 'skipped';
            }

            try {
                $result = $runner->run((bool) $run->skip_waiting);

                $this->finish($run, $runner, [
                    'status' => PrivacyManualRunStatus::Completed->value,
                    'processed_count' => max(0, (int) ($result['count'] ?? 0)),
                    'mode' => isset($result['mode']) ? (string) $result['mode'] : null,
                    'remaining_count' => isset($result['remaining']) ? max(0, (int) $result['remaining']) : null,
                    'finished_at' => now(),
                ]);
            } catch (Throwable $e) {
                Log::error('개인정보 조치 직권 실행 실패', [
                    'run_id' => $run->id,
                    'target' => $run->target,
                    'error' => $e->getMessage(),
                ]);

                $this->finish($run, $runner, [
                    'status' => PrivacyManualRunStatus::Failed->value,
                    'error' => self::failureReason($e, ['run_id' => $run->id, 'target' => $run->target]),
                    'finished_at' => now(),
                ]);
            }
        } finally {
            $lock?->release();
        }

        return 'done';
    }

    /**
     * 예외에서 기록에 남길 실패 사유를 고릅니다.
     *
     * 실패 사유는 카드·상태 API·활동 로그에 그대로 보인다 — 운영 모드에서 DB·PHP 내부 예외의 원문(SQL·DB 호스트·
     * 서버 경로)은 일반 문구의 번역 키로 바꾸고 원문은 서버 로그에 남긴다(#736 H 영역 실측). 확장이 일부러 던진
     * 사용자용 사유와 디버그 모드의 원문은 그대로 남긴다.
     *
     * @param  Throwable  $e  처리 중 잡은 예외
     * @param  array<string, mixed>  $context  서버 로그에 함께 남길 맥락
     * @return string 기록할 실패 사유(문장 또는 {@see SYSTEM_ERROR_KEYS} 의 키)
     */
    public static function failureReason(Throwable $e, array $context = []): string
    {
        $reason = SafeExceptionMessage::for($e, $context);

        if (SafeExceptionMessage::isInternal($e) && ! config('app.debug')) {
            return 'extensions.errors.internal_error';
        }

        return mb_substr($reason, 0, 2000);
    }

    /**
     * 기록의 실패 사유를 화면에 보일 문장으로 풉니다.
     *
     * 코어가 붙인 사유({@see SYSTEM_ERROR_KEYS})는 지금 요청의 언어로 번역하고, 그 밖의 사유(확장이 알린 문장,
     * 이 변경 전에 문장으로 저장된 기록)는 그대로 돌려준다.
     *
     * @param  string|null  $error  저장된 실패 사유
     * @return string|null 화면에 보일 실패 사유
     */
    public static function displayError(?string $error): ?string
    {
        if ($error === null || ! in_array($error, self::SYSTEM_ERROR_KEYS, true)) {
            return $error;
        }

        return __($error, ['minutes' => self::STALE_AFTER_MINUTES]);
    }

    /**
     * 큐 작업이 끝내 실패했을 때(시간 초과·재시도 소진) 기록을 실패로 닫습니다.
     *
     * @param  int  $runId  실행 기록 ID
     * @param  string  $error  실패 사유
     * @return void 반환값 없음
     */
    public function failFromJob(int $runId, string $error): void
    {
        $run = $this->runs->findById($runId);

        if ($run === null || ! $run->status->isActive()) {
            return;
        }

        $this->finish($run, $this->registry->runner($run->target), [
            'status' => PrivacyManualRunStatus::Failed->value,
            'error' => mb_substr($error, 0, 2000),
            'finished_at' => now(),
        ], $run->status->value);
    }

    /**
     * 화면 카드용 대상별 상태를 모읍니다.
     *
     * 권한이 없거나 등록되지 않은 대상은 `can_run=false`·`run=null` 로 가린다 — 실행 기록에는
     * 접수자 이름이 있으므로 실행할 수 없는 관리자에게 보이지 않는다. 다만 열람 표지
     * ({@see PrivacyManualRunReadableInterface})를 단 대상은 열람 권한만 있어도
     * `run` 을 준다(`can_run` 은 그대로 false — 화면은 버튼을 비활성으로 그린다).
     *
     * @param  array<int, string>  $keys  대상 키 목록
     * @param  User|null  $user  관리자
     * @return array<string, array{key: string, label: string|null, can_run: bool, enabled: bool|null, supports_skip_waiting: bool, run: PrivacyManualRun|null}> 대상 키 => 상태
     */
    public function statuses(array $keys, ?User $user): array
    {
        $result = [];
        $visible = [];

        foreach (array_values(array_unique($keys)) as $key) {
            $runner = $this->registry->runner($key);
            $canRun = $runner !== null && $this->registry->canRun($runner, $user);

            $result[$key] = [
                'key' => $key,
                'label' => $canRun ? $this->safeLabel($runner) : null,
                'can_run' => $canRun,
                'enabled' => $canRun ? $this->safeEnabled($runner) : null,
                'supports_skip_waiting' => $canRun && $runner->supportsSkipWaiting(),
                'run' => null,
            ];

            if ($canRun || ($runner !== null && $this->registry->canViewRun($runner, $user))) {
                $visible[] = $key;
            }
        }

        foreach ($this->runs->latestByTargets($visible) as $key => $run) {
            if ($this->isStale($run)) {
                $this->closeStale($run);
                $run = $this->runs->findById($run->id) ?? $run;
            }

            $result[$key]['run'] = $run;
        }

        return $result;
    }

    /**
     * 진행 중 기록이 버려진 것인지 판정합니다.
     *
     * @param  PrivacyManualRun  $run  기록
     * @return bool 기준 시간이 지난 진행 중 기록이면 true
     */
    public function isStale(PrivacyManualRun $run): bool
    {
        if (! $run->status->isActive()) {
            return false;
        }

        $since = $run->status === PrivacyManualRunStatus::Running
            ? ($run->started_at ?? $run->queued_at)
            : $run->queued_at;

        return $since === null || $since->lte(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * 버려진 진행 중 기록을 실패로 닫습니다.
     *
     * @param  PrivacyManualRun  $run  기록
     * @return void 반환값 없음
     */
    private function closeStale(PrivacyManualRun $run): void
    {
        $this->finish($run, $this->registry->runner($run->target), [
            'status' => PrivacyManualRunStatus::Failed->value,
            'error' => 'privacy.manual_run.stale',
            'finished_at' => now(),
        ], $run->status->value);
    }

    /**
     * 기록을 닫고 종료 훅을 발행합니다.
     *
     * @param  PrivacyManualRun  $run  기록
     * @param  PrivacyManualRunnerInterface|null  $runner  대상 (없으면 라벨은 키)
     * @param  array<string, mixed>  $data  갱신 데이터
     * @param  string  $expectedStatus  이 상태일 때만 닫는다 (기본 처리 중)
     * @return void 반환값 없음
     */
    private function finish(PrivacyManualRun $run, ?PrivacyManualRunnerInterface $runner, array $data, string $expectedStatus = 'running'): void
    {
        if (! $this->runs->updateIfStatus($run->id, $expectedStatus, $data)) {
            return;
        }

        $fresh = $this->runs->findById($run->id) ?? $run;

        Log::info('개인정보 조치 직권 실행 종료', [
            'run_id' => $fresh->id,
            'target' => $fresh->target,
            'status' => $fresh->status->value,
            'processed_count' => $fresh->processed_count,
            'scheduled' => false,
        ]);

        HookManager::doAction(self::FINISHED_HOOK, $fresh, $runner !== null ? $this->safeLabel($runner) : $fresh->target);
    }

    /**
     * 대상 라벨을 안전하게 조회합니다.
     *
     * @param  PrivacyManualRunnerInterface  $runner  대상
     * @return string 라벨 (실패 시 키)
     */
    private function safeLabel(PrivacyManualRunnerInterface $runner): string
    {
        try {
            return $runner->label();
        } catch (Throwable) {
            return $runner->key();
        }
    }

    /**
     * 자동 실행 토글을 안전하게 조회합니다.
     *
     * @param  PrivacyManualRunnerInterface  $runner  대상
     * @return bool 활성 여부 (실패 시 false)
     */
    private function safeEnabled(PrivacyManualRunnerInterface $runner): bool
    {
        try {
            return $runner->isEnabled();
        } catch (Throwable) {
            return false;
        }
    }
}
