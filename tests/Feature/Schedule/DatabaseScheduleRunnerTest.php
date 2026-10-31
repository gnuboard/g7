<?php

namespace Tests\Feature\Schedule;

use App\Enums\ScheduleTriggerType;
use App\Models\Schedule;
use App\Services\Schedule\DatabaseScheduleRunner;
use App\Services\Schedule\ScheduleDispatcher;
use App\Services\Schedule\ScheduleHeartbeat;
use App\Services\Schedule\ScheduleRegistrar;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/**
 * DB 스케줄 러너 — 지정 시각에 due 인 `schedule:` 이벤트만 행 시간대 기준으로 실행
 */
class DatabaseScheduleRunnerTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 기준 시각에 due 인 행만 실행하고, 행 시간대로 cron 을 해석하며, 생존 신호도 남긴다.
     *
     * @scenario frequency=cron, trigger=scheduled
     *
     * @effects runner_runs_only_due_rows_in_row_timezone, heartbeat_event_registered_every_minute
     *
     * @return void 반환값 없음
     */
    public function test_runs_only_due_rows_in_row_timezone(): void
    {
        $due = Schedule::factory()->create(['expression' => '30 11 * * *', 'timezone' => 'Asia/Seoul']);
        $notDue = Schedule::factory()->create(['expression' => '31 11 * * *', 'timezone' => 'Asia/Seoul']);

        $dispatched = [];
        $dispatcher = Mockery::mock(ScheduleDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturnUsing(function (int $id, ScheduleTriggerType $trigger) use (&$dispatched) {
                $dispatched[] = [$id, $trigger];

                return null;
            });
        $this->app->instance(ScheduleDispatcher::class, $dispatcher);

        // 11:30 Asia/Seoul = 02:30 UTC
        $ran = app(DatabaseScheduleRunner::class)->runDue(Carbon::parse('2026-10-16 02:30:00', 'UTC'));

        $this->assertContains("schedule:{$due->id}", $ran);
        $this->assertNotContains("schedule:{$notDue->id}", $ran);
        $this->assertContains(ScheduleRegistrar::HEARTBEAT_EVENT, $ran);
        $this->assertContains([$due->id, ScheduleTriggerType::Scheduled], $dispatched);
        $this->assertNotContains([$notDue->id, ScheduleTriggerType::Scheduled], $dispatched);
        $this->assertNotNull(app(ScheduleHeartbeat::class)->lastTick());
    }

    /**
     * 실행 조건이 맞지 않는 행은 due 여도 실행하지 않는다.
     *
     * @scenario condition=unmet
     *
     * @effects conditions_are_evaluated_at_run_time
     *
     * @return void 반환값 없음
     */
    public function test_unmet_condition_is_not_run(): void
    {
        config(['g7_settings.core.seo.sitemap_enabled' => false]);
        $row = Schedule::factory()->create([
            'expression' => '* * * * *',
            'enabled_condition' => [['source' => 'core_setting', 'key' => 'seo.sitemap_enabled', 'operator' => 'truthy', 'default' => true]],
        ]);

        $dispatcher = Mockery::mock(ScheduleDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->with($row->id, Mockery::any())->never();
        $dispatcher->shouldReceive('dispatch')->andReturnNull();
        $this->app->instance(ScheduleDispatcher::class, $dispatcher);

        $ran = app(DatabaseScheduleRunner::class)->runDue(Carbon::now());

        $this->assertNotContains("schedule:{$row->id}", $ran);
    }
}
