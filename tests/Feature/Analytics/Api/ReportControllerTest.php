<?php

namespace Tests\Feature\Analytics\Api;

use App\Enums\Analytics\StatsDimension;
use App\Enums\Analytics\VisitorType;
use App\Models\Analytics\AnalyticsBotHit;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Services\Analytics\CsvExporter;
use App\Services\Analytics\ReportService;
use Illuminate\Support\Carbon;

/**
 * 리포트 API — 차원 하나를 골라 읽는 상위 목록과 CSV 내려받기.
 *
 * 인기 페이지·유입처·브라우저·국가가 모두 한 갈래를 탄다. 그래서 한 갈래가 깨지면
 * 화면 여럿이 함께 빈다 — 차원 전수를 한 번씩 찔러 본다.
 */
class ReportControllerTest extends AnalyticsApiTestCase
{
    /**
     * 차원 행을 하나 심습니다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $label  라벨
     * @param  array<string, mixed>  $attributes  덮어쓸 값
     * @return AnalyticsDimensionStat 만들어진 행
     */
    private function dimensionRow(string $dimension, string $label, array $attributes = []): AnalyticsDimensionStat
    {
        return AnalyticsDimensionStat::factory()->create(array_merge([
            'date' => Carbon::now('UTC')->toDateString(),
            'visitor_type' => VisitorType::Guest->value,
            'dimension' => $dimension,
            'label' => $label,
            'label_hash' => md5($label),
            'visits' => 5,
            'hits' => 12,
            'entries' => 2,
            'exits' => 3,
            'bounces' => 1,
            'total_time_seconds' => 600,
        ], $attributes));
    }

    /**
     * 모든 차원 이름과 별칭이 응답하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects every_report_dimension_answers
     */
    public function test_every_dimension_and_alias_answers(): void
    {
        $admin = $this->readAdmin();

        // 개수를 함께 잠근다 — enum 에서 차원이 빠져도 순회만 하면 계속 green 이라
        // 계획서가 못박은 25차원이 회귀 가드가 되지 못한다.
        $this->assertCount(25, StatsDimension::values(), '차원이 늘거나 줄면 이 숫자와 화면 목록을 함께 고친다');

        foreach (StatsDimension::values() as $dimension) {
            $this->actingAs($admin)
                ->getJson('/api/admin/analytics/reports/'.$dimension.'?preset=today')
                ->assertOk();
        }

        foreach (['entry_pages', 'exit_pages', 'bot_pages', 'goals'] as $alias) {
            $this->actingAs($admin)
                ->getJson('/api/admin/analytics/reports/'.$alias.'?preset=today')
                ->assertOk();
        }
    }

    /**
     * 없는 리포트 이름이 404 인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects unknown_report_is_not_found
     */
    public function test_unknown_report_is_not_found(): void
    {
        $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/there_is_no_such_thing')
            ->assertStatus(404);
    }

    /**
     * 행이 화면용 라벨과 파생 지표를 함께 내는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects report_rows_carry_display_labels
     */
    public function test_rows_carry_display_labels_and_derived_metrics(): void
    {
        $this->dimensionRow(StatsDimension::Country->value, 'KR');

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/country?preset=today');

        $response->assertOk();
        $row = $response->json('data.data.0');

        $this->assertSame('KR', $row['label'], '저장된 라벨은 기계가 읽는 값이다');
        $this->assertNotSame('KR', $row['label_display'], '화면에는 사람이 읽는 이름이 나가야 한다');

        // 국가명은 보는 사람의 언어로 나와야 한다. 화면용 문자열을 만드는 자리는
        // ReportService 이고, 로케일은 조회 옵션으로 넘어간다 — 두 로케일이 같은
        // 문자열이면 번역이 붙지 않은 것이다.
        $service = app(ReportService::class);
        $today = now('UTC')->toDateString();

        $labels = [];

        foreach (['ko', 'en'] as $locale) {
            $labels[$locale] = $service->report('country', $today, $today, [
                'locale' => $locale,
            ])['rows'][0]['label_display'];
        }

        $this->assertSame('한국', $labels['ko']);
        $this->assertSame('South Korea', $labels['en']);
        $this->assertEqualsWithDelta(20.0, $row['bounce_rate'], 0.01, '이탈 1 / 방문 5');
        $this->assertSame(120, $row['avg_time'], '체류 600초 / 방문 5');
        $this->assertEqualsWithDelta(2.4, $row['pages_per_visit'], 0.01, '조회 12 / 방문 5');
    }

