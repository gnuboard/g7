<?php

namespace App\Services\Schedule;

use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleHistoryMode;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Enums\ScheduleType;
use App\Exceptions\ScheduleExecutionException;
use App\Exceptions\ScheduleRunLockUnavailableException;
use App\Extension\HookManager;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Support\OutboundUrlValidator;
use App\Support\ScheduleCommandValidator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * 스케줄 실행기
 *
 * 틱·수동 실행·큐 잡이 모두 여기서 실제로 실행한다. 실행 직전 검증이 마지막 방어선이다 —
 * 저장 시점 검증 도입 이전 데이터나 DB 직접 수정으로 들어온 값을 막기 위해 artisan/shell 허용목록·
 * outbound URL 판정·Job 클래스 허용목록을 타입별 실행 메서드에서 다시 적용한다
 * (관리자 저장 경로의 FormRequest 검증과 같은 SSoT: ScheduleCommandValidator / OutboundUrlValidator / schedule_security).
 *
 * - artisan 은 같은 프로세스에서 `Artisan::call`, shell 은 `Process` Facade, url 은 `Http` Facade 를 쓴다 —
 *   보안 가드 테스트의 `Process::fake`·`Http::fake` 가 실행부를 그대로 가로챌 수 있어야 한다.
 * - 중복 방지는 `without_overlapping`(분) 캐시 잠금 `schedule:run:{id}` 이 틱·수동·큐 모든 경로를 맡는다.
 * - 「실패만 기록」 행은 성공 시 이력을 남기지 않는다. 행 상태 갱신은 모델 이벤트를 타지 않는다.
 * - 실행이 실패해도 예외를 던지지 않고 실패 이력을 돌려준다. 실행 전 거부만 예외다 — 비활성 확장과 수동 중복은
 *   `ScheduleExecutionException`, 수동 실행의 잠금 저장소 오류는 `ScheduleRunLockUnavailableException`(503).
 *   거부는 이력·last_result·잠금·실패 훅을 건드리지 않는다. 호출자(수동 실행 API, 큐 실행 잡)도 거부를 실패로 마감하지 않는다.
 */
class ScheduleExecutor
{
    /** 중복 방지 잠금 키 접두사 */
    public const LOCK_PREFIX = 'schedule:run:';

    /** 이력 출력 최대 길이 (TEXT 컬럼) */
    private const OUTPUT_LIMIT = 65535;

    /**
     * @param  ScheduleRepositoryInterface  $scheduleRepository  스케줄 저장소
     * @param  ScheduleHistoryRepositoryInterface  $historyRepository  실행 이력 저장소
     * @param  ScheduleLookupService  $lookup  다음 실행 시각 계산
     * @param  ScheduleSecurityPolicy  $securityPolicy  스케줄 보안 유효 정책(Job 허용 클래스)
     */
    public function __construct(
        private readonly ScheduleRepositoryInterface $scheduleRepository,
        private readonly ScheduleHistoryRepositoryInterface $historyRepository,
        private readonly ScheduleLookupService $lookup,
        private readonly ScheduleSecurityPolicy $securityPolicy,
    ) {}

    /**
     * 스케줄을 실행하고 이력을 남깁니다.
     *
     * @param  Schedule  $schedule  실행할 스케줄
     * @param  ScheduleTriggerType  $trigger  실행 계기 (예약 / 수동)
     * @param  int|null  $userId  수동 실행자 ID
     * @return ScheduleHistory|null 실행 이력 (「실패만 기록」 성공이거나 예약 실행이 중복으로 건너뛰면 null)
     *
     * @throws ScheduleExecutionException 소유 확장이 비활성일 때, 수동 실행인데 앞선 실행이 잠금을 잡고 있을 때
     * @throws ScheduleRunLockUnavailableException 수동 실행인데 잠금 저장소가 오류를 낼 때 (503)
     */
    public function execute(Schedule $schedule, ScheduleTriggerType $trigger, ?int $userId = null): ?ScheduleHistory
    {
        if (! $schedule->ownerIsActive()) {
            throw ScheduleExecutionException::ownerInactive();
        }

        $lock = $this->acquireLock($schedule, $trigger);

        if ($lock === false) {
            if ($trigger === ScheduleTriggerType::Manual) {
                throw ScheduleExecutionException::alreadyRunning();
            }

            Log::info('DB 스케줄 건너뜀 — 앞선 실행이 아직 끝나지 않았습니다', ['schedule_id' => $schedule->id]);

            return null;
        }

        try {
            return $this->run($schedule, $trigger, $userId);
        } finally {
            $lock?->release();
        }
    }

