<?php

namespace Tests\Feature\Seo;

use App\Seo\BotDetector;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\Contracts\SeoRendererInterface;
use App\Seo\SeoMiddleware;
use Illuminate\Http\Request;
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

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->botDetector = $this->createMock(BotDetector::class);
        $this->cacheManager = $this->createMock(SeoCacheManagerInterface::class);
        $this->renderer = $this->createMock(SeoRendererInterface::class);

        $this->middleware = new SeoMiddleware(
            $this->botDetector,
            $this->cacheManager,
            $this->renderer,
        );
    }

    /**
     * SPA 폴백 응답을 반환하는 Closure를 생성합니다.
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

    // ========================================
    // 렌더링 실패 시 SPA 폴백 테스트
    // ========================================

    /**
     * 렌더링 예외 발생 시 SPA 폴백을 반환하는지 검증
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
        );

        $response = $middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($renderedHtml, $response->getContent());
    }

    /**
     * 회귀: Slackbot-LinkExpanding UA 가 봇으로 감지되어 SEO 렌더 파이프라인으로 진입.
     *
     * 기존 회귀: Slack unfurl 이 SPA 응답을 받아 미리보기 카드가 표시 안 됨.
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
        );

        $response = $middleware->handle($request, $this->spaNext());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($renderedHtml, $response->getContent());
    }

    /**
     * ?locale=en 시 캐시 키에 locale이 반영되는지 검증
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
}
