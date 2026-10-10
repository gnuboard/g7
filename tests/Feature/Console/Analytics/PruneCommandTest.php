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
use Illuminate\Support\Facades\DB;
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
     * 하한 7일의 경계 — 7일 안쪽은 남고 바깥은 지워진다.
     *
     * 3일 전 행만 보면 하한이 4여도 통과한다. 경계 양옆(6일·8일)을 함께 본다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects retention_floor_protects_recent_ledger
     */
    public function test_retention_floor_boundary_is_seven_days(): void
    {
        $inside = $this->ledgerAt(6);
        $outside = $this->ledgerAt(8);

        $this->artisan('analytics:prune', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseHas('analytics_visits', ['id' => $inside->id]);
        $this->assertDatabaseMissing('analytics_visits', ['id' => $outside->id]);
    }

    /**
     * 청크 크기보다 많은 행도 여러 번에 나눠 전부 지운다.
     *
     * 청크 크기는 `config/analytics.php` `limits.prune_chunk_size` 가 정한다(하한 100).
     *
     * @return void 없음
     *
     * @scenario target=actions, row_age=beyond
     *
     * @effects prune_deletes_in_chunks_until_nothing_is_left
     */
    public function test_rows_beyond_one_chunk_are_all_deleted_in_batches(): void
    {
        config(['analytics.limits.prune_chunk_size' => 100]);

        $visit = $this->ledgerAt(200);
        $moment = Carbon::now('UTC')->subDays(200);
        $rows = [];
        for ($i = 0; $i < 249; $i++) {
            $rows[] = [
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'action_type' => ActionType::Pageview->value,
                'action_name_id' => $visit->entry_action_name_id,
                'occurred_at' => $moment,
                'created_at' => $moment,
            ];
        }
        AnalyticsAction::query()->insert($rows);
        $this->assertSame(250, AnalyticsAction::query()->count());

        $deletes = 0;
        DB::listen(function ($query) use (&$deletes): void {
            if (str_starts_with(strtolower($query->sql), 'delete') && str_contains($query->sql, 'analytics_actions')) {
                $deletes++;
            }
        });

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertSame(0, AnalyticsAction::query()->count());
        $this->assertGreaterThanOrEqual(3, $deletes, '250행을 100건씩 — 적어도 세 번에 나눠 지운다');
    }

    /**
     * 솔트 보존일을 설정으로 줄여도 정보주체 조회 창(오늘·어제)은 지킨다.
     *
     * @return void 없음
     *
     * @scenario target=salts, row_age=within
     *
     * @effects salt_retention_never_drops_below_the_subject_lookup_window
     */
    public function test_salt_retention_setting_is_floored_at_the_lookup_window(): void
    {
        config(['analytics.limits.salt_retention_days' => 0]);

        foreach ([0, 1, 3] as $daysAgo) {
            AnalyticsSalt::factory()->create(['salt_date' => Carbon::now('UTC')->subDays($daysAgo)->toDateString()]);
        }

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertSame(2, AnalyticsSalt::query()->count(), '오늘·어제 솔트는 남는다');
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

    /**
     * 방문이 사라질 때 그 액션·전환이 고아로 남지 않는지 확인합니다.
     *
     * 삭제 순서를 코드가 소유한다(`PruneService` 는 액션·전환을 먼저 지우고 방문을
     * 지운다). 순서가 뒤집히면 외래키 연쇄가 없는 이 스키마에서는 방문만 사라지고
     * 액션이 존재하지 않는 방문을 가리킨 채 남아, 다음 집계가 그 행을 영원히 끌고 간다.
     *
     * @return void 없음
     *
     * @scenario target=actions, row_age=beyond
     *
     * @effects actions_are_deleted_before_their_visits
     */
    public function test_no_action_outlives_the_visit_it_belongs_to(): void
    {
        config(['g7_settings.core.analytics.retention_days' => 30]);

        $old = $this->ledgerAt(60);
        $this->ledgerAt(5);

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertDatabaseMissing('analytics_visits', ['id' => $old->id]);
        $this->assertSame(
            0,
            AnalyticsAction::query()->where('visit_id', $old->id)->count(),
            '방문이 지워졌는데 액션이 남으면 그 액션은 영영 주인을 못 찾는다',
        );
        $this->assertSame(
            0,
            AnalyticsConversion::query()->where('visit_id', $old->id)->count(),
            '전환도 같은 순서로 방문보다 먼저 지워져야 한다',
        );

        // 살아남은 방문 쪽은 반대로 액션이 그대로 붙어 있어야 한다 — 순서 보장이
        // 「필요한 것까지 지운다」로 번지지 않았는지 함께 잠근다.
        $survivor = AnalyticsVisit::query()->firstOrFail();
        $this->assertSame(1, AnalyticsAction::query()->where('visit_id', $survivor->id)->count());
    }
}
