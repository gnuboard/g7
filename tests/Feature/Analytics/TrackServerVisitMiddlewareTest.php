<?php

namespace Tests\Feature\Analytics;

use App\Analytics\ExclusionCounter;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\VisitSource;
use App\Http\Middleware\Analytics\TrackServerVisit;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Cookie\Middleware\EncryptCookies;
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
     * @scenario source=server+beacon, visitor=guest, visit_state=new
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
     * 화면 요청을 web 그룹의 쿠키 미들웨어에 통과시킨 뒤 서버 폴백을 돌립니다.
     *
     * 운영에서 서버 폴백이 보는 쿠키는 이 미들웨어를 거친 값이다. 미들웨어를 건너뛰고
     * 쿠키를 바로 넣으면 「평문 쿠키를 web 그룹이 못 읽는」 결함이 테스트에서 사라진다.
     *
     * @param  string  $path  요청 경로
     * @param  string  $visitorCookie  브라우저가 가진 방문자 쿠키(수집 API 가 심은 값 그대로)
     * @return void 없음
     */
    private function reloadThroughWebCookieMiddleware(string $path, string $visitorCookie): void
    {
        $request = Request::create($path, 'GET', [], ['g7_vid' => $visitorCookie], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'HTTP_ACCEPT_LANGUAGE' => 'ko-KR,ko;q=0.9',
            'REMOTE_ADDR' => '203.0.113.50',
        ]);

        $seen = null;
        app(EncryptCookies::class)->handle($request, function (Request $passed) use (&$seen): Response {
            $seen = $passed;

            return new Response('');
        });

        $this->assertInstanceOf(Request::class, $seen);
        $this->middleware->terminate($seen, $this->htmlResponse());
    }

    /**
     * 브라우저가 쿠키를 가진 채 비콘을 보냅니다.
     *
     * @param  string  $visitorCookie  방문자 쿠키
     * @param  array<string, mixed>  $payload  비콘 본문
     * @return void 없음
     */
    private function beaconWithCookie(string $visitorCookie, array $payload): void
    {
        $this->withCredentials()
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept-Language' => 'ko-KR,ko;q=0.9',
            ])
            ->withUnencryptedCookie('g7_vid', $visitorCookie)
            ->postJson('/api/analytics/collect', $payload)
            ->assertNoContent();
    }

    /**
     * 재방문자가 새로고침해도 방문은 하나이고 페이지뷰는 한 번만 센다.
     *
     * 수집 API 는 쿠키 암호화 미들웨어가 없는 api 그룹이라 방문자 쿠키를 평문으로 심는다.
     * 화면 요청(web 그룹)이 그것을 복호화하려다 실패하면 서버 폴백이 쿠키 없는 방문자로 보고
     * 임시 키로 방문을 따로 연다 — 전체 로드마다 방문이 둘로 갈라지고 페이지뷰가 2배가 된다.
     *
     * @scenario source=server, visitor=guest, visit_state=open, merge=within_window
     *
     * @effects returning_visitor_reload_stays_in_one_visit
     *
     * @return void 없음
     */
    public function test_returning_visitor_reload_stays_in_one_visit(): void
    {
        $cookie = str_repeat('d', 32);

        $this->travel(-5)->minutes();
        $this->beaconWithCookie($cookie, ['t' => 'pageview', 'url' => 'http://localhost/', 'init' => true]);
        $this->travelBack();

        $this->reloadThroughWebCookieMiddleware('/board/free', $cookie);
        $this->beaconWithCookie($cookie, ['t' => 'pageview', 'url' => 'http://localhost/board/free', 'init' => true]);

        $this->assertSame(1, AnalyticsVisit::count(), '새로고침이 방문을 둘로 가르면 안 된다');
        $this->assertSame(2, AnalyticsVisit::first()->pageviews_count, '두 화면 = 페이지뷰 2 (서버 폴백과 첫 비콘은 한 번으로)');
    }

    /**
     * 재방문자가 방문 창(30분) 뒤에 다시 오면 새 방문은 정확히 하나다.
     *
     * @scenario source=server, visitor=guest, visit_state=timed_out, merge=within_window
     *
     * @effects returning_visitor_reload_stays_in_one_visit
     *
     * @return void 없음
     */
    public function test_returning_visitor_after_timeout_opens_exactly_one_visit(): void
    {
        $cookie = str_repeat('b', 32);

        $this->travel(-40)->minutes();
        $this->beaconWithCookie($cookie, ['t' => 'pageview', 'url' => 'http://localhost/', 'init' => true]);
        $this->travelBack();

        $this->reloadThroughWebCookieMiddleware('/board/free', $cookie);
        $this->beaconWithCookie($cookie, ['t' => 'pageview', 'url' => 'http://localhost/board/free', 'init' => true]);

        $this->assertSame(2, AnalyticsVisit::count(), '옛 방문 1 + 새 방문 1 — 서버 폴백이 따로 방문을 열면 3');
        $this->assertSame(0, AnalyticsVisit::query()->where('visitor_key_type', 'server')->count());
    }

    /**
     * 서버 폴백이 연 방문에 첫 비콘이 합류하면 서버가 몰랐던 값을 채우고 출처가 비콘이 된다.
     *
     * 문서 요청에는 해상도가 없고 로케일은 서버의 짐작이다. 채우지 않으면 해상도 리포트가
     * 비고, 브라우저가 보고한 방문까지 「서버로만 잡힌 방문」으로 세어진다.
     *
     * @scenario source=beacon, visitor=guest, visit_state=new, merge=within_window
     *
     * @effects first_beacon_fills_what_the_server_could_not_know
     *
     * @return void 없음
     */
    public function test_first_beacon_fills_what_the_server_could_not_know(): void
    {
        $this->middleware->terminate($this->shellRequest('/board/free'), $this->htmlResponse());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept-Language' => 'ko-KR,ko;q=0.9',
            ])
            ->postJson('/api/analytics/collect', [
                't' => 'pageview',
                'url' => 'http://localhost/board/free',
                'res' => '1920x1080',
                'locale' => 'ja',
                'ref' => 'https://www.google.com/search?q=g7',
                'init' => true,
            ])
            ->assertNoContent();

        $visit = AnalyticsVisit::first();

        $this->assertSame(1, AnalyticsVisit::count());
        $this->assertSame(VisitSource::Beacon, $visit->source);
        $this->assertSame('1920x1080', $visit->screen_resolution);
        $this->assertSame('ja', $visit->locale);
        $this->assertNotNull($visit->referrer_url, '문서 요청에 없던 유입을 비콘 값으로 채운다');
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
