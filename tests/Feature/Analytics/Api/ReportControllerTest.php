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
     * 페이지 라벨이 화면에서 읽히는 글자로 나가는지 확인합니다.
     *
     * 저장 라벨은 기계가 읽는 값이라 인코딩된 채 두고, 화면용 라벨만 풉니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects page_labels_are_readable_for_display
     */
    public function test_page_labels_are_readable_for_display(): void
    {
        config(['g7_settings.core.general.site_url' => 'https://example.test']);
        $raw = 'example.test/search?q=%ED%85%8C%EC%8A%A4%ED%8A%B8';
        $this->dimensionRow(StatsDimension::PageUrl->value, $raw);

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/page_url?preset=today');

        $response->assertOk();
        $this->assertSame($raw, $response->json('data.data.0.label'), '저장 라벨은 그대로');
        $this->assertSame('/search?q=테스트', $response->json('data.data.0.label_display'));
    }

    /**
     * 화면에 보이는 글자로 찾아도 인코딩된 저장 라벨이 잡히는지 확인합니다.
     *
     * 라벨을 풀어서 보여 주는데 검색이 저장값만 보면, 화면의 「/search?q=테스트」를 「테스트」로
     * 찾았을 때 0건이 된다(브라우저 실측 §8 M3-T5). 검색어의 LIKE 와일드카드는 글자 그대로 찾는다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects page_labels_are_readable_for_display, report_search_matches_what_the_screen_shows
     */
    public function test_search_matches_the_decoded_page_label(): void
    {
        $this->dimensionRow(StatsDimension::PageUrl->value, 'example.test/search?q=%ED%85%8C%EC%8A%A4%ED%8A%B8');
        $this->dimensionRow(StatsDimension::PageUrl->value, 'example.test/board/free');

        $admin = $this->readAdmin();
        $labels = fn (string $q): array => array_column(
            $this->actingAs($admin)->getJson('/api/admin/analytics/reports/page_url?preset=today&q='.rawurlencode($q))->json('data.data'),
            'label',
        );

        $this->assertSame(['example.test/search?q=%ED%85%8C%EC%8A%A4%ED%8A%B8'], $labels('테스트'), '화면에 보이는 글자로 찾는다');
        $this->assertSame(['example.test/search?q=%ED%85%8C%EC%8A%A4%ED%8A%B8'], $labels('search?q=테스트'), '주소 모양 그대로 찾아도 된다');
        $this->assertSame(['example.test/board/free'], $labels('board'), '영문 검색은 종전대로');
        $this->assertSame([], $labels('_'), '밑줄은 한 글자 와일드카드가 아니라 글자 그대로');
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
     * 사람 방문을 빼는 제외 토글이 봇 목록을 비우지 않는지 확인합니다.
     *
     * 제외 토글은 기간 쿼리와 함께 모든 통계 화면으로 따라다닌다. 봇 차원에 사람 유형
     * 목록을 걸면 봇 행이 하나도 남지 않는다 — 별칭 경로(`bot_pages`)까지 같은 규칙이다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=preset
     *
     * @effects bot_reports_ignore_the_human_exclude_toggle
     */
    public function test_exclude_toggle_does_not_empty_bot_reports(): void
    {
        $this->dimensionRow(StatsDimension::BotName->value, 'Googlebot', [
            'visitor_type' => VisitorType::Bot->value,
        ]);
        $this->dimensionRow(StatsDimension::BotPage->value, '/board/free', [
            'visitor_type' => VisitorType::Bot->value,
        ]);

        $admin = $this->readAdmin();

        $bots = $this->actingAs($admin)->getJson('/api/admin/analytics/bots?preset=today&exclude[]=admin');
        $bots->assertOk();
        $this->assertSame('Googlebot', $bots->json('data.data.0.label'), '관리자 제외를 켰다고 봇 목록이 비면 안 된다');

        $pages = $this->actingAs($admin)->getJson('/api/admin/analytics/reports/bot_pages?preset=today&exclude[]=member');
        $pages->assertOk();
        $this->assertSame('/board/free', $pages->json('data.data.0.label'));
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
     * 검색어 CSV 가 「결과 없음」 열을 함께 내는지 확인합니다.
     *
     * 이 값은 집계가 `extra` 에 담아 두고 화면이 읽는다. CSV 에서만 빠지면 운영자는
     * 「찾았는데 없는 것」을 엑셀로 가져갈 수 없고, 내려받은 파일이 틀린 것인지
     * 화면이 틀린 것인지 판단할 근거도 없다(부록 D).
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, report=site_search, role=read, format=csv
     *
     * @effects csv_export_includes_per_report_extra_columns
     */
    public function test_site_search_csv_carries_the_no_result_column(): void
    {
        $this->dimensionRow(StatsDimension::SiteSearch->value, '환불 규정', ['extra' => ['no_result' => 4]]);

        $response = $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/reports/site_search?preset=today&format=csv');

        $response->assertOk();

        $body = $response->streamedContent();

        $this->assertStringContainsString('pages_per_visit,no_result_count', $body);
        // 값 자체가 실려야 한다 — 헤더만 있고 0 이 나오면 열을 붙인 의미가 없다.
        $this->assertMatchesRegularExpression('/환불 규정.*,4\r\n/u', $body);
    }

    /**
     * 목표 CSV 가 전환 금액 합계 열을 함께 내는지 확인합니다.
     *
     * 집계는 `extra.revenue_cents` 로 센트를 담아 둔다 — `extra` 가 정수 칸이라
     * 소수점을 그대로 넣으면 반올림으로 값이 샌다. 내보낼 때 사람이 쓰는 단위로
     * 되돌려야 운영자가 엑셀에서 그대로 더할 수 있다(부록 D `goal` + `revenue_sum`).
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, report=goal, role=read, format=csv
     *
     * @effects csv_export_includes_per_report_extra_columns
     */
    public function test_goal_csv_carries_the_revenue_sum_column(): void
    {
        $this->dimensionRow(StatsDimension::Goal->value, 'core.signup', ['extra' => ['revenue_cents' => 1234567]]);

        $response = $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/reports/goal?preset=today&format=csv');

        $response->assertOk();

        $body = $response->streamedContent();

        $this->assertStringContainsString('pages_per_visit,revenue_sum', $body);
        // 센트를 그대로 흘리면 12345.67 이 1234567 로 보여 매출이 백 배가 된다.
        $this->assertMatchesRegularExpression('/core\.signup.*,12345\.67\r\n/u', $body);
    }

    /**
     * 별칭 주소로 받은 CSV 도 같은 부가 열을 내는지 확인합니다.
     *
     * `reports/goals` 는 `reports/goal` 의 별칭이라 화면은 둘 중 아무 주소나 쓴다.
     * 부가 열 표가 차원 이름으로만 키를 잡으면 별칭으로 받은 파일에서만 매출 열이
     * 조용히 빠져, 운영자는 같은 화면에서 받은 두 파일이 다른 이유를 알 수 없다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, report=goal, role=read, format=csv
     *
     * @effects csv_export_includes_per_report_extra_columns
     */
    public function test_alias_report_csv_carries_the_same_extra_columns(): void
    {
        $this->dimensionRow(StatsDimension::Goal->value, 'core.signup', ['extra' => ['revenue_cents' => 1234567]]);

        $response = $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/reports/goals?preset=today&format=csv');

        $response->assertOk();

        $body = $response->streamedContent();

        $this->assertStringContainsString('pages_per_visit,revenue_sum', $body);
        $this->assertMatchesRegularExpression('/core\.signup.*,12345\.67\r\n/u', $body);
    }

    /**
     * 부가 열이 없는 차원은 공통 9열만 내는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, report=page_url, role=read, format=csv
     *
     * @effects csv_export_includes_per_report_extra_columns
     */
    public function test_other_reports_keep_the_nine_common_columns(): void
    {
        $this->dimensionRow(StatsDimension::PageUrl->value, 'example.test/공지');

        $response = $this->actingAs($this->readAdmin())
            ->get('/api/admin/analytics/reports/page_url?preset=today&format=csv');

        $body = $response->streamedContent();

        $this->assertStringContainsString("pages_per_visit\r\n", $body);
        $this->assertStringNotContainsString('no_result_count', $body);
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
     * 상세 목록 건수 설정이 실제로 먹는지 확인합니다.
     *
     * 기본값과 같은 숫자를 코드에 박아 두면 설정을 바꿔도 목록이 그대로여서, 저장은
     * 되는데 안 먹는 설정이 됩니다 — 기본값이 아닌 값으로 재야 그 어긋남이 드러납니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=valid
     *
     * @effects detail_top_n_setting_drives_the_page_size
     */
    public function test_detail_top_n_setting_decides_the_page_size(): void
    {
        config(['g7_settings.core.analytics.top_n_detail' => 3]);

        foreach (range(1, 6) as $index) {
            $this->dimensionRow(StatsDimension::Browser->value, 'browser-'.$index, ['visits' => 10 - $index]);
        }

        $response = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/reports/browser?preset=today');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.data'), '설정한 건수만큼만 한 쪽에 담긴다');
        $this->assertTrue($response->json('data.pagination.has_more'), '나머지는 다음 쪽으로 넘어간다');
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
     * 봇 화면의 기간 합계가 쪽과 무관하게 기간 전체를 세는지 확인합니다.
     *
     * 목록은 쪽으로 잘려 오므로 그 행을 더한 KPI 는 봇 종류가 한 쪽보다 많은 기간에 첫 쪽의
     * 합만 보였다(#682 S3 라운드 14). 사람 유형 행은 봇 합계에 섞이지 않는다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=read, period=preset
     *
     * @effects bot_totals_count_the_whole_period_not_the_page
     */
    public function test_bot_totals_count_the_whole_period_not_the_page(): void
    {
        foreach (range(1, 4) as $i) {
            $this->dimensionRow(StatsDimension::BotName->value, "Bot{$i}", [
                'visitor_type' => VisitorType::Bot->value,
                'hits' => 10 * $i,
            ]);
        }
        // 같은 봇의 어제 행 — 라벨 수는 그대로, 요청 수만 더해진다.
        $this->dimensionRow(StatsDimension::BotName->value, 'Bot1', [
            'visitor_type' => VisitorType::Bot->value,
            'date' => Carbon::now('UTC')->subDay()->toDateString(),
            'hits' => 7,
        ]);
        $this->dimensionRow(StatsDimension::BotName->value, 'NotABot', ['hits' => 999]);

        $admin = $this->readAdmin();

        foreach ([1, 2] as $page) {
            $response = $this->actingAs($admin)
                ->getJson("/api/admin/analytics/bots?preset=7d&per_page=2&page={$page}")
                ->assertOk();

            $this->assertCount(2, $response->json('data.data'), '쪽은 두 행씩 잘린다');
            $this->assertSame(
                ['hits' => 107, 'labels' => 4],
                $response->json('data.totals'),
                "{$page} 쪽에서도 합계는 기간 전체(봇 4종 · 10+20+30+40+7)여야 한다",
            );
        }
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
