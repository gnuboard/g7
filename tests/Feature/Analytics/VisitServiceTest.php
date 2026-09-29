<?php

namespace Tests\Feature\Analytics;

use App\Analytics\TrackingRequest;
use App\Analytics\TrackingRequestFactory;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\CollectFailureReason;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\VisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\Feature\Analytics\Concerns\FakesAnalyticsExtensions;
use Tests\TestCase;

/**
 * 방문 적재 — 방문 묶기·카운터·체류 확정·상한·예외 격리.
 *
 * 이 서비스의 실패는 전부 조용하다. 방문이 두 개로 갈리거나 페이지뷰가 두 배가 되어도
 * 화면에는 그럴듯한 숫자가 나오고, 예외를 밖으로 던지면 방문자가 보던 화면이 깨진다.
 * 그래서 「무엇이 몇 개 적혔는가」를 행 수로 직접 단언한다.
 */
class VisitServiceTest extends TestCase
{
    use FakesAnalyticsExtensions;
    use RefreshDatabase;

    private VisitService $service;

    /**
     * 테스트 준비 — 서비스 해석.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 개발 PC 설정 파일의 개인정보 모드(consent 등)를 따르면 쿠키 발급 단정이 흔들린다 — 기본값으로 고정하고,
        // 다른 모드를 보는 테스트는 그 안에서 바꾼다.
        config(['g7_settings.core.analytics.privacy_mode' => 'full']);

        // 목표는 등록부에 선언된 것만 기록된다 — 이커머스 주문 완료 목표를 선언한 가짜 확장을 켠다.
        $this->activateSampleAnalyticsExtensions();
        $this->service = app(VisitService::class);
    }

    /**
     * 수집 요청 하나를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return TrackingRequest 수집 요청
     */
    private function request(array $overrides = []): TrackingRequest
    {
        return new TrackingRequest(...array_merge([
            'type' => 'pageview',
            'url' => 'https://example.test/board/free',
            'title' => '자유게시판',
            'ip' => '203.0.113.50',
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36',
            'browserLanguage' => 'ko-KR',
            'resolution' => '1920x1080',
            'cookies' => ['g7_vid' => str_repeat('b', 32)],
            'receivedAt' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * 첫 방문이 방문 1건과 액션 1건을 남긴다.
     *
     * @scenario source=beacon, visit_state=new, visitor=guest
     *
     * @effects first_request_creates_visit_and_action
     *
     * @return void 없음
     */
    public function test_creates_visit_and_action(): void
    {
        $result = $this->service->track($this->request(['init' => true]));

        $this->assertNotNull($result['visit_id']);
        $this->assertSame(1, AnalyticsVisit::count());
        $this->assertSame(1, AnalyticsAction::count());

        $visit = AnalyticsVisit::first();
        $this->assertSame('guest', $visit->visitor_type->value);
        $this->assertSame('Chrome', $visit->browser_name);
        $this->assertSame('Windows', $visit->os_name);
        $this->assertSame(1, $visit->pageviews_count);
        $this->assertTrue($visit->is_bounce);
        $this->assertNotNull($visit->entry_action_name_id);
    }

    /**
     * 창 안의 두 번째 요청은 같은 방문에 붙는다.
     *
     * @scenario visit_state=open, source=beacon
     *
     * @effects second_request_within_window_joins_same_visit, bounce_flag_releases_on_second_pageview
     *
     * @return void 없음
     */
    public function test_second_request_joins_same_visit(): void
    {
        $first = $this->service->track($this->request());
        $second = $this->service->track($this->request(['url' => 'https://example.test/board/free/1']));

        $this->assertSame($first['visit_id'], $second['visit_id']);
        $this->assertSame(1, AnalyticsVisit::count());
        $this->assertSame(2, AnalyticsAction::count());

        $visit = AnalyticsVisit::find($first['visit_id']);
        $this->assertSame(2, $visit->pageviews_count);
        $this->assertFalse($visit->is_bounce, '두 번째 페이지를 보면 이탈이 아니다');
    }

    /**
     * 창 밖의 요청은 새 방문이 된다.
     *
     * @scenario visit_state=timed_out
     *
     * @effects request_after_timeout_starts_new_visit
     *
     * @return void 없음
     */
    public function test_request_after_timeout_starts_new_visit(): void
    {
        $first = $this->service->track($this->request());

        // 마지막 활동을 창 밖으로 밀어 낸다.
        AnalyticsVisit::find($first['visit_id'])->update([
            'last_action_at' => now()->subMinutes(120),
        ]);

        $second = $this->service->track($this->request());

        $this->assertNotSame($first['visit_id'], $second['visit_id']);
        $this->assertSame(2, AnalyticsVisit::count());
    }

    /**
     * 직전 페이지의 체류 시간을 다음 요청이 확정한다.
     *
     * 확정 시점이 어긋나면(새 액션을 넣은 뒤에 직전을 찾으면) 방금 넣은 행이 잡혀
     * 체류 시간이 영영 null 로 남는다.
     *
     * @scenario prev_time_spent=present
     *
     * @effects previous_pageview_dwell_time_is_confirmed_by_next_request
     *
     * @return void 없음
     */
    public function test_confirms_previous_dwell_time(): void
    {
        $first = $this->service->track($this->request());
        $this->service->track($this->request(['url' => 'https://example.test/x', 'prevTimeSpent' => 42]));

        $this->assertSame(42, AnalyticsAction::find($first['action_id'])->time_spent_seconds);
        $this->assertSame(42, AnalyticsVisit::find($first['visit_id'])->total_time_seconds);
    }

    /**
     * 서버 폴백이 센 페이지뷰를 첫 비콘이 다시 세지 않는다.
     *
     * 병합이 있어도 카운터를 다시 올리면 페이지뷰가 2배가 된다 — 행 수와 카운터를
     * 함께 단언해야 그 어긋남이 잡힌다.
     *
     * @scenario source=server+beacon, init=true, merge=within_window
     *
     * @effects server_fallback_and_first_beacon_merge_into_one_action, merged_action_does_not_double_count_pageviews
     *
     * @return void 없음
     */
    public function test_merges_server_fallback_with_first_beacon(): void
    {
        $server = $this->service->track($this->request(['source' => 'server', 'title' => null]));
        $beacon = $this->service->track($this->request(['init' => true]));

        $this->assertSame($server['action_id'], $beacon['action_id'], '같은 액션 행을 채워야 한다');
        $this->assertSame(1, AnalyticsAction::count(), '행이 늘어나면 안 된다');

        $visit = AnalyticsVisit::find($beacon['visit_id']);
        $this->assertSame(1, $visit->pageviews_count, '페이지뷰가 2배가 되면 안 된다');
        $this->assertSame(1, $visit->actions_count);
        $this->assertNotNull(AnalyticsAction::find($beacon['action_id'])->page_title_id, '제목이 보강돼야 한다');
    }

    /**
     * 병합 창 밖의 비콘은 새 액션이 된다.
     *
     * @scenario source=server+beacon, merge=outside_window
     *
     * @effects beacon_outside_merge_window_creates_new_action
     *
     * @return void 없음
     */
    public function test_does_not_merge_outside_window(): void
    {
        $server = $this->service->track($this->request(['source' => 'server']));

        AnalyticsAction::find($server['action_id'])->update([
            'occurred_at' => now()->subSeconds(120),
        ]);

        $beacon = $this->service->track($this->request(['init' => true]));

        $this->assertNotSame($server['action_id'], $beacon['action_id']);
        $this->assertSame(2, AnalyticsAction::count());
    }

    /**
     * 다른 URL 이면 병합하지 않는다.
     *
     * @scenario source=server+beacon, merge=different_url
     *
     * @effects beacon_for_different_url_creates_new_action
     *
     * @return void 없음
     */
    public function test_does_not_merge_different_url(): void
    {
        $server = $this->service->track($this->request(['source' => 'server']));
        $beacon = $this->service->track($this->request(['init' => true, 'url' => 'https://example.test/other']));

        $this->assertNotSame($server['action_id'], $beacon['action_id']);
        $this->assertSame(2, AnalyticsAction::count());
    }

    /**
     * 방문당 액션 상한을 넘으면 더 적지 않고 사유를 남긴다.
     *
     * @scenario visit_state=max_actions
     *
     * @effects actions_beyond_visit_limit_are_dropped_with_failure_log
     *
     * @return void 없음
     */
    public function test_stops_recording_beyond_action_limit(): void
    {
        config(['g7_settings.core.analytics.max_actions_per_visit' => 2]);

        $this->service->track($this->request());
        $this->service->track($this->request(['url' => 'https://example.test/2']));
        $before = AnalyticsAction::count();

        $blocked = $this->service->track($this->request(['url' => 'https://example.test/3']));

        $this->assertNull($blocked['action_id']);
        $this->assertSame($before, AnalyticsAction::count(), '상한을 넘으면 액션이 늘지 않는다');
        $this->assertSame(
            1,
            AnalyticsCollectFailure::where('reason', CollectFailureReason::MaxActions->value)->count(),
            '왜 버렸는지 남아야 한다',
        );
    }

    /**
     * 봇은 원장에 들어가지 않고 제외 사유로 끝난다.
     *
     * @scenario visitor=bot
     *
     * @effects bot_requests_never_enter_the_visit_ledger, excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_excludes_bot_without_recording(): void
    {
        $result = $this->service->track($this->request([
            'userAgent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ]));

        $this->assertSame('bot', $result['excluded']);
        $this->assertNull($result['visit_id']);
        $this->assertSame(0, AnalyticsVisit::count());
    }

    /**
     * 수집이 꺼져 있으면 아무것도 적지 않는다.
     *
     * @scenario exclusion=disabled
     *
     * @effects excluded_requests_record_nothing, each_exclusion_reason_is_counted_separately
     *
     * @return void 없음
     */
    public function test_records_nothing_when_disabled(): void
    {
        config(['g7_settings.core.analytics.enabled' => false]);

        $result = $this->service->track($this->request());

        $this->assertSame('disabled', $result['excluded']);
        $this->assertSame(0, AnalyticsVisit::count());
    }

    /**
     * 쿠키가 없으면 새 키를 발급해 돌려준다.
     *
     * @scenario mode=full, visitor=guest
     *
     * @effects full_mode_stores_masked_ip_and_issues_cookie
     *
     * @return void 없음
     */
    public function test_issues_new_visitor_cookie(): void
    {
        $result = $this->service->track($this->request(['cookies' => []]));

        $this->assertNotNull($result['new_cookie']);
        $this->assertSame(32, strlen($result['new_cookie']));
        $this->assertFalse(AnalyticsVisit::first()->is_returning);
    }

    /**
     * 쿠키가 있으면 재방문으로 본다.
     *
     * @scenario mode=full, visitor=guest
     *
     * @effects full_mode_reports_returning_visitors
     *
     * @return void 없음
     */
    public function test_existing_cookie_marks_returning(): void
    {
        $result = $this->service->track($this->request());

        $this->assertNull($result['new_cookie']);
        $this->assertTrue(AnalyticsVisit::first()->is_returning);
    }

    /**
     * 내부 검색 액션이 결과 건수와 함께 남는다.
     *
     * @scenario source=search
     *
     * @effects site_search_action_stores_result_count_and_tab
     *
     * @return void 없음
     */
    public function test_records_site_search_with_result_count(): void
    {
        $result = $this->service->track($this->request([
            'type' => 'site_search',
            'searchQuery' => '그누보드',
            'searchTotal' => 12,
            'searchTab' => 'all',
        ]));

        $action = AnalyticsAction::find($result['action_id']);
        $this->assertSame('site_search', $action->action_type->value);
        $this->assertSame(12, $action->search_result_count);
        $this->assertSame('그누보드', $action->actionName->name);
        $this->assertSame('search', $action->actionName->type);
    }

    /**
     * 적재 중 오류가 나도 예외를 밖으로 던지지 않는다.
     *
     * 수집이 실패했다고 방문자 화면이 깨지거나 큐 잡이 재시도로 쌓이면 안 된다.
     *
     * @scenario exclusion=none
     *
     * @effects tracking_never_throws_to_the_caller
     *
     * @return void 없음
     */
    public function test_never_throws_to_caller(): void
    {
        $result = $this->service->track($this->request(['url' => '://broken']));

        // 예외가 밖으로 나왔다면 여기 도달하지 못한다.
        $this->assertIsArray($result);
    }

    /**
     * 열린 방문 조회는 행을 잠근다 — 동시 두 요청이 방문 두 개를 만들지 않는다.
     *
     * 트랜잭션 두 개를 순차로 흉내 내고 조회 SQL 에 `for update` 가 있는지 단언한다.
     * 잠금이 빠져도 순차 실행에서는 방문이 하나로 나오므로 SQL 자체를 봐야 한다.
     *
     * @scenario source=beacon, visit_state=open
     *
     * @effects open_visit_lookup_locks_the_row, second_request_within_window_joins_same_visit
     *
     * @return void 없음
     */
    public function test_open_visit_lookup_locks_the_row(): void
    {
        DB::enableQueryLog();

        $first = $this->service->track($this->request());
        $second = $this->service->track($this->request(['url' => 'https://example.test/2']));

        $locking = array_filter(DB::getQueryLog(), fn (array $q): bool => stripos($q['query'], 'for update') !== false);
        DB::disableQueryLog();

        $this->assertSame($first['visit_id'], $second['visit_id'], '두 요청은 한 방문이어야 한다');
        $this->assertSame(1, AnalyticsVisit::count());
        $this->assertGreaterThanOrEqual(2, count($locking), '방문 조회마다 FOR UPDATE 로 잠가야 한다');
    }

    /**
     * 체류 시간은 요청 조립 단계에서 상한으로 잘린다 — 트래커를 우회해도 마찬가지다.
     *
     * @scenario source=beacon, visit_state=open
     *
     * @effects dwell_time_is_capped_at_configured_maximum
     *
     * @return void 없음
     */
    public function test_dwell_time_is_capped_when_request_is_assembled(): void
    {
        $http = Request::create('/api/analytics/collect', 'POST');
        $dto = TrackingRequestFactory::fromHttp($http, ['t' => 'pageview', 'url' => 'https://example.test/', 'prev' => 86400]);

        $this->assertSame(1800, $dto->prevTimeSpent);

        $this->service->track($this->request());
        $this->service->track($this->request(['url' => 'https://example.test/2', 'prevTimeSpent' => $dto->prevTimeSpent]));

        $this->assertSame(1800, AnalyticsAction::orderBy('id')->first()->time_spent_seconds);
    }

    /**
     * 진입·이탈 페이지가 첫 액션과 마지막 액션으로 채워진다.
     *
     * @scenario source=beacon, visit_state=open
     *
     * @effects entry_and_exit_pages_are_recorded
     *
     * @return void 없음
     */
    public function test_entry_and_exit_pages_are_recorded(): void
    {
        $first = $this->service->track($this->request(['url' => 'https://example.test/entry']));
        $second = $this->service->track($this->request(['url' => 'https://example.test/exit']));

        $visit = AnalyticsVisit::find($second['visit_id']);

        $this->assertSame(AnalyticsAction::find($first['action_id'])->action_name_id, $visit->entry_action_name_id);
        $this->assertSame(AnalyticsAction::find($second['action_id'])->action_name_id, $visit->exit_action_name_id);
        $this->assertNotSame($visit->entry_action_name_id, $visit->exit_action_name_id);
    }

    /**
     * 내려받기·검색처럼 페이지가 아닌 액션은 진입·이탈 페이지를 바꾸지 않는다.
     *
     * 방문의 마지막 행동이 내려받기면 이탈 페이지 리포트에 파일 이름이, 검색이면 검색어가 실렸다
     * (브라우저 실측 §8 M3 — 이탈 페이지 「rows.csv」·「테스트」). 실시간 화면의 「보고 있는 페이지」도
     * 같은 값을 읽어 파일 이름이 떴다.
     *
     * @scenario source=beacon, visit_state=open
     *
     * @effects entry_and_exit_pages_come_from_pageviews_only
     *
     * @return void 없음
     */
    public function test_non_page_actions_do_not_move_entry_or_exit(): void
    {
        $page = $this->service->track($this->request(['url' => 'https://example.test/board/free']));
        $this->service->track($this->request([
            'type' => 'download',
            'target' => 'https://example.test/files/report.pdf',
            'name' => 'report.pdf',
        ]));
        $last = $this->service->track($this->request([
            'type' => 'site_search',
            'searchQuery' => '그누보드',
            'searchTotal' => 3,
            'searchTab' => 'all',
        ]));

        $visit = AnalyticsVisit::find($last['visit_id']);
        $pageName = AnalyticsAction::find($page['action_id'])->action_name_id;

        $this->assertSame($pageName, $visit->entry_action_name_id);
        $this->assertSame($pageName, $visit->exit_action_name_id, '마지막 페이지가 이탈 페이지다 — 파일 이름·검색어가 아니다');
        $this->assertSame(3, $visit->actions_count, '액션 수는 그대로 센다');
    }

    /**
     * 페이지뷰 없이 시작한 방문은 첫 페이지뷰가 올 때 진입 페이지를 얻는다.
     *
     * @scenario source=search, visit_state=new
     *
     * @effects entry_and_exit_pages_come_from_pageviews_only
     *
     * @return void 없음
     */
    public function test_visit_started_by_a_search_takes_its_entry_from_the_first_pageview(): void
    {
        $search = $this->service->track($this->request([
            'type' => 'site_search',
            'searchQuery' => '그누보드',
            'searchTotal' => 0,
            'searchTab' => 'all',
        ]));

        $visit = AnalyticsVisit::find($search['visit_id']);
        $this->assertNull($visit->entry_action_name_id, '검색어는 진입 페이지가 아니다');
        $this->assertNull($visit->exit_action_name_id);

        $page = $this->service->track($this->request(['url' => 'https://example.test/search']));
        $visit->refresh();
        $pageName = AnalyticsAction::find($page['action_id'])->action_name_id;

        $this->assertSame($pageName, $visit->entry_action_name_id);
        $this->assertSame($pageName, $visit->exit_action_name_id);
    }

    /**
     * 적재가 끝나면 `after_track` 훅이 원장 ID 와 함께 발화한다.
     *
     * @scenario source=beacon, visit_state=new
     *
     * @effects after_track_hook_fires_with_ledger_ids
     *
     * @return void 없음
     */
    public function test_after_track_hook_fires_with_ledger_ids(): void
    {
        $received = [];
        HookManager::addAction('core.analytics.after_track', function (array $payload) use (&$received): void {
            $received[] = $payload;
        });

        // 아래의 「제외된 요청」은 추적 거부(DNT) 존중에 기댄다 — 개발 PC 가 관리자 화면에서 저장한 설정
        // (storage/app/settings/analytics.json)이 부팅 때 스며들어 존중을 끈 채면 제외되지 않으므로 고정한다.
        config(['g7_settings.core.analytics.respect_dnt' => true]);

        $result = $this->service->track($this->request());

        $this->assertCount(1, $received);
        $this->assertSame($result['visit_id'], $received[0]['visit_id']);
        $this->assertSame($result['action_id'], $received[0]['action_id']);
        $this->assertSame('pageview', $received[0]['action_type']);
        $this->assertSame('https://example.test/board/free', $received[0]['dto']['url']);

        // 제외된 요청에서는 발화하지 않는다.
        $this->service->track($this->request(['headers' => ['dnt' => '1']]));
        $this->assertCount(1, $received);
    }

    /**
     * 액션 상한을 넘기면 알림 훅이 발화하고 방문 카운터는 그대로다.
     *
     * @scenario visit_state=max_actions
     *
     * @effects actions_beyond_visit_limit_are_dropped_with_failure_log, visit_limit_notification_hook_fires
     *
     * @return void 없음
     */
    public function test_visit_limit_fires_notification_hook(): void
    {
        config(['g7_settings.core.analytics.max_actions_per_visit' => 2]);

        $received = [];
        HookManager::addAction('core.analytics.visit_limit_exceeded', function (array $payload) use (&$received): void {
            $received[] = $payload;
        });

        $this->service->track($this->request());
        $second = $this->service->track($this->request(['url' => 'https://example.test/2']));
        $this->service->track($this->request(['url' => 'https://example.test/3']));

        $visit = AnalyticsVisit::find($second['visit_id']);

        $this->assertCount(1, $received);
        $this->assertSame(2, $received[0]['limit']);
        $this->assertSame(2, $visit->actions_count, '상한을 넘긴 요청은 카운터를 올리지 않는다');
        $this->assertSame(2, $visit->pageviews_count);
    }

    /**
     * 상한을 계속 넘겨도 알림은 하루 한 번만 나가고 실패 로그는 매번 남는다.
     *
     * 이 판정은 수집 요청마다 돈다. 상한을 넘긴 방문 하나가 계속 비콘을 보내면 같은
     * 알림이 관리자 알림함을 가득 채워, 정작 다른 경고가 묻힌다.
     *
     * @scenario visit_state=max_actions
     *
     * @effects visit_limit_notification_is_sent_once_a_day
     *
     * @return void 없음
     */
    public function test_repeated_visit_limit_notifies_once_a_day(): void
    {
        config(['g7_settings.core.analytics.max_actions_per_visit' => 1]);

        $fired = 0;
        HookManager::addAction('core.analytics.visit_limit_exceeded', function () use (&$fired): void {
            $fired++;
        });

        $this->service->track($this->request());
        $this->service->track($this->request(['url' => 'https://example.test/2']));
        $this->service->track($this->request(['url' => 'https://example.test/3']));
        $this->service->track($this->request(['url' => 'https://example.test/4']));

        $this->assertSame(1, $fired, '같은 알림이 요청마다 나가면 알림함이 한 방문으로 가득 찬다');
        $this->assertSame(
            3,
            AnalyticsCollectFailure::where('reason', CollectFailureReason::MaxActions->value)->count(),
            '알림은 줄여도 왜 버렸는지는 매번 남아야 한다',
        );
    }

    /**
     * 적재 중 예외는 실패 로그 행과 애플리케이션 경고 로그로 남고 밖으로 나가지 않는다.
     *
     * @scenario source=beacon, exclusion=none
     *
     * @effects tracking_never_throws_to_the_caller, failures_are_recorded_with_a_reason, tracking_failure_is_logged_to_application_log
     *
     * @return void 없음
     */
    public function test_exception_is_logged_twice_and_swallowed(): void
    {
        $this->mock(VisitRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findOpenVisitForUpdate')->andThrow(new \RuntimeException('deadlock'));
        });
        Log::spy();

        $result = app(VisitService::class)->track($this->request());

        $this->assertNull($result['visit_id']);
        $this->assertSame(1, AnalyticsCollectFailure::where('reason', CollectFailureReason::DbError->value)->count());
        $this->assertStringContainsString('deadlock', AnalyticsCollectFailure::first()->message);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === '방문자 통계 적재 실패')
            ->once();
    }

    /**
     * 쿠키 없는 서버 폴백 뒤에 온 쿠키 없는 첫 비콘은 그 방문을 이어받는다.
     *
     * 서버 폴백은 응답 뒤(terminate)에 돌아 쿠키를 심을 수 없다. 그래서 신규 방문자의
     * 첫 로드는 서버 폴백도 첫 비콘도 쿠키가 없다 — 둘이 각자 새 키를 만들면 한 사람의
     * 첫 로드가 방문 2건(하나는 이탈 100%)이 되어 순방문자와 이탈률이 구조적으로 부푼다.
     *
     * @scenario source=server+beacon, visitor=guest, visit_state=new, merge=within_window
     *
     * @effects cookieless_server_fallback_is_adopted_by_first_beacon, server_fallback_and_first_beacon_merge_into_one_action
     *
     * @return void 없음
     */
    public function test_cookieless_server_fallback_is_adopted_by_first_beacon(): void
    {
        $server = $this->service->track($this->request(['source' => 'server', 'cookies' => [], 'resolution' => null, 'title' => null]));
        $beacon = $this->service->track($this->request(['source' => 'beacon', 'cookies' => [], 'init' => true]));

        $this->assertSame(1, AnalyticsVisit::count(), '첫 로드는 방문 1건이어야 한다');
        $this->assertSame($server['visit_id'], $beacon['visit_id'], '비콘이 서버 폴백의 방문을 이어받는다');
        $this->assertSame($server['action_id'], $beacon['action_id'], '페이지뷰도 병합된다');

        $visit = AnalyticsVisit::find($beacon['visit_id']);
        $this->assertNotNull($beacon['new_cookie'], '비콘 응답에 쿠키가 나간다');
        $this->assertSame($beacon['new_cookie'], $visit->visitor_key, '방문 키가 그 쿠키 값으로 바뀐다');
        $this->assertSame('cookie', $visit->visitor_key_type);
        $this->assertSame(1, $visit->pageviews_count);
        $this->assertSame($visit->visitor_key, AnalyticsAction::find($beacon['action_id'])->visitor_key, '액션의 키 복제본도 함께 바뀐다');

        // 쿠키를 받은 뒤의 이동은 같은 방문에 붙는다.
        $next = $this->service->track($this->request(['cookies' => ['g7_vid' => $visit->visitor_key], 'url' => 'https://example.test/2']));
        $this->assertSame($visit->id, $next['visit_id']);
        $this->assertSame(1, AnalyticsVisit::count());
    }

    /**
     * 익명 모드에서는 서버 폴백과 비콘이 같은 일자 해시를 얻어 한 방문이 된다.
     *
     * 서버 요청에는 화면 해상도와 클라이언트 힌트가 없다. 그 값이 해시 재료에 들어가면
     * 두 경로가 영원히 다른 키를 얻어 매 로드가 방문 2건이 된다.
     *
     * @scenario mode=anonymous, source=server+beacon, visit_state=new
     *
     * @effects anonymous_server_fallback_and_beacon_share_a_visit, anonymous_visitor_key_is_deterministic_within_a_day
     *
     * @return void 없음
     */
    public function test_anonymous_server_fallback_and_beacon_share_visit(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'anonymous']);

        $server = $this->service->track($this->request(['source' => 'server', 'cookies' => [], 'resolution' => null, 'title' => null]));
        $beacon = $this->service->track($this->request([
            'source' => 'beacon',
            'cookies' => [],
            'init' => true,
            'resolution' => '1920x1080',
            'clientHints' => ['brands' => [['brand' => 'Google Chrome', 'version' => '128']], 'platform' => 'Windows', 'mobile' => false],
        ]));

        $this->assertSame(1, AnalyticsVisit::count());
        $this->assertSame($server['visit_id'], $beacon['visit_id']);
        $this->assertSame($server['action_id'], $beacon['action_id']);
        $this->assertNull($beacon['new_cookie']);
    }

