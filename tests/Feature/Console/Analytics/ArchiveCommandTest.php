<?php

namespace Tests\Feature\Console\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\VisitorType;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 집계 커맨드 — 스케줄러가 부르는 입구.
 *
 * 이 커맨드가 예외를 밖으로 던지면 스케줄러가 멈추고 그날 이후 통계가 전부 멈춘다.
 * 실패를 종료 코드로만 알리는지, 어떤 날을 확정하는지를 잠근다.
 */
class ArchiveCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비 — 표준시를 UTC 로 고정.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'UTC']);
    }

    /**
     * 주어진 일자에 방문 하나를 만듭니다.
     *
     * @param  string  $date  방문 일자 (Y-m-d)
     * @return AnalyticsVisit 만들어진 방문
     */
    private function visitOn(string $date): AnalyticsVisit
    {
        $moment = Carbon::parse($date.' 02:00:00', 'UTC');

        $name = AnalyticsActionName::query()->firstOrCreate(
            ['type' => 'url', 'name_hash' => md5('url|example.test/')],
            ['name' => 'example.test/'],
        );

        $visit = AnalyticsVisit::factory()->create([
            'visit_date' => $date,
            'first_action_at' => $moment,
            'last_action_at' => $moment,
            'visitor_type' => VisitorType::Guest,
            'actions_count' => 1,
            'pageviews_count' => 1,
            'is_bounce' => true,
            'entry_action_name_id' => $name->id,
            'exit_action_name_id' => $name->id,
        ]);

        AnalyticsAction::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'action_type' => ActionType::Pageview,
            'action_name_id' => $name->id,
            'occurred_at' => $moment,
            'time_spent_seconds' => 0,
        ]);

        return $visit;
    }

    /**
     * 매시 실행이 오늘치를 확정하지 않은 채 집계하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=hour, trigger=scheduled, final=false, data=all_types
     *
     * @effects daily_rows_are_written_per_visitor_type
     */
    public function test_hour_period_archives_today_without_finalizing(): void
    {
        $today = AnalyticsClock::today();
        $this->visitOn($today);

        $this->artisan('analytics:archive', ['--period' => 'hour', '--scheduled' => true])->assertSuccessful();

        $row = AnalyticsStatsDaily::query()->where('date', $today)->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_final, '오늘 숫자는 계속 변하므로 확정하지 않는다');

        $run = AnalyticsArchiveRun::query()->latest('id')->first();
        $this->assertSame(ArchiveRunType::Hour->value, $this->valueOf($run->run_type));
        $this->assertSame('scheduled', $run->trigger);
    }

    /**
     * `--scheduled` 없이 돌린 실행은 수동으로 기록되는지 확인합니다 — 손으로 돌린 실행이 스케줄러
     * 생존 판정을 속이지 않게.
     *
     * @return void 없음
     *
     * @scenario period=hour, trigger=manual, final=false, data=all_types
     *
     * @effects cli_without_scheduled_flag_is_recorded_as_manual
     */
    public function test_run_without_scheduled_flag_is_recorded_as_manual(): void
    {
        $this->visitOn(AnalyticsClock::today());

        $this->artisan('analytics:archive', ['--period' => 'hour'])->assertSuccessful();
        $this->artisan('analytics:archive', ['--period' => 'day'])->assertSuccessful();

        $this->assertSame(
            ['manual'],
            AnalyticsArchiveRun::query()->pluck('trigger')->unique()->values()->all(),
        );
    }

    /**
     * 매일 실행이 어제 말고도 확정되지 않은 날(보존기간 안)을 따라잡아 확정하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, trigger=scheduled, final=true, data=all_types
     *
     * @effects missed_days_are_finalized_by_catch_up
     */
    public function test_day_period_catches_up_missed_days(): void
    {
        $today = Carbon::parse(AnalyticsClock::today());
        $missed = [
            $today->copy()->subDays(3)->toDateString(),
            $today->copy()->subDays(4)->toDateString(),
        ];

        foreach ($missed as $date) {
            $this->visitOn($date);
        }

        // 보존기간(기본 90일) 밖의 날은 원장이 일부 지워졌을 수 있어 건드리지 않는다.
        $beyond = $today->copy()->subDays(120)->toDateString();
        $this->visitOn($beyond);

        $this->artisan('analytics:archive', ['--period' => 'day', '--scheduled' => true])->assertSuccessful();

        foreach ($missed as $date) {
            $this->assertTrue((bool) AnalyticsStatsDaily::query()->where('date', $date)->value('is_final'), $date.' 이 확정되지 않았다');
        }

        $this->assertFalse(AnalyticsStatsDaily::query()->where('date', $beyond)->exists());
        $this->assertSame(['scheduled'], AnalyticsArchiveRun::query()->pluck('trigger')->unique()->values()->all());
    }

    /**
     * 매일 실행이 어제치를 확정하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, trigger=scheduled, final=true, data=all_types
     *
     * @effects daily_rows_are_written_per_visitor_type
     */
    public function test_day_period_finalizes_yesterday(): void
    {
        $yesterday = Carbon::parse(AnalyticsClock::today())->subDay()->toDateString();
        $this->visitOn($yesterday);

        $this->artisan('analytics:archive', ['--period' => 'day'])->assertSuccessful();

        $row = AnalyticsStatsDaily::query()->where('date', $yesterday)->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->is_final);
    }

    /**
     * 기간 재집계가 하루씩 돌고 확정된 날은 --force 에서만 다시 도는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=range, trigger=manual, final=true, data=all_types
     *
     * @effects finalized_days_are_skipped_without_force, forced_rerun_recomputes_a_finalized_day
     */
    public function test_range_reaggregates_day_by_day_and_respects_final_flag(): void
    {
        // 재집계는 원장 보존일 안의 날만 다시 계산한다 — 오늘을 고정해 두지 않으면 이 날짜가
        // 보존일 밖으로 밀려나는 날부터 건너뜀으로 바뀐다.
        $this->travelTo(Carbon::parse('2026-08-20 12:00:00', 'UTC'));
        $this->visitOn('2026-08-01');
        $this->visitOn('2026-08-02');

        $this->artisan('analytics:archive', ['--from' => '2026-08-01', '--to' => '2026-08-02'])->assertSuccessful();

        $this->assertSame(2, AnalyticsArchiveRun::query()->where('run_type', ArchiveRunType::Reaggregate->value)->count());
        $this->assertSame(2, AnalyticsStatsDaily::query()->whereIn('date', ['2026-08-01', '2026-08-02'])->count());
        $this->assertTrue(
            (bool) AnalyticsStatsDaily::query()->where('date', '2026-08-01')->value('is_final'),
            '지난 날을 재집계하면 확정된다',
        );

        $before = AnalyticsArchiveRun::query()->count();
        $successBefore = AnalyticsArchiveRun::query()->where('status', ArchiveRunStatus::Success->value)->count();
        $this->artisan('analytics:archive', ['--from' => '2026-08-01', '--to' => '2026-08-02'])->assertSuccessful();

        // 다시 돌지는 않지만 건너뛴 사실은 이틀치 이력으로 남는다 — 아무것도 남기지 않으면
        // 커맨드가 성공만 찍고 끝나 무엇을 했는지 물어볼 곳이 없다.
        $this->assertSame($successBefore, AnalyticsArchiveRun::query()->where('status', ArchiveRunStatus::Success->value)->count(), '확정된 날은 다시 돌지 않는다');
        $this->assertSame($before + 2, AnalyticsArchiveRun::query()->count());
        $this->assertSame(2, AnalyticsArchiveRun::query()->where('status', ArchiveRunStatus::Skipped->value)->count());

        $before = AnalyticsArchiveRun::query()->count();

        $this->artisan('analytics:archive', [
            '--from' => '2026-08-01',
            '--to' => '2026-08-02',
            '--force' => true,
        ])->assertSuccessful();
        $this->assertSame($before + 2, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 마른 실행이 집계 표와 실행 이력을 건드리지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, trigger=manual, data=all_types
     *
     * @effects dry_run_touches_no_table
     */
    public function test_dry_run_changes_nothing(): void
    {
        $this->visitOn(AnalyticsClock::today());

        $this->artisan('analytics:archive', ['--period' => 'hour', '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, AnalyticsStatsDaily::query()->count());
        $this->assertSame(0, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 잘못된 단위·기간이 실패 코드로 돌아오는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=range, trigger=manual, data=empty
     *
     * @effects command_rejects_invalid_period_and_inverted_range
     */
    public function test_invalid_options_return_failure_without_touching_data(): void
    {
        $this->artisan('analytics:archive', ['--period' => 'week'])->assertFailed();
        $this->artisan('analytics:archive', ['--from' => '2026-08-10', '--to' => '2026-08-01'])->assertFailed();

        $this->assertSame(0, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 집계가 실패해도 예외를 던지지 않고 실패 코드로 끝나는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, trigger=manual, data=all_types
     *
     * @effects command_reports_failure_by_exit_code_not_exception
     */
    public function test_failure_returns_exit_code_instead_of_throwing(): void
    {
        $today = AnalyticsClock::today();
        $this->visitOn($today);

        HookManager::addAction('core.analytics.archive.day', function (): void {
            throw new \RuntimeException('집계 중 확장이 던진 예외');
        });

        $this->artisan('analytics:archive', ['--period' => 'hour'])->assertFailed();

        $run = AnalyticsArchiveRun::query()->latest('id')->first();
        $this->assertSame(ArchiveRunStatus::Failed->value, $this->valueOf($run->status));
    }

    /**
     * 기간 재집계에서 하루가 실패하면 종료 코드가 실패인지 확인합니다.
     *
     * 단일 일자 경로(`report()`)와 달리 기간 경로는 실패한 날을 **세어서** 종료 코드를
     * 정한다. 그 판정이 열거형을 문자열과 비교하면 언제나 거짓이라 「실패 0일」로
     * 보고하고 성공으로 끝난다 — 스케줄러는 매일 초록을 본다.
     *
     * @return void 없음
     *
     * @scenario period=range, trigger=manual, data=all_types
     *
     * @effects command_reports_failure_by_exit_code_not_exception
     */
    public function test_range_failure_is_counted_in_the_exit_code(): void
    {
        $date = Carbon::parse(AnalyticsClock::today())->subDays(2)->toDateString();
        $this->visitOn($date);

        HookManager::addAction('core.analytics.archive.day', function (): void {
            throw new \RuntimeException('집계 중 확장이 던진 예외');
        });

        $this->artisan('analytics:archive', [
            '--period' => 'range',
            '--from' => $date,
            '--to' => $date,
        ])->assertFailed();
    }

    /**
     * Enum 이거나 문자열인 값에서 문자열을 꺼냅니다.
     *
     * @param  mixed  $value  대상 값
     * @return string 문자열 값
     */
    private function valueOf(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
