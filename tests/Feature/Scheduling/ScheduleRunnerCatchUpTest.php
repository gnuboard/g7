<?php

namespace Tests\Feature\Scheduling;

use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Execution\CommandRunnerResolver;
use App\Scheduling\ArtisanCommandExtractor;
use App\Scheduling\ScheduleRunner;
use App\Scheduling\ScheduleRunState;
use App\Scheduling\ScheduleRunSummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 실행 창 소급 판정 (D17) 과 그 경계의 회귀 테스트.
 *
 * 이 축이 없으면 웹 크론은 조용히 아무것도 하지 않는다 — 등록된 예약의 다수가
 * 고정 시각(`dailyAt`)이고, 호출 주기가 성긴 환경에서는 그 분을 놓쳐 GC 가 영영
 * 실행되지 않기 때문이다. 오류도 로그도 남지 않는 형태라 실측으로만 드러난다.
 *
 * 실제 커맨드를 돌리지 않기 위해 스케줄러를 콜백 이벤트로 갈아 끼운다 —
 * 소급 판정·중복 억제·상한 이월은 계통과 무관한 러너 자신의 로직이다.
 *
 * @scenario caller=panel, catch_up=on_time, catch_up=missed_window, overlap=none
 */
class ScheduleRunnerCatchUpTest extends TestCase
{
    // 러너가 실행 이력을 DB 에 남기므로 이 클래스도 격리가 필요하다 — 없으면 커밋된
    // 이력 행이 남아 뒤따르는 테스트의 모집단을 오염시킨다(실제로 그랬다).
    use RefreshDatabase;

    /** 러너 상태 파일 (케이스마다 새로 만든다) */
    private string $statePath;

    /** 실행 기록 — 콜백이 불릴 때마다 이름이 쌓인다 */
    private array $executed = [];

    /**
     * 러너에 주입하는 가짜 경과 시계(초).
     *
     * 실행 상한은 **경과 시간**으로 재므로 논리 시각(`run($now)`)을 옮겨서는 재현할 수
     * 없다. 실제로 잠들면 케이스마다 초 단위 대기가 붙으므로 시계를 주입한다.
     */
    private float $elapsed = 0.0;

    /**
     * 테스트 준비 — 상태 파일을 케이스 전용 임시 경로로 격리한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = storage_path('framework/testing/schedule-run-state-'.bin2hex(random_bytes(6)).'.json');
        $this->executed = [];
        $this->elapsed = 0.0;

        File::ensureDirectoryExists(dirname($this->statePath));

        // 콘솔 커널을 **먼저** 부팅해 routes/console.php 가 코어 예약을 등록하게 한 뒤,
        // 스케줄러를 케이스 전용 빈 인스턴스로 갈아 끼운다. 그러지 않으면 러너 안의
        // 부팅이 코어 예약을 이 인스턴스에 실어, 요약 수치가 저장소의 예약 목록이
        // 바뀔 때마다 흔들린다(테스트가 무엇을 재는지 알 수 없게 된다).
        //
        // 시간대는 UTC 로 고정한다 — 사이트 설정 시간대(Asia/Seoul)를 그대로 쓰면
        // 케이스의 시각 계산이 서머타임·오프셋에 얽혀 의도를 읽을 수 없다. 시간대
        // 해석 자체는 CoreScheduleRegistrationTest 가 따로 고정한다.
        app(ConsoleKernelContract::class)->bootstrap();
        $this->app->instance(Schedule::class, new Schedule('UTC'));
    }

    /**
     * 케이스 전용 상태 파일을 지운 뒤 부모 정리를 돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (is_file($this->statePath)) {
            @unlink($this->statePath);
        }

        parent::tearDown();
    }

    /**
     * 고정 시각을 지나친 성긴 호출에서 그 사이 due 였던 작업이 소급 실행된다.
     *
     * @scenario catch_up=missed_window, event_kind=callback_event, runtime_budget=within
     *
     * @effects missed_fixed_time_task_runs_on_next_call
     *
     * @return void 반환값 없음
     */
    public function test_missed_fixed_time_task_runs_on_a_later_call(): void
    {
        $this->registerCallbackSchedule('daily-gc', '0 4 * * *');

        $state = $this->state();
        // 03:50 에 한 번 점검했고, 다음 호출은 09:00 이다 — 04:00 을 지나쳤다.
        $state->recordRun($this->summaryAt('2026-08-31 03:50:00'), advanceCheckpoint: true);

        $this->runner($state)->run(CarbonImmutable::parse('2026-08-31 09:00:00'));

        $this->assertSame(['daily-gc'], $this->executed);
    }

