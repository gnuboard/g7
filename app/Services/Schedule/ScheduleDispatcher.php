<?php

namespace App\Services\Schedule;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleTriggerType;
use App\Enums\ScheduleType;
use App\Exceptions\ScheduleExecutionException;
use App\Extension\HookContextCapture;
use App\Jobs\RunScheduledTaskJob;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Services\Queue\QueueWorkerRegistry;
use App\Support\Queue\ThrottledQueueWarning;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * 스케줄 실행 진입점
 *
 * 틱(등록기)·수동 실행 API·웹 크론(#609)이 모두 이 메서드로 들어온다. 행의 실행 방식에 따라
 * 바로 실행(실행기 직접 호출)하거나 큐로 보낸다(RunScheduledTaskJob).
 *
 * - 바로 실행은 `dispatchSync` 를 쓰지 않는다 — 30초 작업까지 큐 처리 기록이 쌓이지 않게 한다.
 * - 큐로 보내기는 운영자가 등록한 연결·큐만 쓴다(QueueWorkerRegistry). 미등록이면 기본값 + 경고.
 * - 수동 실행은 실행 조건(설정에서 꺼짐)이 맞지 않아도 실행한다 — 화면이 미충족 조건을 경고한다.
 */
class ScheduleDispatcher
{
    /** 행에 timeout 이 없을 때 큐 잡 timeout(초) */
    private const DEFAULT_QUEUE_TIMEOUT = 600;

    /**
     * @param  ScheduleRepositoryInterface  $scheduleRepository  스케줄 저장소
     * @param  ScheduleExecutor  $executor  실행기
     * @param  QueueWorkerRegistry  $registry  큐 워커 레지스트리
     * @param  BusDispatcher  $bus  잡 디스패처
     */
    public function __construct(
        private readonly ScheduleRepositoryInterface $scheduleRepository,
        private readonly ScheduleExecutor $executor,
        private readonly QueueWorkerRegistry $registry,
        private readonly BusDispatcher $bus,
    ) {}

    /**
     * 스케줄을 실행하거나 큐로 보냅니다.
     *
     * @param  Schedule|int  $schedule  스케줄 또는 ID
     * @param  ScheduleTriggerType  $trigger  실행 계기 (예약 / 수동)
     * @param  int|null  $userId  수동 실행자 ID
     * @return ScheduleHistory|null 바로 실행이면 실행 이력(「실패만 기록」 성공·중복 건너뜀이면 null), 큐로 보냈으면 null
     *                              (job 유형은 큐 모드여도 Job 투입 결과를 이력으로 돌려준다)
     *
     * @throws ScheduleExecutionException 소유 확장이 비활성일 때(owner_inactive), 수동 실행인데 앞선 실행이 돌고 있을 때(already_running)
     */
    public function dispatch(Schedule|int $schedule, ScheduleTriggerType $trigger, ?int $userId = null): ?ScheduleHistory
    {
        $model = $schedule instanceof Schedule ? $schedule : $this->scheduleRepository->findById($schedule);

        if ($model === null) {
            // 등록 뒤 같은 분 안에 지워진 행 — 실행할 것이 없다.
            Log::info('DB 스케줄 실행 건너뜀 — 행이 없습니다', ['schedule_id' => $schedule]);

            return null;
        }

        if (! $model->ownerIsActive()) {
            throw ScheduleExecutionException::ownerInactive();
        }

        // job 유형은 실행기가 Job 클래스 자체를 선언한 방식(바로/큐)으로 보낸다 — 그 Job 의 큐·timeout·재시도·
        // 중복 방지(예: 사이트맵 전용 큐 1800초)를 실행 잡 안에 감싸 잃지 않게 한다(현행 Schedule::job 동작 보존).
        if ($model->dispatch_mode !== ScheduleDispatchMode::Queue || $model->type === ScheduleType::Job) {
            return $this->executor->execute($model, $trigger, $userId);
        }

        $this->bus->dispatch($this->buildJob($model, $trigger, $userId));

        return null;
    }

    /**
     * 큐 실행 잡을 만들고 연결·큐·timeout 을 정합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @param  ScheduleTriggerType  $trigger  실행 계기
     * @param  int|null  $userId  수동 실행자 ID
     * @return RunScheduledTaskJob 투입할 잡
     */
    private function buildJob(Schedule $schedule, ScheduleTriggerType $trigger, ?int $userId): RunScheduledTaskJob
    {
        // 수동 실행은 누른 관리자의 요청 문맥을 싣는다 — 워커가 실행하는 동안 복원해 활동 로그의 행위자·IP·
        // 브라우저 정보가 바로 실행과 같게 남는다. 예약 실행은 사람이 누르지 않았으므로 싣지 않는다.
        $job = new RunScheduledTaskJob(
            (int) $schedule->id,
            $trigger,
            $userId,
            $trigger === ScheduleTriggerType::Manual ? HookContextCapture::capture() : null,
        );

        $requestedConnection = $schedule->queue_connection ?: null;
        $requestedQueue = $schedule->queue_name ?: null;

        if ($requestedConnection === null && $requestedQueue === null) {
            // 지정이 없으면 긴 작업 배치가 등록돼 있을 때 그쪽으로 — 스케줄 작업은 대개 길다.
            $long = $this->registry->longPlacement();
            if ($long !== null) {
                $job->onConnection($long['connection'])->onQueue($long['queue']);
            }
        } else {
            $resolved = $this->registry->resolve($requestedConnection, $requestedQueue);

            if ($resolved['connection'] !== null) {
                $job->onConnection($resolved['connection']);
            }
            if ($resolved['queue'] !== null) {
                $job->onQueue($resolved['queue']);
            }

            if ($resolved['fallback'] !== []) {
                $info = [
                    'schedule_id' => (int) $schedule->id,
                    'requested_connection' => $requestedConnection,
                    'requested_queue' => $requestedQueue,
                    'reasons' => $resolved['fallback'],
                ];
                $this->registry->recordFallback($info);
                ThrottledQueueWarning::warn(
                    "fallback|schedule|{$schedule->id}|".implode(',', $resolved['fallback']),
                    '스케줄이 등록되지 않은 큐 연결·큐를 가리켜 기본값으로 보냅니다 — 환경설정 → 드라이버 → 큐 워커에 등록하거나 스케줄의 큐 설정을 바꾸세요',
                    $info,
                );
            }
        }

        $job->timeout = $this->registry->clampTimeout(
            (int) ($schedule->timeout ?: self::DEFAULT_QUEUE_TIMEOUT),
            $job->connection,
            $job->queue,
            ['schedule_id' => (int) $schedule->id],
            '스케줄 timeout 이 큐 연결의 retry_after 이상이라 줄여서 보냅니다 — 실행 중인 잡을 다른 워커가 다시 집지 않게 하려면 긴 작업 연결(*-long)을 쓰세요',
        );

        return $job;
    }
}
