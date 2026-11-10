<?php

namespace Tests\Feature\Seo;

use App\Seo\BotDetector;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\Contracts\SeoRendererInterface;
use App\Seo\SeoCacheManager;
use App\Seo\SeoCacheStatsService;
use App\Seo\SeoMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * SeoMiddleware 테스트
 *
 * 검색 봇 요청 시 SEO HTML 응답, 캐시 HIT/MISS, SPA 폴백 등을 검증합니다.
 */
class SeoMiddlewareTest extends TestCase
{
    private SeoMiddleware $middleware;

    private BotDetector $botDetector;

    private SeoCacheManagerInterface $cacheManager;

    private SeoRendererInterface $renderer;

    private SeoCacheStatsService $statsService;

    /**
     * 테스트 환경 설정
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->botDetector = $this->createMock(BotDetector::class);
        $this->cacheManager = $this->createMock(SeoCacheManagerInterface::class);
        $this->renderer = $this->createMock(SeoRendererInterface::class);
        $this->statsService = $this->createMock(SeoCacheStatsService::class);

        $this->middleware = new SeoMiddleware(
            $this->botDetector,
            $this->cacheManager,
            $this->renderer,
            $this->statsService,
        );

        // 렌더·통계 예산은 IP 단위 카운터다 — 테스트 간 이월되면 순서에 따라 결과가 갈린다.
        RateLimiter::clear('seo-render:127.0.0.1');
        RateLimiter::clear('seo-stats:127.0.0.1');
    }

    /**
     * SPA 폴백 응답을 반환하는 Closure를 생성합니다.
     *
     * @return \Closure SPA 폴백 응답을 돌려주는 다음 미들웨어 대역
     */
    private function spaNext(): \Closure
    {
        return fn (Request $req) => response('SPA Fallback', 200, ['Content-Type' => 'text/html']);
    }

    /**
     * 테스트용 Request 객체를 생성합니다.
     *
     * @param  string  $path  요청 경로
     * @param  string  $userAgent  User-Agent 헤더
     * @param  array  $query  쿼리 파라미터
     * @return Request 테스트용 요청
     */
    private function createRequest(string $path = '/products', string $userAgent = '', array $query = []): Request
    {
        $request = Request::create($path, 'GET', $query);

        if ($userAgent !== '') {
            $request->headers->set('User-Agent', $userAgent);
        }

        return $request;
    }

    // ========================================
    // 봇 감지 + SEO 응답 테스트
    // ========================================

    /**
     * Googlebot 요청 시 SEO HTML 응답을 반환하는지 검증
     *
     * @effects bot_receives_seo_html_on_miss
     *
     * @return void 반환값 없음
     */
    public function test_googlebot_receives_seo_html_response(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');
        $expectedHtml = '<html><head><title>SEO</title></head><body>Products</body></html>';

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($expectedHtml);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($expectedHtml, $response->getContent());
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
    }

    /**
     * 일반 브라우저 요청 시 SPA 응답을 반환하는지 검증
     *
     * @effects non_bot_or_disabled_detection_receives_spa
     *
     * @return void 반환값 없음
     */
    public function test_normal_browser_receives_spa_response(): void
    {
        $request = $this->createRequest('/products', 'Mozilla/5.0');

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(false);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals('SPA Fallback', $response->getContent());
    }

    // ========================================
    // 캐시 HIT/MISS 테스트
    // ========================================

    /**
     * 캐시 HIT 시 X-SEO-Cache: HIT 헤더와 캐시된 HTML을 반환하는지 검증
     *
     * @effects cache_hit_served_with_hit_header
     *
     * @return void 반환값 없음
     */
    public function test_cache_hit_returns_cached_html_with_hit_header(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');
        $cachedHtml = '<html><head><title>Cached SEO</title></head><body>Cached</body></html>';

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn($cachedHtml);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($cachedHtml, $response->getContent());
        $this->assertEquals('HIT', $response->headers->get('X-SEO-Cache'));
    }