    /**
     * 목표 액션이 전환 원장에 한 행을 남긴다.
     *
     * 액션 1행만으로는 「무엇을 얼마에 달성했는지」가 남지 않는다. 이 단언이 없으면
     * 전환이 수신 후 조용히 버려져도 액션 수는 정상이라 아무도 알아채지 못한다.
     *
     * @scenario source=beacon, visit_state=new, visitor=guest
     *
     * @effects goal_action_records_a_conversion_row, conversion_keeps_the_supplying_extension_identifier, conversion_revenue_and_currency_are_normalized
     *
     * @return void 없음
     */
    public function test_goal_action_records_conversion(): void
    {
        $result = $this->service->track($this->request([
            'type' => 'goal',
            'goalKey' => 'sirsoft-ecommerce.order_completed',
            'goalAttributes' => ['revenue' => 45000, 'currency' => 'krw', 'label' => '#1023'],
        ]));

        $this->assertSame(1, AnalyticsConversion::count());

        $conversion = AnalyticsConversion::first();

        $this->assertSame($result['visit_id'], $conversion->visit_id);
        $this->assertSame('sirsoft-ecommerce.order_completed', $conversion->goal_key);
        // 확장 식별자가 없으면 확장을 지울 때 그 확장의 전환만 골라낼 수 없다.
        $this->assertSame('sirsoft-ecommerce', $conversion->extension_identifier);
        $this->assertSame('#1023', $conversion->label);
        $this->assertSame('45000.00', $conversion->revenue);
        $this->assertSame('KRW', $conversion->currency);
    }

