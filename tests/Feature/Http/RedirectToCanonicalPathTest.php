<?php

namespace Tests\Feature\Http;

use App\Seo\TemplateRouteResolver;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 끝·연속 슬래시 화면 요청의 정규형 301 이동을 검증합니다 (공개#156).
 *
 * 테스트 헬퍼 `$this->get()` 은 `url()` 로 주소를 만들며 끝 슬래시를 떼므로, 요청은 원문 경로를
 * 그대로 담은 Request 를 직접 만들어 HTTP 커널에 통과시킨다. 화면 셸 라우트 묶음 전체(앞뒤 미들웨어
 * 포함)를 지나므로 순서 계약(봇 기록·SEO·통계보다 앞에서 응답)까지 함께 확인된다.
 */
class RedirectToCanonicalPathTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 사이트 주소와 템플릿 라우트 판정을 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://site.example.test', 'app.installer_completed' => true]);
        $this->bindResolver(true);
    }

    /**
     * routeExists 가 지정 값을 반환하도록 resolver 를 바인딩합니다.
     *
     * @param  bool  $exists  routeExists 반환값
     * @return void 반환값 없음
     */
    private function bindResolver(bool $exists): void
    {
        $resolver = Mockery::mock(TemplateRouteResolver::class);
        $resolver->shouldReceive('routeExists')->andReturn($exists);
        $resolver->shouldReceive('resolve')->andReturn(null);
        $this->app->instance(TemplateRouteResolver::class, $resolver);
    }

    /**
     * 원문 요청 URI 그대로 HTTP 커널에 요청을 보냅니다.
     *
     * @param  string  $requestUri  경로와 쿼리 원문 (예: `/admin/users/?b=2&a=1`)
     * @param  string  $method  HTTP 메서드
     * @param  array<string, string>  $headers  요청 헤더
     * @return TestResponse 응답
     */
    private function send(string $requestUri, string $method = 'GET', array $headers = []): TestResponse
    {
        $query = (string) parse_url('http://x'.$requestUri, PHP_URL_QUERY);
        $request = Request::create(config('app.url').'/', $method, [], [], [], $this->transformHeadersToServerVars($headers));
        $request->server->set('REQUEST_URI', $requestUri);
        $request->server->set('QUERY_STRING', $query);
        parse_str($query, $params);
        $request->query->replace($params);

        $response = $this->app->make(HttpKernel::class)->handle($request);

        return TestResponse::fromBaseResponse($response, $request);
    }

    /**
     * 끝·연속 슬래시 변종.
     *
     * @return array<string, array{0: string, 1: string}> 요청 URI 와 기대 Location 경로
     */
    public static function redirectCases(): array
    {
        return [
            '관리자 진입점 끝 슬래시' => ['/admin/', '/admin'],
            '쿼리 순서 보존' => ['/admin/users/?b=2&a=1', '/admin/users?b=2&a=1'],
            '관리자 연속 슬래시' => ['/admin//users', '/admin/users'],
            '사용자 연속·끝 슬래시' => ['/board//free/', '/board/free'],
            '동적 경로 끝 슬래시' => ['/board/free/123/', '/board/free/123'],
        ];
    }

    /**
     * 끝·연속 슬래시 화면 요청은 정규형 절대 주소로 301 이동한다.
     *
     * @scenario path_shape=trailing_slash, template_type=admin
     *
     * @effects trailing_slash_path_redirects_301_to_canonical, double_slash_path_redirects_301_collapsed
     *
     * @param  string  $requestUri  요청 URI
     * @param  string  $expectedPath  기대 Location 경로(쿼리 포함)
     * @return void 반환값 없음
     */
    #[DataProvider('redirectCases')]
    public function test_redirects_to_canonical_path(string $requestUri, string $expectedPath): void
    {
        $this->send($requestUri)
            ->assertStatus(301)
            ->assertHeader('Location', 'https://site.example.test'.$expectedPath);
    }

    /**
     * HEAD 요청도 이동한다(제보자 교차 재현 항목).
     *
     * @scenario path_shape=trailing_slash, template_type=user
     *
     * @effects trailing_slash_path_redirects_301_to_canonical
     *
     * @return void 반환값 없음
     */
    public function test_head_request_redirects(): void
    {
        $this->send('/board/free/', 'HEAD')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://site.example.test/board/free');
    }

    /**
     * 템플릿에 없는 주소도 먼저 이동하고, 이동한 곳에서 404 를 판정한다 (D2).
     *
     * @scenario path_shape=trailing_slash, route_registration=unregistered
     *
     * @effects trailing_slash_path_redirects_301_to_canonical, unregistered_user_path_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_path_redirects_then_404(): void
    {
        $this->bindResolver(false);

        $this->send('/%EC%97%86%EB%8A%94%EC%A3%BC%EC%86%8C/')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://site.example.test/%EC%97%86%EB%8A%94%EC%A3%BC%EC%86%8C');

        $this->send('/%EC%97%86%EB%8A%94%EC%A3%BC%EC%86%8C')->assertStatus(404);
    }

    /**
     * 정규형 경로·루트·POST·API·실제 디렉터리는 이동하지 않는다.
     *
     * @scenario path_shape=plain
     *
     * @effects canonical_path_redirect_skipped_when_not_applicable
     *
     * @return void 반환값 없음
     */
    public function test_skips_when_not_applicable(): void
    {
        $this->assertSame(200, $this->send('/')->getStatusCode());
        $this->assertSame(200, $this->send('/admin/users')->getStatusCode());
        $this->assertNotSame(301, $this->send('/admin/', 'POST')->getStatusCode());
        $this->assertNotSame(301, $this->send('/api/system/asset-probe/')->getStatusCode());

        $this->assertDirectoryExists(public_path('assets'));
        $this->assertNotSame(301, $this->send('/assets/')->getStatusCode());
    }

    /**
     * 프로토콜 상대 주소처럼 보이는 경로도 같은 사이트 안 경로로만 이동한다 (D4).
     *
     * @scenario path_shape=double_slash
     *
     * @effects double_slash_path_redirects_301_collapsed
     *
     * @return void 반환값 없음
     */
    public function test_protocol_relative_like_path_stays_on_site(): void
    {
        foreach (['//evil.com/', '/\\evil.com/'] as $requestUri) {
            $response = $this->send($requestUri)->assertStatus(301);

            $this->assertSame(
                'site.example.test',
                parse_url((string) $response->headers->get('Location'), PHP_URL_HOST),
                $requestUri.' 의 이동 주소가 다른 호스트를 가리키면 안 된다'
            );
        }
    }

    /**
     * 봇 요청도 SEO 렌더보다 앞에서 301 을 받는다(순서 계약).
     *
     * @scenario path_shape=trailing_slash, bot=bot
     *
     * @effects trailing_slash_path_redirects_301_to_canonical
     *
     * @return void 반환값 없음
     */
    public function test_bot_redirected_before_seo_render(): void
    {
        $response = $this->send('/board/free/', 'GET', ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);

        $response->assertStatus(301)
            ->assertHeader('Location', 'https://site.example.test/board/free')
            ->assertHeaderMissing('X-SEO-Cache');
    }

    /**
     * 화면 셸 두 묶음에만 canonical.host 바로 뒤에 붙는다.
     *
     * @scenario path_shape=trailing_slash
     *
     * @effects canonical_path_middleware_ordered_before_analytics_and_seo
     *
     * @return void 반환값 없음
     */
    public function test_attached_to_page_shells_right_after_canonical_host(): void
    {
        $orders = [];
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (in_array('canonical.path', $middleware, true)) {
                $orders[$route->uri()] = array_values(array_filter(
                    $middleware,
                    fn ($m) => in_array($m, ['canonical.host', 'canonical.path', 'analytics.bots', 'seo', 'analytics.fallback'], true)
                ));
            }
        }

        ksort($orders);
        $this->assertSame([
            'admin/{any?}' => ['canonical.host', 'canonical.path'],
            '{any?}' => ['canonical.host', 'canonical.path', 'analytics.bots', 'seo', 'analytics.fallback'],
        ], $orders);
    }
}
