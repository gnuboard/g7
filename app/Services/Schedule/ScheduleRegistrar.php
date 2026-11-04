<?php

namespace App\Services\Schedule;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleFrequency;
use App\Models\Schedule as ScheduleModel;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * DB 스케줄 등록기
 *
 * `routes/console.php` 의 한 줄이 이 클래스를 부른다. 활성 스케줄 행마다 `Schedule::call` 이벤트를
 * `schedule:{id}` 이름으로 등록하고, 실제 실행은 디스패처(바로 실행 / 큐로 보내기)에 맡긴다.
 *
 * - 이벤트 이름이 행마다 달라야 `onOneServer` 잠금을 서로 공유하지 않는다(같은 분에 겹친 작업이 건너뛰지 않게).
 * - Laravel `withoutOverlapping` 은 쓰지 않는다 — 큐 모드는 투입 직후 잠금이 풀리므로
 *   중복 방지는 실행기의 캐시 잠금(`schedule:run:{id}`)이 모든 경로(틱·수동·큐)를 맡는다.
 * - 이름이 `schedule:` 로 시작하는 이벤트는 이 등록기가 관리한다(isManaged) — 웹 크론 러너(#609)는
 *   이 이벤트를 그대로 돌리고 이력은 실행기에 맡긴다. 러너가 정한 계기·실행자는 `ScheduleRunContext` 로 넘어온다.
 */
class ScheduleRegistrar
{
    /** DB 스케줄 이벤트 이름 접두사 */
    public const EVENT_PREFIX = 'schedule:';

    /** 스케줄러 생존 신호 이벤트 이름 */
    public const HEARTBEAT_EVENT = 'schedule:heartbeat';

    /**
     * @param  ScheduleRepositoryInterface  $scheduleRepository  스케줄 저장소
     * @param  ScheduleConditionEvaluator  $conditions  실행 조건 해석기
     */
    public function __construct(
        private readonly ScheduleRepositoryInterface $scheduleRepository,
        private readonly ScheduleConditionEvaluator $conditions,
    ) {}

    /**
     * 생존 신호와 활성 스케줄 행을 스케줄러에 등록합니다.
     *
     * 등록 실패가 artisan 전체를 막지 않도록 예외는 로그로만 남긴다(설치 전·업그레이드 도중 포함).
     *
     * @param  Schedule  $schedule  Laravel 스케줄러
     * @return void 반환값 없음
     */
    public function register(Schedule $schedule): void
    {
        $schedule->call(static fn () => app(ScheduleHeartbeat::class)->beat())
            ->name(self::HEARTBEAT_EVENT)
            ->everyMinute();

        try {
            // 설치 전(테이블 없음)은 조용히 건너뛴다.
            if (! Schema::hasTable('schedules')) {
                return;
            }

            // 업그레이드 도중(새 컬럼 마이그레이션 전)에는 행의 실행 방식을 알 수 없다 — 등록하지 않는다.
            if (! Schema::hasColumn('schedules', 'dispatch_mode')) {
                Log::warning('DB 스케줄 등록 건너뜀 — schedules 테이블이 아직 새 스키마가 아닙니다 (마이그레이션 필요)');

                return;
            }

            $rows = $this->registrableRows();
        } catch (Throwable $e) {
            Log::warning('DB 스케줄 등록 건너뜀', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($rows as $row) {
            try {
                $this->registerRow($schedule, $row);
            } catch (Throwable $e) {
                // 한 행의 잘못된 값(깨진 cron 식 등)이 나머지 등록을 막지 않게 한다.
                Log::warning('DB 스케줄 행 등록 실패', ['schedule_id' => $row->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * 이 등록기가 관리하는 이벤트인지 판정합니다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @return bool 이름이 `schedule:` 로 시작하면 true
     */
    public function isManaged(Event $event): bool
    {
        return str_starts_with((string) $event->description, self::EVENT_PREFIX);
    }

    /**
     * 등록할 행을 등록 순서대로 반환합니다.
     *
     * 소유 확장이 비활성인 행은 뺀다. 순서는 30초 주기 → 바로 실행 → 큐로 보내기 —
     * 같은 분에 도는 작업 중 가벼운 것이 먼저 돌고, 무거운 바로 실행 작업이 30초 작업을 밀지 않게 한다.
     *
     * @return Collection<int, ScheduleModel> 등록 대상 행
     */
    private function registrableRows(): Collection
    {
        return collect($this->scheduleRepository->getActiveSchedules())
            ->filter(static fn (ScheduleModel $row): bool => $row->ownerIsActive())
            ->sortBy(static fn (ScheduleModel $row): array => [
                $row->frequency === ScheduleFrequency::EveryThirtySeconds ? 0 : 1,
                $row->dispatch_mode === ScheduleDispatchMode::Queue ? 1 : 0,
                (int) $row->id,
            ])
            ->values();
    }

    /**
     * 행 하나를 `schedule:{id}` 이벤트로 등록합니다.
     *
     * @param  Schedule  $schedule  Laravel 스케줄러
     * @param  ScheduleModel  $row  스케줄 행
     * @return void 반환값 없음
     */
    private function registerRow(Schedule $schedule, ScheduleModel $row): void
    {
        $id = (int) $row->id;

        // 계기·실행자는 실행 문맥에서 읽는다 — 서버 크론에서는 늘 예약 실행이고, 웹 러너가 관리자의
        // [지금 실행]으로 돌릴 때만 수동 실행과 누른 사람이 실린다. 결과는 문맥에 돌려줘 러너 요약이
        // 실행기가 마감한 성공·실패를 그대로 센다.
        $event = $schedule->call(static function () use ($id): void {
            try {
                ScheduleRunContext::report(
                    app(ScheduleDispatcher::class)->dispatch($id, ScheduleRunContext::trigger(), ScheduleRunContext::userId()),
                );
            } catch (Throwable $e) {
                ScheduleRunContext::report($e);
                // 실행 실패는 실행기가 이력으로 남긴다 — 여기는 실행 전 거부(비활성 확장, 수동 실행 중복 등)만 온다.
                Log::warning('DB 스케줄 실행 거부', ['schedule_id' => $id, 'error' => $e->getMessage()]);
            }
        })->name(self::EVENT_PREFIX.$id);

        if ($row->frequency === ScheduleFrequency::EveryThirtySeconds) {
            $event->everyThirtySeconds();
        } else {
            $event->cron((string) $row->expression);
        }

        // 행 시간대가 없으면 스케줄러 전체 기준(app.schedule_timezone)을 그대로 쓴다.
        if (! empty($row->timezone)) {
            $event->timezone((string) $row->timezone);
        }

        if ($row->run_in_maintenance) {
            $event->evenInMaintenanceMode();
        }

        if ($row->on_one_server) {
            $event->onOneServer();
        }

        if (! empty($row->enabled_condition)) {
            $conditions = $this->conditions;
            $event->when(static fn (): bool => $conditions->passes($row));
        }
    }
}