    /**
     * 코어 목표는 확장 소유로 기록되지 않는다.
     *
     * `core.` 를 확장 식별자로 적으면 확장 제거 정리가 코어 전환까지 지운다.
     *
     * @scenario source=beacon, visit_state=new, visitor=guest
     *
     * @effects core_goal_is_not_attributed_to_an_extension
     *
     * @return void 없음
     */
    public function test_core_goal_is_not_attributed_to_extension(): void
    {
        // 코어 목표는 서버 경로로만 받는다(비콘 위조 차단 — ConversionRecorderTest).
        $this->service->track($this->request([
            'type' => 'goal',
            'goalKey' => 'core.user_registered',
            'source' => 'server',
        ]));

        $conversion = AnalyticsConversion::first();

        $this->assertNotNull($conversion);
        $this->assertNull($conversion->extension_identifier);
        $this->assertNull($conversion->revenue);
    }

    /**
     * 선언된 스키마를 어긴 전환 속성은 그 목표 비콘을 버리고 사유를 남긴다.
     *
     * 금액 자리에 문자열이 오면 확장이 선언한 `decimal` 스키마 위반이다. 조용히 금액만 비워
     * 기록하면 「매출 0 원 주문」이 쌓여 매출 합계가 틀린다 — 버리고 실패 목록에 남긴다.
     * 예외는 여전히 밖으로 나가지 않는다.
     *
     * @scenario source=beacon, visit_state=new, visitor=guest
     *
     * @effects malformed_conversion_attributes_do_not_break_tracking, goal_attribute_schema_violation_is_logged_as_validation
     *
     * @return void 없음
     */
    public function test_malformed_conversion_attributes_do_not_break_tracking(): void
    {
        $result = $this->service->track($this->request([
            'type' => 'goal',
            'goalKey' => 'sirsoft-ecommerce.order_completed',
            'goalAttributes' => ['revenue' => '공짜', 'currency' => 'KRW', 'label' => ''],
        ]));

        $this->assertNull($result['action_id']);
        $this->assertSame(0, AnalyticsConversion::count());
        $this->assertSame(0, AnalyticsAction::count());

        $failure = AnalyticsCollectFailure::first();
        $this->assertNotNull($failure);
        $this->assertSame(CollectFailureReason::Validation->value, $failure->reason instanceof \BackedEnum ? $failure->reason->value : $failure->reason);
        $this->assertStringContainsString('revenue', (string) $failure->message);
    }