    /**
     * 봇 리포트가 봇 유형 행을 읽는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects bot_reports_read_bot_typed_rows
     */
    public function test_bot_report_reads_bot_typed_rows(): void
    {
        $this->dimensionRow(StatsDimension::BotName->value, 'Googlebot', [
            'visitor_type' => VisitorType::Bot->value,
        ]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/bots?preset=today');

        $response->assertOk();
        $this->assertSame('Googlebot', $response->json('data.data.0.label'), '봇 행은 사람 유형으로 물으면 영영 비어 있다');
    }

    /**
     * CSV 내려받기가 엑셀이 읽을 모양인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, format=csv
     *
     * @effects csv_export_starts_with_a_byte_order_mark, csv_export_uses_crlf_line_endings
     */
    public function test_csv_export_is_readable_by_excel(): void
    {
        $this->dimensionRow(StatsDimension::PageUrl->value, 'example.test/자유게시판');

        $response = $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/reports/page_url?preset=today&format=csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'analytics-page_url-',
            (string) $response->headers->get('content-disposition'),
        );

        $body = $response->streamedContent();

        $this->assertStringStartsWith(CsvExporter::BOM, $body, 'BOM 이 없으면 엑셀에서 한글이 전부 깨진다');
        $this->assertStringContainsString("\r\n", $body);
        $this->assertStringContainsString('label,label_display,visits', $body);
        $this->assertStringContainsString('자유게시판', $body);
    }

    /**
     * 동점 라벨이 쪽 경계에 걸쳐도 CSV 가 한 줄도 잃거나 겹치지 않는지 확인합니다.
     *
     * CSV 는 쪽마다 **독립 쿼리**를 던진다. 정렬이 `visits` 하나뿐이면 동점 구간의
     * 순서가 쿼리마다 달라질 수 있고, 그때 어떤 라벨은 두 번 나오고 어떤 라벨은
     * 아예 빠진다. 예외도 로그도 없이 운영자가 받은 표만 조용히 틀린다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, format=csv
     *
     * @effects csv_export_covers_the_whole_period_not_one_page
     */
    public function test_csv_export_keeps_every_tied_row_across_page_boundaries(): void
    {
        // per_page 는 서비스가 100 으로 잠근다 — 250건이면 세 쪽에 걸친다.
        $labels = [];

        for ($i = 0; $i < 250; $i++) {
            $label = sprintf('example.test/tie-%03d', $i);
            $labels[] = $label;
            $this->dimensionRow(StatsDimension::PageUrl->value, $label, ['visits' => 5]);
        }

        $response = $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/reports/page_url?preset=today&format=csv');

        $response->assertOk();

        $body = $response->streamedContent();
        $exported = [];

        foreach (explode("\r\n", trim($body)) as $index => $line) {
            if ($index === 0 || $line === '') {
                continue;
            }

            $exported[] = explode(',', $line)[0];
        }

        $this->assertCount(
            count($labels),
            $exported,
            '내보낸 줄 수가 원본과 다르다 — 동점 구간에서 줄이 빠지거나 겹쳤다',
        );
        $this->assertSame(
            count($exported),
            count(array_unique($exported)),
            '같은 라벨이 두 번 실렸다 — 쪽 경계에서 정렬이 흔들렸다',
        );
        $this->assertEqualsCanonicalizing($labels, $exported, '원본 라벨 집합과 일치해야 한다');
    }

