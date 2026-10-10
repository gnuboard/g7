<?php

namespace Tests\Feature\Analytics\Api;

use App\Analytics\AnalyticsClock;
use App\Models\Analytics\AnalyticsStatsHourly;
use App\Services\Analytics\CsvExporter;
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
        // 날짜 컬럼 캐스트가 시각을 붙여 「최대 (2026-09-17 00:00:00)」로 보였다(브라우저 실측 §8 M2-T1).
        $this->assertSame(Carbon::parse($today)->subDays(5)->toDateString(), $kpi['max']['date'], '최대일은 날짜(Y-m-d)만');
        $this->assertSame(57, $kpi['total'], '전체는 지난 일 집계 합(55) + 오늘(2) 이다');
    }

    /**
     * 오늘 숫자가 일 집계와 같은 정의(방문자 유형별 순방문자의 합)로 세어지는지 확인합니다.
     *
     * 같은 방문자 키가 하루 안에 게스트로 들어왔다가 로그인해 회원 방문이 되면, 일 집계는
     * 유형별 행마다 그 사람을 하나씩 센다(게스트 1 + 회원 1). 오늘만 유형을 가로질러
     * 하나로 세면 같은 날의 「오늘 2」와 「최대 3 (오늘 날짜)」가 함께 보이고, 자정이 지나면
     * 어제 숫자가 오늘 보던 값과 달라진다(7.1.0 실측 E-D2).
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read, period=valid
     *
     * @effects today_uses_the_same_per_visitor_type_definition_as_the_daily_archive
     */
    public function test_today_counts_visitors_per_visitor_type_like_the_daily_archive(): void
    {
        $today = AnalyticsClock::today();
        $key = str_repeat('c', 32);

        $this->visit(['visitor_key' => $key, 'visitor_type' => 'guest', 'visit_date' => $today]);
        $this->visit(['visitor_key' => $key, 'visitor_type' => 'member', 'visit_date' => $today]);
        $this->visit(['visitor_key' => str_repeat('d', 32), 'visitor_type' => 'guest', 'visit_date' => $today]);
        // 오늘치 일 집계 행(화면 열 때 집계가 쓰는 값) — 유형별 순방문자: 게스트 2, 회원 1.
        $this->daily($today, ['visitor_type' => 'guest', 'visitors' => 2, 'is_final' => false]);
        $this->daily($today, ['visitor_type' => 'member', 'visitors' => 1, 'is_final' => false]);

        $kpi = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/overview')
            ->assertOk()
            ->json('data.kpi');

        $this->assertSame(3, $kpi['today'], '오늘도 일 집계처럼 유형별 순방문자를 더한다');
        $this->assertSame(3, $kpi['max']['value']);
        $this->assertSame($today, $kpi['max']['date']);
        $this->assertSame(3, $kpi['total']);
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
        $this->assertContains($meta['consent_source'], ['provider', 'none'], '옛 출처 값(filter)은 더는 없다');
        $this->assertArrayHasKey('consent_selection', $meta);
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

    /**
     * 기록이 없는 칸을 0 으로 채우는지 확인합니다.
     *
     * 방문이 없던 시간·날을 건너뛰면 차트가 앞뒤 칸을 곧장 이어 그려 추이가 거짓이 됩니다.
     * 브라우저 실측(§8 M2-T3)에서 이틀치 시간 단위 추이가 48칸이 아니라 46칸으로 왔습니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=series, role=read, period=valid
     *
     * @effects series_fills_empty_buckets_with_zero
     */
    public function test_series_fills_empty_buckets_with_zero(): void
    {
        config(['g7_settings.core.analytics.hourly_retention_days' => 30]);

        $today = Carbon::parse(AnalyticsClock::today());
        $from = $today->copy()->subDays(2)->toDateString();
        $to = $today->copy()->subDay()->toDateString();

        AnalyticsStatsHourly::factory()->forHour($from.' 03:00:00')->create(['visits' => 4]);
        AnalyticsStatsHourly::factory()->forHour($to.' 15:00:00')->create(['visits' => 6]);

        $admin = $this->readAdmin();

        $hourly = $this->actingAs($admin)->getJson("/api/admin/analytics/series?from={$from}&to={$to}&unit=hour");

        $hourly->assertOk();
        $points = $hourly->json('data.points');

        $this->assertCount(48, $points, '이틀을 시간 단위로 그리면 48칸이다 — 방문 없는 시간도 한 칸');
        $this->assertSame($from.' 00:00:00', $points[0]['ts']);
        $this->assertSame($to.' 23:00:00', $points[47]['ts']);
        $this->assertSame(4, $points[3]['visits']);
        $this->assertSame(0, $points[4]['visits'], '방문 없는 시간은 0 으로 그린다');
        $this->assertSame(6, $points[24 + 15]['visits']);

        $this->daily($today->copy()->subDays(6)->toDateString(), ['visits' => 7]);
        $this->daily($today->copy()->subDays(3)->toDateString(), ['visits' => 5]);

        $daily = $this->actingAs($admin)->getJson(
            '/api/admin/analytics/series?from='.$today->copy()->subDays(6)->toDateString()
            .'&to='.$today->copy()->subDays(2)->toDateString().'&unit=day',
        );

        $daily->assertOk();
        $this->assertSame(
            [7, 0, 0, 5, 0],
            array_column($daily->json('data.points'), 'visits'),
            '방문 없는 날도 한 칸이다',
        );
    }

    /**
     * 아직 오지 않은 칸은 만들지 않는지 확인합니다.
     *
     * 오늘 남은 시간이나 내일을 0 으로 미리 그리면 방문이 뚝 끊긴 것처럼 보입니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=series, role=read, period=valid
     *
     * @effects series_stops_at_the_current_bucket
     */
    public function test_series_stops_at_the_current_bucket(): void
    {
        config(['g7_settings.core.analytics.hourly_retention_days' => 30]);
        $this->travelTo(Carbon::parse('2026-09-19 10:30:00', AnalyticsClock::siteTimezone()));

        AnalyticsStatsHourly::factory()->forHour('2026-09-19 09:00:00')->create();
        $this->daily('2026-09-17');

        $admin = $this->readAdmin();

        $hourly = $this->actingAs($admin)->getJson('/api/admin/analytics/series?preset=today&unit=hour');

        $hourly->assertOk();
        $this->assertCount(11, $hourly->json('data.points'), '지금 시간(10시)까지만 — 남은 시간을 0 으로 미리 그리지 않는다');
        $this->assertSame('2026-09-19 00:00:00', $hourly->json('data.points.0.ts'));
        $this->assertSame('2026-09-19 10:00:00', $hourly->json('data.points.10.ts'));

        $daily = $this->actingAs($admin)->getJson('/api/admin/analytics/series?from=2026-09-17&to=2026-09-21&unit=day');

        $daily->assertOk();
        $this->assertSame(
            ['2026-09-17', '2026-09-18', '2026-09-19'],
            array_column($daily->json('data.points'), 'ts'),
            '오늘까지만 — 내일부터는 칸이 없다',
        );
    }

    /**
     * 기간 전체에 기록이 없으면 0 으로 채우지 않는지 확인합니다.
     *
     * 0 으로 채운 평평한 선은 「선택 기간에 기록이 없습니다」 안내를 가립니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=series, role=read, period=preset
     *
     * @effects series_without_any_record_stays_empty
     */
    public function test_series_without_any_record_stays_empty(): void
    {
        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/series?preset=30d');

        $response->assertOk();
        $this->assertSame([], $response->json('data.points'), '빈 목록이어야 화면이 기록 없음 안내를 띄운다');
    }

    /**
     * 추이를 CSV 로 내려받을 수 있는지 확인합니다.
     *
     * 화면의 그래프를 운영자가 스프레드시트에서 자기 방식대로 다시 그릴 수 있어야
     * 합니다. 목록만 내려받을 수 있으면 「추이는 눈으로만 보라」는 뜻이 됩니다(부록 D).
     *
     * @return void 없음
     *
     * @scenario endpoint=series, role=read, period=valid, format=csv
     *
     * @effects csv_export_starts_with_a_byte_order_mark, series_can_be_downloaded_as_csv
     */
    public function test_series_can_be_downloaded_as_csv(): void
    {
        $date = Carbon::parse(AnalyticsClock::today())->subDays(3)->toDateString();
        $this->daily($date, ['visits' => 7, 'visitors' => 5, 'pageviews' => 19]);

        $response = $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/series?preset=30d&format=csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'analytics-series-',
            (string) $response->headers->get('content-disposition'),
        );

        $body = $response->streamedContent();

        $this->assertStringStartsWith(CsvExporter::BOM, $body, 'BOM 이 없으면 엑셀에서 한글이 전부 깨진다');
        $this->assertStringContainsString(
            "ts,visits,visitors,pageviews,bounce_rate,avg_time_seconds,pages_per_visit,new_visitors,returning_visitors\r\n",
            $body,
        );
        // 헤더만 나가고 값이 비면 내려받은 파일이 화면과 다르다.
        $this->assertMatchesRegularExpression("/{$date},7,5,19,/u", $body);
    }

    /**
     * 추이 점에 신규·재방문이 실리고, 칸의 행이 모두 익명(null)이면 null 로 남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read, period=valid
     *
     * @effects series_points_carry_new_and_returning_visitors
     */
    public function test_series_points_carry_new_and_returning_visitors(): void
    {
        $today = Carbon::parse(AnalyticsClock::today());
        $mixed = $today->copy()->subDays(2)->toDateString();
        $anonymous = $today->copy()->subDays(3)->toDateString();

        // 같은 날 두 유형 — 하나는 쿠키 모드(값 있음), 하나는 익명(null). 값이 있는 행만 더한다.
        $this->daily($mixed, ['visitor_type' => 'guest', 'new_visitors' => 6, 'returning_visitors' => 2]);
        $this->daily($mixed, ['visitor_type' => 'member', 'new_visitors' => null, 'returning_visitors' => null]);
        $this->daily($anonymous, ['new_visitors' => null, 'returning_visitors' => null]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/overview?preset=7d')
            ->assertOk();

        $points = collect($response->json('data.series.points'))->keyBy('ts');

        $this->assertSame(6, $points[$mixed]['new_visitors']);
        $this->assertSame(2, $points[$mixed]['returning_visitors']);
        $this->assertNull($points[$anonymous]['new_visitors'], '익명 모드 칸은 모름(null)이다 — 0 이 아니다');
        $this->assertNull($points[$today->copy()->subDays(1)->toDateString()]['returning_visitors'], '기록 없는 칸도 null');
    }
}
