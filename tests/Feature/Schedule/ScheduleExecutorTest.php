<?php

namespace Tests\Feature\Schedule;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Exceptions\ScheduleExecutionException;
use App\Extension\HookManager;
use App\Jobs\GenerateSitemapJob;
use App\Listeners\CoreActivityLogListener;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Services\Schedule\ScheduleExecutor;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Modules\G7TestingWeb\Providers\FakeWebCommandServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 스케줄 실행기 — 이력 모드·중복 방지 잠금·실행 전 거부·타입별 실행·실패 마감
 *
 * URL 유형은 내부 주소 허용 설정 + `Http::fake` 로 성공/실패를 만든다(외부 통신 없음).
 */
class ScheduleExecutorTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<array{0: string, 1: array}> 이 테스트가 붙인 훅 (tearDown 에서 뗀다) */
    private array $hooks = [];

    /**
     * 테스트 준비
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('g7_settings.core.security.allow_internal_outbound_urls', true);
        Http::fake([
            'http://192.168.0.10/ok' => Http::response('pong', 200),
            'http://192.168.0.10/fail' => Http::response('nope', 500),
        ]);
    }

    /**
     * 테스트 정리
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->hooks as [$hook, $callback]) {
            HookManager::removeAction($hook, $callback);
        }

        parent::tearDown();
    }

    /**
     * 「모든 실행 기록」 성공은 running → success 이력 1건과 행 상태 갱신을 남긴다.
     *
     * @scenario history_mode=all, outcome=success, trigger=scheduled
     *
     * @effects history_all_records_every_run, row_state_updated_without_model_events
     *
     * @return void 반환값 없음
     */
    public function test_all_mode_success_records_history_and_row_state(): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/ok');

        $history = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);

        $this->assertInstanceOf(ScheduleHistory::class, $history);
        $this->assertTrue($history->exists);
        $this->assertSame(ScheduleResultStatus::Success, $history->status);
        $this->assertSame(ScheduleTriggerType::Scheduled, $history->trigger_type);
        $this->assertStringContainsString('HTTP Status: 200', (string) $history->output);
        $this->assertSame(1, ScheduleHistory::where('schedule_id', $schedule->id)->count());

        $row = $schedule->fresh();
        $this->assertSame(ScheduleResultStatus::Success, $row->last_result);
        $this->assertNotNull($row->last_run_at);
        $this->assertNotNull($row->next_run_at);
    }

    /**
     * 「실패만 기록」 성공은 이력 0건, 반환 null, 실행 후 훅에는 저장되지 않은 이력이 간다.
     *
     * @scenario history_mode=failures_only, outcome=success
     *
     * @effects failures_only_success_leaves_no_history, after_run_keeps_history_argument
     *
     * @return void 반환값 없음
     */
    public function test_failures_only_success_leaves_no_history(): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/ok', ['history_mode' => 'failures_only']);
        $captured = [];
        $this->listen('core.schedule.after_run', function ($s, $h) use (&$captured) {
            $captured[] = $h;
        });

        $result = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);

        $this->assertNull($result);
        $this->assertSame(0, ScheduleHistory::where('schedule_id', $schedule->id)->count());
        $this->assertCount(1, $captured);
        $this->assertInstanceOf(ScheduleHistory::class, $captured[0]);
        $this->assertFalse($captured[0]->exists, '「실패만 기록」 성공 이력이 저장되었다');
        $this->assertSame(ScheduleResultStatus::Success, $captured[0]->status);
        $this->assertSame(ScheduleResultStatus::Success, $schedule->fresh()->last_result);
    }

    /**
     * 「실패만 기록」 실패는 실패 이력 1건만 남긴다(running 선행 기록 없음).
     *
     * @scenario history_mode=failures_only, outcome=failure
     *
     * @effects failures_only_failure_records_one_history
     *
     * @return void 반환값 없음
     */
    public function test_failures_only_failure_records_single_failed_history(): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/fail', ['history_mode' => 'failures_only']);

        $history = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);

        $this->assertNotNull($history);
        $this->assertSame(ScheduleResultStatus::Failed, $history->status);
        $this->assertSame(1, $history->exit_code, 'HTTP 상태(500)를 종료 코드 컬럼에 넣으면 이력 저장이 실패한다');
        $this->assertStringContainsString('500', (string) $history->error_output);
        $this->assertSame(1, ScheduleHistory::where('schedule_id', $schedule->id)->count());
        $this->assertSame(ScheduleResultStatus::Failed, $schedule->fresh()->last_result);
    }

    /**
     * 실행 실패는 예외 없이 실패 이력을 돌려주고 run_failed 훅에 문자열 오류를 넘긴다.
     *
     * @scenario history_mode=all, outcome=failure
     *
     * @effects failure_returns_failed_history_without_throwing, run_failed_hook_receives_string_error
     *
     * @return void 반환값 없음
     */
    public function test_failure_returns_history_and_fires_run_failed_with_string(): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/fail');
        $captured = [];
        $this->listen('core.schedule.run_failed', function ($s, $h, $error) use (&$captured) {
            $captured[] = [$s, $h, $error];
        });

        $history = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);

        $this->assertSame(ScheduleResultStatus::Failed, $history->status);
        $this->assertNotEmpty($history->error_output);
        $this->assertCount(1, $captured);
        $this->assertSame($schedule->id, $captured[0][0]->id);
        $this->assertSame($history->id, $captured[0][1]->id);
        $this->assertIsString($captured[0][2], '오류가 문자열이 아니면 큐 직렬화에서 사라진다');
        $this->assertSame($history->error_output, $captured[0][2]);
    }

    /**
     * 중복 방지 잠금 유지 시간은 `without_overlapping` 분 × 60 초다 (현행 TTL 5/30/55/60/120 보존).
     *
     * @param  int  $minutes  잠금 유지 분
     *
     * @scenario overlap=minutes
     *
     * @effects overlap_lock_ttl_preserves_minutes
     *
     * @return void 반환값 없음
     */
    #[DataProvider('overlapMinutesProvider')]
    public function test_overlap_lock_ttl_is_minutes_times_sixty(int $minutes): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/ok', ['without_overlapping' => $minutes]);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andReturn(true);

        Cache::partialMock()
            ->shouldReceive('lock')
            ->once()
            ->with(ScheduleExecutor::LOCK_PREFIX.$schedule->id, $minutes * 60)
            ->andReturn($lock);

        $history = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);

        $this->assertSame(ScheduleResultStatus::Success, $history->status);
    }

    /**
     * 현행 console.php 의 withoutOverlapping 분 값.
     *
     * @return array<string, array{int}> 데이터 케이스 (케이스 이름 → 테스트 인자)
     */
    public static function overlapMinutesProvider(): array
    {
        return [
            '30초 작업 5분' => [5],
            '사이트맵 30분' => [30],
            '매시 집계 55분' => [55],
            '주간·GeoIP 60분' => [60],
            '일 집계 120분' => [120],
        ];
    }

    /**
     * 중복 방지가 꺼져 있으면(0) 잠금을 잡지 않는다.
     *
     * @scenario overlap=off
     *
     * @effects overlap_off_takes_no_lock
     *
     * @return void 반환값 없음
     */
    public function test_overlap_off_takes_no_lock(): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/ok', ['without_overlapping' => 0]);

        Cache::partialMock()->shouldNotReceive('lock');

        $history = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);

        $this->assertSame(ScheduleResultStatus::Success, $history->status);
    }

    /**
     * 앞선 실행이 잠금을 쥐고 있으면 예약 실행은 건너뛰고(null·이력 0) 수동 실행은 already_running 으로 거부된다.
     *
     * @scenario overlap=minutes, trigger=scheduled, trigger=manual, outcome=rejected
     *
     * @effects overlapping_scheduled_run_is_skipped, overlapping_manual_run_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_held_lock_skips_scheduled_and_rejects_manual(): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/ok', ['without_overlapping' => 5]);
        $held = Cache::lock(ScheduleExecutor::LOCK_PREFIX.$schedule->id, 300);
        $this->assertTrue($held->get());

        try {
            $this->assertNull(app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled));
            $this->assertSame(0, ScheduleHistory::where('schedule_id', $schedule->id)->count());

            try {
                app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Manual, null);
                $this->fail('잠금 중 수동 실행이 거부되지 않았다');
            } catch (ScheduleExecutionException $e) {
                $this->assertSame('already_running', $e->reasonCode);
            }

            Http::assertNothingSent();
        } finally {
            $held->release();
        }
    }

    /**
     * 소유 확장이 비활성이면 실행 전에 owner_inactive 로 거부하고 이력을 남기지 않는다.
     *
     * @scenario owner=module_inactive, outcome=rejected
     *
     * @effects inactive_owner_is_blocked
     *
     * @return void 반환값 없음
     */
    public function test_inactive_owner_is_rejected_before_running(): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/ok', [], ExtensionOwnerType::Module, 'vendor-not-installed-'.uniqid());

        try {
            app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);
            $this->fail('비활성 확장의 스케줄이 실행되었다');
        } catch (ScheduleExecutionException $e) {
            $this->assertSame('owner_inactive', $e->reasonCode);
        }

        Http::assertNothingSent();
        $this->assertSame(0, ScheduleHistory::where('schedule_id', $schedule->id)->count());
    }

    /**
     * artisan 명령이 0 이 아닌 종료 코드로 끝나면 실패다(출력이 오류 메시지가 된다).
     *
     * @scenario schedule_type=artisan, outcome=failure
     *
     * @effects nonzero_exit_code_is_failure
     *
     * @return void 반환값 없음
     */
    public function test_artisan_nonzero_exit_code_is_failure(): void
    {
        $kernel = Mockery::mock(ConsoleKernel::class)->shouldIgnoreMissing();
        $kernel->shouldReceive('call')->once()->andReturn(3);
        $kernel->shouldReceive('output')->andReturn('boom');
        Artisan::swap($kernel);

        $schedule = Schedule::factory()->create([
            'type' => 'artisan',
            'command' => 'notification:cleanup',
        ])->fresh();

        $history = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);

        $this->assertSame(ScheduleResultStatus::Failed, $history->status);
        $this->assertSame(3, $history->exit_code);
        $this->assertSame('boom', $history->error_output);
    }

    /**
     * job 유형: 바로 실행은 동기 실행, 큐로 보내기는 Job 자체를 큐에 넣는다(실행 잡으로 감싸지 않음).
     *
     * @scenario schedule_type=job, dispatch_mode=sync, dispatch_mode=queue, outcome=success
     *
     * @effects job_runs_in_declared_dispatch_mode
     *
     * @return void 반환값 없음
     */
    public function test_job_type_runs_in_declared_dispatch_mode(): void
    {
        Config::set('schedule_security.job.allowed_classes', [GenerateSitemapJob::class]);
        // 대상 Job 만 가짜로 둔다 — 실행 후 훅의 큐 리스너 잡은 그대로 흐르게 한다.
        Bus::fake([GenerateSitemapJob::class]);

        $sync = $this->jobSchedule(GenerateSitemapJob::class, 'sync');
        $queue = $this->jobSchedule(GenerateSitemapJob::class, 'queue');

        $syncHistory = app(ScheduleExecutor::class)->execute($sync, ScheduleTriggerType::Scheduled);
        Bus::assertDispatchedSync(GenerateSitemapJob::class);

        Bus::fake([GenerateSitemapJob::class]);
        $queueHistory = app(ScheduleExecutor::class)->execute($queue, ScheduleTriggerType::Scheduled);
        Bus::assertNotDispatchedSync(GenerateSitemapJob::class);
        Bus::assertDispatched(GenerateSitemapJob::class, fn (GenerateSitemapJob $job) => $job->queue === GenerateSitemapJob::QUEUE);

        $this->assertSame(ScheduleResultStatus::Success, $syncHistory->status);
        $this->assertSame(ScheduleResultStatus::Success, $queueHistory->status);
        $this->assertStringContainsString('queued', (string) $queueHistory->output);
    }

    /**
     * 허용목록 밖 Job 클래스는 실행하지 않고 실패로 남긴다. 확장은 필터로 자기 Job 을 더할 수 있다.
     *
     * @scenario schedule_type=job, outcome=rejected, outcome=success
     *
     * @effects job_class_outside_allowlist_is_blocked, job_allowlist_extends_via_filter
     *
     * @return void 반환값 없음
     */
    public function test_job_class_outside_allowlist_is_blocked_and_filter_extends_it(): void
    {
        Config::set('schedule_security.job.allowed_classes', []);
        Bus::fake([GenerateSitemapJob::class]);

        $schedule = $this->jobSchedule(GenerateSitemapJob::class, 'sync');

        $blocked = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);
        $this->assertSame(ScheduleResultStatus::Failed, $blocked->status);
        Bus::assertNotDispatched(GenerateSitemapJob::class);

        $filter = static fn (array $classes): array => [...$classes, GenerateSitemapJob::class];
        HookManager::addFilter('core.schedule.filter_allowed_job_classes', $filter);

        try {
            $allowed = app(ScheduleExecutor::class)->execute($schedule->fresh(), ScheduleTriggerType::Scheduled);
            $this->assertSame(ScheduleResultStatus::Success, $allowed->status);
            Bus::assertDispatchedSync(GenerateSitemapJob::class);
        } finally {
            HookManager::removeFilter('core.schedule.filter_allowed_job_classes', $filter);
        }
    }

    /**
     * 큐 워커가 죽은 실행은 running 이력을 failed 로 닫고, 행을 실패로 바꾸고, 잠금을 푼다.
     *
     * @scenario trigger=queue_worker, outcome=failure
     *
     * @effects abandoned_queue_run_is_closed_as_failed
     *
     * @return void 반환값 없음
     */
    public function test_mark_abandoned_closes_running_history(): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/ok', ['without_overlapping' => 10]);
        $running = ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now()->subMinutes(3),
            'status' => ScheduleResultStatus::Running,
            'trigger_type' => ScheduleTriggerType::Scheduled,
        ]);
        $this->assertTrue(Cache::lock(ScheduleExecutor::LOCK_PREFIX.$schedule->id, 600)->get());

        $fired = 0;
        $this->listen('core.schedule.run_failed', function () use (&$fired) {
            $fired++;
        });

        $history = app(ScheduleExecutor::class)->markAbandoned($schedule->id, ScheduleTriggerType::Scheduled, null, 'Job timed out');

        $this->assertSame($running->id, $history->id);
        $this->assertSame(ScheduleResultStatus::Failed, $running->fresh()->status);
        $this->assertSame('Job timed out', $running->fresh()->error_output);
        $this->assertSame(ScheduleResultStatus::Failed, $schedule->fresh()->last_result);
        $this->assertSame(1, $fired);
        $this->assertTrue(Cache::lock(ScheduleExecutor::LOCK_PREFIX.$schedule->id, 600)->get(), '죽은 실행의 잠금이 남아 있다');
    }

    /**
     * 활동 로그는 수동 실행만 남긴다(30초 작업의 로그 폭증 방지).
     *
     * @scenario trigger=manual, trigger=scheduled
     *
     * @effects activity_log_records_manual_runs_only
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_records_manual_runs_only(): void
    {
        $schedule = $this->urlSchedule('http://192.168.0.10/ok');

        $scheduled = new ScheduleHistory(['trigger_type' => ScheduleTriggerType::Scheduled]);
        $manual = new ScheduleHistory(['trigger_type' => ScheduleTriggerType::Manual]);

        $listener = Mockery::mock(CoreActivityLogListener::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $listener->shouldReceive('logActivity')->once()->with('schedule.run', Mockery::type('array'));

        $listener->handleScheduleAfterRun($schedule, $scheduled);
        $listener->handleScheduleAfterRun($schedule, $manual);

        $this->addToAssertionCount(1);
    }

    /**
     * URL 호출 스케줄을 만듭니다.
     *
     * @param  string  $url  호출 URL
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @param  ExtensionOwnerType|null  $ownerType  소유 타입
     * @param  string|null  $ownerIdentifier  소유 식별자
     * @return Schedule 저장 후 다시 읽은 스케줄
     */
    private function urlSchedule(string $url, array $attributes = [], ?ExtensionOwnerType $ownerType = null, ?string $ownerIdentifier = null): Schedule
    {
        $factory = Schedule::factory();

        if ($ownerType !== null) {
            $factory = $factory->ownedBy($ownerType, (string) $ownerIdentifier);
        }

        return $factory->create(['type' => 'url', 'command' => $url, ...$attributes])->fresh();
    }

    /**
     * 웹 요청(관리자 「즉시 실행」)에서도 확장 소유 artisan 스케줄이 실행된다 — 확장 명령이
     * 콘솔에서만 등록되고 행에 `command_class` 가 없어도 활성 확장의 명령 디렉터리에서 찾아 등록한다.
     *
     * 수정 전에는 이커머스 스케줄이 `The command "…" does not exist.` 로 실패했다(2026-10-17 라이브 실측).
     *
     * @scenario trigger=manual, schedule_type=artisan, owner=custom, outcome=success
     *
     * @effects manual_run_registers_extension_command_in_web
     *
     * @return void 반환값 없음
     */
    public function test_manual_run_in_web_request_registers_undeclared_extension_command(): void
    {
        require_once __DIR__.'/../../Fixtures/ExtensionWebCommand/src/Providers/FakeWebCommandServiceProvider.php';
        require_once __DIR__.'/../../Fixtures/ExtensionWebCommand/src/Console/Commands/FakeWebScheduleCommand.php';
        $this->app->register(FakeWebCommandServiceProvider::class);

        $schedule = Schedule::factory()->create([
            'type' => 'artisan',
            'command' => 'g7-testing-web-command --scope=all',
            'command_class' => null,
            'history_mode' => 'all',
            'without_overlapping' => 0,
        ])->fresh();

        $this->assertArrayNotHasKey('g7-testing-web-command', Artisan::all(), '전제: 콘솔에 등록되지 않은 확장 명령');

        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);

        try {
            $history = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Manual, null);
        } finally {
            $console->setValue($this->app, true);
        }

        $this->assertSame(ScheduleResultStatus::Success, $history->status, (string) $history->output);
        $this->assertStringContainsString('g7-testing-web-command ran', (string) $history->output);
    }

    /**
     * 실행 직전 검사에 막히면 결과 창·이력의 오류 문구가 거부 사유를 그대로 말한다 —
     * 금지 명령만 「보안상」이고, 허용 목록에 없음·형식 오류·옵션은 저장 때와 같은 사유별 문구다.
     *
     * 수정 전에는 사유와 상관없이 「이 Artisan 명령은 보안상 스케줄로 실행할 수 없습니다」 하나라
     * 단순히 허용 목록에 없는 확장 명령도 공격처럼 보였다(2026-10-17 운영 점검).
     *
     * @scenario trigger=manual, schedule_type=artisan, owner=custom, outcome=rejected
     *
     * @effects run_rejection_message_follows_reason
     *
     * @param  string  $command  막혀야 하는 artisan 명령
     * @param  string  $messageKey  기대하는 거부 문구 다국어 키
     * @return void 반환값 없음
     */
    #[DataProvider('rejectedArtisanCommands')]
    public function test_run_rejection_message_follows_the_reason(string $command, string $messageKey): void
    {
        $schedule = Schedule::factory()->create([
            'type' => 'artisan',
            'command' => $command,
            'history_mode' => 'all',
            'without_overlapping' => 0,
        ])->fresh();

        $history = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Manual, null);

        $this->assertSame(ScheduleResultStatus::Failed, $history->status);
        $this->assertSame(__($messageKey), (string) $history->error_output);
    }

    /**
     * 거부 사유별 대표 명령과 기대 문구 키.
     *
     * @return array<string, array{0: string, 1: string}> 명령, 문구 키
     */
    public static function rejectedArtisanCommands(): array
    {
        return [
            'denied' => ['db:wipe', 'validation.schedule_command.artisan_denied'],
            'not_allowlisted' => ['route:list', 'validation.schedule_command.artisan_not_allowlisted'],
            'malformed' => ['"cache:clear"', 'validation.schedule_command.artisan_malformed'],
            'option_denied' => ['cache:clear --g7-not-a-real-option', 'validation.schedule_command.artisan_option_denied'],
        ];
    }

    /**
     * job 유형 스케줄을 만듭니다.
     *
     * @param  string  $class  Job 클래스
     * @param  string  $dispatchMode  실행 방식 (sync/queue)
     * @return Schedule 저장 후 다시 읽은 스케줄
     */
    private function jobSchedule(string $class, string $dispatchMode): Schedule
    {
        return Schedule::factory()->create([
            'type' => 'job',
            'command' => $class,
            'dispatch_mode' => $dispatchMode,
        ])->fresh();
    }

    /**
     * 테스트 동안만 훅을 붙입니다.
     *
     * @param  string  $hook  훅 이름
     * @param  callable  $callback  콜백
     * @return void 반환값 없음
     */
    private function listen(string $hook, callable $callback): void
    {
        HookManager::addAction($hook, $callback);
        $this->hooks[] = [$hook, $callback];
    }
}
