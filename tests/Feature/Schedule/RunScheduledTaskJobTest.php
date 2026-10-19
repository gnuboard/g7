<?php

namespace Tests\Feature\Schedule;

use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Jobs\RunScheduledTaskJob;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Services\Schedule\ScheduleExecutor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 큐 실행 잡 — payload 계약(K5), 실행 직전 재확인, 워커 사망 시 이력 마감
 */
class RunScheduledTaskJobTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 생성자·tries·표시 이름·직렬화 왕복이 계약대로다(스케줄 ID 만 싣는다).
     *
     * @scenario dispatch_mode=queue
     *
     * @effects queue_dispatch_pushes_run_job
     *
     * @return void 반환값 없음
     */
    public function test_payload_contract(): void
    {
        $schedule = Schedule::factory()->create(['name' => 'nightly-report-'.uniqid()]);
        $job = new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Manual, 9);

        $this->assertSame(1, $job->tries);
        $this->assertSame("schedule:{$schedule->id} {$schedule->name}", $job->displayName());
        $this->assertSame('schedule:'.PHP_INT_MAX, (new RunScheduledTaskJob(PHP_INT_MAX, ScheduleTriggerType::Scheduled))->displayName());

        $restored = unserialize(serialize($job));
        $this->assertSame($schedule->id, $restored->scheduleId);
        $this->assertSame(ScheduleTriggerType::Manual, $restored->trigger);
        $this->assertSame(9, $restored->userId);
    }

    /**
     * 예약 실행 잡은 투입 뒤 비활성으로 바뀐 행·조건 미충족 행을 건너뛰고, 수동 실행 잡은 조건과 무관하게 실행한다.
     *
     * @scenario trigger=queue_worker, condition=unmet, trigger=manual
     *
     * @effects queued_run_rechecks_state_before_running
     *
     * @return void 반환값 없음
     */
    public function test_handle_rechecks_state_before_running(): void
    {
        config(['g7_settings.core.seo.sitemap_enabled' => false]);
        $schedule = Schedule::factory()->create([
            'enabled_condition' => [['source' => 'core_setting', 'key' => 'seo.sitemap_enabled', 'operator' => 'truthy', 'default' => true]],
        ]);
        $inactive = Schedule::factory()->create(['is_active' => false]);

        $executor = Mockery::mock(ScheduleExecutor::class);
        $executor->shouldReceive('execute')
            ->once()
            ->withArgs(fn (Schedule $s, ScheduleTriggerType $t) => $s->id === $schedule->id && $t === ScheduleTriggerType::Manual)
            ->andReturnNull();
        $this->app->instance(ScheduleExecutor::class, $executor);

        app()->call([new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Scheduled), 'handle']);
        app()->call([new RunScheduledTaskJob($inactive->id, ScheduleTriggerType::Scheduled), 'handle']);
        app()->call([new RunScheduledTaskJob(PHP_INT_MAX, ScheduleTriggerType::Scheduled), 'handle']);
        app()->call([new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Manual), 'handle']);

        $this->addToAssertionCount(1);
    }

    /**
     * 워커가 잡을 실패 처리하면 running 이력이 failed 로 마감된다.
     *
     * @scenario trigger=queue_worker, outcome=failure
     *
     * @effects abandoned_queue_run_is_closed_as_failed
     *
     * @return void 반환값 없음
     */
    public function test_failed_closes_running_history(): void
    {
        $schedule = Schedule::factory()->create();
        $running = ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now(),
            'status' => ScheduleResultStatus::Running,
            'trigger_type' => ScheduleTriggerType::Scheduled,
        ]);

        (new RunScheduledTaskJob($schedule->id, ScheduleTriggerType::Scheduled))
            ->failed(new RuntimeException('has timed out'));

        $this->assertSame(ScheduleResultStatus::Failed, $running->fresh()->status);
        $this->assertSame('has timed out', $running->fresh()->error_output);
        $this->assertSame(ScheduleResultStatus::Failed, $schedule->fresh()->last_result);
    }
}