    /**
     * 캐시 MISS 시 새 HTML 렌더링 후 X-SEO-Cache: MISS 헤더를 반환하는지 검증
     *
     * @effects bot_receives_seo_html_on_miss
     *
     * @return void 반환값 없음
     */
    public function test_cache_miss_returns_new_html_with_miss_header(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');
        $renderedHtml = '<html><head><title>Fresh SEO</title></head><body>Fresh</body></html>';

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($renderedHtml);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($renderedHtml, $response->getContent());
        $this->assertEquals('MISS', $response->headers->get('X-SEO-Cache'));
    }

    /**
     * 캐시 MISS 시 렌더링 결과를 캐시에 저장하는지 검증
     *
     * @effects bot_receives_seo_html_on_miss
     *
     * @return void 반환값 없음
     */
    public function test_cache_miss_stores_rendered_html_in_cache(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');
        $renderedHtml = '<html><body>New Content</body></html>';

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($renderedHtml);

        $this->cacheManager->expects($this->once())
            ->method('putWithLayout')
            ->with('/products', $this->anything(), $renderedHtml, $this->anything());

        $this->middleware->handle($request, $this->spaNext());
    }

    /**
     * 데이터 소스가 시간 초과·서버 오류로 대체값을 쓴 렌더(저하)는 봇에게 보내되 캐시에 넣지 않는다.
     *
     * 넣으면 데이터 없는 화면(상품명 없는 제목·본문)이 캐시 수명 동안 봇에게 계속 나간다 — 다음 미스가 다시 그리게 둔다.
     *
     * @effects degraded_render_not_cached
     *
     * @return void 반환값 없음
     */
    public function test_degraded_render_is_served_but_not_cached(): void
    {
        $request = $this->createRequest('/products/17', 'Googlebot/2.1');
        $renderedHtml = '<html><head><title>상품 상세</title></head><body></body></html>';

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturnCallback(function (Request $request) use ($renderedHtml) {
            $request->attributes->set('seo_layout_name', 'sirsoft-ecommerce.shop/show');
            $request->attributes->set('seo_render_degraded', ['product']);

            return $renderedHtml;
        });

        $this->cacheManager->expects($this->never())->method('putWithLayout');

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertSame($renderedHtml, $response->getContent());
        $this->assertSame('MISS', $response->headers->get('X-SEO-Cache'));
    }

    // ========================================
    // 렌더링 실패 시 SPA 폴백 테스트
    // ========================================

    /**
     * 렌더링 예외 발생 시 SPA 폴백을 반환하는지 검증
     *
     * @effects render_failure_falls_back_to_spa
     *
     * @return void 반환값 없음
     */
    public function test_render_exception_falls_back_to_spa(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willThrowException(new \RuntimeException('Render failed'));

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals('SPA Fallback', $response->getContent());
    }

    /**
     * 렌더러가 null을 반환하면 SPA 폴백을 반환하는지 검증
     *
     * @effects render_failure_falls_back_to_spa
     *
     * @return void 반환값 없음
     */
    public function test_render_returns_null_falls_back_to_spa(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn(null);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals('SPA Fallback', $response->getContent());
    }

    // ========================================
    // bot_detection_enabled 비활성화 테스트
    // ========================================

    /**
     * bot_detection_enabled=false 시 봇 요청도 SPA 응답을 반환하는지 검증
     *
     * @effects non_bot_or_disabled_detection_receives_spa
     *
     * @return void 반환값 없음
     */
    public function test_bot_detection_disabled_returns_spa_for_bot(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');

        config(['g7_settings.core.seo.bot_detection_enabled' => false]);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals('SPA Fallback', $response->getContent());
    }

    // ========================================
    // Content-Type 헤더 테스트
    // ========================================

    /**
     * SEO 응답의 Content-Type이 text/html인지 검증
     *
     * @effects bot_receives_seo_html_on_miss
     *
     * @return void 반환값 없음
     */
    public function test_seo_response_has_text_html_content_type(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn('<html></html>');

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('charset=utf-8', $response->headers->get('Content-Type'));
    }

