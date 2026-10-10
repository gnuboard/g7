<?php

namespace Tests\Feature\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\StatsDimension;
use App\Enums\Analytics\VisitorType;
use App\Enums\Analytics\VisitSource;
use App\Events\GenericBroadcastEvent;
use App\Extension\HookManager;
use App\Http\Middleware\Analytics\TrackServerVisit;
use App\Jobs\Analytics\ArchiveRangeJob;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsBotHit;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsStatsHourly;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\ArchiveCollector;
use App\Services\Analytics\ArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 집계 — 원장이 집계 표가 되는 과정.
 *
 * 집계는 화면이 보는 유일한 숫자다. 여기서 한 자리가 어긋나면 운영자는 그 사실을
 * 영영 모른 채 틀린 숫자를 본다. 그래서 「몇 건이 어떤 값으로 적혔는가」를 검산한다.
 */
class ArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    private ArchiveService $service;

    /**
     * 테스트 준비 — 서비스 해석과 표준시 고정.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 집계 경계는 사이트 표준시가 정한다 — 검산을 단순하게 두려고 UTC 로 고정한다.
        // 표준시가 다를 때의 거동은 ArchiveTimezoneTest 가 따로 잠근다.
        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'UTC']);
        $this->service = app(ArchiveService::class);
    }

    /**
     * 이름 사전 행을 만듭니다.
     *
     * @param  string  $type  이름 종류
     * @param  string  $name  이름
     * @return AnalyticsActionName 사전 행
     */
    private function actionName(string $type, string $name): AnalyticsActionName
    {
        return AnalyticsActionName::query()->firstOrCreate(
            ['type' => $type, 'name_hash' => md5($type.'|'.$name)],
            ['name' => $name],
        );
    }

    /**
     * 방문 하나와 페이지뷰 액션을 만듭니다.
     *
     * @param  string  $date  방문 일자 (Y-m-d)
     * @param  array<string, mixed>  $attributes  방문 속성 덮어쓰기
     * @param  array<int, string>  $pages  페이지뷰 URL 목록
     * @return AnalyticsVisit 만들어진 방문
     */
    private function visit(string $date, array $attributes = [], array $pages = ['example.test/']): AnalyticsVisit
    {
        $first = Carbon::parse($date.' 01:00:00', 'UTC');

        $entry = $this->actionName('url', $pages[0]);
        $exit = $this->actionName('url', $pages[count($pages) - 1]);

        $visit = AnalyticsVisit::factory()->create(array_merge([
            'visit_date' => $date,
            'first_action_at' => $first,
            'last_action_at' => $first->copy()->addMinutes(5),
            'visitor_type' => VisitorType::Guest,
            'source' => VisitSource::Beacon,
            'actions_count' => count($pages),
            'pageviews_count' => count($pages),
            'total_time_seconds' => 120,
            'is_bounce' => count($pages) === 1,
            'entry_action_name_id' => $entry->id,
            'exit_action_name_id' => $exit->id,
            'is_returning' => false,
        ], $attributes));

        foreach ($pages as $offset => $page) {
            AnalyticsAction::factory()->create([
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'action_type' => ActionType::Pageview,
                'action_name_id' => $this->actionName('url', $page)->id,
                'occurred_at' => $first->copy()->addMinutes($offset),
                'time_spent_seconds' => 60,
            ]);
        }

        return $visit;
    }

    /**
     * 세 방문자 유형이 각각 자기 일 집계 행을 얻고 지표가 검산과 맞는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects daily_rows_are_written_per_visitor_type, daily_metrics_match_hand_checked_ledger, unique_visitors_are_counted_by_key_not_summed, bounce_counts_only_single_pageview_visits
     */
    public function test_daily_rows_are_written_per_visitor_type_with_checked_metrics(): void
    {
        $date = '2026-09-10';

        $this->visit($date, ['visitor_type' => VisitorType::Guest, 'total_time_seconds' => 100]);
        $this->visit($date, ['visitor_type' => VisitorType::Guest, 'total_time_seconds' => 50], ['example.test/a', 'example.test/b']);
        $this->visit($date, ['visitor_type' => VisitorType::Member, 'total_time_seconds' => 30]);
        $this->visit($date, ['visitor_type' => VisitorType::Admin, 'total_time_seconds' => 10]);

        $this->service->archiveDay($date, ['is_final' => true]);

        $guest = AnalyticsStatsDaily::query()->where('date', $date)->where('visitor_type', 'guest')->first();
        $this->assertNotNull($guest);
        $this->assertSame(2, (int) $guest->visits);
        $this->assertSame(2, (int) $guest->visitors);
        $this->assertSame(3, (int) $guest->pageviews);
        $this->assertSame(3, (int) $guest->actions);
        $this->assertSame(1, (int) $guest->bounces, '페이지뷰 1건짜리 방문만 이탈이다');
        $this->assertSame(150, (int) $guest->total_time_seconds);
        $this->assertTrue((bool) $guest->is_final);

        $this->assertSame(1, (int) AnalyticsStatsDaily::query()->where('date', $date)->where('visitor_type', 'member')->value('visits'));
        $this->assertSame(1, (int) AnalyticsStatsDaily::query()->where('date', $date)->where('visitor_type', 'admin')->value('visits'));
        $this->assertSame(3, AnalyticsStatsDaily::query()->where('date', $date)->count(), '방문자 유형별 한 행씩만 적힌다');
    }

    /**
     * 쿠키 모드에서 신규·재방문자 수가 채워지고 익명 모드에서는 비워지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects new_and_returning_are_null_without_cookie_mode
     */
    public function test_new_and_returning_counts_are_null_when_mode_has_no_cookie(): void
    {
        $date = '2026-09-11';

        $this->visit($date, ['is_returning' => false]);
        $this->visit($date, ['is_returning' => true]);

        $this->service->archiveDay($date);

        $row = AnalyticsStatsDaily::query()->where('date', $date)->where('visitor_type', 'guest')->first();
        $this->assertSame(1, (int) $row->new_visitors);
        $this->assertSame(1, (int) $row->returning_visitors);

        $anonymousDate = '2026-09-12';
        $this->visit($anonymousDate, ['is_returning' => null]);

        $this->service->archiveDay($anonymousDate);

        $anonymousRow = AnalyticsStatsDaily::query()->where('date', $anonymousDate)->where('visitor_type', 'guest')->first();
        $this->assertNull($anonymousRow->new_visitors, '익명 모드는 재방문을 셀 수 없으므로 0 이 아니라 빈 값이다');
        $this->assertNull($anonymousRow->returning_visitors);
    }

    /**
     * 하루 24칸의 시간 집계가 만들어지고 액션이 자기 시각에 들어가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=hour, data=single_type, trigger=scheduled
     *
     * @effects hourly_rows_use_site_local_hours, visit_count_lands_only_in_the_hour_the_visit_started, hourly_visitors_are_counted_from_actions_not_visit_start
     */
    public function test_hourly_rows_land_in_the_hour_of_each_action(): void
    {
        $date = '2026-09-13';
        $visit = $this->visit($date);

        AnalyticsAction::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'action_type' => ActionType::Pageview,
            'action_name_id' => $this->actionName('url', 'example.test/late')->id,
            'occurred_at' => Carbon::parse($date.' 05:30:00', 'UTC'),
            'time_spent_seconds' => 15,
        ]);

        $this->service->archiveDay($date);

        $first = AnalyticsStatsHourly::query()->where('hour_start', $date.' 01:00:00')->where('visitor_type', 'guest')->first();
        $this->assertNotNull($first);
        $this->assertSame(1, (int) $first->visits, '방문 수는 방문이 시작한 시각에만 센다');
        $this->assertSame(1, (int) $first->pageviews);

        $late = AnalyticsStatsHourly::query()->where('hour_start', $date.' 05:00:00')->where('visitor_type', 'guest')->first();
        $this->assertNotNull($late);
        $this->assertSame(0, (int) $late->visits, '나중 시각에는 방문 수가 다시 세어지지 않는다');
        $this->assertSame(1, (int) $late->pageviews);
        $this->assertSame(1, (int) $late->visitors);
    }

    /**
     * 방문·액션에서 나오는 차원이 모두 행으로 적히는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects visit_dimensions_are_recorded_for_every_stored_attribute, action_dimensions_are_split_by_action_type, entry_and_exit_pages_become_their_own_dimensions, region_and_city_labels_carry_their_country, zero_result_searches_are_counted_in_extra, repeat_views_of_one_label_count_one_visit_many_hits
     */
    public function test_dimension_rows_cover_visit_and_action_dimensions(): void
    {
        $date = '2026-09-14';
        $visit = $this->visit($date, [
            'referrer_name' => 'google',
            'referrer_keyword' => '그누보드',
            'campaign_name' => 'spring',
            'campaign_source' => 'newsletter',
            'browser_name' => 'Chrome',
            'browser_version' => '128',
            'os_name' => 'Windows',
            'country_code' => 'KR',
            'region_code' => '11',
            'city' => 'Seoul',
        ], ['example.test/a', 'example.test/b']);

        AnalyticsAction::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'action_type' => ActionType::SiteSearch,
            'action_name_id' => $this->actionName('search', '그누보드')->id,
            'occurred_at' => Carbon::parse($date.' 01:10:00', 'UTC'),
            'search_result_count' => 0,
        ]);

        $this->service->archiveDay($date);

        $expected = [
            StatsDimension::PageUrl->value,
            StatsDimension::EntryPage->value,
            StatsDimension::ExitPage->value,
            StatsDimension::ReferrerType->value,
            StatsDimension::ReferrerName->value,
            StatsDimension::ReferrerKeyword->value,
            StatsDimension::Campaign->value,
            StatsDimension::CampaignSource->value,
            StatsDimension::Browser->value,
            StatsDimension::BrowserVersion->value,
            StatsDimension::Os->value,
            StatsDimension::DeviceType->value,
            StatsDimension::Resolution->value,
            StatsDimension::BrowserLanguage->value,
            StatsDimension::Locale->value,
            StatsDimension::Country->value,
            StatsDimension::Region->value,
            StatsDimension::City->value,
            StatsDimension::SiteSearch->value,
        ];

        foreach ($expected as $dimension) {
            $this->assertTrue(
                AnalyticsDimensionStat::query()->where('date', $date)->where('dimension', $dimension)->exists(),
                "차원 {$dimension} 행이 없다",
            );
        }

        $pageRows = AnalyticsDimensionStat::query()->where('date', $date)->where('dimension', StatsDimension::PageUrl->value)->get();
        $this->assertCount(2, $pageRows, '본 페이지 수만큼 라벨 행이 생긴다');
        $this->assertSame(1, (int) $pageRows->first()->visits);

        $search = AnalyticsDimensionStat::query()->where('date', $date)->where('dimension', StatsDimension::SiteSearch->value)->first();
        $this->assertSame(['no_result' => 1], json_decode((string) $search->getRawOriginal('extra'), true));

        $region = AnalyticsDimensionStat::query()->where('date', $date)->where('dimension', StatsDimension::Region->value)->first();
        $this->assertSame('KR/11', $region->label);
    }

    /**
     * 모든 차원(`StatsDimension` 전수)이 재료가 있으면 행을 얻는지 확인합니다.
     *
     * 모집단을 손으로 적으면 차원이 하나 늘어도 케이스 없이 통과한다 — 열거형에서 파생하고,
     * 픽스처가 그 차원의 재료를 만들지 못하면 여기서 red 가 되어 픽스처를 고치게 한다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects every_stats_dimension_gets_rows_from_its_source, page_title_is_recorded_alongside_page_url
     */
    public function test_every_stats_dimension_gets_rows_from_its_source(): void
    {
        $date = '2026-09-14';
        $visit = $this->visit($date, [
            'referrer_name' => 'google',
            'referrer_keyword' => '그누보드',
            'campaign_name' => 'spring',
            'campaign_source' => 'newsletter',
            'campaign_medium' => 'email',
            'browser_name' => 'Chrome',
            'browser_version' => '128',
            'os_name' => 'Windows',
            'screen_resolution' => '1920x1080',
            'browser_language' => 'ko',
            'locale' => 'ko',
            'country_code' => 'KR',
            'region_code' => '11',
            'city' => 'Seoul',
        ], ['example.test/a']);

        $at = Carbon::parse($date.' 01:10:00', 'UTC');
        AnalyticsAction::query()->where('visit_id', $visit->id)->update(['page_title_id' => $this->actionName('title', '첫 화면')->id]);

        foreach ([
            [ActionType::SiteSearch, 'search', '그누보드'],
            [ActionType::Download, 'download', 'report.pdf'],
            [ActionType::Outlink, 'outlink', 'https://other.test/'],
            [ActionType::Event, 'event', 'coupon_click'],
        ] as [$type, $nameType, $name]) {
            AnalyticsAction::factory()->create([
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'action_type' => $type,
                'action_name_id' => $this->actionName($nameType, $name)->id,
                'occurred_at' => $at,
                'search_result_count' => $type === ActionType::SiteSearch ? 3 : null,
                'search_tab' => $type === ActionType::SiteSearch ? 'posts' : null,
            ]);
        }

        AnalyticsConversion::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'goal_key' => 'core.user_logged_in',
            'occurred_at' => $at,
        ]);

        AnalyticsBotHit::factory()->create([
            'bot_name' => 'Googlebot',
            'url' => '/robots-page',
            'occurred_at' => $at,
        ]);

        $this->service->archiveDay($date);

        $dimensions = StatsDimension::values();
        $this->assertNotEmpty($dimensions);

        $missing = array_values(array_filter(
            $dimensions,
            fn (string $dimension): bool => ! AnalyticsDimensionStat::query()->where('date', $date)->where('dimension', $dimension)->exists(),
        ));

        $this->assertSame([], $missing, '재료를 넣었는데 행이 없는 차원');

        // 제목은 URL 과 같은 페이지뷰에서 나오며 따로 센다.
        $title = AnalyticsDimensionStat::query()->where('date', $date)->where('dimension', StatsDimension::PageTitle->value)->first();
        $this->assertSame('첫 화면', $title->label);
        $this->assertSame(1, (int) $title->visits);
    }

    /**
     * 다시 집계했을 때 활동이 사라진 시각의 옛 시간 행이 지워지는지 확인합니다.
     *
     * 재집계의 멱등은 「지우고 넣기」다. 재료가 있는 시각만 갈아 끼우면, 원장 정리·정보주체
     * 삭제로 활동이 사라진 시각의 옛 숫자가 영영 남아 시간대 패턴이 부푼다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects hour_slots_with_no_activity_are_cleared
     */
    public function test_hour_slots_with_no_activity_are_cleared_on_rerun(): void
    {
        $date = '2026-09-14';
        $visit = $this->visit($date);

        $this->service->archiveDay($date);
        $this->assertTrue(AnalyticsStatsHourly::query()->where('hour_start', $date.' 01:00:00')->exists());

        // 그 방문이 지워진 뒤(정보주체 삭제 등) 다시 집계한다.
        AnalyticsAction::query()->where('visit_id', $visit->id)->delete();
        $visit->delete();

        $this->service->archiveDay($date);

        $this->assertSame(0, AnalyticsStatsHourly::query()->whereBetween('hour_start', [$date.' 00:00:00', $date.' 23:59:59'])->count());
    }

    /**
     * 리포트 파생 지표가 차원마다 뜻 있는 값을 갖는지 확인합니다.
     *
     * 방문 차원(브라우저·국가·유입 등)은 방문마다 조회 1로 적혀 「방문당 페이지」가 늘 1 이었고, 페이지
     * 차원은 이탈 수를 더하지 않아 「이탈률」이 늘 0% 였다(브라우저 실측 §8 M3-T8). 방문 차원의 조회는 그
     * 방문들의 페이지뷰 합, 페이지 차원의 이탈은 그 페이지 하나만 보고 떠난 방문이다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects visit_dimension_hits_are_the_visits_pageviews, page_dimensions_count_single_page_visits_as_bounces
     */
    public function test_derived_report_metrics_mean_something_per_dimension(): void
    {
        $date = '2026-09-14';
        $this->visit($date, ['browser_name' => 'Chrome'], ['example.test/a', 'example.test/b']);
        $this->visit($date, ['browser_name' => 'Chrome'], ['example.test/a']);

        $this->service->archiveDay($date);

        $row = fn (string $dimension, string $label): AnalyticsDimensionStat => AnalyticsDimensionStat::query()
            ->where('date', $date)->where('dimension', $dimension)->where('label', $label)->firstOrFail();

        $chrome = $row(StatsDimension::Browser->value, 'Chrome');
        $this->assertSame(2, (int) $chrome->visits);
        $this->assertSame(3, (int) $chrome->hits, '방문 차원의 조회는 그 방문들의 페이지뷰 합이다 — 방문당 페이지 1.5');
        $this->assertSame(1, (int) $chrome->bounces);

        $entry = $row(StatsDimension::EntryPage->value, 'example.test/a');
        $this->assertSame(3, (int) $entry->hits, '진입 페이지도 방문 차원이다');

        $a = $row(StatsDimension::PageUrl->value, 'example.test/a');
        $this->assertSame(2, (int) $a->visits);
        $this->assertSame(1, (int) $a->bounces, '이 페이지만 보고 떠난 방문 1건 — 페이지 이탈률 50%');
        $this->assertSame(0, (int) $row(StatsDimension::PageUrl->value, 'example.test/b')->bounces);
    }

    /**
     * 전환 금액이 목표마다 따로 잡히는지 확인합니다.
     *
     * 한 방문이 목표 둘을 달성해도 매출은 자기 목표 행에만 붙어야 합니다. 방문 합계를
     * 목표마다 복사하면 매출이 없는 목표에도 금액이 붙고, 같은 목표가 두 번 일어나면
     * 같은 금액이 두 번 더해집니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=conversions, trigger=manual
     *
     * @effects goal_revenue_is_attributed_per_goal_key
     */
    public function test_goal_revenue_belongs_to_its_own_goal_only(): void
    {
        $date = '2026-09-19';
        $visit = $this->visit($date);

        // 한 방문에서 두 목표 — 하나는 금액이 있고 하나는 없다.
        AnalyticsConversion::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'goal_key' => 'core.purchase',
            'revenue' => 45000.00,
            'occurred_at' => Carbon::parse($date.' 01:02:00', 'UTC'),
        ]);

        AnalyticsConversion::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'goal_key' => 'core.signup',
            'revenue' => 0,
            'occurred_at' => Carbon::parse($date.' 01:03:00', 'UTC'),
        ]);

        $this->service->archiveDay($date);

        $rows = AnalyticsDimensionStat::query()
            ->where('date', $date)
            ->where('dimension', StatsDimension::Goal->value)
            ->get()
            ->keyBy('label');

        $this->assertCount(2, $rows, '목표마다 자기 행이 생긴다');

        $purchase = json_decode((string) $rows['core.purchase']->getRawOriginal('extra'), true);
        $this->assertSame(['revenue_cents' => 4500000], $purchase, '매출은 자기 목표 행에만 붙는다');

        $this->assertNull(
            $rows['core.signup']->getRawOriginal('extra'),
            '매출이 없는 목표에 다른 목표의 금액이 붙으면 안 된다',
        );
    }

    /**
     * 같은 목표가 한 방문에서 두 번 일어나도 금액이 부풀지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=conversions, trigger=manual
     *
     * @effects goal_revenue_is_attributed_per_goal_key
     */
    public function test_repeated_goal_in_one_visit_sums_only_its_own_revenue(): void
    {
        $date = '2026-09-20';
        $visit = $this->visit($date);

        foreach ([10000.00, 5000.00] as $offset => $revenue) {
            AnalyticsConversion::factory()->create([
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'goal_key' => 'core.purchase',
                'revenue' => $revenue,
                'occurred_at' => Carbon::parse($date.' 01:0'.($offset + 2).':00', 'UTC'),
            ]);
        }

        $this->service->archiveDay($date);

        $row = AnalyticsDimensionStat::query()
            ->where('date', $date)
            ->where('dimension', StatsDimension::Goal->value)
            ->where('label', 'core.purchase')
            ->first();

        $this->assertSame(
            ['revenue_cents' => 1500000],
            json_decode((string) $row->getRawOriginal('extra'), true),
            '두 전환의 금액 합계 그대로여야 한다',
        );
        $this->assertSame(2, (int) $row->hits, '전환 두 건이 두 번으로 세어진다');
        $this->assertSame(1, (int) $row->visits, '같은 방문이므로 방문 수는 1이다');
    }

    /**
     * 다시 집계하면 같은 결과가 나오고 사라진 라벨이 남지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects rerun_produces_the_same_rows, labels_that_disappeared_are_removed_on_rerun
     */
    public function test_rerun_is_idempotent_and_removes_labels_that_disappeared(): void
    {
        $date = '2026-09-15';
        $visit = $this->visit($date, [], ['example.test/gone']);

        $this->service->archiveDay($date);

        $this->assertTrue(
            AnalyticsDimensionStat::query()->where('date', $date)->where('label', 'example.test/gone')->exists(),
        );
        $firstDaily = AnalyticsStatsDaily::query()->where('date', $date)->count();

        AnalyticsAction::query()->where('visit_id', $visit->id)->delete();
        AnalyticsVisit::query()->where('id', $visit->id)->delete();
        $this->visit($date, [], ['example.test/kept']);

        $this->service->archiveDay($date);

        $this->assertFalse(
            AnalyticsDimensionStat::query()->where('date', $date)->where('label', 'example.test/gone')->exists(),
            '순위에서 사라진 라벨이 남아 있으면 합계가 실제보다 커진다',
        );
        $this->assertTrue(
            AnalyticsDimensionStat::query()->where('date', $date)->where('label', 'example.test/kept')->exists(),
        );
        $this->assertSame($firstDaily, AnalyticsStatsDaily::query()->where('date', $date)->count());
    }

    /**
     * 청크 크기보다 방문이 많아도 지표가 맞고 청크 수가 기록되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects chunked_reads_cover_every_visit, progress_percent_advances_and_ends_at_full
     */
    public function test_visits_spanning_multiple_chunks_are_all_counted(): void
    {
        config(['g7_settings.core.analytics.archive_chunk_size' => 2]);

        $date = '2026-09-16';

        for ($i = 0; $i < 5; $i++) {
            $this->visit($date);
        }

        $run = $this->service->archiveDay($date);

        $this->assertSame(5, (int) AnalyticsStatsDaily::query()->where('date', $date)->where('visitor_type', 'guest')->value('visits'));
        $this->assertSame(5, (int) $run->processed_visits);
        $this->assertGreaterThan(1, (int) $run->chunks, '청크 크기 2 로 방문 5건이면 청크가 여러 개여야 한다');
        $this->assertSame(100, (int) $run->progress_percent);
    }

    /**
     * 같은 기간을 이미 집계 중이면 건너뛴 이력만 남기는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, lock=held, trigger=scheduled
     *
     * @effects concurrent_run_on_the_same_period_is_skipped
     */
    public function test_run_is_skipped_when_the_same_period_lock_is_held(): void
    {
        $date = '2026-09-17';
        $this->visit($date);

        $lock = Cache::lock(ArchiveService::lockKey($date), 60);
        $this->assertTrue($lock->get());

        try {
            $run = $this->service->archiveDay($date);

            $this->assertSame(ArchiveRunStatus::Skipped->value, $this->statusOf($run));
            $this->assertSame(0, AnalyticsStatsDaily::query()->where('date', $date)->count(), '건너뛴 실행은 집계 표를 건드리지 않는다');
        } finally {
            $lock->release();
        }
    }

    /**
     * 집계 도중 예외가 나면 실패 이력과 훅이 남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=scheduled
     *
     * @effects failure_records_the_run_and_fires_the_alert_hook
     */
    public function test_failure_records_the_run_and_fires_the_hook(): void
    {
        $date = '2026-09-18';
        $this->visit($date);

        $fired = [];
        HookManager::addAction('core.analytics.archive_failed', function ($payload) use (&$fired): void {
            $fired[] = $payload;
        });

        HookManager::addAction('core.analytics.archive.day', function (): void {
            throw new \RuntimeException('집계 중 확장이 던진 예외');
        });

        $run = $this->service->archiveDay($date);

        $this->assertSame(ArchiveRunStatus::Failed->value, $this->statusOf($run));
        $this->assertStringContainsString('집계 중 확장이 던진 예외', (string) $run->error);
        $this->assertCount(1, $fired);
        $this->assertSame($date, $fired[0]['period']);
    }

    /**
     * 집계가 거듭 실패해도 알림은 하루 한 번만 나가는지 확인합니다.
     *
     * 집계는 매시 돈다. 원인이 고쳐지기 전까지 같은 실패 메일이 하루 스물네 통 가면
     * 운영자는 그 알림을 통째로 무시하게 된다. 실행 이력은 매번 남아야 한다.
     *
     * @return void 없음
     *
     * @scenario period=hour, data=all_types, trigger=scheduled
     *
     * @effects the_archive_failure_alert_is_sent_once_a_day
     */
    public function test_repeated_failures_alert_once_a_day(): void
    {
        $date = '2026-09-18';
        $this->visit($date);

        $fired = 0;
        HookManager::addAction('core.analytics.archive_failed', function () use (&$fired): void {
            $fired++;
        });

        HookManager::addAction('core.analytics.archive.day', function (): void {
            throw new \RuntimeException('집계 중 확장이 던진 예외');
        });

        $first = $this->service->archiveDay($date);
        $second = $this->service->archiveDay($date);

        $this->assertSame(ArchiveRunStatus::Failed->value, $this->statusOf($first));
        $this->assertSame(ArchiveRunStatus::Failed->value, $this->statusOf($second));
        $this->assertSame(1, $fired, '같은 실패 알림이 매시 나가면 운영자가 알림 전체를 무시하게 된다');
    }

    /**
     * 기간 재집계 잡이 하루라도 실패하면 잡 자체도 실패로 끝나는지 확인합니다.
     *
     * 서비스는 하루가 실패해도 다음 날로 넘어가고 예외를 올리지 않는다. 잡이 그
     * 반환값을 버리면 90일 중 30일이 실패해도 `failed_jobs` 에 아무것도 남지 않아,
     * 큐만 보는 운영자에게는 성공으로 보인다.
     *
     * @return void 없음
     *
     * @scenario period=range, trigger=manual, data=all_types
     *
     * @effects failure_records_the_run_and_fires_the_alert_hook
     */
    public function test_range_job_fails_when_any_day_failed(): void
    {
        $date = '2026-09-18';
        $this->visit($date);

        HookManager::addAction('core.analytics.archive.day', function (): void {
            throw new \RuntimeException('집계 중 확장이 던진 예외');
        });

        $job = new ArchiveRangeJob($date, $date);
        $thrown = null;

        try {
            $job->handle($this->service);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull(
            $thrown,
            '실패한 날이 있는데 잡이 성공으로 끝났다 — 큐 감시에 아무 신호도 남지 않는다',
        );
        $this->assertStringContainsString('1일이 실패했다', $thrown->getMessage());
    }

    /**
     * 봇 요청이 봇 유형 집계 행과 두 차원으로 들어가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=bot_only, trigger=manual
     *
     * @effects bot_hits_become_bot_typed_rows, bot_visits_are_counted_by_name_and_address_pair
     */
    public function test_bot_hits_become_bot_typed_rows(): void
    {
        $date = '2026-09-19';

        foreach ([['Googlebot', 'example.test/a'], ['Googlebot', 'example.test/b'], ['Bingbot', 'example.test/a']] as [$bot, $url]) {
            AnalyticsBotHit::factory()->create([
                'bot_name' => $bot,
                'url' => $url,
                'url_hash' => md5($url),
                'ip' => '203.0.113.9',
                'occurred_at' => Carbon::parse($date.' 03:00:00', 'UTC'),
            ]);
        }

        $this->service->archiveDay($date);

        $botRow = AnalyticsStatsDaily::query()->where('date', $date)->where('visitor_type', VisitorType::Bot->value)->first();
        $this->assertNotNull($botRow);
        $this->assertSame(3, (int) $botRow->pageviews);
        $this->assertSame(2, (int) $botRow->visits, '봇 방문 수는 봇 이름과 IP 조합 수로 본다');

        $this->assertSame(
            2,
            AnalyticsDimensionStat::query()->where('date', $date)->where('dimension', StatsDimension::BotName->value)->count(),
        );
        $this->assertSame(
            2,
            AnalyticsDimensionStat::query()->where('date', $date)->where('dimension', StatsDimension::BotPage->value)->count(),
        );
    }

    /**
     * 봇 방문 수가 일 표와 시간 표에서 같은 값으로 세어지는지 확인합니다.
     *
     * 시간 표에 방문 시작을 올리지 않으면 페이지뷰는 차 있는데 방문 수만 0 으로
     * 남아, 같은 데이터를 두고 두 표가 다른 수를 보고한다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=bot_only, trigger=manual
     *
     * @effects bot_visits_agree_between_daily_and_hourly
     */
    public function test_bot_visits_agree_between_daily_and_hourly(): void
    {
        $date = '2026-09-19';

        // 같은 시각에 Googlebot 2건(같은 IP)·Bingbot 1건 → 방문 2건, 페이지뷰 3건.
        foreach ([['Googlebot', 'example.test/a'], ['Googlebot', 'example.test/b'], ['Bingbot', 'example.test/a']] as [$bot, $url]) {
            AnalyticsBotHit::factory()->create([
                'bot_name' => $bot,
                'url' => $url,
                'url_hash' => md5($url),
                'ip' => '203.0.113.9',
                'occurred_at' => Carbon::parse($date.' 03:00:00', 'UTC'),
            ]);
        }

        $this->service->archiveDay($date);

        $dailyVisits = (int) AnalyticsStatsDaily::query()
            ->where('date', $date)
            ->where('visitor_type', VisitorType::Bot->value)
            ->value('visits');

        $hourlyVisits = (int) AnalyticsStatsHourly::query()
            ->where('visitor_type', VisitorType::Bot->value)
            ->sum('visits');

        $this->assertSame(2, $dailyVisits);
        $this->assertSame(
            $dailyVisits,
            $hourlyVisits,
            '봇 방문 수는 일 표와 시간 표가 같아야 한다',
        );
    }

    /**
     * 한 봇이 여러 시간에 걸쳐 오면 방문 1건이 처음 시각에만 잡히는지 확인합니다.
     *
     * 시각마다 방문 시작을 올리면 하루 합이 일 표보다 커진다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=bot_only, trigger=manual
     *
     * @effects bot_visit_counted_once_at_its_first_hour
     */
    public function test_bot_visit_is_counted_once_at_its_first_hour(): void
    {
        $date = '2026-09-19';

        // 같은 봇·같은 IP 가 세 시각에 걸쳐 방문 → 방문은 여전히 1건이어야 한다.
        foreach (['01:00:00', '05:00:00', '09:00:00'] as $time) {
            AnalyticsBotHit::factory()->create([
                'bot_name' => 'Googlebot',
                'url' => 'example.test/a',
                'url_hash' => md5('example.test/a'),
                'ip' => '203.0.113.9',
                'occurred_at' => Carbon::parse($date.' '.$time, 'UTC'),
            ]);
        }

        $this->service->archiveDay($date);

        $dailyVisits = (int) AnalyticsStatsDaily::query()
            ->where('date', $date)
            ->where('visitor_type', VisitorType::Bot->value)
            ->value('visits');

        $hourlyRows = AnalyticsStatsHourly::query()
            ->where('visitor_type', VisitorType::Bot->value)
            ->get();

        $this->assertSame(1, $dailyVisits);
        $this->assertSame(1, (int) $hourlyRows->sum('visits'), '여러 시각에 걸쳐도 방문은 1건이다');
        $this->assertSame(3, (int) $hourlyRows->sum('pageviews'), '페이지뷰는 시각마다 쌓인다');
    }

    /**
     * 확장이 수집기에 넣은 행이 집계 표에 함께 들어가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=extension_rows, trigger=manual
     *
     * @effects extension_collector_rows_are_stored_with_the_batch, extension_cannot_write_into_core_dimensions
     */
    public function test_extension_rows_from_the_collector_are_stored(): void
    {
        $date = '2026-09-20';
        $this->visit($date);

        HookManager::addAction('core.analytics.archive.day', function (array $payload): void {
            /** @var ArchiveCollector $collector */
            $collector = $payload['collector'];
            $collector->addDimensionRow('ext:sirsoft-board:board_id', '자유게시판', VisitorType::Guest->value, [
                'visits' => 3,
                'hits' => 7,
            ]);
            $collector->addDimensionRow('page_url', '코어 차원 사칭', VisitorType::Guest->value, ['visits' => 99]);
        });

        $this->service->archiveDay($date);

        $extension = AnalyticsDimensionStat::query()
            ->where('date', $date)
            ->where('dimension', 'ext:sirsoft-board:board_id')
            ->first();

        $this->assertNotNull($extension);
        $this->assertSame(3, (int) $extension->visits);
        $this->assertSame(7, (int) $extension->hits);

        $this->assertFalse(
            AnalyticsDimensionStat::query()->where('date', $date)->where('label', '코어 차원 사칭')->exists(),
            '확장은 코어 차원 이름으로 행을 넣을 수 없다',
        );
    }

    /**
     * 확정된 날은 다시 집계하지 않고 --force 에서만 다시 도는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, final=true, trigger=manual
     *
     * @effects finalized_days_are_skipped_without_force, forced_rerun_recomputes_a_finalized_day
     */
    public function test_finalized_days_are_skipped_unless_forced(): void
    {
        // 재집계는 원장 보존일 안의 날만 다시 계산한다 — 오늘을 고정해 날짜가 보존일 밖으로
        // 밀려나지 않게 한다.
        $this->travelTo(Carbon::parse('2026-09-25 12:00:00', 'UTC'));
        $date = '2026-09-21';
        $this->visit($date);
        $this->service->archiveDay($date, ['is_final' => true]);

        $before = AnalyticsArchiveRun::query()->count();
        $this->service->archiveRange($date, $date);

        // 다시 계산하지는 않지만 건너뛴 사실은 남긴다 — 아무 행도 없으면 화면이 「시작했습니다」만
        // 띄운 채 결과가 비어, 돌다 만 것인지 안 돌아도 되는 것인지 운영자가 가릴 수 없다.
        $skipped = AnalyticsArchiveRun::query()->where('status', ArchiveRunStatus::Skipped->value)->count();
        $this->assertSame($before + 1, AnalyticsArchiveRun::query()->count(), '건너뛴 사실이 이력에 남아야 한다');
        $this->assertSame(1, $skipped);
        $this->assertSame(
            0,
            AnalyticsArchiveRun::query()->where('status', ArchiveRunStatus::Success->value)->where('run_type', 'reaggregate')->count(),
            '확정된 날을 다시 계산하지는 않는다',
        );

        $before = AnalyticsArchiveRun::query()->count();

        $this->service->archiveRange($date, $date, ['force' => true]);
        $this->assertGreaterThan($before, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 마른 실행이 집계 표를 바꾸지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects dry_run_touches_no_table
     */
    public function test_dry_run_writes_nothing(): void
    {
        $date = '2026-09-22';
        $this->visit($date);

        $run = $this->service->archiveDay($date, ['dry_run' => true]);

        $this->assertNull($run);
        $this->assertSame(0, AnalyticsStatsDaily::query()->count());
        $this->assertSame(0, AnalyticsArchiveRun::query()->count());
    }

    /**
     * 「서버로만 잡힌 방문」은 비콘이 끝내 오지 않은 방문만 센다.
     *
     * 전체 로드는 언제나 서버 폴백이 먼저 방문을 연다. 뒤이어 온 첫 비콘이 출처를 바꾸지
     * 않으면 브라우저가 멀쩡히 보고한 방문까지 이 지표에 들어가, 「비콘이 오지 않는 환경을
     * 진단한다」는 지표가 방문 수와 같아진다. 실제 수집 경로(서버 폴백 → 비콘)로 만든다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects server_only_visits_are_counted_separately
     */
    public function test_server_only_visits_count_only_visits_the_beacon_never_joined(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
        $html = new Response('<!doctype html><html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        // 스크립트가 막힌 방문자 — 서버 폴백만 남는다.
        app(TrackServerVisit::class)->terminate(
            Request::create('/board/free', 'GET', server: ['HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => '203.0.113.11']),
            $html,
        );

        // 평범한 방문자 — 서버 폴백 뒤에 첫 비콘이 합류한다.
        app(TrackServerVisit::class)->terminate(
            Request::create('/board/free', 'GET', server: ['HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => '203.0.113.12']),
            $html,
        );
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
            ->withHeaders(['User-Agent' => $ua])
            ->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'http://localhost/board/free', 'res' => '1920x1080', 'init' => true])
            ->assertNoContent();

        $this->assertSame(2, AnalyticsVisit::query()->count());

        $this->service->archiveDay(AnalyticsClock::today());

        $daily = AnalyticsStatsDaily::query()->where('visitor_type', VisitorType::Guest->value)->firstOrFail();
        $this->assertSame(2, $daily->visits);
        $this->assertSame(1, $daily->server_only_visits, '비콘이 합류한 방문은 서버 전용이 아니다');
        $this->assertSame(1, (int) AnalyticsStatsHourly::query()->where('visitor_type', VisitorType::Guest->value)->sum('server_only_visits'));
    }

    /**
     * 원장이 보존일에 걸려 지워진 날은 재집계가 건너뛰고 영구 집계를 지우지 않는다.
     *
     * 원장은 보존일(기본 90일)이 지나면 지워지지만 일·차원 집계는 영구 보존된다. 강제
     * 재집계가 그날을 다시 계산하면 빈 재료로 집계를 덮어써 과거 숫자가 0 이 된다 —
     * 업그레이드 뒤 「재집계」를 전 기간으로 돌리는 것이 바로 그 경로다. 경계일(오늘 −
     * 보존일)은 새벽 정리로 일부가 이미 지워지므로 건너뛰고, 그다음 날부터 다시 계산한다.
     *
     * @return void 없음
     *
     * @scenario period=range, trigger=manual, final=true, data=empty
     *
     * @effects reaggregation_never_erases_days_past_ledger_retention
     */
    public function test_reaggregation_keeps_aggregates_of_days_past_ledger_retention(): void
    {
        $this->travelTo(Carbon::parse('2026-09-18 12:00:00', 'UTC'));
        config(['g7_settings.core.analytics.retention_days' => 90]);

        $boundary = '2026-06-20';
        $firstKept = '2026-06-21';

        foreach (['2026-06-01', $boundary] as $date) {
            AnalyticsStatsDaily::factory()->create([
                'date' => $date,
                'visitor_type' => VisitorType::Guest->value,
                'visits' => 42,
                'is_final' => true,
            ]);
            AnalyticsDimensionStat::factory()->create([
                'date' => $date,
                'visitor_type' => VisitorType::Guest->value,
                'dimension' => StatsDimension::PageUrl->value,
            ]);
        }

        $runs = $this->service->archiveRange('2026-06-01', $firstKept, ['force' => true]);

        foreach (['2026-06-01', $boundary] as $date) {
            $this->assertSame(42, (int) AnalyticsStatsDaily::query()->where('date', $date)->value('visits'), $date.' 영구 집계가 빈 재료로 덮였다');
            $this->assertSame(1, AnalyticsDimensionStat::query()->where('date', $date)->count(), $date.' 차원 집계가 지워졌다');
        }

        $skipped = collect($runs)->filter(fn (AnalyticsArchiveRun $run): bool => $this->statusOf($run) === ArchiveRunStatus::Skipped->value);
        $this->assertCount(20, $skipped, '06-01 ~ 06-20 스무 날은 건너뛰고 이유를 이력에 남긴다');
        $this->assertSame(__('analytics.archive.skipped_beyond_retention'), $skipped->first()->error);
        $this->assertSame(ArchiveRunStatus::Success->value, $this->statusOf(collect($runs)->last()), '보존일 안쪽 첫날은 다시 계산한다');
    }

    /**
     * 자정을 넘긴 방문의 뒷부분 액션이 시간 표에서 사라지지 않는다.
     *
     * 방문은 시작한 날(23:50)에 속해 그날 집계가 읽지만, 그날 집계는 자기 24칸만 써서
     * 자정 뒤(00:10) 액션을 둘 칸이 없다. 다음 날 집계는 그날 시작한 방문만 읽는다. 둘
     * 사이에서 액션이 빠지면 시간 표 합이 일 표보다 작아진다 — 두 표를 나란히 놓아 잰다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects actions_after_midnight_land_in_the_next_days_hours
     */
    public function test_actions_after_midnight_are_not_lost_from_the_hourly_table(): void
    {
        $start = Carbon::parse('2026-09-13 23:50:00', 'UTC');
        $visit = AnalyticsVisit::factory()->create([
            'visit_date' => '2026-09-13',
            'first_action_at' => $start,
            'last_action_at' => $start->copy()->addMinutes(20),
            'visitor_type' => VisitorType::Guest,
            'source' => VisitSource::Beacon,
            'actions_count' => 2,
            'pageviews_count' => 2,
            'is_bounce' => false,
        ]);

        foreach ([0, 20] as $minutes) {
            AnalyticsAction::factory()->create([
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'action_type' => ActionType::Pageview,
                'action_name_id' => $this->actionName('url', 'example.test/'.$minutes)->id,
                'occurred_at' => $start->copy()->addMinutes($minutes),
                'time_spent_seconds' => null,
            ]);
        }

        $this->service->archiveDay('2026-09-13');
        $this->service->archiveDay('2026-09-14');
        $this->service->archiveDay('2026-09-14');

        $hourly = fn (string $hour): ?AnalyticsStatsHourly => AnalyticsStatsHourly::query()
            ->where('hour_start', $hour)->where('visitor_type', VisitorType::Guest->value)->first();

        $this->assertSame(1, (int) $hourly('2026-09-13 23:00:00')?->pageviews);
        $this->assertSame(1, (int) $hourly('2026-09-14 00:00:00')?->pageviews, '자정 뒤 페이지가 다음 날 00시 칸에 없다');
        $this->assertSame(0, (int) $hourly('2026-09-14 00:00:00')?->visits, '방문은 시작한 시각에만 센다');
        $this->assertSame(
            (int) AnalyticsStatsDaily::query()->where('date', '2026-09-13')->where('visitor_type', VisitorType::Guest->value)->value('pageviews'),
            (int) AnalyticsStatsHourly::query()->where('visitor_type', VisitorType::Guest->value)->sum('pageviews'),
            '시간 표 합이 일 표와 같아야 한다',
        );
    }

    /**
     * 집계 진행률이 시작·완료 시점에 관리자 채널로 방송되는지 확인합니다.
     *
     * 진단 화면은 소켓이 있으면 이 방송으로 진행률 바를 움직이고, 없으면 진단 API 를
     * 폴링한다. 방송이 빠지면 소켓을 켠 사이트에서만 바가 멈춘 채 남는다 — 폴링 폴백은
     * 소켓이 없을 때만 도니 아무도 그 결함을 보지 못한다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects archive_progress_is_broadcast_to_the_admin_channel
     */
    public function test_progress_is_broadcast_at_start_and_completion(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => 'localhost']);
        Event::fake([GenericBroadcastEvent::class]);

        $date = '2026-09-18';
        $this->visit($date);

        $run = $this->service->archiveDay($date);

        $onChannel = static fn (GenericBroadcastEvent $event): bool => $event->channel === ArchiveService::PROGRESS_CHANNEL
            && $event->eventName === ArchiveService::PROGRESS_EVENT
            && ($event->payload['type'] ?? null) === 'archive_progress'
            && ($event->payload['data']['id'] ?? null) === $run->id;

        Event::assertDispatched(
            GenericBroadcastEvent::class,
            static fn (GenericBroadcastEvent $event): bool => $onChannel($event)
                && ($event->payload['data']['status'] ?? null) === ArchiveRunStatus::Running->value
                && ($event->payload['data']['progress_percent'] ?? null) === 0,
        );

        Event::assertDispatched(
            GenericBroadcastEvent::class,
            static fn (GenericBroadcastEvent $event): bool => $onChannel($event)
                && ($event->payload['data']['status'] ?? null) === ArchiveRunStatus::Success->value
                && ($event->payload['data']['progress_percent'] ?? null) === 100
                && ($event->payload['data']['processed_visits'] ?? null) === 1
                && array_key_exists('finished_at', $event->payload['data']),
        );
    }

    /**
     * 집계가 실패해도 종료 방송이 나가는지 확인합니다.
     *
     * 성공만 방송하면 실패한 집계의 진행률 바는 영원히 「진행 중」이다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects archive_progress_is_broadcast_to_the_admin_channel
     */
    public function test_failure_is_broadcast_as_a_terminal_state(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => 'localhost']);
        Event::fake([GenericBroadcastEvent::class]);

        $date = '2026-09-18';
        $this->visit($date);

        HookManager::addAction('core.analytics.archive.day', function (): void {
            throw new \RuntimeException('집계 중 확장이 던진 예외');
        });

        $run = $this->service->archiveDay($date);

        Event::assertDispatched(
            GenericBroadcastEvent::class,
            static fn (GenericBroadcastEvent $event): bool => $event->eventName === ArchiveService::PROGRESS_EVENT
                && ($event->payload['data']['id'] ?? null) === $run->id
                && ($event->payload['data']['status'] ?? null) === ArchiveRunStatus::Failed->value,
        );
    }

    /**
     * 확정된 날을 force 없이 재집계하면 건너뛴 사실이 이력에 남는지 확인합니다.
     *
     * 화면은 「재집계를 시작했습니다」를 띄우고 이력 표로 결과를 보여 준다. 아무 행도
     * 남지 않으면 운영자는 돌다 만 것인지, 안 돌아도 되는 것인지 가릴 수 없다 —
     * 보존일 밖 분기는 이미 건너뜀을 남긴다(브라우저 실측으로 드러난 비대칭).
     *
     * @return void 없음
     *
     * @scenario period=range, data=all_types, trigger=manual
     *
     * @effects range_job_reports_failed_days
     */
    public function test_a_finalized_day_is_recorded_as_skipped_without_force(): void
    {
        $date = '2026-09-15';
        $this->visit($date);

        // 먼저 확정해 둔다(어제까지는 is_final).
        $this->service->archiveDay($date, ['is_final' => true]);
        $before = AnalyticsArchiveRun::count();

        $runs = $this->service->archiveRange($date, $date, [
            'run_type' => ArchiveRunType::Reaggregate->value,
            'trigger' => 'manual',
            'force' => false,
        ]);

        $this->assertCount(1, $runs, '건너뛰었어도 그 사실이 이력으로 돌아와야 한다');
        $this->assertSame(ArchiveRunStatus::Skipped->value, $runs[0]->status instanceof \BackedEnum ? $runs[0]->status->value : (string) $runs[0]->status);
        $this->assertSame($before + 1, AnalyticsArchiveRun::count());
        $this->assertNotEmpty($runs[0]->error, '왜 건너뛰었는지 사유가 있어야 한다');
    }

    /**
     * 청크마다 중간 진행률이 방송되는지 확인합니다.
     *
     * 시작·종료만 방송하면 바가 0 에서 100 으로 튄다 — 몇 분짜리 재집계에서 운영자는
     * 멈춘 것과 구별할 수 없다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects archive_progress_is_broadcast_to_the_admin_channel
     */
    public function test_progress_is_broadcast_between_start_and_finish(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'g7_settings.core.analytics.archive_chunk_size' => 2,
        ]);
        Event::fake([GenericBroadcastEvent::class]);

        $date = '2026-09-16';

        for ($i = 0; $i < 5; $i++) {
            $this->visit($date);
        }

        $run = $this->service->archiveDay($date);

        // 시작(0)·종료(100) 사이에 0 < p < 100 인 프레임이 적어도 하나 있어야 한다.
        Event::assertDispatched(
            GenericBroadcastEvent::class,
            static function (GenericBroadcastEvent $event) use ($run): bool {
                if ($event->eventName !== ArchiveService::PROGRESS_EVENT) {
                    return false;
                }

                if (($event->payload['data']['id'] ?? null) !== $run->id) {
                    return false;
                }

                $percent = (int) ($event->payload['data']['progress_percent'] ?? 0);

                return $percent > 0 && $percent < 100;
            },
        );
    }

    /**
     * 방송 드라이버가 꺼져 있으면 방송 없이 집계만 끝나는지 확인합니다.
     *
     * 소켓을 두지 않은 설치가 대부분이다 — 그 사이트에서 방송 시도가 예외를 내거나
     * 집계를 늦추면 안 된다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=all_types, trigger=manual
     *
     * @effects archive_progress_is_broadcast_to_the_admin_channel
     */
    public function test_progress_is_not_broadcast_when_the_driver_is_off(): void
    {
        config(['broadcasting.default' => 'null']);
        Event::fake([GenericBroadcastEvent::class]);

        $date = '2026-09-18';
        $this->visit($date);

        $run = $this->service->archiveDay($date);

        Event::assertNotDispatched(GenericBroadcastEvent::class);
        $this->assertSame(ArchiveRunStatus::Success->value, $this->statusOf($run));
        $this->assertSame(100, (int) $run->progress_percent);
    }

    /**
     * 실행 이력의 상태 문자열을 꺼냅니다.
     *
     * @param  AnalyticsArchiveRun  $run  실행 이력
     * @return string 상태 값
     */
    private function statusOf(AnalyticsArchiveRun $run): string
    {
        return $run->status instanceof ArchiveRunStatus ? $run->status->value : (string) $run->status;
    }

    /**
     * 차원 하나의 라벨별 방문 수를 읽습니다.
     *
     * @param  string  $date  일자 (Y-m-d)
     * @param  string  $dimension  차원 이름
     * @return array<string, int> 라벨 => 방문 수 (라벨 순)
     */
    private function labelVisits(string $date, string $dimension): array
    {
        return AnalyticsDimensionStat::query()
            ->where('date', $date)
            ->where('dimension', $dimension)
            ->orderBy('label')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->label => (int) $row->visits])
            ->all();
    }

    /**
     * 새 차원 5종이 규칙대로 쌓이는지 확인합니다 — 매체만 있는 방문, 검색 탭, 체류·페이지 수 구간.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects new_report_dimensions_get_rows
     */
    public function test_new_dimensions_follow_their_rules(): void
    {
        $date = '2026-09-15';

        // 체류 5초·1페이지(매체 표식), 체류 45초·7페이지, 체류 2000초·0페이지(내려받기만 한 방문)
        $short = $this->visit($date, ['total_time_seconds' => 5, 'campaign_medium' => 'qa_email']);
        $this->visit($date, ['total_time_seconds' => 45, 'pageviews_count' => 7]);
        $this->visit($date, ['total_time_seconds' => 2000, 'pageviews_count' => 0]);

        AnalyticsAction::factory()->create([
            'visit_id' => $short->id,
            'visitor_key' => $short->visitor_key,
            'action_type' => ActionType::SiteSearch,
            'action_name_id' => $this->actionName('search', 'qa')->id,
            'search_tab' => 'products',
            'search_result_count' => 0,
            'occurred_at' => Carbon::parse($date.' 01:30:00', 'UTC'),
        ]);

        $this->service->archiveDay($date);

        $this->assertSame(['qa_email' => 1], $this->labelVisits($date, StatsDimension::CampaignMedium->value));
        $this->assertSame(['products' => 1], $this->labelVisits($date, StatsDimension::SearchTab->value));
        // 체류: 5초 → [0,10], 45초 → (30,60], 2000초 → 1800 초과 (경계 10초·1800초 자체는 BucketLabelerTest 가 잠근다)
        $this->assertSame(['0-10' => 1, '1800+' => 1, '30-60' => 1], $this->labelVisits($date, StatsDimension::VisitDuration->value));
        // 페이지 수: 0페이지 방문은 빠지고 7페이지는 6~7 구간
        $this->assertSame(['1' => 1, '6-7' => 1], $this->labelVisits($date, StatsDimension::VisitPages->value));
        // 검색어 행은 탭 행과 따로 그대로 남는다
        $this->assertSame(['qa' => 1], $this->labelVisits($date, StatsDimension::SiteSearch->value));
    }

    /**
     * 설정에서 바꾼 구간 경계가 그 뒤 집계에 적용되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects bucket_bounds_setting_changes_labels
     */
    public function test_bucket_bounds_setting_changes_labels(): void
    {
        config([
            'g7_settings.core.analytics.duration_bucket_bounds' => '5,20',
            'g7_settings.core.analytics.pages_bucket_bounds' => '2',
        ]);

        $date = '2026-09-16';
        $this->visit($date, ['total_time_seconds' => 3]);
        $this->visit($date, ['total_time_seconds' => 12, 'pageviews_count' => 2]);
        $this->visit($date, ['total_time_seconds' => 99, 'pageviews_count' => 9]);

        $this->service->archiveDay($date);

        $this->assertSame(['0-5', '20+', '5-20'], array_keys($this->labelVisits($date, StatsDimension::VisitDuration->value)));
        $this->assertSame(['1-2', '3+'], array_keys($this->labelVisits($date, StatsDimension::VisitPages->value)));
    }

    /**
     * 이름 없이 주소를 가리키는 옛 이벤트 행은 이벤트 리포트에 들어가지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects legacy_url_event_rows_are_excluded
     */
    public function test_event_rows_pointing_at_urls_are_excluded(): void
    {
        $date = '2026-09-17';
        $visit = $this->visit($date);

        foreach ([['url', 'example.test/legacy'], ['event', 'signup_click']] as [$type, $name]) {
            AnalyticsAction::factory()->create([
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'action_type' => ActionType::Event,
                'action_name_id' => $this->actionName($type, $name)->id,
                'occurred_at' => Carbon::parse($date.' 01:20:00', 'UTC'),
            ]);
        }

        $this->service->archiveDay($date);

        $this->assertSame(['signup_click' => 1], $this->labelVisits($date, StatsDimension::EventName->value));
    }

    /**
     * 검색 탭은 하루 라벨 수 상한(50)을 넘으면 상위만 남는지 확인합니다 — 공개 수집 API 로 들어오는 자유 문자열.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects free_text_dimensions_are_capped
     */
    public function test_search_tab_labels_are_capped_per_day(): void
    {
        $date = '2026-09-18';
        $visit = $this->visit($date);
        $name = $this->actionName('search', 'q');

        for ($i = 0; $i < 60; $i++) {
            AnalyticsAction::factory()->create([
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'action_type' => ActionType::SiteSearch,
                'action_name_id' => $name->id,
                'search_tab' => 'tab_'.$i,
                'occurred_at' => Carbon::parse($date.' 01:20:00', 'UTC'),
            ]);
        }

        $this->service->archiveDay($date);

        $this->assertCount(50, $this->labelVisits($date, StatsDimension::SearchTab->value));
    }

    /**
     * 이벤트 이름은 하루 라벨 수 상한(500)을 넘으면 방문 수 상위만 남는지 확인합니다 — 공개 수집 API 로 이름을
     * 바꿔 가며 보내도 집계 행이 끝없이 늘지 않게.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects free_text_dimensions_are_capped
     */
    public function test_event_name_labels_are_capped_per_day(): void
    {
        $date = '2026-09-20';
        $visit = $this->visit($date);
        $at = Carbon::parse($date.' 01:20:00', 'UTC');
        $rows = [];

        for ($i = 0; $i < 505; $i++) {
            $rows[] = [
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'action_type' => ActionType::Event->value,
                'action_name_id' => $this->actionName('event', 'evt_'.$i)->id,
                'occurred_at' => $at,
            ];
        }

        AnalyticsAction::query()->insert($rows);

        $this->service->archiveDay($date);

        $this->assertCount(500, $this->labelVisits($date, StatsDimension::EventName->value));
    }

    /**
     * 잠금을 못 잡았을 때 `record_skipped=false` 면 건너뜀 행을 남기지 않고 null 을 돌려주는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario period=day, data=single_type, trigger=manual
     *
     * @effects on_view_lock_miss_leaves_no_skipped_row
     */
    public function test_lock_miss_without_record_skipped_leaves_no_row(): void
    {
        $date = '2026-09-19';
        $lock = Cache::lock(ArchiveService::lockKey($date), 60);
        $this->assertTrue($lock->get());

        try {
            $this->assertNull($this->service->archiveDay($date, ['record_skipped' => false]));
            $this->assertSame(0, AnalyticsArchiveRun::query()->count());

            // 기본값은 예전처럼 건너뜀을 남긴다.
            $this->assertSame(ArchiveRunStatus::Skipped->value, $this->statusOf($this->service->archiveDay($date)));
        } finally {
            $lock->release();
        }
    }
}
