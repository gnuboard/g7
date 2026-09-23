<?php

namespace Tests\Feature\Analytics\Api;

use App\Models\Analytics\AnalyticsStatsHourly;
use Illuminate\Support\Carbon;

/**
 * 이용 패턴 API — 「언제 붐비는가」.
 *
 * 합이 아니라 평균이라는 것이 이 화면의 전부다. 기간에 월요일이 다섯 번, 화요일이 네 번
 * 들어 있으면 합계만으로는 월요일이 더 붐비는 것처럼 보인다.
 *
 * @scenario endpoint=patterns, role=read, period=valid
 */
class PatternsControllerTest extends AnalyticsApiTestCase
{
    /**
     * 요일 일곱 칸·시간대 스물네 칸이 언제나 채워지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=patterns, role=read, period=valid
     *
     * @effects patterns_always_return_seven_weekdays_and_twenty_four_hours
     */
    public function test_all_buckets_are_present_even_without_data(): void
    {
        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/patterns?preset=7d');

        $response->assertOk();
        $this->assertCount(7, $response->json('data.weekday'), '빈 칸도 0 으로 나와야 차트에 구멍이 생기지 않는다');
        $this->assertCount(24, $response->json('data.hour'));
    }

    /**
     * 요일 평균이 그 요일이 몇 번 있었는지로 나뉘는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=patterns, role=read, period=valid
     *
     * @effects patterns_average_instead_of_summing
     */
    public function test_weekday_values_are_averaged_by_their_occurrence(): void
    {
        // 월요일 두 번(방문 10씩) · 화요일 한 번(방문 10) — 합계로 내면 월요일이 두 배로
        // 붐비는 것처럼 보이지만, 평균은 같다.
        $this->daily('2026-09-07', ['visits' => 10, 'visitors' => 10, 'pageviews' => 20]);
        $this->daily('2026-09-14', ['visits' => 10, 'visitors' => 10, 'pageviews' => 20]);
        $this->daily('2026-09-08', ['visits' => 10, 'visitors' => 10, 'pageviews' => 20]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/patterns?from=2026-09-07&to=2026-09-14');

        $response->assertOk();

        $monday = collect($response->json('data.weekday'))->firstWhere('dow', 1);
        $tuesday = collect($response->json('data.weekday'))->firstWhere('dow', 2);

        $this->assertSame(2, $monday['days']);
        $this->assertSame(1, $tuesday['days']);
        $this->assertEqualsWithDelta(10.0, $monday['visits'], 0.01);
        $this->assertEqualsWithDelta(
            $tuesday['visits'],
            $monday['visits'],
            0.01,
            '같은 세기의 요일은 등장 횟수가 달라도 평균이 같아야 한다',
        );
    }

    /**
     * 시간대 평균이 시간 집계에서 나오는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=patterns, role=read, period=valid
     *
     * @effects patterns_hour_buckets_come_from_hourly_stats
     */
    public function test_hour_buckets_read_the_hourly_stats(): void
    {
        $today = now('UTC')->toDateString();

        AnalyticsStatsHourly::factory()->create([
            'hour_start' => $today.' 14:00:00',
            'visitor_type' => 'guest',
            'visits' => 9,
            'visitors' => 9,
            'pageviews' => 18,
        ]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/patterns?preset=today');

        $response->assertOk();

        $afternoon = collect($response->json('data.hour'))->firstWhere('hour', 14);
        $this->assertEqualsWithDelta(9.0, $afternoon['visits'], 0.01);

        $midnight = collect($response->json('data.hour'))->firstWhere('hour', 3);
        $this->assertEqualsWithDelta(0.0, $midnight['visits'], 0.01);
    }

    /**
     * 시간 집계를 언제부터 볼 수 있는지 함께 알려 주는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=patterns, role=read, period=too_long
     *
     * @effects patterns_report_the_hourly_availability_window
     */
    public function test_the_hourly_availability_window_is_reported(): void
    {
        config(['g7_settings.core.analytics.hourly_retention_days' => 30]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/patterns?preset=7d');

        $response->assertOk();
        $this->assertSame(
            now()->subDays(30)->startOfDay()->toDateString(),
            $response->json('data.hourly_available_from'),
            '그보다 이른 구간은 재료가 이미 지워졌다는 사실을 화면이 알아야 한다',
        );
    }

    /**
     * 제외 토글이 패턴에도 적용되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=patterns, role=read, period=valid
     *
     * @effects exclude_toggles_drop_those_visitor_types_from_totals
     */
    public function test_exclude_toggle_applies_to_patterns(): void
    {
        $this->daily('2026-09-07', ['visitor_type' => 'guest', 'visits' => 10]);
        $this->daily('2026-09-07', ['visitor_type' => 'admin', 'visits' => 6]);

        $admin = $this->readAdmin();

        $all = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/patterns?from=2026-09-07&to=2026-09-07');
        $withoutAdmin = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/patterns?from=2026-09-07&to=2026-09-07&exclude[]=admin');

        $mondayAll = collect($all->json('data.weekday'))->firstWhere('dow', 1);
        $mondayFiltered = collect($withoutAdmin->json('data.weekday'))->firstWhere('dow', 1);

        $this->assertEqualsWithDelta(16.0, $mondayAll['visits'], 0.01);
        $this->assertEqualsWithDelta(10.0, $mondayFiltered['visits'], 0.01);
    }

    /**
     * 시간 집계 가능 시작일이 사이트 표준시 일자인지 확인합니다.
     *
     * 이 값은 화면이 안내 문구에 찍고(「N일부터 시간 단위로 볼 수 있습니다」) 기간 시작과
     * 견주어 경고를 띄울지도 정한다. `Carbon::now()` 는 `config('app.timezone')`(UTC)을 따르므로
     * UTC 보다 앞선 사이트에서는 **새벽 시간대에 하루 앞 날짜**가 나오고 경고 분기까지 함께
     * 어긋난다(#682 S3 라운드 7).
     *
     * 사이트 자정 직후(KST 00:30 = 전날 15:30 UTC)를 골라 두 기준이 서로 다른 날짜를 가리키게
     * 만든다 — 같은 날짜가 되는 시각에 재면 이 축이 성립하지 않는다.
     *
     * @return void 없음
     *
     * @scenario endpoint=patterns, role=read, period=valid
     *
     * @effects hourly_availability_boundary_follows_the_site_date
     */
    public function test_hourly_availability_boundary_uses_the_site_date(): void
    {
        config(['g7_settings.core.general.timezone' => 'Asia/Seoul']);
        // 2026-09-21 15:30 UTC = 2026-09-22 00:30 KST — UTC 일자와 사이트 일자가 하루 다르다.
        $this->travelTo(Carbon::parse('2026-09-21 15:30:00', 'UTC'));
        config(['g7_settings.core.analytics.hourly_retention_days' => 7]);

        $availableFrom = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/patterns?preset=7d')
            ->assertOk()
            ->json('data.hourly_available_from');

        // 사이트 일자 2026-09-22 에서 7일 전 = 2026-09-15. UTC 로 세면 2026-09-14 가 나온다.
        $this->assertSame('2026-09-15', $availableFrom, '경계가 UTC 일자로 새면 안내와 경고가 하루 어긋난다');
    }
}
