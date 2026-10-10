<?php

namespace Tests\Feature\Analytics\Api;

use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsVisit;

/**
 * 데이터 API — 용량 현황과, 되돌릴 수 없는 전체 삭제.
 *
 * 전체 삭제는 사이트의 모든 방문 기록을 지운다. 콘솔에는 `--confirm` 과 대화형 확인이
 * 있지만 API 경로에도 같은 문이 있어야 한다 — 주소를 직접 치는 길이 남기 때문이다.
 *
 * @scenario endpoint=data, role=read
 */
class DataControllerTest extends AnalyticsApiTestCase
{
    /**
     * 용량 현황이 표별 행 수와 정확한 원장 계수를 함께 내는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=data, role=read
     *
     * @effects storage_stats_list_every_analytics_table, storage_stats_count_the_two_ledger_tables_exactly, stored_codes_are_shown_as_human_labels
     */
    public function test_storage_stats_cover_every_table(): void
    {
        $this->visit();

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/data');

        $response->assertOk();

        $tables = collect($response->json('data.tables'))->pluck('table');
        $this->assertCount(11, $tables, '방문자 통계 표 열한 개가 모두 보여야 보존일을 정할 수 있다');
        $this->assertContains('analytics_visits', $tables);
        $this->assertContains('analytics_stats_daily', $tables);

        // 표 이름만으로는 무엇이 쌓이는지 모른다 — 코어 표는 전부 화면 이름이 붙는다(#682 S4 라운드 8).
        $unnamed = collect($response->json('data.tables'))
            ->filter(fn (array $row): bool => ! is_string($row['label'] ?? null) || $row['label'] === $row['table'])
            ->pluck('table')->values()->all();
        $this->assertSame([], $unnamed, '화면 이름이 없는 코어 표');

        // 운영자가 지울지 말지를 정하는 두 표는 어림값이 아니라 정확히 센다.
        $this->assertSame(1, $response->json('data.exact.analytics_visits'));
        $this->assertSame(1, $response->json('data.exact.analytics_actions'));
        $this->assertFalse($response->json('data.over_limit'));
    }

    /**
     * 표 목록의 원장 두 줄이 위의 정확한 계수와 같은 행 수를 보이는지 확인합니다.
     *
     * 표별 행 수는 DB 통계(TABLE_ROWS) 어림값이다. 같은 카드 위쪽은 「방문 19행」인데 표의 방문 기록 줄은
     * 「11」로 보여 어느 쪽이 맞는지 알 수 없었다(7.1.0 실측 E-D7). 이미 정확히 센 두 표는 그 값을 쓴다.
     *
     * @return void 없음
     *
     * @scenario endpoint=data, role=read
     *
     * @effects storage_table_rows_use_the_exact_ledger_counts
     */
    public function test_ledger_table_rows_match_the_exact_counts(): void
    {
        $this->visit();
        $this->visit();
        $this->visit();

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/data');

        $response->assertOk();
        $rows = collect($response->json('data.tables'))->keyBy('table');

        $this->assertSame($response->json('data.exact.analytics_visits'), $rows['analytics_visits']['rows']);
        $this->assertSame($response->json('data.exact.analytics_actions'), $rows['analytics_actions']['rows']);
        $this->assertSame(3, $rows['analytics_visits']['rows']);
    }

    /**
     * 한계를 넘으면 현황이 그 사실을 표시하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=data, role=read
     *
     * @effects storage_stats_flag_the_warning_threshold
     */
    public function test_crossing_the_threshold_is_flagged(): void
    {
        config(['g7_settings.core.analytics.ledger_warning_visits' => 1]);
        $this->visit();

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/data');

        $response->assertOk();
        $this->assertTrue($response->json('data.over_limit'));
    }

    /**
     * 확인 없이는 삭제되지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=data, role=update, confirm=missing
     *
     * @effects purge_requires_confirmation
     */
    public function test_delete_requires_confirmation(): void
    {
        $this->visit();

        $admin = $this->updateAdmin();

        $this->actingAs($admin)->deleteJson('/api/admin/analytics/data')->assertStatus(422);
        $this->actingAs($admin)->deleteJson('/api/admin/analytics/data', ['confirm' => false])->assertStatus(422);

        $this->assertSame(1, AnalyticsVisit::query()->count(), '확인을 안 했는데 지워지면 사고다');
    }

    /**
     * 확인을 거치면 원장과 집계가 모두 사라지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=data, role=update, confirm=given
     *
     * @effects purge_removes_ledger_and_aggregates, purge_fires_the_data_purged_hook
     */
    public function test_confirmed_delete_removes_everything(): void
    {
        $this->visit();
        AnalyticsStatsDaily::factory()->create();
        AnalyticsDimensionStat::factory()->create();

        $fired = 0;
        HookManager::addAction('core.analytics.data_purged', function () use (&$fired): void {
            $fired++;
        });

        $response = $this->actingAs($this->updateAdmin())
            ->deleteJson('/api/admin/analytics/data', ['confirm' => true]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.deleted.visits'));

        $this->assertSame(0, AnalyticsVisit::query()->count());
        $this->assertSame(0, AnalyticsAction::query()->count());
        $this->assertSame(0, AnalyticsStatsDaily::query()->count());
        $this->assertSame(0, AnalyticsDimensionStat::query()->count());
        $this->assertSame(1, $fired);
    }

    /**
     * 조회 권한만으로는 지우지 못하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=data, role=read, confirm=given
     *
     * @effects read_permission_cannot_reach_update_endpoints
     */
    public function test_read_permission_cannot_delete(): void
    {
        $this->visit();

        $this->actingAs($this->readAdmin())
            ->deleteJson('/api/admin/analytics/data', ['confirm' => true])
            ->assertStatus(403);

        $this->assertSame(1, AnalyticsVisit::query()->count());
    }
}