    /**
     * 큐 워커가 실행 도중 죽은(timeout·kill) 실행을 실패로 마감합니다.
     *
     * 실행기가 남긴 running 이력을 failed 로 닫고(「실패만 기록」이라 없으면 새로 남긴다), 행 상태를
     * 실패로 바꾸고, 실패 훅을 발화한다. 죽은 프로세스가 쥔 중복 방지 잠금도 푼다 — 그대로 두면
     * 잠금 유지 시간 동안 다음 실행이 모두 건너뛴다.
     *
     * @param  int  $scheduleId  스케줄 ID
     * @param  ScheduleTriggerType  $trigger  실행 계기
     * @param  int|null  $userId  수동 실행자 ID
     * @param  string  $error  실패 사유
     * @return ScheduleHistory|null 마감한 실패 이력 (스케줄이 지워졌으면 null)
     */
    public function markAbandoned(int $scheduleId, ScheduleTriggerType $trigger, ?int $userId, string $error): ?ScheduleHistory
    {
        $schedule = $this->scheduleRepository->findById($scheduleId);

        if ($schedule === null) {
            return null;
        }

        $outcome = [
            'ended_at' => now(),
            'status' => ScheduleResultStatus::Failed,
            'exit_code' => 1,
            'error_output' => $this->limit($error),
        ];

        $history = $this->historyRepository->findLatestRunning($scheduleId);

        if ($history !== null) {
            $this->historyRepository->update($history, $outcome);
            $history = $history->fresh() ?? $history;
        } else {
            $history = $this->historyRepository->create([
                'schedule_id' => $scheduleId,
                'started_at' => now(),
                'trigger_type' => $trigger,
                'triggered_by' => $userId,
                ...$outcome,
            ]);
        }

        $this->updateRowState($schedule, ScheduleResultStatus::Failed);

        try {
            Cache::lock(self::LOCK_PREFIX.$scheduleId)->forceRelease();
        } catch (Throwable) {
            // 잠금은 유지 시간이 지나면 스스로 풀린다.
        }

        HookManager::doAction('core.schedule.run_failed', $schedule, $history, $error);

        return $history;
    }