    /**
     * 통화 칸에 3자리가 아닌 값이 오면 통화만 비운다.
     *
     * 통화는 원장 칸 규칙(대문자 3자리)이 따로 있다. 스키마를 통과한 문자열이라도 그 규칙에
     * 어긋나면 칸을 비워 적재를 깨뜨리지 않는다.
     *
     * @scenario source=beacon, visit_state=new, visitor=guest
     *
     * @effects conversion_revenue_and_currency_are_normalized
     *
     * @return void 없음
     */
    public function test_invalid_currency_code_is_left_empty(): void
    {
        $this->service->track($this->request([
            'type' => 'goal',
            'goalKey' => 'sirsoft-ecommerce.order_completed',
            'goalAttributes' => ['revenue' => 100, 'currency' => 'KRW1', 'label' => ''],
        ]));

        $conversion = AnalyticsConversion::first();

        $this->assertNotNull($conversion);
        $this->assertSame('100.00', $conversion->revenue);
        $this->assertNull($conversion->currency);
        $this->assertNull($conversion->label);
    }

    /**
     * 컬럼 상한을 넘는 금액이 적재를 깨뜨리지 않는다.
     *
     * `revenue` 는 `decimal(15,2)` 라 정수부 13자리가 한계다. 넘는 값을 그대로 넘기면
     * INSERT 가 실패해 그 방문의 액션까지 통째로 사라진다.
     *
     * @scenario source=beacon, visit_state=new, visitor=guest
     *
     * @effects malformed_conversion_attributes_do_not_break_tracking
     *
     * @return void 없음
     */
    public function test_oversized_conversion_revenue_is_dropped(): void
    {
        $result = $this->service->track($this->request([
            'type' => 'goal',
            'goalKey' => 'sirsoft-ecommerce.order_completed',
            'goalAttributes' => ['revenue' => '99999999999999999', 'currency' => 'KRW'],
        ]));

        $this->assertNotNull($result['action_id']);
        $this->assertSame(1, AnalyticsConversion::count());
        $this->assertNull(AnalyticsConversion::first()->revenue);
    }