    /**
     * 같은 작업이 이어지는 호출에서 다시 실행되지 않는다 (중복 0).
     *
     * @scenario catch_up=missed_window, event_kind=callback_event
     *
     * @effects catch_up_does_not_duplicate
     *
     * @return void 반환값 없음
     */
    public function test_catch_up_does_not_run_the_same_occurrence_twice(): void
    {
        $this->registerCallbackSchedule('daily-gc', '0 4 * * *');

        $state = $this->state();
        $state->recordRun($this->summaryAt('2026-08-31 03:50:00'), advanceCheckpoint: true);

        $runner = $this->runner($state);
        $runner->run(CarbonImmutable::parse('2026-08-31 09:00:00'));
        $runner->run(CarbonImmutable::parse('2026-08-31 09:05:00'));

        $this->assertSame(['daily-gc'], $this->executed);
    }

    /**
     * 소급 창 안에서 여러 번 due 였던 주기 작업도 **1회만** 실행된다.
     *
     * 정리 배치는 멱등이라 횟수 보전이 무의미하고, 반복은 실행 상한만 소모한다.
     *
     * @scenario catch_up=long_gap, event_kind=callback_event
     *
     * @effects periodic_task_runs_once_per_call
     *
     * @return void 반환값 없음
     */
    public function test_periodic_task_runs_once_even_after_a_long_gap(): void
    {
        $this->registerCallbackSchedule('half-hourly', '*/30 * * * *');

        $state = $this->state();
        $state->recordRun($this->summaryAt('2026-08-31 00:00:00'), advanceCheckpoint: true);

        $this->runner($state)->run(CarbonImmutable::parse('2026-08-31 06:00:00'));

        $this->assertSame(['half-hourly'], $this->executed);
    }

    /**
     * 최소 호출 간격에 걸려 건너뛴 구간의 작업이 유실되지 않는다 (D17 계약).
     *
     * 스킵된 호출은 러너를 부르지 않으므로 점검 시각이 전진하지 않고, 다음 유효
     * 호출의 소급 창이 그 사이 시각을 그대로 덮는다.
     *
     * @scenario catch_up=missed_window, min_interval=within
     *
     * @effects throttled_calls_do_not_lose_work
     *
     * @return void 반환값 없음
     */
    public function test_work_survives_a_throttled_call_gap(): void
    {
        $this->registerCallbackSchedule('daily-gc', '0 4 * * *');

        $state = $this->state();
        $state->recordRun($this->summaryAt('2026-08-31 03:58:00'), advanceCheckpoint: true);

        // 04:01·04:02 호출은 최소 간격에 걸려 러너를 부르지 않았다고 가정한다.
        // 04:05 의 유효 호출이 03:58~04:05 창을 덮어 04:00 작업을 실행한다.
        $this->runner($state)->run(CarbonImmutable::parse('2026-08-31 04:05:00'));

        $this->assertSame(['daily-gc'], $this->executed);
    }

