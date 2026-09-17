<?php

namespace Tests\Feature\Console\Analytics;

use App\Enums\Analytics\ActionType;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsBotHit;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsSalt;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsStatsHourly;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 보존 정리 — 무엇을 지우고 무엇을 남기는지.
 *
 * 정리가 너무 많이 지우면 운영자는 사라진 줄도 모르고, 너무 적게 지우면 원장이 조용히
 * 자란다. 대상별로 「보존 안쪽은 남고 바깥쪽은 사라진다」를 행 수로 잠근다.
 */
class PruneCommandTest extends TestCase
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
     * 보존 안팎에 방문·액션·전환을 하나씩 만듭니다.
     *
     * @param  int  $daysAgo  며칠 전 기록으로 만들지
     * @return AnalyticsVisit 만들어진 방문
     */
    private function ledgerAt(int $daysAgo): AnalyticsVisit
    {
        $moment = Carbon::now('UTC')->subDays($daysAgo);

        $name = AnalyticsActionName::query()->firstOrCreate(
            ['type' => 'url', 'name_hash' => md5('url|example.test/')],
            ['name' => 'example.test/'],
        );

        $visit = AnalyticsVisit::factory()->create([
            'visit_date' => $moment->toDateString(),
            'first_action_at' => $moment,
            'last_action_at' => $moment,
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

        AnalyticsConversion::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'occurred_at' => $moment,
        ]);

        return $visit;
    }

    /**
     * 보존 기간 바깥 원장만 사라지고 안쪽은 남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=beyond
     *
     * @effects ledger_beyond_retention_is_deleted, ledger_within_retention_is_kept
     */
    public function test_only_ledger_beyond_retention_is_removed(): void
    {
        config(['g7_settings.core.analytics.retention_days' => 30]);

        $old = $this->ledgerAt(60);
        $fresh = $this->ledgerAt(5);

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertDatabaseMissing('analytics_visits', ['id' => $old->id]);
        $this->assertDatabaseHas('analytics_visits', ['id' => $fresh->id]);
        $this->assertSame(1, AnalyticsAction::query()->count(), '보존 안쪽 액션만 남는다');
        $this->assertSame(1, AnalyticsConversion::query()->count());
    }

    /**
     * 부속 기록이 각자의 보존일로 지워지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=actions, row_age=beyond
     *
     * @effects each_target_uses_its_own_retention_window
     */
    public function test_each_target_uses_its_own_retention_setting(): void
    {
        config([
            'g7_settings.core.analytics.retention_days' => 90,
            'g7_settings.core.analytics.hourly_retention_days' => 10,
            'g7_settings.core.analytics.bot_hits_retention_days' => 10,
            'g7_settings.core.analytics.failure_log_retention_days' => 10,
        ]);

        $old = Carbon::now('UTC')->subDays(20);
        $fresh = Carbon::now('UTC')->subDays(2);

        foreach ([$old, $fresh] as $moment) {
            AnalyticsStatsHourly::factory()->create(['hour_start' => $moment->format('Y-m-d H:00:00')]);
            AnalyticsBotHit::factory()->create(['occurred_at' => $moment]);
            AnalyticsCollectFailure::factory()->create(['occurred_at' => $moment]);
            AnalyticsArchiveRun::factory()->create(['started_at' => $moment]);
            AnalyticsSalt::factory()->create(['salt_date' => $moment->toDateString()]);
        }

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertSame(1, AnalyticsStatsHourly::query()->count(), '시간 집계는 자기 보존일을 따른다');
        $this->assertSame(1, AnalyticsBotHit::query()->count());
        $this->assertSame(1, AnalyticsCollectFailure::query()->count());
        $this->assertSame(1, AnalyticsArchiveRun::query()->count());
        $this->assertSame(1, AnalyticsSalt::query()->count(), '솔트는 5일만 남는다 — 20일 전 것만 사라진다');
    }

    /**
     * 일 집계와 이름 사전이 정리에서 살아남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=stats_hourly, row_age=beyond
     *
     * @effects daily_stats_survive_pruning, action_name_dictionary_survives_pruning
     */
    public function test_daily_stats_and_dictionary_are_never_pruned(): void
    {
        config(['g7_settings.core.analytics.retention_days' => 30]);

        $this->ledgerAt(60);
        AnalyticsStatsDaily::factory()->create(['date' => Carbon::now('UTC')->subDays(500)->toDateString()]);

        $dictionaryBefore = AnalyticsActionName::query()->count();

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertSame(1, AnalyticsStatsDaily::query()->count(), '원장이 사라져도 과거 숫자는 남아야 한다');
        $this->assertSame(
            $dictionaryBefore,
            AnalyticsActionName::query()->count(),
            '집계 행이 사전을 가리키므로 고아로 보이는 사전 행도 지우지 않는다',
        );
    }

    /**
     * 보존일을 하한보다 낮게 줘도 하한이 지켜지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects retention_floor_protects_recent_ledger
     */
    public function test_retention_floor_is_enforced_against_a_smaller_option(): void
    {
        $recent = $this->ledgerAt(3);

        $this->artisan('analytics:prune', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseHas('analytics_visits', ['id' => $recent->id]);
    }

    /**
     * 마른 실행이 아무것도 지우지 않고 건수만 세는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=beyond
     *
     * @effects dry_run_counts_without_deleting
     */
    public function test_dry_run_reports_targets_without_deleting(): void
    {
        config(['g7_settings.core.analytics.retention_days' => 30]);
        $old = $this->ledgerAt(60);

        $this->artisan('analytics:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('analytics_visits', ['id' => $old->id]);
        $this->assertSame(1, AnalyticsAction::query()->count());
    }

    /**
     * 정리 전후 훅이 건수와 함께 발화하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=conversions, row_age=beyond
     *
     * @effects prune_hooks_fire_before_and_after
     */
    public function test_before_and_after_hooks_fire_with_counts(): void
    {
        config(['g7_settings.core.analytics.retention_days' => 30]);
        $this->ledgerAt(60);

        $before = [];
        $after = [];

        HookManager::addAction('core.analytics.before_prune', function ($payload) use (&$before): void {
            $before[] = $payload;
        });
        HookManager::addAction('core.analytics.after_prune', function ($payload) use (&$after): void {
            $after[] = $payload;
        });

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertCount(1, $before);
        $this->assertSame(30, $before[0]['days']);
        $this->assertCount(1, $after);
        $this->assertSame(1, $after[0]['counts']['visits']);
    }
}
