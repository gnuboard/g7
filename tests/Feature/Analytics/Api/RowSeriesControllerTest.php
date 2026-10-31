<?php

namespace Tests\Feature\Analytics\Api;

use App\Analytics\AnalyticsClock;
use App\Analytics\UrlNormalizer;
use App\Enums\Analytics\VisitorType;
use App\Models\Analytics\AnalyticsDimensionStat;
use Illuminate\Support\Carbon;

/**
 * 한 항목 추이 API — 리포트 목록 한 줄의 날짜별 추이.
 *
 * 목록은 기간 합계만 말한다. 추이 창이 엉뚱한 라벨을 합치거나 빈 날을 건너뛰면 「언제부터 늘었나」가
 * 거짓이 된다.
 */
class RowSeriesControllerTest extends AnalyticsApiTestCase
{
    /**
     * 차원 행을 하나 심습니다.
     *
     * @param  string  $date  일자 (Y-m-d)
     * @param  string  $dimension  차원 이름
     * @param  string  $label  라벨
     * @param  array<string, mixed>  $attributes  덮어쓸 값
     * @return AnalyticsDimensionStat 만들어진 행
     */
    private function row(string $date, string $dimension, string $label, array $attributes = []): AnalyticsDimensionStat
    {
        return AnalyticsDimensionStat::factory()->create(array_merge([
            'date' => $date,
            'visitor_type' => VisitorType::Guest->value,
            'dimension' => $dimension,
            'label' => $label,
            'label_hash' => md5($label),
            'visits' => 4,
            'hits' => 6,
            'entries' => 0,
            'exits' => 0,
            'bounces' => 1,
            'total_time_seconds' => 400,
        ], $attributes));
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
     * 라벨 하나의 날짜별 합계를 유형 행을 더해 내고, 빈 날은 0 으로 채우는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=row_series, period=7d, data=present
     *
     * @effects row_series_sums_label_per_day
     */
    public function test_label_is_summed_per_day_with_empty_days_filled(): void
    {
        $label = 'example.test/board/qna';
        $this->row($this->daysAgo(1), 'page_url', $label);
        $this->row($this->daysAgo(1), 'page_url', $label, ['visitor_type' => VisitorType::Member->value, 'visits' => 1, 'hits' => 1]);
        $this->row($this->daysAgo(3), 'page_url', $label, ['visits' => 2, 'hits' => 2]);
        // 다른 라벨·다른 차원은 섞이지 않는다
        $this->row($this->daysAgo(1), 'page_url', 'example.test/other', ['visits' => 99]);
        $this->row($this->daysAgo(1), 'entry_page', $label, ['visits' => 77]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/page_url/series?preset=7d&label='.urlencode($label))
            ->assertOk();

        $points = collect($response->json('data.points'))->keyBy('ts');
        $this->assertSame('day', $response->json('data.unit'));
        $this->assertFalse($response->json('data.unit_downgraded'));
        $this->assertCount(7, $points);
        $this->assertSame(5, $points[$this->daysAgo(1)]['visits']);
        $this->assertSame(7, $points[$this->daysAgo(1)]['hits']);
        $this->assertSame(2, $points[$this->daysAgo(3)]['visits']);
        $this->assertSame(0, $points[$this->daysAgo(2)]['visits']);
        $this->assertSame($label, $response->json('data.label'));
        $this->assertSame(UrlNormalizer::forDisplay($label), $response->json('data.label_display'), '목록과 같은 풀이(주소 풀기)');
    }

    /**
     * 92일까지는 일 단위, 93일부터는 주 단위인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=row_series, period=boundary
     *
     * @effects row_series_unit_follows_range_length
     */
    public function test_unit_switches_from_day_to_week_after_92_days(): void
    {
        $admin = $this->readAdmin();
        $this->row($this->daysAgo(1), 'browser', 'Chrome');

        $to = AnalyticsClock::today();

        $day = $this->actingAs($admin)->getJson('/api/admin/analytics/reports/browser/series?label=Chrome&from='.$this->daysAgo(91).'&to='.$to);
        $week = $this->actingAs($admin)->getJson('/api/admin/analytics/reports/browser/series?label=Chrome&from='.$this->daysAgo(92).'&to='.$to);

        $this->assertSame('day', $day->json('data.unit'));
        $this->assertSame('week', $week->json('data.unit'));
    }

    /**
     * 시간 단위를 달라고 하면 일 단위로 낮추고 그 사실을 알리는지 확인합니다(차원 집계는 일 단위뿐).
     *
     * @return void 없음
     *
     * @scenario endpoint=row_series, unit=hour
     *
     * @effects row_series_downgrades_hour_to_day
     */
    public function test_hour_unit_is_downgraded(): void
    {
        $this->row($this->daysAgo(1), 'browser', 'Chrome');

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/browser/series?preset=7d&unit=hour&label=Chrome')
            ->assertOk();

        $this->assertSame('day', $response->json('data.unit'));
        $this->assertTrue($response->json('data.unit_downgraded'));
    }

    /**
     * 없는 리포트는 404, 라벨이 없으면 422 인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=row_series, input=invalid
     *
     * @effects row_series_rejects_unknown_report_and_missing_label
     */
    public function test_unknown_report_and_missing_label_are_rejected(): void
    {
        $admin = $this->readAdmin();

        $this->actingAs($admin)->getJson('/api/admin/analytics/reports/nope/series?label=x')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/admin/analytics/reports/browser/series?preset=7d')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label']);
    }

