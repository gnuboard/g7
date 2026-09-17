<?php

namespace Tests\Feature\Analytics;

use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\StatsDimension;
use App\Enums\Analytics\VisitorType;
use App\Enums\Analytics\VisitSource;
use App\Extension\HookManager;
use App\Jobs\Analytics\ArchiveRangeJob;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsBotHit;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsStatsHourly;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\ArchiveCollector;
use App\Services\Analytics\ArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

        $lock = Cache::lock('analytics:archive:'.ArchiveRunType::Day->value.':'.$date, 60);
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
        $date = '2026-09-21';
        $this->visit($date);
        $this->service->archiveDay($date, ['is_final' => true]);

        $before = AnalyticsArchiveRun::query()->count();
        $this->service->archiveRange($date, $date);
        $this->assertSame($before, AnalyticsArchiveRun::query()->count(), '확정된 날은 실행 이력조차 남기지 않는다');

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
     * 실행 이력의 상태 문자열을 꺼냅니다.
     *
     * @param  AnalyticsArchiveRun  $run  실행 이력
     * @return string 상태 값
     */
    private function statusOf(AnalyticsArchiveRun $run): string
    {
        return $run->status instanceof ArchiveRunStatus ? $run->status->value : (string) $run->status;
    }
}