    /**
     * 중복 방지 잠금을 잡습니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @param  ScheduleTriggerType  $trigger  실행 계기 (예약 / 수동)
     * @return Lock|false|null 잡은 잠금, 이미 잡혀 있으면 false, 잠금이 필요 없거나 예약 실행 중 캐시 장애면 null
     *
     * @throws ScheduleRunLockUnavailableException 수동 실행인데 잠금 저장소가 오류를 낼 때 (503)
     */
    private function acquireLock(Schedule $schedule, ScheduleTriggerType $trigger): Lock|false|null
    {
        $minutes = (int) $schedule->without_overlapping;

        if ($minutes <= 0) {
            return null;
        }

        try {
            $lock = Cache::lock(self::LOCK_PREFIX.$schedule->id, $minutes * 60);

            return $lock->get() ? $lock : false;
        } catch (Throwable $e) {
            // 수동 실행(「지금 실행」)은 잠금 없이 돌리지 않는다 — 중복 실행 방지를 보장할 수 없고, 누른 사람이 다시
            // 시도할 수 있다(#161 F4). 원본 저장소 오류 문구는 응답에 싣지 않고 로그에만 남긴다.
            if ($trigger === ScheduleTriggerType::Manual) {
                Log::error('스케줄 실행 잠금 저장소 오류 — 실행하지 않습니다', ['schedule_id' => $schedule->id, 'error' => $e->getMessage()]);

                throw new ScheduleRunLockUnavailableException((int) $schedule->id);
            }

            // 예약 실행은 캐시가 잠금을 못 줘도 실행을 막지 않는다 — 작업이 영영 멈추는 쪽이 겹쳐 도는 쪽보다 해롭다.
            Log::warning('DB 스케줄 중복 방지 잠금 실패 — 잠금 없이 실행합니다', [
                'schedule_id' => $schedule->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 실행하고 이력·행 상태·훅을 처리합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @param  ScheduleTriggerType  $trigger  실행 계기
     * @param  int|null  $userId  수동 실행자 ID
     * @return ScheduleHistory|null 실행 이력 (「실패만 기록」 성공이면 null)
     */
    private function run(Schedule $schedule, ScheduleTriggerType $trigger, ?int $userId): ?ScheduleHistory
    {
        HookManager::doAction('core.schedule.before_run', $schedule);

        $recordAll = $schedule->history_mode !== ScheduleHistoryMode::FailuresOnly;
        $startedAt = now();
        $startTime = microtime(true);
        $memoryStart = memory_get_usage();

        $base = [
            'schedule_id' => $schedule->id,
            'started_at' => $startedAt,
            'trigger_type' => $trigger,
            'triggered_by' => $userId,
        ];

        $history = $recordAll
            ? $this->historyRepository->create([...$base, 'status' => ScheduleResultStatus::Running])
            : null;

        $this->scheduleRepository->updateRunState((int) $schedule->id, [
            'last_result' => ScheduleResultStatus::Running,
            'last_run_at' => $startedAt,
        ]);

        $error = null;

        try {
            $result = $this->runByType($schedule);
        } catch (Throwable $e) {
            $error = $e;
            $result = null;
        }

        $failed = $error !== null;
        $status = $failed ? ScheduleResultStatus::Failed : ScheduleResultStatus::Success;
        $outcome = [
            'ended_at' => now(),
            'duration' => (int) (microtime(true) - $startTime),
            'status' => $status,
            'exit_code' => $failed ? $this->failureExitCode($error) : (int) ($result['exit_code'] ?? 0),
            'memory_usage' => max(0, memory_get_usage() - $memoryStart),
            'output' => $failed ? null : $this->limit($result['output'] ?? null),
            'error_output' => $failed ? $this->limit($error->getMessage()) : null,
        ];

        if ($history !== null) {
            $this->historyRepository->update($history, $outcome);
            $history = $history->fresh() ?? $history;
        } elseif ($failed) {
            $history = $this->historyRepository->create([...$base, ...$outcome]);
        }

        $this->updateRowState($schedule, $status);

        if ($failed) {
            // 거부 사유는 error 레벨 한 줄만 보는 알림 파이프라인에서도 보여야 한다 —
            // 별도 warning 로그에만 남기면 그 경로에서는 "왜 막혔는지" 가 유실된다.
            Log::error('Schedule execution failed', [
                'schedule_id' => $schedule->id,
                'type' => $schedule->type?->value,
                'command' => $schedule->command,
                'trigger' => $trigger->value,
                'reason' => $error instanceof ScheduleExecutionException ? $error->reasonCode : null,
                'error' => $error->getMessage(),
            ]);
        }

        // 「실패만 기록」 성공은 이력 행이 없지만 훅 인자 계약(Schedule, ScheduleHistory)을 지킨다 — 미저장 인스턴스.
        HookManager::doAction(
            'core.schedule.after_run',
            $schedule,
            $history ?? $this->historyRepository->make([...$base, ...$outcome]),
        );

        if ($failed) {
            // 오류는 문자열로 넘긴다 — 큐로 가는 리스너(실패 알림)는 Throwable 을 직렬화하지 못한다.
            HookManager::doAction('core.schedule.run_failed', $schedule, $history, (string) $error->getMessage());
        }

        return $failed || $recordAll ? $history : null;
    }

    /**
     * 실행 결과와 다음 실행 시각을 모델 이벤트 없이 행에 남깁니다.
     *
     * 다음 실행 시각은 행의 주기(30초·cron)·시간대 기준이고, 비활성 행은 비운다. 메모리의 모델은
     * 건드리지 않는다(Service 에서 모델 속성을 쓰지 않는다 — 다시 읽을 곳은 저장소에서 읽는다).
     *
     * @param  Schedule  $schedule  스케줄
     * @param  ScheduleResultStatus  $status  실행 결과
     * @return void 반환값 없음
     */
    private function updateRowState(Schedule $schedule, ScheduleResultStatus $status): void
    {
        $this->scheduleRepository->updateRunState((int) $schedule->id, [
            'last_result' => $status,
            'next_run_at' => $schedule->is_active ? $this->lookup->nextRunAt($schedule) : null,
        ]);
    }

    /**
     * 타입별로 실행합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @return array{output: string|null, exit_code: int} 실행 결과
     *
     * @throws Throwable 차단·실패
     */
    private function runByType(Schedule $schedule): array
    {
        return match ($schedule->type) {
            ScheduleType::Artisan => $this->executeArtisanCommand($schedule),
            ScheduleType::Shell => $this->executeShellCommand($schedule),
            ScheduleType::Url => $this->executeUrlCall($schedule),
            ScheduleType::Job => $this->executeJob($schedule),
        };
    }

    /**
     * Artisan 커맨드를 같은 프로세스에서 실행합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @return array{output: string|null, exit_code: int} 실행 결과
     *
     * @throws ScheduleExecutionException 차단목록에 걸렸거나 0 이 아닌 종료 코드로 끝났을 때
     */
    private function executeArtisanCommand(Schedule $schedule): array
    {
        // 저장 시점 검증 도입 이전 데이터나 DB 직접 수정으로 들어온 값을 방어한다
        // (isUrlCallAllowed 와 동일 사유 — 실행 직전이 마지막 방어선).
        $verdict = ScheduleCommandValidator::inspectArtisanCommand((string) $schedule->command);

        if (! $verdict['allowed']) {
            // 업그레이드 점검 스텝을 두지 않으므로, 거부 사유는 이 로그가 유일한 운영 진단 통로다.
            Log::warning('Schedule artisan command rejected', [
                'schedule_id' => $schedule->id,
                'command' => $schedule->command,
                'reason' => $verdict['reason'],
            ]);

            throw ScheduleExecutionException::artisanNotAllowed($verdict['reason']);
        }

        $this->registerCommandClassForWeb($schedule, (string) $verdict['name']);

        // 문자열이 아닌 (명령명, 인자배열) 로 넘겨 StringInput 재파싱을 경유하지 않는다 —
        // parameters 가 비어 있으면 Laravel 이 문자열 전체를 다시 파싱해
        // 검증한 이름과 다른 명령이 실행될 수 있다.
        $exitCode = (int) Artisan::call($verdict['name'], $verdict['parameters'], new BufferedOutput);
        $output = (string) Artisan::output();

        if ($exitCode !== 0) {
            throw ScheduleExecutionException::artisanCommandFailed($exitCode, $output);
        }

        return [
            'output' => $output,
            'exit_code' => 0,
        ];
    }

    /**
     * 웹 요청 안에서 실행할 때 확장 커맨드를 먼저 등록합니다.
     *
     * 확장 커맨드는 콘솔에서만 등록되므로 웹 크론(#609)·수동 실행 API 에서는 `Artisan::call` 이 찾지 못한다.
     * 확장이 선언한 `command_class`(없으면 활성 확장의 명령 디렉터리에서 찾은 클래스)가 검증한 명령명과 같은
     * 커맨드일 때만 등록한다(운영자는 이 값을 입력할 수 없다).
     *
     * @param  Schedule  $schedule  스케줄
     * @param  string  $name  검증된 명령명
     * @return void 반환값 없음
     */
    private function registerCommandClassForWeb(Schedule $schedule, string $name): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        $kernel = app(ConsoleKernel::class);

        if (array_key_exists($name, $kernel->all())) {
            return;
        }

        // 선언된 command_class 가 없으면(번들 확장 대부분) 활성 확장의 명령 디렉터리에서 찾는다 —
        // 검사기와 같은 소유 판정(확장 네임스페이스 + 선언 명령명)이다.
        $class = (string) ($schedule->command_class ?? '');

        if ($class === '') {
            $class = (string) (ScheduleCommandValidator::resolveExtensionCommandClass($name) ?? '');
        }

        if ($class === '') {
            return;
        }

        if (! class_exists($class) || ! is_subclass_of($class, Command::class)) {
            Log::warning('DB 스케줄 command_class 무시 — 커맨드 클래스가 아닙니다', ['schedule_id' => $schedule->id, 'class' => $class]);

            return;
        }

        $command = app($class);

        if ($command->getName() !== $name) {
            Log::warning('DB 스케줄 command_class 무시 — 명령명이 다릅니다', [
                'schedule_id' => $schedule->id,
                'class' => $class,
                'expected' => $name,
                'actual' => $command->getName(),
            ]);

            return;
        }

        // 계약(인터페이스)에는 없고 Foundation 콘솔 커널에만 있는 메서드다.
        if (method_exists($kernel, 'registerCommand')) {
            $kernel->registerCommand($command);
        }
    }

    /**
     * 쉘 명령을 실행합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @return array{output: string|null, exit_code: int} 실행 결과
     *
     * @throws ScheduleExecutionException 차단목록에 걸렸거나 실행이 실패했을 때
     */
    private function executeShellCommand(Schedule $schedule): array
    {
        // 저장 시점 검증 도입 이전 데이터나 DB 직접 수정으로 들어온 값을 방어한다
        // (isUrlCallAllowed 와 동일 사유 — 실행 직전이 마지막 방어선).
        $arguments = ScheduleCommandValidator::tokenizeShellCommand((string) $schedule->command);

        if ($arguments === null || ! ScheduleCommandValidator::isShellCommandAllowed((string) $schedule->command)) {
            throw ScheduleExecutionException::shellNotAllowed();
        }

        $timeout = $schedule->timeout ?? 60;

        // 문자열이 아닌 인자 배열로 넘겨 `/bin/sh -c` 를 경유하지 않는다 —
        // 파이프·`;`·`$()` 같은 셸 메타문자가 명령으로 해석될 여지를 없앤다.
        $result = Process::timeout($timeout)->run($arguments);

        if ($result->failed()) {
            throw ScheduleExecutionException::shellCommandFailed($result->errorOutput(), (int) $result->exitCode());
        }

        return [
            'output' => $result->output(),
            'exit_code' => (int) $result->exitCode(),
        ];
    }

    /**
     * URL 호출을 실행합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @return array{output: string|null, exit_code: int} 실행 결과
     *
     * @throws ScheduleExecutionException 내부망 URL 이거나 호출이 실패했을 때
     */
    private function executeUrlCall(Schedule $schedule): array
    {
        // 저장된 URL 이 그대로 서버의 outbound 목적지가 되므로, 실행 직전에도 내부망 주소를 차단한다
        // (저장 시점 검증 도입 이전 데이터나 DB 직접 수정으로 들어온 값 방어).
        if (! $this->isUrlCallAllowed((string) $schedule->command)) {
            throw ScheduleExecutionException::urlNotPublic();
        }

        $timeout = $schedule->timeout ?? 30;

        $response = Http::timeout($timeout)->get((string) $schedule->command);

        if ($response->failed()) {
            throw ScheduleExecutionException::httpRequestFailed($response->status());
        }

        return [
            'output' => 'HTTP Status: '.$response->status()."\n".$response->body(),
            'exit_code' => 0,
        ];
    }

    /**
     * 스케줄의 URL 호출이 허용되는 목적지인지 판정합니다.
     *
     * 사설 IP·localhost 등 내부 네트워크 주소는 기본 차단하되, 사내 엔드포인트를 주기 호출하는
     * 정당한 운영을 위해 `security.allow_internal_outbound_urls` 로 허용할 수 있습니다.
     * 허용하더라도 userinfo 위장·비 HTTP scheme 은 계속 거부합니다.
     *
     * @param  string  $url  스케줄에 저장된 호출 URL
     * @return bool 호출을 허용하면 true
     */
    private function isUrlCallAllowed(string $url): bool
    {
        $options = ['schemes' => ['http', 'https']];

        if ((bool) g7_core_settings('security.allow_internal_outbound_urls', false)) {
            return OutboundUrlValidator::isStructurallySafeUrl($url, $options);
        }

        return OutboundUrlValidator::isPublicHttpUrl($url, $options);
    }

    /**
     * 허용목록에 있는 Job 클래스를 선언된 실행 방식으로 보냅니다.
     *
     * 바로 실행이면 이 프로세스에서 동기로 실행하고, 큐로 보내기면 Job 자체를 큐에 넣는다 — Job 이 가진
     * 큐·timeout·재시도·중복 방지를 그대로 쓴다(디스패처는 job 유형을 실행 잡으로 감싸지 않는다).
     * 코어는 `schedule_security.job.allowed_classes`(코드 소유 리터럴)로, 서버 관리자는 덮어쓰기 파일
     * (`storage/app/schedule_security.json`, 더하기만)로, 확장은 `core.schedule.filter_allowed_job_classes`
     * 필터로 자기 Job 을 등재한다(확장 코드는 이미 신뢰 경계 안).
     *
     * @param  Schedule  $schedule  스케줄
     * @return array{output: string|null, exit_code: int} 실행 결과
     *
     * @throws ScheduleExecutionException 허용목록에 없거나 클래스가 없을 때
     */
    private function executeJob(Schedule $schedule): array
    {
        $class = trim((string) $schedule->command);

        // 코어 config + 서버 관리자 덮어쓰기 + 확장 필터 — 저장 검증(FormRequest)과 같은 목록이다
        $allowed = $this->securityPolicy->filteredJobAllowedClasses();

        if (! in_array(ltrim($class, '\\'), $allowed, true)) {
            Log::warning('Schedule job class rejected', [
                'schedule_id' => $schedule->id,
                'command' => $schedule->command,
            ]);

            throw ScheduleExecutionException::jobNotAllowed();
        }

        if (! class_exists($class)) {
            throw ScheduleExecutionException::jobNotFound($class);
        }

        if ($schedule->dispatch_mode === ScheduleDispatchMode::Queue) {
            Bus::dispatch(app($class));

            return [
                'output' => 'Job queued: '.$class,
                'exit_code' => 0,
            ];
        }

        Bus::dispatchSync(app($class));

        return [
            'output' => 'Job completed: '.$class,
            'exit_code' => 0,
        ];
    }

    /**
     * 실패 이력에 남길 종료 코드를 정합니다.
     *
     * 예외 코드는 HTTP 상태(500 등)일 수도 있다 — 종료 코드 컬럼(tinyint)에 넣으면 범위 초과로
     * 이력 저장 자체가 실패하므로, 프로세스 종료 코드 범위(1~127)가 아니면 1 로 남긴다
     * (HTTP 상태는 오류 메시지에 이미 들어 있다).
     *
     * @param  Throwable  $error  실패 사유
     * @return int 종료 코드 (1~127)
     */
    private function failureExitCode(Throwable $error): int
    {
        $code = (int) $error->getCode();

        return $code >= 1 && $code <= 127 ? $code : 1;
    }

    /**
     * 이력에 담을 출력 길이를 자릅니다.
     *
     * @param  string|null  $text  출력
     * @return string|null 잘린 출력
     */
    private function limit(?string $text): ?string
    {
        return $text === null ? null : mb_substr($text, 0, self::OUTPUT_LIMIT);
    }
}
