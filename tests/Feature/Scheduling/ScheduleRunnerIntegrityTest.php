<?php

namespace Tests\Feature\Scheduling;

use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Execution\CommandRunnerResolver;
use App\Models\ScheduleHistory;
use App\Scheduling\ArtisanCommandExtractor;
use App\Scheduling\ScheduleRunner;
use App\Scheduling\ScheduleRunState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 러너가 「실행했다」고 보고한 것이 실제로 실행됐는지, 그리고 그 사실이 남는지를 잰다.
 *
 * 이 파일이 잠그는 축은 전부 **오류를 내지 않는 결함**이다 — 작업이 돌지 않았는데
 * 요약은 정상이거나, 이력이 남지 않아 지난 실패를 되짚을 수 없거나, 중복 방지 표시가
 * 무력해진 상태다. 실행 결과만 보는 테스트로는 어느 것도 red 가 되지 않는다.
 *
 * @scenario caller=panel, entry_point=web, event_kind=callback_event, overlap=none, catch_up=on_time
 */
class ScheduleRunnerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** 러너 상태 파일 (케이스마다 새로 만든다) */
    private string $statePath;

    /** 실행 기록 — 콜백이 불릴 때마다 이름이 쌓인다 */
    private array $executed = [];

    /**
     * 테스트 준비 — 상태 파일을 격리하고 스케줄러를 케이스 전용 인스턴스로 바꾼다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = storage_path('framework/testing/schedule-integrity-'.bin2hex(random_bytes(6)).'.json');
        $this->executed = [];

        File::ensureDirectoryExists(dirname($this->statePath));

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
     * 중복 방지 표시가 붙은 콜백 예약이 **실제로 실행**된다.
     *
     * 러너는 자물쇠를 잡기 위해 `shouldSkipDueToOverlapping()` 을 부르는데, 그 호출은
     * 판정이 아니라 **생성**이다. 프레임워크의 `Event::run()` 첫 줄이 같은 호출을 다시
     * 하므로, 재검사를 끄지 않고 부르면 두 번째 생성이 실패해 run() 이 아무것도 하지
     * 않고 돌아온다 — 예외가 없어 러너는 그것을 '실행됨' 으로 센다.
     *
     * 그래서 이 케이스는 요약의 `executed` 만 보지 않고 **콜백이 실제로 불렸는지**를
     * 함께 단언한다. 둘 중 하나만 보면 이 결함이 통과한다.
     *
     * @scenario event_kind=callback_event, overlap=none, catch_up=on_time
     *
     * @effects overlapping_callback_actually_executes
     *
     * @return void 반환값 없음
     */
    public function test_callback_with_without_overlapping_actually_runs(): void
    {
        app(Schedule::class)
            ->call(function (): void {
                $this->executed[] = 'sitemap';
            })
            ->name('sitemap')
            ->cron('0 4 * * *')
            ->withoutOverlapping(30);

        $summary = $this->runner()->run(CarbonImmutable::parse('2026-08-31 04:00:00'));

        $this->assertSame(['sitemap'], $this->executed, '중복 방지 표시가 붙은 콜백이 실행되지 않았다.');
        $this->assertSame(1, $summary->executed);
    }

    /**
     * 그 예약의 자물쇠가 실행 뒤에 **풀린다**.
     *
     * 풀리지 않으면 다음 호출부터 만료(위 케이스는 30분)까지 'overlapping' 으로
     * 건너뛰어, 그 사이 도래한 실행이 통째로 사라진다.
     *
     * @scenario event_kind=callback_event, overlap=none, catch_up=on_time
     *
     * @effects overlapping_lock_is_released_after_run
     *
     * @return void 반환값 없음
     */
    public function test_overlapping_lock_is_released_after_the_run(): void
    {
        $event = app(Schedule::class)
            ->call(function (): void {
                $this->executed[] = 'sitemap';
            })
            ->name('sitemap')
            ->cron('0 4 * * *')
            ->withoutOverlapping(30);

        $this->runner()->run(CarbonImmutable::parse('2026-08-31 04:00:00'));

        $this->assertFalse(
            $event->mutex->exists($event),
            '실행이 끝났는데 중복 방지 자물쇠가 남아 있다 — 이후 호출이 전부 건너뛰어진다.',
        );
    }

    /**
     * 주기를 해석할 수 없는 예약이 요약에 **드러난다**.
     *
     * 조용히 빼면 그 예약은 due 에도 skipped 에도 없어, 확장이 잘못된 표현식을 등록한
     * 사이트의 운영자는 그 작업이 안 돈다는 사실 자체를 화면에서 볼 수 없다.
     *
     * @scenario event_kind=callback_event
     *
     * @effects unparsable_expression_is_reported_not_dropped
     *
     * @return void 반환값 없음
     */
    public function test_unparsable_expression_is_reported(): void
    {
        $event = app(Schedule::class)
            ->call(fn () => null)
            ->name('broken');
        $event->expression = 'not a cron expression';

        $summary = $this->runner()->run(CarbonImmutable::parse('2026-08-31 09:00:00'));

        $this->assertSame(1, $summary->failed, '해석 불가한 예약이 실패로 집계되지 않았다.');

        $reasons = array_column($summary->events, 'reason');
        $this->assertContains('expression_unparsable', $reasons);
    }

    /**
     * 실행한 예약마다 이력이 **한 건씩** 남는다.
     *
     * 상태 파일의 '직전 1회' 요약은 다음 호출에 덮이므로, 그것만으로는 「어젯밤 그
     * 작업이 실패했나」를 나중에 볼 수 없다.
     *
     * @scenario event_kind=callback_event, overlap=none
     *
     * @effects each_run_leaves_one_history_row
     *
     * @return void 반환값 없음
     */
    public function test_each_executed_event_leaves_a_history_row(): void
    {
        app(Schedule::class)->call(fn () => null)->name('gc-a')->cron('0 4 * * *');
        app(Schedule::class)->call(fn () => null)->name('gc-b')->cron('30 4 * * *');

        $this->runner()->run(CarbonImmutable::parse('2026-08-31 09:00:00'));

        $rows = ScheduleHistory::where('source', ScheduleRunner::HISTORY_SOURCE)->get();

        $this->assertCount(2, $rows, '실행한 예약 수만큼 이력이 남지 않았다.');
        $this->assertEqualsCanonicalizing(['gc-a', 'gc-b'], $rows->pluck('event_name')->all());

        foreach ($rows as $row) {
            $this->assertNull($row->schedule_id, '코드 예약 이력에 스케줄 ID 가 실렸다.');
            $this->assertSame(ScheduleResultStatus::Success, $row->status);
            $this->assertSame(ScheduleTriggerType::Scheduled, $row->trigger_type);
            $this->assertNotNull($row->event_key);
            $this->assertNotNull($row->event_expression);
        }
    }

    /**
     * 실패한 예약의 이력에 사유가 실린다.
     *
     * @scenario event_kind=callback_event, catch_up=on_time
     *
     * @effects failed_run_history_carries_the_reason
     *
     * @return void 반환값 없음
     */
    public function test_failed_run_records_the_reason_in_history(): void
    {
        app(Schedule::class)
            ->call(function (): void {
                throw new \RuntimeException('의도된 실패');
            })
            ->name('breaks')
            ->cron('0 4 * * *');

        $this->runner()->run(CarbonImmutable::parse('2026-08-31 04:00:00'));

        $row = ScheduleHistory::where('source', ScheduleRunner::HISTORY_SOURCE)->firstOrFail();

        $this->assertSame(ScheduleResultStatus::Failed, $row->status);
        $this->assertStringContainsString('의도된 실패', (string) $row->error_output);
    }

    /**
     * 건너뛴 예약은 이력을 남기지 않는다 — 실행되지 않았다는 사실은 요약이 나른다.
     *
     * @scenario overlap=filtered, catch_up=on_time
     *
     * @effects skipped_event_leaves_no_history_row
     *
     * @return void 반환값 없음
     */
    public function test_skipped_event_does_not_leave_history(): void
    {
        app(Schedule::class)
            ->call(fn () => null)
            ->name('filtered-out')
            ->cron('0 4 * * *')
            ->when(fn () => false);

        $summary = $this->runner()->run(CarbonImmutable::parse('2026-08-31 04:00:00'));

        $this->assertSame(1, $summary->skipped);
        $this->assertSame(0, ScheduleHistory::where('source', ScheduleRunner::HISTORY_SOURCE)->count());
    }

    /**
     * 다중 서버 잠금의 열쇠가 **due 시각**이다 (호출 시각이 아니다).
     *
     * 호출 시각을 쓰면 소급 창 덕에 09:00 과 09:01 에 부른 두 서버가 같은 04:00 예약을
     * 서로 다른 열쇠로 잠가 **둘 다** 실행한다.
     *
     * 두 서버를 재현하려면 `Schedule` **인스턴스를 각각** 만들어야 한다 —
     * `Schedule::serverShouldRun()` 은 판정을 인스턴스 안에 메모이즈하므로(`mutexCache`),
     * 한 인스턴스를 공유하면 두 번째 호출이 캐시된 값을 돌려받아 잠금 판정 자체가
     * 일어나지 않는다. 실제 배치에서는 서버마다 프로세스가 달라 인스턴스도 다르다.
     * 상태 파일도 서버마다 따로 둔다 — 스토리지를 공유하지 않는 배치가 이 축의 대상이다.
     *
     * @scenario overlap=other_server, catch_up=missed_window
     *
     * @effects one_server_lock_keys_on_due_time_not_call_time
     *
     * @return void 반환값 없음
     */
    public function test_one_server_lock_uses_the_due_time(): void
    {
        $runOnServer = function (string $at): void {
            $schedule = new Schedule('UTC');
            $this->app->instance(Schedule::class, $schedule);

            $schedule->call(function (): void {
                $this->executed[] = 'shared';
            })->name('shared')->cron('0 4 * * *')->onOneServer();

            $runner = new ScheduleRunner(
                new ScheduleRunState(
                    storage_path('framework/testing/schedule-integrity-'.bin2hex(random_bytes(6)).'.json'),
                ),
                new ArtisanCommandExtractor,
                app(CommandRunnerResolver::class),
                app(ScheduleHistoryRepositoryInterface::class),
            );

            $runner->run(CarbonImmutable::parse($at));
        };

        // 같은 04:00 due 를 서버 A 는 09:00 에, 서버 B 는 09:01 에 소급으로 잡는다.
        $runOnServer('2026-08-31 09:00:00');
        $runOnServer('2026-08-31 09:01:00');

        $this->assertSame(['shared'], $this->executed, 'onOneServer 예약이 두 서버에서 각각 실행됐다.');
    }

    /**
     * 케이스 전용 러너.
     *
     * @return ScheduleRunner 러너
     */
    private function runner(): ScheduleRunner
    {
        return new ScheduleRunner(
            new ScheduleRunState($this->statePath),
            new ArtisanCommandExtractor,
            app(CommandRunnerResolver::class),
            app(ScheduleHistoryRepositoryInterface::class),
        );
    }
}
