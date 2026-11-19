<?php

namespace App\Listeners\Scheduling;

use App\Scheduling\ScheduleRunner;
use App\Scheduling\ScheduleRunState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;

/**
 * 서버 크론(`schedule:run`)이 돌린 예약 작업을 웹 러너와 같은 실행 기록에 남기는 리스너.
 *
 * 실행 방식 `auto`·`both` 는 서버 크론과 웹 러너(웹 크론 주소·방문 트리거·`g7:schedule-run`)를
 * 함께 쓴다. 웹 러너는 직전 점검 이후의 소급 창 안에서 due 였던 작업을 돌리는데, 서버 크론은
 * 이벤트별 실행 시각을 남기지 않아 그 창이 크론이 이미 돌린 작업을 「놓친 것」으로 보고 한 번 더
 * 돌렸다. 이벤트 자물쇠(R18)는 동시 실행만 막으므로 이 순차 중복은 막지 못한다.
 *
 * 그래서 프레임워크가 `schedule:run` 안에서만 내는 작업 완료·실패 이벤트로 같은 기록
 * (`ScheduleRunState::recordEventRun`)을 남긴다. 웹 러너는 이 이벤트를 내지 않으므로(자체 기록)
 * 두 번 남지 않는다. 기록이 실패해도 서버 크론의 실행은 막지 않는다.
 */
// audit:allow listener-must-implement-hooklistenerinterface reason: 훅이 아니라 Laravel 스케줄러 이벤트(ScheduledTaskFinished·ScheduledTaskFailed) 리스너 — EventServiceProvider::boot() 가 Event::listen 으로 직접 등록하는 명시 등록 패턴 (RecordQueueJobLog 와 동일)
class RecordServerCronEventRun
{
    /**
     * @param  ScheduleRunner  $runner  스케줄 러너 본체 (이벤트 식별 키의 출처)
     * @param  ScheduleRunState  $state  점검·실행 기록
     */
    public function __construct(
        private readonly ScheduleRunner $runner,
        private readonly ScheduleRunState $state,
    ) {}

    /**
     * 작업 완료를 기록합니다 (종료 코드가 0 이 아니면 실패로 남긴다).
     *
     * @param  ScheduledTaskFinished  $event  작업 완료 이벤트
     * @return void 반환값 없음
     */
    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $exitCode = $event->task->exitCode;

        $this->record($event->task, $exitCode !== null && (int) $exitCode !== 0);
    }

    /**
     * 작업 실패를 기록합니다.
     *
     * @param  ScheduledTaskFailed  $event  작업 실패 이벤트
     * @return void 반환값 없음
     */
    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, true);
    }

    /**
     * 이벤트의 실행 시각을 웹 러너와 같은 키로 남깁니다.
     *
     * @param  Event  $task  스케줄 이벤트
     * @param  bool  $failed  실패로 끝났는지
     * @return void 반환값 없음
     */
    private function record(Event $task, bool $failed): void
    {
        try {
            $key = $this->runner->eventKeyFor($task, app());

            if ($key === null) {
                return;
            }

            // 웹 러너가 같은 파일을 그사이 바꿨을 수 있다 — 읽기 캐시를 버리고 최신 상태 위에 쓴다.
            $this->state->refresh();
            $this->state->recordEventRun($key, CarbonImmutable::now(), $failed);
        } catch (\Throwable $e) {
            Log::warning('서버 크론 실행 기록을 남기지 못했습니다 — 웹 러너가 이 작업을 소급으로 다시 돌릴 수 있습니다.', [
                'event' => $task->getSummaryForDisplay(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