    /**
     * 소급 창의 상한을 넘어선 과거는 소급하지 않는다 (첫 호출 폭주 방지).
     *
     * @scenario catch_up=beyond_cap, event_kind=callback_event
     *
     * @effects catch_up_is_bounded_by_the_window_cap
     *
     * @return void 반환값 없음
     */
    public function test_catch_up_is_bounded_by_the_window_cap(): void
    {
        config(['core.cron.catch_up_window_minutes' => 60]);

        $this->registerCallbackSchedule('daily-gc', '0 4 * * *');

        $state = $this->state();
        // 점검 기록은 5일 전이지만 창 상한이 1시간이므로 04:00 은 창 밖이다.
        $state->recordRun($this->summaryAt('2026-08-26 03:50:00'), advanceCheckpoint: true);

        $this->runner($state)->run(CarbonImmutable::parse('2026-08-31 09:00:00'));

        $this->assertSame([], $this->executed);
    }

    /**
     * 조건부 등록(`when`)이 거짓이면 소급 실행에서도 돌지 않는다 (R16).
     *
     * 확장의 `enabled_config` 가 OFF 인 상태가 이 경로로 무시되면, 운영자가 끈
     * 기능이 웹 크론에서만 되살아난다.
     *
     * @scenario catch_up=missed_window, overlap=filtered
     *
     * @effects conditional_registration_is_respected_on_catch_up
     *
     * @return void 반환값 없음
     */
    public function test_conditional_registration_is_respected(): void
    {
        $this->registerCallbackSchedule('gated', '0 4 * * *')->when(fn () => false);

        $state = $this->state();
        $state->recordRun($this->summaryAt('2026-08-31 03:50:00'), advanceCheckpoint: true);

        $summary = $this->runner($state)->run(CarbonImmutable::parse('2026-08-31 09:00:00'));

        $this->assertSame([], $this->executed);
        $this->assertSame(1, $summary->due);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(0, $summary->executed);
    }

    /**
     * 실행 상한에 걸리면 남은 작업은 다음 호출로 넘어가고, 점검 시각은 전진하지 않는다.
     *
     * 점검 시각을 전진시키면 그 잔여 작업이 창 밖으로 밀려나 영영 실행되지 않는다.
     *
     * @scenario catch_up=missed_window, runtime_budget=exceeded
     *
     * @effects runtime_limit_defers_remaining_work
     *
     * @return void 반환값 없음
     */
    public function test_runtime_limit_defers_remaining_work(): void
    {
        config(['core.cron.max_runtime_seconds' => 1]);

        // 첫 이벤트가 상한을 소진하고, 두 번째는 이번 호출에서 실행되지 않는다.
        $this->registerCallbackSchedule('slow', '0 4 * * *', function () {
            $this->executed[] = 'slow';
            $this->elapsed += 3.0;
        });
        $this->registerCallbackSchedule('deferred', '0 4 * * *');

        $state = $this->state();
        $state->recordRun($this->summaryAt('2026-08-31 03:50:00'), advanceCheckpoint: true);

        $summary = $this->runner($state)->run(CarbonImmutable::parse('2026-08-31 09:00:00'));

        $this->assertSame(['slow'], $this->executed);
        $this->assertTrue($summary->truncated);

        // 점검 시각이 전진하지 않았으므로 다음 호출의 창이 같은 구간을 덮는다.
        $state->refresh();
        $this->assertNotNull($state->lastCheckedAt());
        $this->assertSame(
            '2026-08-31T03:50:00',
            $state->lastCheckedAt()->format('Y-m-d\TH:i:s'),
        );
    }

