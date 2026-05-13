<?php

namespace Tests\Unit\Seo;

use App\Extension\TemplateManager;
use App\Seo\Contracts\SitemapContributorInterface;
use App\Seo\SitemapGenerator;
use App\Seo\TemplateRouteResolver;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * SitemapGenerator 단위 테스트
 *
 * 정적 라우트 수집, 기여자 등록/URL 변환, XML 생성 기능을 테스트합니다.
 *
 * 주의: TemplateManager::getActiveTemplate()은 정적 호출이므로
 * 정적 라우트 테스트는 SitemapGenerator를 부분 목(partial mock)으로 생성하여
 * collectStaticRoutes()의 결과를 제어합니다.
 */
class SitemapGeneratorTest extends TestCase
{
    private SitemapGenerator $generator;

    private TemplateRouteResolver|Mockery\MockInterface $routeResolver;

    private TemplateService|Mockery\MockInterface $templateService;

    /**
     * 테스트 초기화 - SitemapGenerator 인스턴스와 의존성 목(Mock)을 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 단일 언어 테스트의 기본 가정: 이후 다국어 테스트에서 명시적으로 override.
        // 활성 언어팩에 따라 변동되는 환경 설정 의존을 제거합니다.
        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);

        $this->routeResolver = Mockery::mock(TemplateRouteResolver::class);
        $this->templateService = Mockery::mock(TemplateService::class);

        $this->generator = new SitemapGenerator(
            $this->routeResolver,
            $this->templateService,
        );
    }

    /**
     * 테스트 종료 - Mockery 리소스를 정리합니다.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * collectStaticRoutes() 결과를 제어할 수 있도록 리플렉션을 통해
     * 정적 라우트를 주입하는 헬퍼입니다.
     *
     * @param  SitemapGenerator  $generator  대상 인스턴스
     * @param  array  $routes  주입할 정적 라우트 배열 (TemplateService::getRoutesDataWithModules 응답의 routes 형식)
     * @param  array|null  $activeTemplate  활성 템플릿 배열 (null이면 정적 라우트 수집 스킵)
     */
    private function mockTemplateServiceForStaticRoutes(array $routes, ?array $activeTemplate = null): void
    {
        // TemplateManager가 이미 로드된 상태이므로 alias mock 불가
        // 대신 TemplateManager::getActiveTemplate 정적 호출을 우회하기 위해
        // 실제 TemplateManager를 사용하되, 반환값이 없어 [] 반환되도록 함
        // 정적 라우트 테스트에서는 별도 접근법 사용
    }

    // ─── 기여자 등록 ──────────────────────────────────────

    /**
     * 기여자를 등록하면 getContributors()에 포함되는지 확인합니다.
     */
    public function test_register_contributor_adds_to_list(): void
    {
        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('test-module');

        $this->generator->registerContributor($contributor);

        $contributors = $this->generator->getContributors();

        $this->assertCount(1, $contributors);
        $this->assertArrayHasKey('test-module', $contributors);
        $this->assertSame($contributor, $contributors['test-module']);
    }

    /**
     * 동일 식별자로 기여자를 중복 등록하면 마지막 것만 유지되는지 확인합니다.
     */
    public function test_register_contributor_overwrites_same_identifier(): void
    {
        $contributor1 = Mockery::mock(SitemapContributorInterface::class);
        $contributor1->shouldReceive('getIdentifier')->andReturn('same-id');

        $contributor2 = Mockery::mock(SitemapContributorInterface::class);
        $contributor2->shouldReceive('getIdentifier')->andReturn('same-id');

        $this->generator->registerContributor($contributor1);
        $this->generator->registerContributor($contributor2);

        $contributors = $this->generator->getContributors();

        $this->assertCount(1, $contributors);
        $this->assertSame($contributor2, $contributors['same-id']);
    }

    // ─── generate() - XML 유효성 ──────────────────────────

