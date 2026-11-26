<?php

namespace App\Services\Schedule;

use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DB 스케줄 러너 — 지정한 시각에 due 인 DB 스케줄 이벤트만 실행합니다.
 *
 * 서버 크론(`schedule:run`)이 아닌 경로(웹 크론 등)에서 한 시점의 DB 스케줄을 돌릴 때 쓴다.
 * 등록기와 같은 규칙으로 새 스케줄러에 등록한 뒤 `schedule:` 이벤트만 골라 실행하므로
 * 이벤트 이름·조건·다중 서버 1대·점검 모드 처리가 `schedule:run` 과 같다.
 *
 * - 30초 주기 행은 호출 1회에 1번만 실행한다(같은 분의 두 번째 슬롯은 다음 호출이 맡는다).
 * - 웹 크론 러너(`app/Scheduling/ScheduleRunner`)와 이름이 겹치지 않도록 이 이름을 쓴다.
 *   그쪽이 Laravel 스케줄러 이벤트 전체를 돌린다면 이 러너를 함께 부르지 않는다(이중 실행).
 */
class DatabaseScheduleRunner
{
    /**
     * @param  ScheduleRegistrar  $registrar  DB 스케줄 등록기
     * @param  Application  $app  애플리케이션
     */
    public function __construct(
        private readonly ScheduleRegistrar $registrar,
        private readonly Application $app,
    ) {}

    /**
     * 지정한 시각에 due 인 DB 스케줄 이벤트를 실행합니다.
     *
     * @param  CarbonInterface  $now  기준 시각
     * @return list<string> 실행한 이벤트 이름 (`schedule:{id}`·`schedule:heartbeat`)
     */
    public function runDue(CarbonInterface $now): array
    {
        $defaultTimezone = (string) (config('app.schedule_timezone') ?? config('app.timezone', 'UTC'));
        // 컨테이너의 Schedule 싱글턴(콘솔 라우트가 이미 등록한 이벤트 포함)을 쓰지 않고 새 인스턴스에 등록한다.
        $schedule = new Schedule($defaultTimezone);
        $this->registrar->register($schedule);

        $ran = [];

        foreach ($schedule->events() as $event) {
            if (! $this->registrar->isManaged($event) || ! $this->isDueAt($event, $now, $defaultTimezone)) {
                continue;
            }

            if (! $event->runsInMaintenanceMode() && $this->app->isDownForMaintenance()) {
                continue;
            }

            if (! $event->filtersPass($this->app)) {
                continue;
            }

            if ($event->onOneServer && ! $schedule->serverShouldRun($event, $now)) {
                continue;
            }

            try {
                $event->run($this->app);
                $ran[] = (string) $event->description;
            } catch (Throwable $e) {
                // 한 이벤트의 실패가 같은 시점의 나머지 실행을 막지 않게 한다.
                Log::warning('DB 스케줄 러너 이벤트 실패', ['event' => $event->description, 'error' => $e->getMessage()]);
            }
        }

        return $ran;
    }

    /**
     * 이벤트의 cron 식이 기준 시각에 due 인지 판정합니다 (이벤트 시간대 기준).
     *
     * @param  Event  $event  스케줄 이벤트
     * @param  CarbonInterface  $now  기준 시각
     * @param  string  $defaultTimezone  이벤트에 시간대가 없을 때 쓸 시간대
     * @return bool due 이면 true
     */
    private function isDueAt(Event $event, CarbonInterface $now, string $defaultTimezone): bool
    {
        $timezone = $event->timezone ?: $defaultTimezone;
        $timezone = $timezone instanceof \DateTimeZone ? $timezone->getName() : (string) $timezone;

        return (new CronExpression($event->expression))->isDue(Carbon::instance($now)->toDateTime(), $timezone);
    }
}
