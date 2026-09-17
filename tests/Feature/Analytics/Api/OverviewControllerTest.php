<?php

namespace Tests\Feature\Analytics\Api;

use App\Analytics\AnalyticsClock;
use Illuminate\Support\Carbon;

/**
 * 개요 API — 화면 첫 장이 받아 가는 네 숫자와 그 메타.
 *
 * 이 숫자가 틀리면 운영자는 그 사실을 알 길이 없다. 정의가 특이한 값(전체 = 일별
 * 순방문자의 합, 어제 0 일 때 증감률 없음)을 특히 잠근다.
 *
 * @scenario endpoint=overview, role=read, period=valid
 */
class OverviewControllerTest extends AnalyticsApiTestCase
{
    /**
     * 네 숫자가 정의대로 계산되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read, period=valid
     *
     * @effects overview_returns_four_headline_numbers, total_is_the_sum_of_daily_unique_visitors, today_is_counted_from_the_ledger_not_the_archive
     */
    public function test_headline_numbers_follow_their_definitions(): void
    {
        $today = AnalyticsClock::today();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        $this->daily($yesterday, ['visitors' => 20]);
        $this->daily(Carbon::parse($today)->subDays(5)->toDateString(), ['visitors' => 35]);
        $this->visit(['visitor_key' => str_repeat('a', 32)]);
        $this->visit(['visitor_key' => str_repeat('b', 32)]);

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/overview');

        $response->assertOk();
        $kpi = $response->json('data.kpi');

        $this->assertSame(2, $kpi['today'], '오늘은 집계가 아니라 원장에서 직접 센다');
        $this->assertSame(20, $kpi['yesterday']);
        $this->assertSame(35, $kpi['max']['value']);
        $this->assertSame(57, $kpi['total'], '전체는 지난 일 집계 합(55) + 오늘(2) 이다');
    }

    /**
     * 어제가 0 이면 증감률이 비는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read, period=valid
     *
     * @effects change_percent_is_empty_when_yesterday_was_zero
     */
    public function test_change_percent_is_null_without_yesterday(): void
    {
        $this->visit();

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/overview');

        $response->assertOk();
        $this->assertNull(
            $response->json('data.kpi.change_pct'),
            '0 에서 늘어난 것을 무한 % 증가로 적으면 읽는 사람이 오해한다',
        );
    }

    /**
     * 화면이 경고를 띄울 메타가 함께 오는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read, period=valid
     *
     * @effects overview_meta_exposes_collection_and_queue_state
     */
    public function test_meta_carries_collection_and_queue_state(): void
    {
        config(['g7_settings.core.analytics.enabled' => false]);

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/overview');

        $response->assertOk();
        $meta = $response->json('data.meta');

        $this->assertFalse($meta['collection_enabled'], '수집이 꺼진 것을 화면이 알아야 안내를 띄운다');
        $this->assertArrayHasKey('consent_source', $meta);
        $this->assertArrayHasKey('queue_backlogged', $meta);
        $this->assertArrayHasKey('realtime_poll_seconds', $meta);
    }

    /**
     * 프리셋과 직접 지정 기간이 함께 오면 직접 지정이 이기는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read, period=preset
     *
     * @effects period_preset_resolves_to_a_date_range, explicit_dates_win_over_the_preset
     */
    public function test_explicit_dates_win_over_the_preset(): void
    {
        $response = $this->actingAs($this->readAdmin())->getJson(
            '/api/admin/analytics/overview?preset=30d&from=2026-08-01&to=2026-08-03',
        );

        $response->assertOk();
        $this->assertSame('2026-08-01', $response->json('data.meta.from'));
        $this->assertSame('2026-08-03', $response->json('data.meta.to'), '주소를 공유했을 때 보던 화면이 그대로 열려야 한다');
    }

    /**
     * 뒤집힌 기간과 너무 긴 기간이 거절되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read, period=inverted
     *
     * @effects inverted_range_is_rejected, range_beyond_the_cap_is_rejected
     */
    public function test_invalid_ranges_are_rejected(): void
    {
        $admin = $this->readAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/analytics/overview?from=2026-08-10&to=2026-08-01')
            ->assertStatus(422);

        // 상한은 366일이다 — 그 경계 하루 안쪽은 통과하고 하루 밖은 막혀야 한다.
        $this->actingAs($admin)
            ->getJson('/api/admin/analytics/overview?from=2026-01-01&to=2026-12-31')
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/admin/analytics/overview?from=2026-01-01&to=2027-01-02')
            ->assertStatus(422);
    }

    /**
     * 제외 토글이 그 유형을 합산에서 빼는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read, period=valid
     *
     * @effects exclude_toggles_drop_those_visitor_types_from_totals
     */
    public function test_exclude_toggle_drops_that_visitor_type(): void
    {
        $yesterday = Carbon::parse(AnalyticsClock::today())->subDay()->toDateString();

        $this->daily($yesterday, ['visitor_type' => 'guest', 'visitors' => 10]);
        $this->daily($yesterday, ['visitor_type' => 'admin', 'visitors' => 7]);

        $admin = $this->readAdmin();

        $all = $this->actingAs($admin)->getJson('/api/admin/analytics/overview?preset=yesterday');
        $this->assertSame(17, $all->json('data.kpi.yesterday'));

        $withoutAdmin = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/overview?preset=yesterday&exclude[]=admin');
        $this->assertSame(10, $withoutAdmin->json('data.kpi.yesterday'));
    }

    /**
     * 추이 단위가 구간 길이로 정해지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=series, role=read, period=valid
     *
     * @effects series_unit_is_chosen_from_the_range_length
     */
    public function test_series_unit_follows_the_range_length(): void
    {
        $admin = $this->readAdmin();

        $short = $this->actingAs($admin)->getJson('/api/admin/analytics/series?preset=today');
        $this->assertSame('hour', $short->json('data.unit'));

        $medium = $this->actingAs($admin)->getJson('/api/admin/analytics/series?preset=30d');
        $this->assertSame('day', $medium->json('data.unit'));

        $long = $this->actingAs($admin)->getJson('/api/admin/analytics/series?from=2026-01-01&to=2026-12-01');
        $this->assertSame('week', $long->json('data.unit'));
    }

    /**
     * 시간 집계 보존기간 밖을 시간 단위로 물으면 일 단위로 낮추는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=series, role=read, period=too_long
     *
     * @effects series_downgrades_hour_outside_the_retention_window
     */
    public function test_hour_unit_downgrades_outside_the_retention_window(): void
    {
        config(['g7_settings.core.analytics.hourly_retention_days' => 7]);

        $from = Carbon::now('UTC')->subDays(60)->toDateString();

        $response = $this->actingAs($this->readAdmin())
            ->getJson("/api/admin/analytics/series?from={$from}&to={$from}&unit=hour");

        $response->assertOk();
        $this->assertSame('day', $response->json('data.unit'));
        $this->assertTrue($response->json('data.unit_downgraded'), '빈 차트 대신 낮췄다는 사실을 화면에 알린다');
    }
}
