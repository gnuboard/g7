<?php

namespace Tests\Feature\Analytics;

use App\Analytics\ExclusionCounter;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\VisitSource;
use App\Http\Middleware\Analytics\TrackServerVisit;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * 서버 폴백 미들웨어 — 화면 응답만 세고 봇·API·제외 경로는 세지 않는다.
 *
 * 자바스크립트가 막힌 방문자도 세려고 둔 경로라, 잘못 켜지면 API 호출·리다이렉트까지
 * 페이지뷰가 되고 잘못 꺼지면 그 방문자는 영영 0 이다. 둘 다 화면에는 아무 증상이 없다.
 */
class TrackServerVisitMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private TrackServerVisit $middleware;

    /**
     * 테스트 준비 — 즉시 적재로 두고 미들웨어를 해석한다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['g7_settings.core.analytics.dispatch_mode' => 'sync']);

        $this->middleware = app(TrackServerVisit::class);
    }

    /**
     * SPA 셸 GET 요청 하나를 만듭니다.
     *
     * @param  string  $path  요청 경로
     * @return Request HTTP 요청
     */
    private function shellRequest(string $path = '/board/free'): Request
    {
        return Request::create($path, 'GET', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'HTTP_ACCEPT_LANGUAGE' => 'ko-KR,ko;q=0.9',
            'REMOTE_ADDR' => '203.0.113.50',
        ]);
    }

    /**
     * HTML 응답 하나를 만듭니다.
     *
     * @param  int  $status  상태 코드
     * @return Response HTML 응답
     */
    private function htmlResponse(int $status = 200): Response
    {
        return new Response('<!doctype html><html></html>', $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * SPA 셸 GET 은 server 출처 페이지뷰 1건이 된다.
     *
     * @scenario source=server, visitor=guest, visit_state=new
     *
     * @effects first_request_creates_visit_and_action, server_fallback_records_html_page_loads_only
     *
     * @return void 없음
     */
    public function test_html_shell_get_records_server_pageview(): void
    {
        $this->middleware->terminate($this->shellRequest(), $this->htmlResponse());

        $this->assertSame(1, AnalyticsVisit::count());
        $this->assertSame(VisitSource::Server, AnalyticsVisit::first()->source);
        $this->assertSame(1, AnalyticsAction::where('action_type', ActionType::Pageview->value)->count());
    }

    /**
     * JSON 응답·오류 응답·POST 는 페이지 열람이 아니다.
     *
     * @scenario source=server, exclusion=none
     *
     * @effects server_fallback_records_html_page_loads_only
     *
     * @return void 없음
     */
    public function test_non_html_error_and_post_are_ignored(): void
    {
        $this->middleware->terminate($this->shellRequest('/api/search'), new JsonResponse(['ok' => true]));
        $this->middleware->terminate($this->shellRequest(), $this->htmlResponse(404));
        $this->middleware->terminate($this->shellRequest(), $this->htmlResponse(302));
        $this->middleware->terminate(Request::create('/board/free', 'POST'), $this->htmlResponse());

        $this->assertSame(0, AnalyticsVisit::count());
    }

    /**
     * SEO 가 봇으로 판정한 요청은 사람 원장에 들어가지 않는다.
     *
     * @scenario source=server, visitor=bot
     *
     * @effects bot_requests_never_enter_the_visit_ledger
     *
     * @return void 없음
     */
    public function test_bot_attribute_skips_visit_ledger(): void
    {
        $request = $this->shellRequest();
        $request->attributes->set('seo.is_bot', true);

        $this->middleware->terminate($request, $this->htmlResponse());

        $this->assertSame(0, AnalyticsVisit::count());
    }

    /**
     * 기본 제외 패턴(`/admin*`)은 서버 폴백에도 적용되고 사유가 세어진다.
     *
     * @scenario source=server, exclusion=url_pattern
     *
     * @effects excluded_requests_record_nothing, each_exclusion_reason_is_counted_separately
     *
     * @return void 없음
     */
    public function test_excluded_pattern_applies_to_server_fallback(): void
    {
        $before = app(ExclusionCounter::class)->forDate()['url_pattern'];

        $this->middleware->terminate($this->shellRequest('/admin/users'), $this->htmlResponse());

        $this->assertSame(0, AnalyticsVisit::count());
        $this->assertSame($before + 1, app(ExclusionCounter::class)->forDate()['url_pattern']);
    }

    /**
     * HTTP 경로 전체 — 서버 폴백 뒤의 첫 비콘이 같은 방문에 붙고 쿠키를 받는다.
     *
     * @scenario source=server+beacon, visitor=guest, visit_state=new, dispatch=sync
     *
     * @effects cookieless_server_fallback_is_adopted_by_first_beacon
     *
     * @return void 없음
     */
    public function test_first_beacon_after_server_fallback_joins_the_same_visit(): void
    {
        $this->middleware->terminate($this->shellRequest('/board/free'), $this->htmlResponse());
        $this->assertSame(1, AnalyticsVisit::count());

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept-Language' => 'ko-KR,ko;q=0.9',
            ])
            ->postJson('/api/analytics/collect', [
                't' => 'pageview',
                'url' => 'http://localhost/board/free',
                'title' => '자유게시판',
                'lang' => 'ko-KR',
                'res' => '1920x1080',
                'init' => true,
            ]);

        $response->assertNoContent();
        $response->assertCookie(config('analytics.cookies.visitor', 'g7_vid'));
        $this->assertSame(1, AnalyticsVisit::count(), '첫 로드는 방문 1건');
        $this->assertSame(1, AnalyticsAction::count(), '페이지뷰도 1건');
        $this->assertSame('cookie', AnalyticsVisit::first()->visitor_key_type);
    }

    /**
     * 수집이 꺼져 있으면 서버 폴백도 아무것도 남기지 않는다.
     *
     * @scenario source=server, exclusion=disabled
     *
     * @effects excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_disabled_collection_records_nothing(): void
    {
        config(['g7_settings.core.analytics.enabled' => false]);

        $this->middleware->terminate($this->shellRequest(), $this->htmlResponse());

        $this->assertSame(0, AnalyticsVisit::count());
    }
}