    /**
     * 봇 차원은 봇 유형 행을 읽고, 제외 토글은 사람 유형을 뺀 채 적용되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=row_series, dimension=bot
     *
     * @effects row_series_reads_bot_rows_for_bot_dimensions
     */
    public function test_bot_dimension_reads_bot_rows_and_exclude_applies(): void
    {
        $admin = $this->readAdmin();
        $this->row($this->daysAgo(1), 'bot_name', 'Googlebot', ['visitor_type' => VisitorType::Bot->value, 'visits' => 3]);
        $this->row($this->daysAgo(1), 'browser', 'Chrome', ['visits' => 4]);
        $this->row($this->daysAgo(1), 'browser', 'Chrome', ['visitor_type' => VisitorType::Admin->value, 'visits' => 2]);

        $bots = $this->actingAs($admin)->getJson('/api/admin/analytics/reports/bot_name/series?preset=7d&label=Googlebot');
        $this->assertSame(3, collect($bots->json('data.points'))->sum('visits'));

        $excluded = $this->actingAs($admin)->getJson('/api/admin/analytics/reports/browser/series?preset=7d&label=Chrome&exclude[]=admin');
        $this->assertSame(4, collect($excluded->json('data.points'))->sum('visits'));
    }

    /**
     * 통계 화면에서 켠 제외 토글이 봇 화면으로 따라와도 봇 추이가 비지 않는지 확인합니다 — 목록과 같은 규약.
     *
     * @return void 없음
     *
     * @scenario endpoint=row_series, dimension=bot, exclude=carried
     *
     * @effects row_series_bot_dimension_ignores_exclude
     */
    public function test_bot_dimension_ignores_carried_exclude(): void
    {
        $this->row($this->daysAgo(1), 'bot_name', 'Googlebot', ['visitor_type' => VisitorType::Bot->value, 'visits' => 3]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/bot_name/series?preset=7d&label=Googlebot&exclude[]=admin')
            ->assertOk();

        $this->assertSame(3, collect($response->json('data.points'))->sum('visits'));
    }

    /**
     * 확장 차원(`ext:{식별자}:{키}`)도 집계 행이 있으면 추이를 낸다.
     *
     * @return void 없음
     *
     * @scenario endpoint=row_series, dimension=extension
     *
     * @effects row_series_supports_extension_dimensions
     */
    public function test_extension_dimension_with_rows_answers(): void
    {
        $this->row($this->daysAgo(1), 'ext:sirsoft-board:board', 'qna', ['visits' => 6]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/'.urlencode('ext:sirsoft-board:board').'/series?preset=7d&label=qna')
            ->assertOk();

        $this->assertSame(6, collect($response->json('data.points'))->sum('visits'));
    }

    /**
     * 기간에 기록이 하나도 없으면 빈 목록을 돌려주는지 확인합니다 — 화면이 「기록이 없습니다」를 띄운다.
     *
     * @return void 없음
     *
     * @scenario endpoint=row_series, data=empty
     *
     * @effects row_series_is_empty_without_rows
     */
    public function test_empty_period_returns_empty_points(): void
    {
        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/browser/series?preset=7d&label=Nothing')
            ->assertOk();

        $this->assertSame([], $response->json('data.points'));
    }
}
