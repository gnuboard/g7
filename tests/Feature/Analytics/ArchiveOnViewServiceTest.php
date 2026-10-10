<?php

namespace Tests\Feature\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\OnViewOutcome;
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
use App\Services\Analytics\ArchiveOnViewService;
use App\Services\Analytics\ArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * 화면 열 때 집계 — 스케줄러 없이도 관리자가 화면을 열면 1분 이내 최신 숫자.
 *
 * 이 경로는 통계 화면 GET 요청 앞에서 돈다. 1분 간격·오늘 방문 상한·동시 요청 잠금·밀린 날 따라잡기
 * 가운데 하나라도 틀리면 화면이 느려지거나(과잉 집계) 숫자가 멈춘다(집계 누락). 어떤 실패도
 * 응답을 깨지 않아야 한다.
 */
class ArchiveOnViewServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비 — 표준시 UTC 고정.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'UTC']);

        // 판정은 화면 열 때 집계 설정(켜짐·오늘 방문 상한)에 기댄다 — 개발 PC 가 저장한 설정
        // (storage/app/settings/analytics.json)이 부팅 때 스며들면 결과가 흔들리므로 기본값으로 고정한다.
        // 상한을 시험하는 케이스는 아래에서 따로 덮어쓴다.
        config([
            'g7_settings.core.analytics.archive_on_view_enabled' => true,
            'g7_settings.core.analytics.archive_on_view_max_visits' => 50000,
        ]);
    }

    /**
     * 서비스를 꺼냅니다.
     *
     * @return ArchiveOnViewService 화면 열 때 집계
     */
    private function service(): ArchiveOnViewService
    {
        return app(ArchiveOnViewService::class);
    }

    /**
     * 주어진 일자에 방문을 만듭니다.
     *
     * @param  string  $date  방문 일자 (Y-m-d)
     * @param  int  $count  방문 수
     * @return void 없음
     */
    private function visitsOn(string $date, int $count = 1): void
    {
        $name = AnalyticsActionName::query()->firstOrCreate(
            ['type' => 'url', 'name_hash' => md5('url|example.test/')],
            ['name' => 'example.test/'],
        );
        $moment = Carbon::parse($date.' 02:00:00', 'UTC');

        for ($i = 0; $i < $count; $i++) {
            $visit = AnalyticsVisit::factory()->create([
                'visit_date' => $date,
                'first_action_at' => $moment,
                'last_action_at' => $moment,
                'visitor_type' => VisitorType::Guest,
                'actions_count' => 1,
                'pageviews_count' => 1,
                'entry_action_name_id' => $name->id,
                'exit_action_name_id' => $name->id,
            ]);

            AnalyticsAction::factory()->create([
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'action_type' => ActionType::Pageview,
                'action_name_id' => $name->id,
                'occurred_at' => $moment,
            ]);
        }
    }

    /**
     * 오늘에서 며칠 전 날짜를 만듭니다.
     *
     * @param  int  $days  며칠 전
     * @return string 일자 (Y-m-d)
     */
    private function daysAgo(int $days): string
    {
        return Carbon::parse(AnalyticsClock::today())->subDays($days)->toDateString();
    }

    /**
     * 설정에서 끄면 아무것도 하지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario on_view=disabled
     *
     * @effects on_view_disabled_does_nothing
     */
    public function test_disabled_setting_does_nothing(): void
    {
        config(['g7_settings.core.analytics.archive_on_view_enabled' => false]);
        $this->visitsOn(AnalyticsClock::today());

        $outcome = $this->service()->ensureFresh();

        $this->assertSame(OnViewOutcome::DISABLED, $outcome->status);
        $this->assertSame(0, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 첫 요청이 오늘치를 집계해 `on_view` 실행 행을 남기고, 1분 안의 두 번째 요청은 문에서 돌아가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario on_view=ran, repeat=within_minute
     *
     * @effects on_view_runs_once_per_minute
     */
    public function test_first_request_runs_and_second_is_throttled(): void
    {
        $this->visitsOn(AnalyticsClock::today(), 2);

        $first = $this->service()->ensureFresh();
        $second = $this->service()->ensureFresh();

        $this->assertSame(OnViewOutcome::RAN, $first->status);
        $this->assertSame(2, $first->todayVisits);
        $this->assertSame(OnViewOutcome::THROTTLED, $second->status);

        $runs = AnalyticsArchiveRun::query()->get();
        $this->assertCount(1, $runs);
        $this->assertSame('on_view', $runs->first()->trigger);
        $this->assertSame(ArchiveRunType::Hour, $runs->first()->run_type);
        $this->assertSame(ArchiveRunStatus::Success, $runs->first()->status);
        $this->assertSame(2, (int) AnalyticsStatsDaily::query()->where('date', AnalyticsClock::today())->sum('visits'));
    }

    /**
     * 마지막 매시 집계가 1분 안에 끝났으면 다시 계산하지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario on_view=fresh
     *
     * @effects recent_archive_is_not_repeated
     */
    public function test_recent_success_is_fresh(): void
    {
        $this->visitsOn(AnalyticsClock::today());
        AnalyticsArchiveRun::factory()->create([
            'run_type' => ArchiveRunType::Hour->value,
            'trigger' => 'scheduled',
            'status' => ArchiveRunStatus::Success->value,
            'started_at' => now()->subSeconds(40),
            'finished_at' => now()->subSeconds(30),
        ]);

        $this->assertSame(OnViewOutcome::FRESH, $this->service()->ensureFresh()->status);
        $this->assertSame(1, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 오늘 방문이 상한을 넘으면 집계하지 않고 실행 행도 남기지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario on_view=too_many_visits
     *
     * @effects on_view_skips_when_today_visits_exceed_limit
     */
    public function test_too_many_visits_skips_archiving(): void
    {
        // 저장 규칙의 하한(1000)은 화면 입력에만 걸린다 — 값 객체는 1 이상이면 받는다.
        config(['g7_settings.core.analytics.archive_on_view_max_visits' => 2]);
        $this->visitsOn(AnalyticsClock::today(), 3);

        $outcome = $this->service()->ensureFresh();

        $this->assertSame(OnViewOutcome::TOO_MANY_VISITS, $outcome->status);
        $this->assertSame(3, $outcome->todayVisits);
        $this->assertSame(2, $outcome->maxVisits);
        $this->assertSame(0, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 상한을 넘은 동안에는 1분 안에 다시 열어도 「상한 초과」로 답하는지 확인합니다.
     *
     * 문이 상한 판정보다 먼저 닫히면 두 번째 화면부터 「대기(throttled)」로 답해 상단 경고가 사라지고,
     * 멈춘 숫자가 정상처럼 보인다.
     *
     * @return void 없음
     *
     * @scenario on_view=too_many_visits, repeat=within_gate
     *
     * @effects on_view_too_many_status_persists_across_requests
     */
    public function test_too_many_visits_is_reported_on_every_request(): void
    {
        config(['g7_settings.core.analytics.archive_on_view_max_visits' => 2]);
        $this->visitsOn(AnalyticsClock::today(), 3);

        $first = $this->service()->ensureFresh();
        $second = $this->service()->ensureFresh();

        $this->assertSame(OnViewOutcome::TOO_MANY_VISITS, $first->status);
        $this->assertSame(OnViewOutcome::TOO_MANY_VISITS, $second->status, '두 번째 화면에서도 경고 조건이 참이어야 한다');
        $this->assertSame(3, $second->todayVisits);
    }

    /**
     * 다른 실행이 오늘치를 계산 중이면 기다리지 않고 돌아가며, 건너뜀 행을 남기지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario on_view=locked
     *
     * @effects on_view_lock_miss_leaves_no_skipped_row
     */
    public function test_locked_day_returns_without_skipped_row(): void
    {
        $this->visitsOn(AnalyticsClock::today());
        $lock = Cache::lock(ArchiveService::lockKey(AnalyticsClock::today()), 60);
        $this->assertTrue($lock->get());

        try {
            $this->assertSame(OnViewOutcome::LOCKED, $this->service()->ensureFresh()->status);
            $this->assertSame(0, AnalyticsArchiveRun::query()->count());
        } finally {
            $lock->release();
        }
    }

    /**
     * 밀린 날을 최근 날부터 최대 7일 확정하고, 확정된 날·보존기간 밖·오늘은 건드리지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario on_view=ran, catch_up=missed_days
     *
     * @effects missed_days_are_finalized_by_catch_up
     */
    public function test_catch_up_finalizes_at_most_seven_recent_days(): void
    {
        $this->visitsOn(AnalyticsClock::today());

        // 밀린 날 9일(2~10일 전) — 최근 7일(2~8일 전)만 확정된다.
        for ($days = 2; $days <= 10; $days++) {
            $this->visitsOn($this->daysAgo($days));
        }

        // 1일 전은 이미 확정됐다.
        $this->visitsOn($this->daysAgo(1));
        AnalyticsStatsDaily::factory()->create(['date' => $this->daysAgo(1), 'visitor_type' => 'guest', 'is_final' => true]);

        // 보존기간(90일) 밖
        $this->visitsOn($this->daysAgo(120));

        $outcome = $this->service()->ensureFresh();
        $this->assertSame(OnViewOutcome::RAN, $outcome->status);

        $dayRuns = AnalyticsArchiveRun::query()->where('run_type', ArchiveRunType::Day->value)->get();
        $this->assertCount(7, $dayRuns);
        $this->assertSame(['on_view'], $dayRuns->pluck('trigger')->unique()->values()->all());

        for ($days = 2; $days <= 8; $days++) {
            $this->assertTrue((bool) AnalyticsStatsDaily::query()->where('date', $this->daysAgo($days))->value('is_final'), $days.'일 전');
        }

        $this->assertFalse(AnalyticsStatsDaily::query()->where('date', $this->daysAgo(9))->exists());
        $this->assertFalse(AnalyticsStatsDaily::query()->where('date', $this->daysAgo(120))->exists());
        $this->assertFalse((bool) AnalyticsStatsDaily::query()->where('date', AnalyticsClock::today())->value('is_final'));
    }

    /**
     * 따라잡기가 오늘 방문을 뺀 예산 안에서만 도는지 확인합니다 — 요청 하나가 과도하게 길어지지 않게.
     *
     * @return void 없음
     *
     * @scenario on_view=ran, catch_up=budget
     *
     * @effects catch_up_respects_visit_budget
     */
    public function test_catch_up_stops_at_visit_budget(): void
    {
        config(['g7_settings.core.analytics.archive_on_view_max_visits' => 5]);
        $this->visitsOn(AnalyticsClock::today(), 1);
        $this->visitsOn($this->daysAgo(2), 3);
        $this->visitsOn($this->daysAgo(3), 3);

        $this->service()->ensureFresh();

        // 예산 = 5 − 1 = 4. 2일 전(3건)은 들고, 3일 전까지 더하면 6건이라 멈춘다.
        $this->assertTrue((bool) AnalyticsStatsDaily::query()->where('date', $this->daysAgo(2))->value('is_final'));
        $this->assertFalse(AnalyticsStatsDaily::query()->where('date', $this->daysAgo(3))->exists());
    }

    /**
     * 워커가 죽어 「실행 중」으로 남은 행을 닫는지 확인합니다 — 스케줄러 없는 설치의 유일한 정리 기회.
     *
     * @return void 없음
     *
     * @scenario on_view=ran, stale_run=present
     *
     * @effects stale_running_rows_are_closed
     */
    public function test_stale_running_rows_are_closed(): void
    {
        $this->visitsOn(AnalyticsClock::today());
        $stale = AnalyticsArchiveRun::factory()->create([
            'run_type' => ArchiveRunType::Reaggregate->value,
            'trigger' => 'manual',
            'status' => ArchiveRunStatus::Running->value,
            'started_at' => now()->subHours(3),
            'finished_at' => null,
        ]);

        $this->service()->ensureFresh();

        $this->assertSame(ArchiveRunStatus::Failed, $stale->fresh()->status);
    }

    /**
     * 집계 중 예외가 나도 밖으로 던지지 않고 `failed` 로 돌려주는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario on_view=failed
     *
     * @effects on_view_failure_never_breaks_response
     */
    public function test_exception_is_reported_as_failed(): void
    {
        $this->visitsOn(AnalyticsClock::today());
        $this->mock(ArchiveService::class, function ($mock): void {
            $mock->shouldReceive('archiveHour')->andThrow(new RuntimeException('boom'));
        });

        $outcome = $this->service()->ensureFresh();

        $this->assertSame(OnViewOutcome::FAILED, $outcome->status);
        $this->assertSame(1, $outcome->todayVisits);
    }

    /**
     * 화면 열 때 집계는 활동 로그 훅(`core.analytics.archive_requested`)을 발화하지 않는지 확인합니다 —
     * 분당 한 건씩 활동 로그가 쌓이지 않게. 실행 사실은 실행 이력 행으로 남는다.
     *
     * @return void 없음
     *
     * @scenario on_view=ran
     *
     * @effects on_view_leaves_no_activity_log
     */
    public function test_on_view_does_not_fire_archive_requested_hook(): void
    {
        $fired = 0;
        HookManager::addAction('core.analytics.archive_requested', function () use (&$fired): void {
            $fired++;
        });

        $this->visitsOn(AnalyticsClock::today());
        $this->service()->ensureFresh();

        $this->assertSame(0, $fired);
        $this->assertSame(1, AnalyticsArchiveRun::query()->where('trigger', 'on_view')->count());
    }
}