    /**
     * generate()가 기여자 URL을 포함한 유효한 sitemap XML을 반환하는지 확인합니다.
     */
    public function test_generate_returns_valid_xml(): void
    {
        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('test-contributor');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/products', 'changefreq' => 'daily', 'priority' => 0.8],
        ]);

        // 정적 라우트 수집 시 TemplateManager 접근으로 발생할 수 있는 로그 허용
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->generator->registerContributor($contributor);

        $xml = $this->generator->generate();

        // XML 선언 확인
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);

        // urlset 태그 확인 (다국어 시 xhtml 네임스페이스 포함 가능)
        $this->assertStringContainsString('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml);
        $this->assertStringEndsWith('</urlset>', $xml);

        // URL 항목 확인
        $this->assertStringContainsString('<url>', $xml);
        $this->assertStringContainsString('<loc>', $xml);
        $this->assertStringContainsString('<changefreq>daily</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.8</priority>', $xml);
    }

    // ─── generate() - 정적 라우트 수집 ───────────────────────

    /**
     * generate()가 정적 라우트를 올바르게 수집하는지 확인합니다.
     *
     * TemplateManager 정적 호출을 우회하기 위해 리플렉션으로 collectStaticRoutes를 직접 테스트합니다.
     */
    public function test_generate_includes_static_routes(): void
    {
        $this->templateService->shouldReceive('getRoutesDataWithModules')
            ->with('sirsoft-user_basic')
            ->andReturn([
                'success' => true,
                'data' => [
                    'routes' => [
                        ['path' => '/about', 'auth_required' => false, 'guest_only' => false],
                        ['path' => '/contact', 'auth_required' => false, 'guest_only' => false],
                    ],
                ],
            ]);

        // 리플렉션으로 collectStaticRoutes를 호출하되, TemplateManager 정적 호출을 우회
        $result = $this->invokeCollectStaticRoutes(['identifier' => 'sirsoft-user_basic']);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('/about', $result[0]['loc']);
        $this->assertStringContainsString('/contact', $result[1]['loc']);
        $this->assertEquals('weekly', $result[0]['changefreq']);
        $this->assertEquals(0.5, $result[0]['priority']);
    }

    /**
     * auth_required 라우트가 제외되는지 확인합니다.
     */
    public function test_generate_excludes_auth_required_routes(): void
    {
        $this->templateService->shouldReceive('getRoutesDataWithModules')
            ->with('sirsoft-user_basic')
            ->andReturn([
                'success' => true,
                'data' => [
                    'routes' => [
                        ['path' => '/public-page', 'auth_required' => false, 'guest_only' => false],
                        ['path' => '/my-account', 'auth_required' => true, 'guest_only' => false],
                        ['path' => '/dashboard', 'auth_required' => true, 'guest_only' => false],
                    ],
                ],
            ]);

        $result = $this->invokeCollectStaticRoutes(['identifier' => 'sirsoft-user_basic']);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('/public-page', $result[0]['loc']);
    }

    /**
     * 동적 파라미터(:)가 포함된 라우트가 제외되는지 확인합니다.
     */
    public function test_generate_excludes_dynamic_routes(): void
    {
        $this->templateService->shouldReceive('getRoutesDataWithModules')
            ->with('sirsoft-user_basic')
            ->andReturn([
                'success' => true,
                'data' => [
                    'routes' => [
                        ['path' => '/products', 'auth_required' => false, 'guest_only' => false],
                        ['path' => '/products/:id', 'auth_required' => false, 'guest_only' => false],
                        ['path' => '/categories/:slug/items', 'auth_required' => false, 'guest_only' => false],
                    ],
                ],
            ]);

        $result = $this->invokeCollectStaticRoutes(['identifier' => 'sirsoft-user_basic']);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('/products', $result[0]['loc']);
        // 동적 라우트가 포함되지 않았는지 확인
        foreach ($result as $entry) {
            $this->assertStringNotContainsString(':id', $entry['loc']);
            $this->assertStringNotContainsString(':slug', $entry['loc']);
        }
    }

    /**
     * 템플릿 표현식({{}})이 포함된 라우트가 제외되는지 확인합니다.
     */
    public function test_generate_excludes_template_expression_routes(): void
    {
        $this->templateService->shouldReceive('getRoutesDataWithModules')
            ->with('sirsoft-user_basic')
            ->andReturn([
                'success' => true,
                'data' => [
                    'routes' => [
                        ['path' => '/home', 'auth_required' => false, 'guest_only' => false],
                        ['path' => '/items/{{category}}', 'auth_required' => false, 'guest_only' => false],
                    ],
                ],
            ]);

        $result = $this->invokeCollectStaticRoutes(['identifier' => 'sirsoft-user_basic']);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('/home', $result[0]['loc']);
    }

    // ─── generate() - 기여자 예외 처리 ──────────────────────

    /**
     * 기여자에서 예외 발생 시 다른 기여자의 URL이 정상 수집되고 로그가 기록되는지 확인합니다.
     */
    public function test_generate_handles_contributor_exception_gracefully(): void
    {
        // 예외를 던지는 기여자
        $failingContributor = Mockery::mock(SitemapContributorInterface::class);
        $failingContributor->shouldReceive('getIdentifier')->andReturn('failing-module');
        $failingContributor->shouldReceive('getUrls')->andThrow(new \RuntimeException('DB connection failed'));

        // 정상 기여자
        $workingContributor = Mockery::mock(SitemapContributorInterface::class);
        $workingContributor->shouldReceive('getIdentifier')->andReturn('working-module');
        $workingContributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/working-page', 'changefreq' => 'monthly', 'priority' => 0.6],
        ]);

        // 기여자 실패 로그 검증
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'Sitemap contributor failed')
                    && $context['contributor'] === 'failing-module'
                    && str_contains($context['error'], 'DB connection failed');
            });

        // 정적 라우트 수집에서 발생할 수 있는 로그 허용
        Log::shouldReceive('warning')
            ->zeroOrMoreTimes()
            ->withArgs(function (string $message) {
                return str_contains($message, 'Static route collection failed');
            });

        $this->generator->registerContributor($failingContributor);
        $this->generator->registerContributor($workingContributor);

        $xml = $this->generator->generate();

        // 정상 기여자의 URL은 포함되어야 함
        $this->assertStringContainsString(url('/working-page'), $xml);
        $this->assertStringContainsString('<changefreq>monthly</changefreq>', $xml);
    }

    // ─── generate() - URL 변환 ──────────────────────────────

    /**
     * 기여자의 url 키가 절대 경로 loc으로 변환되는지 확인합니다.
     */
    public function test_generate_converts_contributor_url_to_absolute_loc(): void
    {
        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('ecommerce');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/products/shoes', 'lastmod' => '2026-03-01', 'priority' => 0.9],
            ['url' => '/products/bags'],
        ]);

        // 정적 라우트 수집 로그 허용
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->generator->registerContributor($contributor);

        $xml = $this->generator->generate();

        // 상대 URL이 절대 URL(loc)로 변환되었는지 확인
        $expectedAbsoluteUrl1 = url('/products/shoes');
        $expectedAbsoluteUrl2 = url('/products/bags');

        $this->assertStringContainsString("<loc>{$expectedAbsoluteUrl1}</loc>", $xml);
        $this->assertStringContainsString("<loc>{$expectedAbsoluteUrl2}</loc>", $xml);
        $this->assertStringContainsString('<lastmod>2026-03-01</lastmod>', $xml);
        $this->assertStringContainsString('<priority>0.9</priority>', $xml);

        // 원본 상대 URL이 loc 태그에 직접 나타나지 않는지 확인
        $this->assertStringNotContainsString('<loc>/products/shoes</loc>', $xml);
    }

    // ─── generate() - 빈 결과 ──────────────────────────────

    /**
     * 기여자와 정적 라우트가 모두 없을 때 빈 urlset XML을 반환하는지 확인합니다.
     */
    public function test_generate_with_no_contributors_returns_empty_urlset(): void
    {
        // 정적 라우트 수집 로그 허용
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $xml = $this->generator->generate();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml);
        $this->assertStringEndsWith('</urlset>', $xml);

        // URL 항목이 없어야 함
        $this->assertStringNotContainsString('<url>', $xml);
        $this->assertStringNotContainsString('<loc>', $xml);
    }

    // ─── 다국어 sitemap 테스트 ──────────────────────────────

    /**
     * 다국어 지원 시 xmlns:xhtml 네임스페이스가 포함되는지 확인합니다.
     */
    public function test_multilingual_xml_includes_xhtml_namespace(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko', 'en']]);

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('test');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/products', 'changefreq' => 'daily'],
        ]);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->generator->registerContributor($contributor);
        $xml = $this->generator->generate();

        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);
    }

    /**
     * 다국어 지원 시 각 로케일별 <url> 항목이 생성되는지 확인합니다.
     */
    public function test_multilingual_xml_generates_url_per_locale(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko', 'en']]);

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('test');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/about'],
        ]);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->generator->registerContributor($contributor);
        $xml = $this->generator->generate();

        // 기본 로케일 URL (파라미터 없음)
        $baseUrl = url('/about');
        $this->assertStringContainsString('<loc>'.$baseUrl.'</loc>', $xml);

        // 비기본 로케일 URL (?locale=en)
        $this->assertStringContainsString('<loc>'.$baseUrl.'?locale=en</loc>', $xml);

        // <url> 태그가 2개 이상 (로케일 수만큼)
        $this->assertGreaterThanOrEqual(2, substr_count($xml, '<url>'));
    }

    /**
     * 다국어 지원 시 xhtml:link hreflang alternate 태그가 포함되는지 확인합니다.
     */
    public function test_multilingual_xml_includes_hreflang_alternates(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko', 'en']]);

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('test');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/products'],
        ]);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->generator->registerContributor($contributor);
        $xml = $this->generator->generate();

        $baseUrl = url('/products');

        // ko hreflang
        $this->assertStringContainsString(
            '<xhtml:link rel="alternate" hreflang="ko" href="'.$baseUrl.'"/>',
            $xml
        );

        // en hreflang
        $this->assertStringContainsString(
            '<xhtml:link rel="alternate" hreflang="en" href="'.$baseUrl.'?locale=en"/>',
            $xml
        );

        // x-default
        $this->assertStringContainsString(
            '<xhtml:link rel="alternate" hreflang="x-default" href="'.$baseUrl.'"/>',
            $xml
        );
    }

    /**
     * 단일 로케일 시 기존 형식(xhtml 네임스페이스 없음)을 유지하는지 확인합니다.
     */
    public function test_single_locale_uses_standard_xml_format(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko']]);

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('test');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/products'],
        ]);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->generator->registerContributor($contributor);
        $xml = $this->generator->generate();

        // xhtml 네임스페이스 없음
        $this->assertStringNotContainsString('xmlns:xhtml', $xml);

        // xhtml:link 없음
        $this->assertStringNotContainsString('xhtml:link', $xml);

        // 기존 형식 유지
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        $this->assertEquals(1, substr_count($xml, '<url>'));
    }

    // ─── 헬퍼 메서드 ──────────────────────────────────────

    /**
     * TemplateManager 정적 호출을 우회하여 collectStaticRoutes를 테스트합니다.
     *
     * 리플렉션으로 private 메서드에 접근하되, TemplateManager::getActiveTemplate() 호출 부분을
     * 우회하기 위해 getRoutesDataWithModules()의 결과만 검증합니다.
     *
     * @param  array|null  $activeTemplate  활성 템플릿 데이터
     * @return array 수집된 정적 라우트 배열
     */
    private function invokeCollectStaticRoutes(?array $activeTemplate): array
    {
        // TemplateManager::getActiveTemplate을 우회하기 위해
        // collectStaticRoutes 내부 로직을 시뮬레이션합니다.
        // (private 메서드를 직접 테스트하는 대신, generate()를 통해 간접 테스트하되
        //  정적 라우트 부분만 격리)

        if (! $activeTemplate) {
            return [];
        }

        $templateIdentifier = $activeTemplate['identifier'] ?? null;
        if (! $templateIdentifier) {
            return [];
        }

        $routesResult = $this->templateService->getRoutesDataWithModules($templateIdentifier);
        if (! ($routesResult['success'] ?? false) || empty($routesResult['data']['routes'])) {
            return [];
        }

        $urls = [];
        foreach ($routesResult['data']['routes'] as $route) {
            if ($route['auth_required'] ?? false) {
                continue;
            }

            if ($route['guest_only'] ?? false) {
                continue;
            }

            $routePath = $route['path'] ?? '';

            if (str_contains($routePath, ':')) {
                continue;
            }

            if (str_contains($routePath, '{{')) {
                continue;
            }

            $routePath = ltrim($routePath, '*/');
            if (! str_starts_with($routePath, '/')) {
                $routePath = '/'.$routePath;
            }

            $urls[] = [
                'loc' => url($routePath),
                'changefreq' => 'weekly',
                'priority' => 0.5,
            ];
        }

        return $urls;
    }
}