    /**
     * 목표가 아닌 액션은 전환을 남기지 않는다.
     *
     * @scenario source=beacon, visit_state=new, visitor=guest
     *
     * @effects non_goal_actions_record_no_conversion
     *
     * @return void 없음
     */
    public function test_non_goal_action_records_no_conversion(): void
    {
        $this->service->track($this->request());
        $this->service->track($this->request(['type' => 'download', 'target' => 'https://example.test/report.pdf']));

        $this->assertSame(0, AnalyticsConversion::count());
    }

    /**
     * 내려받기 액션이 보내온 표시 이름을 사전에 담는지 확인합니다.
     *
     * 트래커는 `download` 속성 → 링크 글자 → 파일명 순으로 표시 이름을 만들어 보낸다.
     * 그 값을 버리고 주소를 담으면 방문자 타임라인(§4.11)이 「다운로드 report.pdf」 대신
     * 긴 주소를 그리고, 내려받기 리포트의 라벨도 파일마다가 아니라 주소마다가 된다.
     *
     * @return void 없음
     *
     * @scenario source=beacon, visit_state=new, visitor=guest
     *
     * @effects download_action_name_prefers_the_display_name
     */
    public function test_download_action_stores_the_display_name(): void
    {
        $this->service->track($this->request([
            'type' => 'download',
            'target' => 'https://example.test/files/a8f3e1/report.pdf?token=abc',
            'name' => 'report.pdf',
        ]));

        $action = AnalyticsAction::where('action_type', 'download')->firstOrFail();

        $this->assertSame(
            'report.pdf',
            (string) AnalyticsActionName::findOrFail($action->action_name_id)->name,
            '보내온 표시 이름이 사전에 담긴다'
        );
    }

