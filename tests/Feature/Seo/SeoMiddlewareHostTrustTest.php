<?php

namespace Tests\Feature\Seo;

use App\Seo\BotDetector;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\Contracts\SeoRendererInterface;
use App\Seo\SeoCacheBounds;
use App\Seo\SeoCacheStatsService;
use App\Seo\SeoMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * SeoMiddleware 의 Host 비신뢰·동시 렌더 상한을 검증합니다.
 *
 * 렌더러·캐시는 목으로 두고 미들웨어가 직접 만드는 값(301 Location)과 렌더 게이트(예산·슬롯)만 본다.
 */
class SeoMiddlewareHostTrustTest extends TestCase
{
    private SeoMiddleware $middleware;

    private BotDetector $botDetector;

    private SeoCacheManagerInterface $cacheManager;

    private SeoRendererInterface $renderer;

    /**
     * 테스트 환경 설정 - 봇 요청·캐시 미스 상태의 미들웨어를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->botDetector = $this->createMock(BotDetector::class);
        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager = $this->createMock(SeoCacheManagerInterface::class);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer = $this->createMock(SeoRendererInterface::class);

        $this->middleware = new SeoMiddleware(
            $this->botDetector,
            $this->cacheManager,
            $this->renderer,
            $this->createMock(SeoCacheStatsService::class),
        );

        config([
            'app.url' => 'https://site.example',
            'app.locale' => 'ko',
            'app.supported_locales' => ['ko', 'en'],
            'g7_settings.core.seo.bot_detection_enabled' => true,
        ]);

        Cache::flush();
        RateLimiter::clear('seo-render:127.0.0.1');
        RateLimiter::clear('seo-stats:127.0.0.1');
    }

    /**
     * SPA 폴백 응답을 반환하는 Closure 를 생성합니다.
     *
     * @return \Closure SPA 응답 생성기
     */
    private function spaNext(): \Closure
    {
        return fn (Request $req) => response('SPA Fallback', 200, ['Content-Type' => 'text/html']);
    }

    /**
     * 위조 Host 로 기본 로케일을 명시해도 301 Location 은 사이트 주소를 가리킨다.
     *
     * 회귀: `$request->url()`(요청 Host)로 Location 을 만들어 다른 호스트로 보내는 리다이렉트가 됐다.
     *
     * @scenario host_header=forged, sink=locale_redirect
     *
     * @effects locale_redirect_uses_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_locale_redirect_ignores_forged_host(): void
    {
        $request = Request::create('https://attacker.example:6666/products?locale=ko&page=2');

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://site.example/products?page=2', $response->headers->get('Location'));
    }

    /**
     * 정상 Host 의 기본 로케일 리다이렉트도 사이트 주소 기준 clean URL 이다.
     *
     * @scenario host_header=genuine, sink=locale_redirect
     *
     * @effects locale_redirect_uses_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_locale_redirect_uses_site_origin_for_genuine_host(): void
    {
        $request = Request::create('https://site.example/products?locale=ko');

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://site.example/products', $response->headers->get('Location'));
    }

    /**
     * 동시 렌더 슬롯이 모두 차 있으면 렌더하지 않고 SPA + BYPASS(200, 429 아님)를 주며 예산을 되돌린다.
     *
     * @effects concurrent_render_over_cap_gets_spa_bypass, slot_full_refunds_render_budget
     *
     * @return void 반환값 없음
     */
    public function test_render_over_concurrency_cap_gets_spa_bypass(): void
    {
        config(['core.seo_cache_limits.max_concurrent_renders' => 1]);
        $busy = SeoCacheBounds::acquireRenderSlot();
        $this->assertNotNull($busy);

        $this->renderer->expects($this->never())->method('render');

        try {
            $response = $this->middleware->handle(Request::create('/products'), $this->spaNext());
        } finally {
            $busy();
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('BYPASS', $response->headers->get('X-SEO-Cache'));
        $this->assertSame('SPA Fallback', $response->getContent());
        $this->assertSame(0, (int) RateLimiter::attempts('seo-render:127.0.0.1'), '렌더하지 못한 요청은 예산을 쓰지 않는다');
    }

    /**
     * 렌더가 끝나면(성공·예외 모두) 슬롯을 돌려줘 다음 렌더가 들어올 수 있다.
     *
     * @effects render_slot_released_after_render
     *
     * @return void 반환값 없음
     */
    public function test_render_slot_is_released_after_success_and_exception(): void
    {
        config(['core.seo_cache_limits.max_concurrent_renders' => 1]);

        $this->renderer->method('render')->willReturnOnConsecutiveCalls(
            '<html>ok</html>',
            $this->throwException(new \RuntimeException('render failed')),
            '<html>again</html>',
        );

        $first = $this->middleware->handle(Request::create('/a'), $this->spaNext());
        $this->assertSame('MISS', $first->headers->get('X-SEO-Cache'));

        $second = $this->middleware->handle(Request::create('/b'), $this->spaNext());
        $this->assertSame('SPA Fallback', $second->getContent(), '렌더 예외는 SPA 폴백');

        $third = $this->middleware->handle(Request::create('/c'), $this->spaNext());
        $this->assertSame('MISS', $third->headers->get('X-SEO-Cache'), '예외 뒤에도 슬롯이 반환돼 렌더된다');
    }

    /**
     * `//다른호스트/…` 경로로 기본 로케일을 명시해도 301 Location 은 사이트 주소 안 경로다.
     *
     * 회귀: 요청 경로가 `//` 로 시작하면 완전 URL 로 취급되어 그대로 Location 이 됐다(봇 UA 전용
     * 오픈 리다이렉트 — UA 는 누구나 위조할 수 있다).
     *
     * @scenario host_header=genuine, sink=locale_redirect
     *
     * @effects locale_redirect_uses_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_locale_redirect_keeps_double_slash_path_inside_site(): void
    {
        $request = Request::create('https://site.example/x?locale=ko');
        $request->server->set('REQUEST_URI', '//evil.example/x?locale=ko');

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://site.example/evil.example/x', $response->headers->get('Location'));
    }
}