    /**
     * 캐시 HIT 응답의 Content-Type이 text/html인지 검증
     *
     * @effects cache_hit_served_with_hit_header
     *
     * @return void 반환값 없음
     */
    public function test_cached_response_has_text_html_content_type(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');

        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn('<html>Cached</html>');

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
    }

    // ========================================
    // 다국어 ?locale= 파라미터 처리 테스트
    // ========================================

    /**
     * ?locale=en 시 영어 SEO 페이지를 반환하는지 검증
     *
     * @effects locale_param_selects_render_locale_and_cache_key
     *
     * @return void 반환값 없음
     */
    public function test_locale_en_sets_app_locale_and_renders(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1', ['locale' => 'en']);
        $expectedHtml = '<html><body>Products in English</body></html>';

        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'app.locale' => 'ko',
            'app.supported_locales' => ['ko', 'en'],
        ]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($expectedHtml);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($expectedHtml, $response->getContent());
        $this->assertEquals('en', app()->getLocale());
    }

    /**
     * ?locale=ko (기본 로케일) 시 301 리다이렉트를 반환하는지 검증
     *
     * @effects locale_param_selects_render_locale_and_cache_key
     *
     * @return void 반환값 없음
     */
    public function test_locale_default_redirects_to_clean_url(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1', ['locale' => 'ko']);

        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'app.locale' => 'ko',
            'app.supported_locales' => ['ko', 'en'],
        ]);

        $this->botDetector->method('isBot')->willReturn(true);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals(301, $response->getStatusCode());
        // 리다이렉트 URL에 ?locale가 없어야 함
        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('locale=', $location);
    }

    /**
     * ?locale=ja (미지원) 시 기본 로케일로 폴백하는지 검증
     *
     * @effects locale_param_selects_render_locale_and_cache_key
     *
     * @return void 반환값 없음
     */
    public function test_unsupported_locale_falls_back_to_default(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1', ['locale' => 'ja']);
        $expectedHtml = '<html><body>Products</body></html>';

        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'app.locale' => 'ko',
            'app.supported_locales' => ['ko', 'en'],
        ]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($expectedHtml);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('ko', app()->getLocale());
    }

    /**
     * ?locale 없음 시 기본 로케일로 동작하는지 검증 (기존 호환)
     *
     * @effects locale_param_selects_render_locale_and_cache_key
     *
     * @return void 반환값 없음
     */
    public function test_no_locale_param_uses_default_locale(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1');
        $expectedHtml = '<html><body>Products</body></html>';

        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'app.locale' => 'ko',
            'app.supported_locales' => ['ko', 'en'],
        ]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($expectedHtml);

        $response = $this->middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('ko', app()->getLocale());
    }

    // ========================================
    // jaybizzle 라이브러리 통합 — 실제 BotDetector 사용
    // ========================================

    /**
     * kakaotalk-scrap UA(라이브러리 미커버 → G7 보강 패턴)도 SEO 렌더링 경로로 진입하는지 검증.
     * BotDetector 를 mock 없이 실제로 사용하여 라이브러리 통합이 미들웨어 단에서 동작함을 확인.
     *
     * @effects kakaotalk_scrap_routed_to_seo_pipeline
     *
     * @return void 반환값 없음
     */
    public function test_kakaotalk_scrap_routed_to_seo_pipeline(): void
    {
        $request = $this->createRequest(
            '/products',
            'facebookexternalhit/1.1;kakaotalk-scrap/1.0;+https://devtalk.kakao.com/t/scrap/33984',
        );
        $renderedHtml = '<html><head><meta property="og:title" content="..."></head></html>';

        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'g7_settings.core.seo.bot_detection_library_enabled' => true,
            'g7_settings.core.seo.bot_user_agents' => [],
        ]);

        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($renderedHtml);

        // mock BotDetector 대신 컨테이너에서 실제 인스턴스를 꺼내 미들웨어 재구성.
        $middleware = new SeoMiddleware(
            app(BotDetector::class),
            $this->cacheManager,
            $this->renderer,
            $this->statsService,
        );

        $response = $middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($renderedHtml, $response->getContent());
        $this->assertStringContainsString('og:title', $response->getContent());
    }

    /**
     * 회귀: facebookexternalhit/1.1 UA 가 봇으로 감지되어 SEO 렌더 파이프라인으로 진입.
     *
     * 기존 회귀: 라이브러리 통합 누락 시 페이스북이 SPA 응답을 받아 미리보기가 표시 안 됨.
     *
     * @effects facebook_external_hit_routed_to_seo_pipeline
     *
     * @return void 반환값 없음
     */
    public function test_facebook_external_hit_routed_to_seo_pipeline(): void
    {
        $request = $this->createRequest('/shop/products/99', 'facebookexternalhit/1.1');
        $renderedHtml = '<meta property="og:image" content="https://example.com/p.jpg">';

        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'g7_settings.core.seo.bot_detection_library_enabled' => true,
            'g7_settings.core.seo.bot_user_agents' => [],
        ]);

        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($renderedHtml);

        $middleware = new SeoMiddleware(
            app(BotDetector::class),
            $this->cacheManager,
            $this->renderer,
            $this->statsService,
        );

        $response = $middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($renderedHtml, $response->getContent());
    }

    /**
     * 회귀: Slackbot-LinkExpanding UA 가 봇으로 감지되어 SEO 렌더 파이프라인으로 진입.
     *
     * 기존 회귀: Slack unfurl 이 SPA 응답을 받아 미리보기 카드가 표시 안 됨.
     *
     * @effects slackbot_routed_to_seo_pipeline
     *
     * @return void 반환값 없음
     */
    public function test_slackbot_routed_to_seo_pipeline(): void
    {
        $request = $this->createRequest(
            '/shop/products/99',
            'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'
        );
        $renderedHtml = '<meta name="twitter:card" content="summary_large_image">';

        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'g7_settings.core.seo.bot_detection_library_enabled' => true,
            'g7_settings.core.seo.bot_user_agents' => [],
        ]);

        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($renderedHtml);

        $middleware = new SeoMiddleware(
            app(BotDetector::class),
            $this->cacheManager,
            $this->renderer,
            $this->statsService,
        );

        $response = $middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($renderedHtml, $response->getContent());
    }

    /**
     * ?locale=en 시 캐시 키에 locale이 반영되는지 검증
     *
     * @effects locale_param_selects_render_locale_and_cache_key
     *
     * @return void 반환값 없음
     */
    public function test_locale_en_cache_uses_correct_locale_key(): void
    {
        $request = $this->createRequest('/products', 'Googlebot/2.1', ['locale' => 'en']);
        $renderedHtml = '<html><body>English</body></html>';

        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'app.locale' => 'ko',
            'app.supported_locales' => ['ko', 'en'],
        ]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn($renderedHtml);

        $this->cacheManager->expects($this->once())
            ->method('putWithLayout')
            ->with('/products', 'en', $renderedHtml, $this->anything());

        $this->middleware->handle($request, $this->spaNext());
    }

    // ========================================
    // 캐시 상한 / 렌더 예산 테스트 (KVE-2026-2191 동형)
    // ========================================

    /**
     * IP 당 미스 렌더 예산을 넘기면 렌더도 저장도 하지 않고 SPA 를 돌려준다.
     *
     * 봇 판정은 User-Agent 문자열뿐이라 위장이 가능하고, 캐시 키에 쿼리가 들어가므로
     * 값만 바꾼 반복 요청이 매번 미스가 된다. 미스 1건은 레이아웃 병합·표현식 평가·자기
     * API 루프백 호출을 유발하므로 요청 하나가 워커 여러 개를 묶는다.
     *
     * @effects bot_miss_over_limit_gets_spa_bypass
     *
     * @return void 반환값 없음
     */
    public function test_bot_miss_over_render_limit_gets_spa_bypass_without_render(): void
    {
        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'core.seo_cache_limits.render_misses_per_minute' => 2,
        ]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->expects($this->never())->method('render');
        $this->cacheManager->expects($this->never())->method('putWithLayout');

        RateLimiter::hit('seo-render:127.0.0.1', 60);
        RateLimiter::hit('seo-render:127.0.0.1', 60);

        $response = $this->middleware->handle(
            $this->createRequest('/products', 'Googlebot/2.1'),
            $this->spaNext()
        );

        $this->assertSame('SPA Fallback', $response->getContent());
        $this->assertSame('BYPASS', $response->headers->get('X-SEO-Cache'));
    }

    /**
     * 예산을 넘긴 IP 라도 **캐시 적중**은 그대로 서빙한다 — 비용이 없기 때문이다.
     *
     * @effects bot_hit_served_regardless_of_limit
     *
     * @return void 반환값 없음
     */
    public function test_cache_hit_is_served_even_when_render_limit_exceeded(): void
    {
        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'core.seo_cache_limits.render_misses_per_minute' => 1,
        ]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn('<html>cached</html>');

        RateLimiter::hit('seo-render:127.0.0.1', 60);

        $response = $this->middleware->handle(
            $this->createRequest('/products', 'Googlebot/2.1'),
            $this->spaNext()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('HIT', $response->headers->get('X-SEO-Cache'));
    }

    /**
     * `_escaped_fragment_` 는 봇 렌더 요청 표식일 뿐이라 캐시 키를 가르지 않는다.
     *
     * @effects system_query_params_excluded_from_key
     *
     * @return void 반환값 없음
     */
    public function test_escaped_fragment_param_does_not_change_cache_key(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);

        $seen = [];
        $this->cacheManager->method('get')->willReturnCallback(function (string $url) use (&$seen) {
            $seen[] = $url;

            return '<html>cached</html>';
        });

        $this->middleware->handle($this->createRequest('/products', 'Googlebot/2.1'), $this->spaNext());
        $this->middleware->handle(
            $this->createRequest('/products', 'Googlebot/2.1', ['_escaped_fragment_' => '']),
            $this->spaNext()
        );

        $this->assertCount(2, $seen);
        $this->assertSame($seen[0], $seen[1]);
    }

    /**
     * 정규화할 수 없을 만큼 큰 쿼리는 색인 대상이 아니다 — 캐시 조회도 렌더도 하지 않는다.
     *
     * @effects oversized_query_bypasses_cache_and_render
     *
     * @return void 반환값 없음
     */
    public function test_oversized_query_bypasses_cache_and_render(): void
    {
        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'core.seo_cache_limits.max_query_params' => 10,
        ]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->expects($this->never())->method('get');
        $this->renderer->expects($this->never())->method('render');

        $query = [];
        for ($i = 0; $i < 11; $i++) {
            $query['p'.$i] = '1';
        }

        $response = $this->middleware->handle(
            $this->createRequest('/products', 'Googlebot/2.1', $query),
            $this->spaNext()
        );

        $this->assertSame('SPA Fallback', $response->getContent());
        $this->assertSame('BYPASS', $response->headers->get('X-SEO-Cache'));
    }

    /**
     * 캐시 적중·미적중이 통계에 기록된다.
     *
     * 기록 호출처가 없으면 `seo:stats` 와 관리자 통계가 항상 0 이라, 공격이 진행돼도
     * 운영자 화면은 아무것도 달라지지 않는다.
     *
     * @effects cache_hit_and_miss_are_recorded_in_stats
     *
     * @return void 반환값 없음
     */
    public function test_cache_hit_records_stat_hit(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn('<html>cached</html>');

        $this->statsService->expects($this->once())->method('recordHit');
        $this->statsService->expects($this->never())->method('recordMiss');

        $this->middleware->handle($this->createRequest('/products', 'Googlebot/2.1'), $this->spaNext());
    }

    /**
     * 캐시 MISS 는 응답 시간과 함께 통계에 MISS 로 기록된다.
     *
     * @effects cache_hit_and_miss_are_recorded_in_stats
     *
     * @return void 반환값 없음
     */
    public function test_cache_miss_records_stat_miss_with_response_time(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn('<html>rendered</html>');

        $this->statsService->expects($this->once())
            ->method('recordMiss')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->greaterThanOrEqual(0)
            );

        $this->middleware->handle($this->createRequest('/products', 'Googlebot/2.1'), $this->spaNext());
    }

    /**
     * 통계 기록도 IP 당 상한을 넘기면 멈춘다 — 통계 테이블이 새 증식 축이 되면 안 된다.
     *
     * @effects stats_recording_capped_per_ip
     *
     * @return void 반환값 없음
     */
    public function test_stats_recording_is_capped_per_ip(): void
    {
        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'core.seo_cache_limits.stats_records_per_minute' => 1,
        ]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn('<html>cached</html>');

        RateLimiter::hit('seo-stats:127.0.0.1', 60);

        $this->statsService->expects($this->never())->method('recordHit');

        $this->middleware->handle($this->createRequest('/products', 'Googlebot/2.1'), $this->spaNext());
    }

    /**
     * 캐시 적중(HIT)은 렌더러를 거치지 않으므로 레이아웃명을 요청 속성에서 얻을 수 없다 —
     * 캐시 항목에 함께 저장된 레이아웃명으로 통계에 귀속한다. 없으면 화면별 표에서 모든
     * 화면의 적중이 0 이 되고 'N/A' 행에만 쌓인다.
     *
     * @effects cache_hit_carries_layout_name_from_cache_entry
     *
     * @return void 반환값 없음
     */
    public function test_cache_hit_records_stat_hit_with_layout_from_cache_entry(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $cacheManager = $this->createMock(SeoCacheManager::class);
        $cacheManager->method('getEntry')->willReturn(['html' => '<html>cached</html>', 'layout' => 'shop/show']);

        $middleware = new SeoMiddleware($this->botDetector, $cacheManager, $this->renderer, $this->statsService);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->statsService->expects($this->once())
            ->method('recordHit')
            ->with('/products', config('app.locale'), 'shop/show');

        $response = $middleware->handle($this->createRequest('/products', 'Googlebot/2.1'), $this->spaNext());

        $this->assertSame('<html>cached</html>', $response->getContent());
        $this->assertSame('HIT', $response->headers->get('X-SEO-Cache'));
    }

    /**
     * 렌더러가 "그릴 게 없음"(null)을 돌려주면 방금 뺀 렌더 예산을 되돌린다 — 미라우트 404 나
     * SEO 비활성 화면은 캐시에 남지 않아 올 때마다 다시 예산을 쓰므로, 죽은 주소 재크롤이
     * 정상 페이지의 예산을 태운다.
     *
     * @effects null_render_refunds_render_budget
     *
     * @return void 반환값 없음
     */
    public function test_null_render_refunds_render_budget(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturn(null);

        $this->middleware->handle($this->createRequest('/gone', 'Googlebot/2.1'), $this->spaNext());

        $this->assertSame(0, (int) RateLimiter::attempts('seo-render:127.0.0.1'));
    }

    /**
     * 렌더 중 예외는 비용을 이미 치른 것이므로 예산을 되돌리지 않는다 (회귀 가드).
     *
     * @effects null_render_refunds_render_budget
     *
     * @return void 반환값 없음
     */
    public function test_render_exception_keeps_render_budget_charged(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willThrowException(new \RuntimeException('boom'));

        $this->middleware->handle($this->createRequest('/products', 'Googlebot/2.1'), $this->spaNext());

        $this->assertSame(1, (int) RateLimiter::attempts('seo-render:127.0.0.1'));
    }

    /**
     * 끝 슬래시 유무가 다른 같은 화면은 같은 캐시 url 로 조회·저장한다 (공개#156).
     *
     * @scenario path_shape=trailing_slash, bot=bot
     *
     * @effects seo_render_and_cache_key_use_canonical_path
     *
     * @return void 반환값 없음
     */
    public function test_trailing_slash_path_uses_same_cache_url(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->renderer->method('render')->willReturn('<html>rendered</html>');

        $gets = [];
        $puts = [];
        $this->cacheManager->method('get')->willReturnCallback(function (string $url) use (&$gets) {
            $gets[] = $url;

            return null;
        });
        $this->cacheManager->method('putWithLayout')->willReturnCallback(function (string $url) use (&$puts) {
            $puts[] = $url;
        });

        $this->middleware->handle($this->createRequest('/products/1/', 'Googlebot/2.1'), $this->spaNext());
        $this->middleware->handle($this->createRequest('/products/1', 'Googlebot/2.1'), $this->spaNext());
        $this->middleware->handle($this->createRequest('/products//1', 'Googlebot/2.1'), $this->spaNext());

        $this->assertSame(['/products/1', '/products/1', '/products/1'], $gets);
        $this->assertSame(['/products/1', '/products/1', '/products/1'], $puts);
    }

    /**
     * 모듈 소유 화면의 캐시 미스는 렌더러가 남긴 전체 레이아웃 이름과 모듈 식별자를 통계에 기록한다 (공개#154).
     * 종전에는 모듈 식별자 자리에 null 을 넘겨 `seo:stats` 의 모듈별 묶음이 늘 비었다.
     *
     * @scenario surface=stats_miss
     *
     * @effects module_screen_stats_carry_module_identifier
     *
     * @return void 반환값 없음
     */
    public function test_module_screen_miss_records_stored_layout_name_and_module(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->botDetector->method('isBot')->willReturn(true);
        $this->cacheManager->method('get')->willReturn(null);
        $this->renderer->method('render')->willReturnCallback(function (Request $request) {
            $request->attributes->set('seo_layout_name', 'gnuboard7-hello_module.user_memo_list');
            $request->attributes->set('seo_module_identifier', 'gnuboard7-hello_module');

            return '<html>memos</html>';
        });

        $this->cacheManager->expects($this->once())
            ->method('putWithLayout')
            ->with('/memos', config('app.locale'), '<html>memos</html>', 'gnuboard7-hello_module.user_memo_list');
        $this->statsService->expects($this->once())
            ->method('recordMiss')
            ->with(
                '/memos',
                config('app.locale'),
                'gnuboard7-hello_module.user_memo_list',
                'gnuboard7-hello_module',
                $this->greaterThanOrEqual(0)
            );

        $this->middleware->handle($this->createRequest('/memos', 'Googlebot/2.1'), $this->spaNext());
    }

    /**
     * 모듈 소유 화면의 캐시 적중은 캐시 항목의 전체 레이아웃 이름에서 모듈 식별자를 얻어 통계에 기록한다 (공개#154).
     * 템플릿 화면(점 없는 이름)은 모듈 식별자가 없다.
     *
     * @scenario surface=stats_hit
     *
     * @effects module_screen_stats_carry_module_identifier
     *
     * @return void 반환값 없음
     */
    public function test_module_screen_hit_records_module_identifier_from_cache_entry(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $cacheManager = $this->createMock(SeoCacheManager::class);
        $cacheManager->method('getEntry')->willReturnCallback(fn (string $url) => [
            'html' => '<html>cached</html>',
            'layout' => $url === '/memos' ? 'gnuboard7-hello_module.user_memo_list' : 'home',
        ]);

        $middleware = new SeoMiddleware($this->botDetector, $cacheManager, $this->renderer, $this->statsService);

        $this->botDetector->method('isBot')->willReturn(true);

        $calls = [];
        $this->statsService->expects($this->exactly(2))
            ->method('recordHit')
            ->willReturnCallback(function (...$args) use (&$calls) {
                $calls[] = $args;
            });

        $middleware->handle($this->createRequest('/memos', 'Googlebot/2.1'), $this->spaNext());
        $middleware->handle($this->createRequest('/', 'Googlebot/2.1'), $this->spaNext());

        $this->assertSame(['/memos', config('app.locale'), 'gnuboard7-hello_module.user_memo_list', 'gnuboard7-hello_module'], $calls[0]);
        $this->assertSame('home', $calls[1][2]);
        $this->assertNull($calls[1][3] ?? null);
    }
}