    /**
     * 쪽 나누기와 검색이 동작하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects every_report_dimension_answers
     */
    public function test_paging_and_search_narrow_the_rows(): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $index => $label) {
            $this->dimensionRow(StatsDimension::Browser->value, $label, ['visits' => 10 - $index]);
        }

        $admin = $this->readAdmin();

        $firstPage = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/reports/browser?preset=today&per_page=2');
        $this->assertCount(2, $firstPage->json('data.data'));
        $this->assertTrue($firstPage->json('data.pagination.has_more'));

        $searched = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/reports/browser?preset=today&q=bet');
        $this->assertCount(1, $searched->json('data.data'));
        $this->assertSame('beta', $searched->json('data.data.0.label'));
    }

    /**
     * 요일·시간대 패턴이 합이 아니라 평균인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=patterns, role=read, period=valid
     *
     * @effects patterns_average_instead_of_summing
     */
    public function test_patterns_return_averages_with_their_divisor(): void
    {
        // 같은 요일(월) 두 날에 방문 10 씩 — 합은 20 이지만 평균은 10 이어야 한다.
        $this->daily('2026-09-07', ['visits' => 10, 'visitors' => 10, 'pageviews' => 20]);
        $this->daily('2026-09-14', ['visits' => 10, 'visitors' => 10, 'pageviews' => 20]);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/patterns?from=2026-09-07&to=2026-09-14');

        $response->assertOk();

        $monday = collect($response->json('data.weekday'))->firstWhere('dow', 1);

        $this->assertSame(2, $monday['days'], '그 요일이 기간에 몇 번 들어 있었는지를 함께 낸다');
        $this->assertEqualsWithDelta(10.0, $monday['visits'], 0.01, '합계로 내면 자주 온 요일이 더 붐비는 것처럼 보인다');
        $this->assertArrayHasKey('hourly_available_from', $response->json('data'));
    }

    /**
     * 봇 응답이 최근 요청 목록을 함께 내는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects bot_report_carries_recent_hits
     */
    public function test_the_bot_report_carries_recent_hits(): void
    {
        $this->dimensionRow(StatsDimension::BotName->value, 'Googlebot', [
            'visitor_type' => VisitorType::Bot->value,
        ]);

        AnalyticsBotHit::factory()->create([
            'bot_name' => 'Googlebot',
            'url' => '/pricing',
            'cache_status' => 'miss',
            'occurred_at' => Carbon::now('UTC'),
        ]);

        $hits = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/bots?preset=today')
            ->assertOk()
            ->json('data.recent_hits');

        // 집계는 「어느 봇이 얼마나」까지만 답한다. 어떤 주소를 언제 긁어 갔는지가
        // 없으면 운영자는 차단할지 말지 판단할 근거가 없다.
        $this->assertIsArray($hits, '봇 화면은 집계 표 아래에 최근 요청을 함께 깐다');
        $this->assertCount(1, $hits);
        $this->assertSame('Googlebot', $hits[0]['bot_name']);
        $this->assertSame('/pricing', $hits[0]['url']);
        $this->assertSame('miss', $hits[0]['cache_status']);
    }

    /**
     * 리포트 행이 차원별 부가 값을 싣는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects report_rows_carry_dimension_extra_values
     */
    public function test_report_rows_carry_extra_values(): void
    {
        $today = Carbon::now('UTC')->toDateString();
        $yesterday = Carbon::now('UTC')->subDay()->toDateString();

        // 같은 검색어가 이틀에 걸쳐 쌓였다 — 「결과 없음」은 기간 내내 더해져야 한다.
        $this->dimensionRow(StatsDimension::SiteSearch->value, '배송비', [
            'date' => $yesterday,
            'extra' => ['no_result' => 2],
        ]);
        $this->dimensionRow(StatsDimension::SiteSearch->value, '배송비', [
            'date' => $today,
            'extra' => ['no_result' => 3],
        ]);

        $row = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/site_search?from='.$yesterday.'&to='.$today)
            ->assertOk()
            ->json('data.data.0');

        // 집계 표에 있는 값을 응답에서 버리면 화면이 「결과 없음」 열을 채울 길이 없다.
        $this->assertSame(5, $row['extra']['no_result'], '부가 값은 기간 내내 키별로 더해진다');
    }

    /**
     * 부가 값이 없는 차원도 같은 모양을 내는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects report_rows_without_extra_stay_shaped
     */
    public function test_rows_without_extra_values_stay_shaped(): void
    {
        $this->dimensionRow(StatsDimension::Browser->value, 'Chrome');

        $row = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/browser?preset=today')
            ->assertOk()
            ->json('data.data.0');

        // 차원마다 키가 있다 없다 하면 화면이 매번 존재를 확인해야 한다.
        $this->assertSame([], $row['extra']);
    }
}