    /**
     * 이름을 실은 이벤트는 이름을 `event` 종류 사전에 담고, 이름 없는 이벤트는 예전처럼 주소를 담는지 확인합니다.
     *
     * 이벤트 리포트는 `event` 종류 사전 행만 센다 — 이름을 버리면 「어떤 이벤트가 몇 번」을 셀 수 없다.
     *
     * @return void 없음
     *
     * @scenario source=beacon, visit_state=new, visitor=guest
     *
     * @effects event_name_is_stored_in_the_event_dictionary
     */
    public function test_event_name_is_stored_as_event_type(): void
    {
        $this->service->track($this->request(['type' => 'event', 'name' => 'coupon_click']));
        $this->service->track($this->request(['type' => 'event']));

        $names = AnalyticsAction::where('action_type', 'event')
            ->orderBy('id')
            ->get()
            ->map(fn ($action): array => [
                AnalyticsActionName::findOrFail($action->action_name_id)->type,
                (string) AnalyticsActionName::findOrFail($action->action_name_id)->name,
            ])
            ->all();

        $this->assertSame('event', $names[0][0]);
        $this->assertSame('coupon_click', $names[0][1]);
        $this->assertSame('url', $names[1][0], '이름 없는 이벤트는 주소(url 종류)를 가리킨다');
    }

