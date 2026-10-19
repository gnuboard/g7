<?php

namespace Tests\Feature\Schedule;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Exceptions\ScheduleExecutionException;
use App\Jobs\GenerateSitemapJob;
use App\Jobs\RunScheduledTaskJob;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Models\User;
use App\Services\Queue\QueueWorkerRegistry;
use App\Services\Schedule\ScheduleDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 스케줄 디스패처 — 바로 실행 / 큐로 보내기 분기, 큐 연결 해석·폴백, timeout 클램프, 실행 전 거부
 */
class ScheduleDispatcherTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 테스트 준비
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('g7_settings.core.security.allow_internal_outbound_urls', true);
        Http::fake(['*' => Http::response('pong', 200)]);
    }

    /**
     * 바로 실행 행은 실행기를 직접 불러 이력을 돌려준다(큐 잡을 쓰지 않는다).
     *
     * @scenario dispatch_mode=sync, trigger=manual, outcome=success
     *
     * @effects sync_dispatch_runs_in_process_and_returns_history
     *
     * @return void 반환값 없음
     */
    public function test_sync_mode_runs_in_process_and_returns_history(): void
    {
        Queue::fake();
        $schedule = $this->schedule(['dispatch_mode' => 'sync']);

        $operator = User::factory()->create();

        $history = app(ScheduleDispatcher::class)->dispatch($schedule, ScheduleTriggerType::Manual, $operator->id);

        $this->assertInstanceOf(ScheduleHistory::class, $history);
        $this->assertSame(ScheduleResultStatus::Success, $history->status);
        $this->assertSame(ScheduleTriggerType::Manual, $history->trigger_type);
        $this->assertSame($operator->id, $history->triggered_by);
        Queue::assertNotPushed(RunScheduledTaskJob::class);
    }

    /**
     * ID 로도 실행할 수 있고, 지워진 행은 아무것도 하지 않는다.
     *
     * @scenario dispatch_mode=sync, trigger=scheduled
     *
     * @effects dispatch_accepts_id_and_ignores_missing_row
     *
     * @return void 반환값 없음
     */
    public function test_dispatch_accepts_id_and_ignores_missing_row(): void
    {
        $schedule = $this->schedule(['dispatch_mode' => 'sync']);

        $this->assertNotNull(app(ScheduleDispatcher::class)->dispatch($schedule->id, ScheduleTriggerType::Scheduled));
        $this->assertNull(app(ScheduleDispatcher::class)->dispatch(PHP_INT_MAX, ScheduleTriggerType::Scheduled));
    }

    /**
     * 큐로 보내기 행은 실행 잡을 넣고 null 을 돌려준다. 잡은 tries=1·스케줄 ID·계기를 싣는다.
     *
     * @scenario dispatch_mode=queue, trigger=manual
     *
     * @effects queue_dispatch_pushes_run_job
     *
     * @return void 반환값 없음
     */
    public function test_queue_mode_pushes_run_job_and_returns_null(): void
    {
        Queue::fake();
        $schedule = $this->schedule(['dispatch_mode' => 'queue']);

        $result = app(ScheduleDispatcher::class)->dispatch($schedule, ScheduleTriggerType::Manual, 3);

        $this->assertNull($result);
        Queue::assertPushed(RunScheduledTaskJob::class, function (RunScheduledTaskJob $job) use ($schedule) {
            return $job->scheduleId === $schedule->id
                && $job->trigger === ScheduleTriggerType::Manual
                && $job->userId === 3
                && $job->tries === 1;
        });
        $this->assertSame(0, ScheduleHistory::where('schedule_id', $schedule->id)->count(), '투입 시점에 이력이 생기면 안 된다');
    }

    /**
     * 등록되지 않은 큐 연결을 가리키면 기본 연결로 폴백하고 폴백 사례를 센다.
     *
     * @scenario dispatch_mode=queue
     *
     * @effects unregistered_queue_connection_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_queue_connection_falls_back_to_default(): void
    {
        Queue::fake();
        $schedule = $this->schedule([
            'dispatch_mode' => 'queue',
            'queue_connection' => 'not-registered-'.uniqid(),
            'queue_name' => 'nowhere',
        ]);
        $registry = app(QueueWorkerRegistry::class);
        $before = (int) ($registry->statusReport()['placement']['fallback']['count'] ?? 0);

        app(ScheduleDispatcher::class)->dispatch($schedule, ScheduleTriggerType::Scheduled);

        Queue::assertPushed(RunScheduledTaskJob::class, function (RunScheduledTaskJob $job) {
            return $job->connection === null;
        });
        $this->assertGreaterThan($before, (int) ($registry->statusReport()['placement']['fallback']['count'] ?? 0), '폴백 사례가 기록되지 않았다');
    }

    /**
     * 잡 timeout 은 행 timeout(없으면 600초)을 연결의 retry_after 아래로 줄인 값이다.
     *
     * @scenario dispatch_mode=queue
     *
     * @effects queue_job_timeout_clamped_below_retry_after
     *
     * @return void 반환값 없음
     */
    public function test_queue_job_timeout_is_clamped_below_retry_after(): void
    {
        Queue::fake();
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.retry_after', 90);
        Config::set('queue.worker_registry.timeout_margin_seconds', 10);

        $long = $this->schedule(['dispatch_mode' => 'queue', 'timeout' => 3600]);
        $short = $this->schedule(['dispatch_mode' => 'queue', 'timeout' => 30]);

        app(ScheduleDispatcher::class)->dispatch($long, ScheduleTriggerType::Scheduled);
        app(ScheduleDispatcher::class)->dispatch($short, ScheduleTriggerType::Scheduled);

        Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job) => $job->scheduleId === $long->id && $job->timeout === 80);
        Queue::assertPushed(RunScheduledTaskJob::class, fn (RunScheduledTaskJob $job) => $job->scheduleId === $short->id && $job->timeout === 30);
    }

    /**
     * job 유형은 큐 모드여도 실행 잡으로 감싸지 않는다 — Job 자체가 자기 큐로 간다.
     *
     * @scenario schedule_type=job, dispatch_mode=queue
     *
     * @effects job_runs_in_declared_dispatch_mode
     *
     * @return void 반환값 없음
     */
    public function test_job_type_queue_mode_is_not_wrapped(): void
    {
        Config::set('schedule_security.job.allowed_classes', [GenerateSitemapJob::class]);
        Bus::fake([GenerateSitemapJob::class, RunScheduledTaskJob::class]);

        $schedule = $this->schedule(['type' => 'job', 'command' => GenerateSitemapJob::class, 'dispatch_mode' => 'queue']);

        $history = app(ScheduleDispatcher::class)->dispatch($schedule, ScheduleTriggerType::Scheduled);

        $this->assertNotNull($history);
        Bus::assertDispatched(GenerateSitemapJob::class);
        Bus::assertNotDispatched(RunScheduledTaskJob::class);
    }

    /**
     * 소유 확장이 비활성이면 투입 전에 owner_inactive 로 거부한다(큐에도 넣지 않는다).
     *
     * @scenario owner=module_inactive, dispatch_mode=queue, outcome=rejected
     *
     * @effects inactive_owner_is_blocked
     *
     * @return void 반환값 없음
     */
    public function test_inactive_owner_is_rejected_before_dispatch(): void
    {
        Queue::fake();
        $schedule = Schedule::factory()
            ->ownedBy(ExtensionOwnerType::Plugin, 'vendor-not-installed-'.uniqid())
            ->create(['type' => 'url', 'command' => 'http://192.168.0.10/ok', 'dispatch_mode' => 'queue'])
            ->fresh();

        try {
            app(ScheduleDispatcher::class)->dispatch($schedule, ScheduleTriggerType::Manual);
            $this->fail('비활성 확장의 스케줄이 투입되었다');
        } catch (ScheduleExecutionException $e) {
            $this->assertSame('owner_inactive', $e->reasonCode);
        }

        Queue::assertNotPushed(RunScheduledTaskJob::class);
    }

    /**
     * URL 호출 스케줄을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return Schedule 저장 후 다시 읽은 스케줄
     */
    private function schedule(array $attributes): Schedule
    {
        return Schedule::factory()->create([
            'type' => 'url',
            'command' => 'http://192.168.0.10/ok',
            ...$attributes,
        ])->fresh();
    }
}
