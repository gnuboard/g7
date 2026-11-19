<?php

// audit:allow job-generator-needs-scale-test reason: ID-only payload 단건 실행 진입점 — 생성량이 스케줄 행 수에 묶여 대량 생성 축이 없다

namespace App\Jobs;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ScheduleRejectReason;
use App\Enums\ScheduleTriggerType;
use App\Exceptions\ScheduleExecutionException;
use App\Exceptions\ScheduleRunLockUnavailableException;
use App\Extension\HookContextCapture;
use App\Models\Schedule;
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
 * payload 에는 스케줄 ID 를 싣는다 — 처리 기록(queue_job_logs)은 `scheduleId` 로 스케줄을 잇는다.
 * 수동 실행이면 누른 관리자의 요청 문맥(HookContextCapture::capture())도 함께 싣고, 워커는 실행하는 동안만
 * 그 문맥을 복원한다 — 바로 실행과 같이 실행 중 남는 활동 로그의 행위자·IP·브라우저 정보가 누른 관리자의
 * 것이 되고, 끝나면 문맥을 되돌려 다음 잡에 넘기지 않는다.
 *
 * tries=1 — 같은 실행이 두 번 이력을 남기지 않게 재시도는 큐 화면에서 운영자가 명시적으로 한다.
 * 워커가 timeout·kill 로 죽으면 failed() 가 실행기의 running 이력을 실패로 마감한다.
 * 실행 전 거부(소유 확장 비활성, 앞선 실행 진행 중, 수동 실행의 잠금 저장소 오류)는 경고 로그를 남기고 정상 종료한다 —
 * 실행을 시작하지 않았으므로 last_result·잠금·실패 훅을 건드리지 않는다. 수동 실행의 거부는 실행 이력에
 * 「거절됨」 행 한 줄(사유, 누른 관리자)을 남긴다 — 요청이 이미 접수(202)로 끝나 화면에 알릴 길이 이력뿐이다.
 */
class RunScheduledTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var int 최대 시도 횟수 */
    public int $tries = 1;

    /** @var int|null 제한 시간(초) — 디스패처가 행 timeout 을 클램프해 채운다 */
    public ?int $timeout = null;

    /**
     * 수동 실행을 누른 요청의 문맥 (HookContextCapture::capture() 결과, 예약 실행은 null)
     *
     * 생성자 승격 속성이 아니라 기본값이 있는 일반 속성이다 — unserialize 는 생성자를 돌리지 않으므로
     * 이 속성이 없던 payload 도 null 로 읽힌다.
     *
     * @var array<string, mixed>|null
     */
    public ?array $context = null;

    /**
     * @param  int  $scheduleId  스케줄 ID
     * @param  ScheduleTriggerType  $trigger  실행 계기
     * @param  int|null  $userId  수동 실행자 ID
     * @param  array<string, mixed>|null  $context  수동 실행 요청 문맥 (HookContextCapture::capture() 결과)
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly ScheduleTriggerType $trigger,
        public readonly ?int $userId = null,
        ?array $context = null,
    ) {
        $this->context = $context;
    }

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
     * 실행은 실린 문맥 안에서 한다 — 문맥이 없으면(예약 실행) 사용자 없이 돌고, 어느 쪽이든 끝나면 잡 전
     * 상태로 되돌린다. 상주 워커가 앞 잡의 사용자·요청을 이 실행에 물려주지 않게 하기 위해서다.
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
        HookContextCapture::within(
            $this->context ?? [],
            fn () => $this->run($scheduleRepository, $executor, $conditions),
        );
    }

    /**
     * 복원된 문맥 안에서 스케줄을 실행합니다.
     *
     * @param  ScheduleRepositoryInterface  $scheduleRepository  스케줄 저장소
     * @param  ScheduleExecutor  $executor  실행기
     * @param  ScheduleConditionEvaluator  $conditions  실행 조건 해석기
     * @return void 반환값 없음
     */
    private function run(
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
            // 실행 전 거부(소유 확장 비활성, 앞선 실행 진행 중)는 잡 실패가 아니다 — 재시도해도 같은 결과다.
            Log::warning('스케줄 실행 잡 거부', [
                'schedule_id' => $this->scheduleId,
                'reason' => $e->reasonCode,
                'error' => $e->getMessage(),
            ]);
            $this->recordRejection($executor, $schedule, ScheduleRejectReason::tryFrom((string) $e->reasonCode));
        } catch (ScheduleRunLockUnavailableException $e) {
            // 수동 실행인데 잠금 저장소가 오류를 낸 거절이다 — 실행을 시작하지 않았으므로 잡 실패로 올리지 않는다.
            // 올리면 failed() 가 실패 이력·last_result 를 만들고, 저장소가 돌아온 뒤라면 앞선 실행이 쥔 잠금까지 푼다.
            // 다시 큐에 넣지도 않는다 — 저장소가 언제 돌아올지 모르고, 누른 사람이 다시 누를 수 있다.
            // 저장소 원본 오류는 실행기가 error 로그로 이미 남겼다.
            Log::warning('스케줄 실행 잡 거부', [
                'schedule_id' => $this->scheduleId,
                'reason' => ScheduleRejectReason::RunLockUnavailable->value,
                'error' => $e->getMessage(),
            ]);
            $this->recordRejection($executor, $schedule, ScheduleRejectReason::RunLockUnavailable);
        }
    }

    /**
     * 수동 실행이 거절된 사실을 실행 이력에 남깁니다.
     *
     * 요청은 투입 시점에 이미 접수(202)로 끝났다 — 누른 관리자가 거절을 볼 수 있는 곳은 이력 창뿐이다.
     * 예약 실행은 누른 사람이 없으므로 남기지 않는다(다음 회차가 다시 돈다). 이력을 남기다 난 오류는
     * 삼킨다 — 잡 밖으로 나가면 failed() 의 실패 마감이 실패 이력을 만들고 앞선 실행의 잠금을 푼다.
     *
     * @param  ScheduleExecutor  $executor  실행기
     * @param  Schedule  $schedule  스케줄
     * @param  ScheduleRejectReason|null  $reason  거절 사유 (이력에 남기지 않는 사유면 null)
     * @return void 반환값 없음
     */
    private function recordRejection(ScheduleExecutor $executor, Schedule $schedule, ?ScheduleRejectReason $reason): void
    {
        if ($reason === null || $this->trigger !== ScheduleTriggerType::Manual) {
            return;
        }

        try {
            $executor->recordRejected($schedule, $reason, $this->userId);
        } catch (Throwable $e) {
            Log::error('스케줄 실행 거절 이력 기록 실패', ['schedule_id' => $this->scheduleId, 'error' => $e->getMessage()]);
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