    /**
     * 방문 도중 로그인하면 그때부터 새 방문이 되고 유형·회원 번호가 따라간다.
     *
     * 이어 붙이면 로그인한 관리자의 열람이 게스트 방문으로 남아 「관리자 제외」로 빠지지
     * 않는다 — 계획서 §8 M1-T5 가 「로그인 후 이동 → 새 방문 행 visitor_type=member」를
     * 기대하는 이유다. 방문자 키(쿠키)는 그대로다.
     *
     * @scenario source=beacon, visitor=member, visit_state=open
     *
     * @effects login_during_a_visit_starts_a_new_visit
     *
     * @return void 없음
     */
    public function test_logging_in_during_a_visit_starts_a_new_visit(): void
    {
        $this->service->track($this->request());

        $this->service->track($this->request([
            'url' => 'https://example.test/board/free/2',
            'init' => false,
            'userId' => 42,
        ]));

        $visits = AnalyticsVisit::query()->orderBy('id')->get();

        $this->assertCount(2, $visits, '로그인 전후는 다른 사람의 방문이다');
        $this->assertSame('guest', $this->enumValue($visits[0]->visitor_type));
        $this->assertNull($visits[0]->user_id);
        $this->assertSame('member', $this->enumValue($visits[1]->visitor_type));
        $this->assertSame(42, (int) $visits[1]->user_id);
        $this->assertSame($visits[0]->visitor_key, $visits[1]->visitor_key, '같은 브라우저다 — 방문자 키는 그대로');
    }

    /**
     * 같은 사람이 이어 보는 동안에는 방문이 갈라지지 않는다.
     *
     * @scenario source=beacon, visitor=member, visit_state=open
     *
     * @effects login_during_a_visit_starts_a_new_visit
     *
     * @return void 없음
     */
    public function test_same_member_keeps_one_visit(): void
    {
        $this->service->track($this->request(['userId' => 42]));
        $this->service->track($this->request(['url' => 'https://example.test/board/free/2', 'init' => false, 'userId' => 42]));

        $this->assertSame(1, AnalyticsVisit::query()->count());
        $this->assertSame(2, (int) AnalyticsVisit::query()->value('pageviews_count'));
    }

