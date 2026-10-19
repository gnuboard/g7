<?php

// audit:allow job-generator-needs-scale-test reason: ID-only payload 단건 실행 진입점 — 생성량이 스케줄 행 수에 묶여 대량 생성 축이 없다

namespace App\Jobs;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ScheduleTriggerType;
use App\Exceptions\ScheduleExecutionException;
use App\Services\Schedule\ScheduleConditionEvaluator;
use App\Services\Schedule\ScheduleExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 「큐로 보내기」 스케줄 실행 잡
 *
 * 디스패처가 연결·큐(QueueWorkerRegistry 해석)와 timeout(retry_after 아래로 클램프)을 정해 넣는다.
 * payload 에는 스케줄 ID 만 싣는다 — 처리 기록(queue_job_logs)은 `scheduleId` 로 스케줄을 잇는다.
 *
 * tries=1 — 같은 실행이 두 번 이력을 남기지 않게 재시도는 큐 화면에서 운영자가 명시적으로 한다.
 * 워커가 timeout·kill 로 죽으면 failed() 가 실행기의 running 이력을 실패로 마감한다.
 */
class RunScheduledTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var int 최대 시도 횟수 */
    public int $tries = 1;

    /** @var int|null 제한 시간(초) — 디스패처가 행 timeout 을 클램프해 채운다 */
    public ?int $timeout = null;

    /**
     * @param  int  $scheduleId  스케줄 ID
     * @param  ScheduleTriggerType  $trigger  실행 계기
     * @param  int|null  $userId  수동 실행자 ID
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly ScheduleTriggerType $trigger,
        public readonly ?int $userId = null,
    ) {}

    /**
     * 큐 화면·처리 기록에 보이는 이름을 반환합니다.
     *
     * @return string `schedule:{id} {name}` (행이 없으면 `schedule:{id}`)
     */
    public function displayName(): string
    {
        try {
            $name = app(ScheduleRepositoryInterface::class)->findById($this->scheduleId)?->name;
        } catch (Throwable) {
            $name = null;
        }

        return trim("schedule:{$this->scheduleId} ".($name ?? ''));
    }

    /**
     * 스케줄을 실행합니다.
     *
     * 투입과 실행 사이에 바뀐 상태를 다시 본다 — 행 삭제·소유 확장 비활성은 건너뛰고,
     * 예약 실행은 비활성 행·조건 미충족도 건너뛴다(수동 실행은 조건과 무관하게 실행).
     *
     * @param  ScheduleRepositoryInterface  $scheduleRepository  스케줄 저장소
     * @param  ScheduleExecutor  $executor  실행기
     * @param  ScheduleConditionEvaluator  $conditions  실행 조건 해석기
     * @return void 반환값 없음
     */
    public function handle(
        ScheduleRepositoryInterface $scheduleRepository,
        ScheduleExecutor $executor,
        ScheduleConditionEvaluator $conditions,
    ): void {
        $schedule = $scheduleRepository->findById($this->scheduleId);

        if ($schedule === null) {
            Log::info('스케줄 실행 잡 건너뜀 — 행이 없습니다', ['schedule_id' => $this->scheduleId]);

            return;
        }

        if ($this->trigger === ScheduleTriggerType::Scheduled
            && (! $schedule->is_active || ! $conditions->passes($schedule))) {
            Log::info('스케줄 실행 잡 건너뜀 — 비활성이거나 실행 조건이 맞지 않습니다', ['schedule_id' => $this->scheduleId]);

            return;
        }

        try {
            $executor->execute($schedule, $this->trigger, $this->userId);
        } catch (ScheduleExecutionException $e) {
            // 실행 전 거부(소유 확장 비활성·앞선 실행 진행 중)는 잡 실패가 아니다 — 재시도해도 같은 결과다.
            Log::warning('스케줄 실행 잡 거부', [
                'schedule_id' => $this->scheduleId,
                'reason' => $e->reasonCode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 워커가 잡을 실패 처리할 때 실행 중 이력을 마감합니다.
     *
     * @param  Throwable  $exception  실패 사유
     * @return void 반환값 없음
     */
    public function failed(Throwable $exception): void
    {
        Log::error('스케줄 실행 잡 실패', [
            'schedule_id' => $this->scheduleId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        try {
            app(ScheduleExecutor::class)->markAbandoned(
                $this->scheduleId,
                $this->trigger,
                $this->userId,
                $exception->getMessage(),
            );
        } catch (Throwable $e) {
            Log::error('스케줄 실행 잡 실패 마감 실패', ['schedule_id' => $this->scheduleId, 'error' => $e->getMessage()]);
        }
    }
}