    /**
     * 상한으로 미뤄진 작업이 다음 호출에서 실행된다 (이월이 실제로 성립한다).
     *
     * 앞 케이스는 "미뤄졌다" 만 재고, 이 케이스가 "그래서 결국 돈다" 를 잰다 —
     * 둘을 함께 재지 않으면 영원히 미루는 구현도 초록이다.
     *
     * @scenario catch_up=missed_window, runtime_budget=exceeded, runtime_budget=within
     *
     * @effects deferred_work_runs_on_the_next_call
     *
     * @return void 반환값 없음
     */
    public function test_deferred_work_runs_on_the_next_call(): void
    {
        config(['core.cron.max_runtime_seconds' => 1]);

        $this->registerCallbackSchedule('slow', '0 4 * * *', function () {
            $this->executed[] = 'slow';
            $this->elapsed += 3.0;
        });
        $this->registerCallbackSchedule('deferred', '0 4 * * *');

        $state = $this->state();
        $state->recordRun($this->summaryAt('2026-08-31 03:50:00'), advanceCheckpoint: true);

        $this->runner($state)->run(CarbonImmutable::parse('2026-08-31 09:00:00'));

        // 두 번째 호출은 상한을 넉넉히 준다.
        config(['core.cron.max_runtime_seconds' => 50]);
        $this->elapsed = 0.0;
        $state->refresh();
        $this->runner($state)->run(CarbonImmutable::parse('2026-08-31 09:10:00'));

        $this->assertSame(['slow', 'deferred'], $this->executed);
    }

    /**
     * 실패한 작업은 요약에 실패로 집계되고 다른 작업의 실행을 막지 않는다.
     *
     * @scenario catch_up=missed_window, event_kind=callback_event, runtime_budget=within
     *
     * @effects failed_task_does_not_stop_the_run
     *
     * @return void 반환값 없음
     */
    public function test_failed_task_is_counted_and_does_not_stop_the_run(): void
    {
        $this->registerCallbackSchedule('boom', '0 4 * * *', function () {
            $this->executed[] = 'boom';

            throw new \RuntimeException('실패 주입');
        });
        $this->registerCallbackSchedule('after', '0 4 * * *');

        $state = $this->state();
        $state->recordRun($this->summaryAt('2026-08-31 03:50:00'), advanceCheckpoint: true);

        $summary = $this->runner($state)->run(CarbonImmutable::parse('2026-08-31 09:00:00'));

        $this->assertSame(['boom', 'after'], $this->executed);
        $this->assertSame(1, $summary->failed);
        $this->assertSame(1, $summary->executed);
    }

    /**
     * 콜백 이벤트를 스케줄러에 등록합니다 (실제 커맨드를 돌리지 않기 위함).
     *
     * @param  string  $name  식별용 이름 (실행 기록에 쌓인다)
     * @param  string  $expression  cron 표현식
     * @param  callable|null  $callback  실행 본문 (기본: 이름만 기록)
     * @return CallbackEvent 등록된 이벤트
     */
    private function registerCallbackSchedule(string $name, string $expression, ?callable $callback = null)
    {
        $callback ??= function () use ($name) {
            $this->executed[] = $name;
        };

        return app(Schedule::class)
            ->call($callback)
            ->name($name)
            ->cron($expression);
    }

    /**
     * 케이스 전용 상태 저장소.
     *
     * @return ScheduleRunState 상태 저장소
     */
    private function state(): ScheduleRunState
    {
        return new ScheduleRunState($this->statePath);
    }

    /**
     * 주어진 상태 저장소를 쓰는 러너.
     *
     * @param  ScheduleRunState  $state  상태 저장소
     * @return ScheduleRunner 러너
     */
    private function runner(ScheduleRunState $state): ScheduleRunner
    {
        return new ScheduleRunner(
            $state,
            new ArtisanCommandExtractor,
            app(CommandRunnerResolver::class),
            app(ScheduleHistoryRepositoryInterface::class),
            fn (): float => $this->elapsed,
        );
    }

    /**
     * 점검 시각만 심기 위한 최소 요약.
     *
     * @param  string  $at  점검 시각
     * @return ScheduleRunSummary 요약
     */
    private function summaryAt(string $at)
    {
        $moment = CarbonImmutable::parse($at);

        return new ScheduleRunSummary(
            startedAt: $moment,
            finishedAt: $moment,
            due: 0,
            executed: 0,
            failed: 0,
            skipped: 0,
            truncated: false,
        );
    }
}