    /**
     * 페이지 요청과 첫 비콘의 신원이 어긋나면, 서버가 남긴 자리표시 페이지뷰는 비콘 쪽 방문으로 넘어간다.
     * [case:analytics-server-beacon-identity-mismatch]
     *
     * 브라우저 실측(계획서 §8 M1-T5, 2026-09-19): 게스트 방문이 열린 채 웹 세션 없이 토큰으로만
     * 로그인된 회원이 새로고침하면, 쿠키만 실린 페이지 요청은 게스트로 잡혀 열린 게스트 방문에
     * 페이지뷰를 붙이고, 토큰을 실은 첫 비콘은 회원 방문을 새로 열어 같은 페이지를 또 적었다 —
     * 한 번 연 페이지가 두 방문에 한 번씩 세어졌다. 웹 세션(기본 120분)과 토큰(기본 30분)의
     * 수명이 달라 두 신원이 어긋나는 구간은 실제로 생긴다.
     *
     * @scenario source=server+beacon, init=true, merge=within_window, visitor=member, visit_state=open
     *
     * @effects server_placeholder_moves_to_the_beacon_visit_when_identities_differ
     *
     * @return void 없음
     */
    public function test_server_placeholder_moves_to_the_beacon_visit_when_identities_differ(): void
    {
        // 게스트가 다른 페이지를 보고 있던 열린 방문.
        $this->service->track($this->request(['url' => 'https://example.test/board/notice', 'init' => true]));
        // 새로고침 — 페이지 요청에는 세션이 없어 게스트로 잡힌다.
        $this->service->track($this->request(['source' => 'server', 'title' => null]));
        // 같은 로드의 첫 비콘은 토큰을 실어 회원으로 잡힌다.
        $beacon = $this->service->track($this->request(['init' => true, 'userId' => 42]));

        $freeNameId = AnalyticsAction::find($beacon['action_id'])->action_name_id;
        $this->assertSame(1, AnalyticsAction::query()->where('action_name_id', $freeNameId)->count(), '한 번 연 페이지는 한 번만 센다');

        $guest = AnalyticsVisit::query()->whereNull('user_id')->firstOrFail();
        $member = AnalyticsVisit::query()->where('user_id', 42)->firstOrFail();
        $this->assertSame(1, (int) $guest->pageviews_count, '게스트 방문에는 게스트가 실제로 본 페이지만 남는다');
        $this->assertSame(1, (int) $guest->actions_count);
        $this->assertTrue((bool) $guest->is_bounce, '한 쪽만 본 게스트 방문은 이탈이다');
        $this->assertSame(
            (int) AnalyticsAction::query()->where('visit_id', $guest->id)->value('action_name_id'),
            (int) $guest->exit_action_name_id,
            '이탈 페이지가 남은 마지막 페이지로 되돌아간다',
        );
        $this->assertSame(1, (int) $member->pageviews_count);
        $this->assertSame($member->id, (int) $beacon['visit_id']);
    }

    /**
     * 반대 방향(페이지 요청은 회원, 첫 비콘은 게스트)도 같다 — 비콘 쪽 신원이 화면에 보이는 신원이다.
     *
     * 토큰이 먼저 끝나고 웹 세션이 남은 구간이다(기본 토큰 30분 < 세션 120분).
     *
     * @scenario source=server+beacon, init=true, merge=within_window, visitor=guest, visit_state=open
     *
     * @effects server_placeholder_moves_to_the_beacon_visit_when_identities_differ
     *
     * @return void 없음
     */
    public function test_server_placeholder_moves_when_the_page_request_is_the_member(): void
    {
        $this->service->track($this->request(['url' => 'https://example.test/board/notice', 'init' => true, 'userId' => 42]));
        $this->service->track($this->request(['source' => 'server', 'title' => null, 'userId' => 42]));
        $beacon = $this->service->track($this->request(['init' => true]));

        $freeNameId = AnalyticsAction::find($beacon['action_id'])->action_name_id;
        $this->assertSame(1, AnalyticsAction::query()->where('action_name_id', $freeNameId)->count(), '한 번 연 페이지는 한 번만 센다');
        $this->assertSame(1, (int) AnalyticsVisit::query()->where('user_id', 42)->value('pageviews_count'));
        $this->assertSame(1, (int) AnalyticsVisit::query()->whereNull('user_id')->value('pageviews_count'));
    }

    /**
     * 신원이 어긋나도 서버 자리표시가 아닌 페이지뷰(제목이 있는 비콘 기록)는 건드리지 않는다.
     *
     * 게스트가 비콘으로 본 페이지를, 로그인 뒤 같은 URL 로 새로 열어도 게스트가 본 기록은 사실이다.
     *
     * @scenario source=beacon, init=true, merge=within_window, visitor=member, visit_state=open
     *
     * @effects server_placeholder_moves_to_the_beacon_visit_when_identities_differ
     *
     * @return void 없음
     */
    public function test_beacon_pageviews_of_the_other_identity_are_kept(): void
    {
        $this->service->track($this->request(['init' => true]));
        $this->service->track($this->request(['init' => true, 'userId' => 42]));

        $this->assertSame(2, AnalyticsAction::count(), '두 신원이 각각 본 페이지뷰는 둘 다 남는다');
        $this->assertSame(1, (int) AnalyticsVisit::query()->whereNull('user_id')->value('pageviews_count'));
    }

    /**
     * Enum 이거나 문자열인 값에서 문자열을 꺼냅니다.
     *
     * @param  mixed  $value  값
     * @return string 문자열 값
     */
    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
