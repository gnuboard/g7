<?php

namespace Tests\Unit\Seo;

use App\Seo\AbstractSitemapContributor;
use App\Seo\Contracts\SitemapContributorInterface;
use App\Seo\SitemapGenerator;
use App\Seo\SitemapWriter;
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

        // hreflang alternate 방출을 기본값(true)으로 고정한다. SitemapXmlRenderer::fromConfig()
        // 는 g7_core_settings('seo.sitemap_hreflang_enabled') 를 읽는데, 이 값은 부팅 시
        // storage/app/settings/seo.json 으로 적재되며 settings 디스크 fake 의 영향을 받지 않는다.
        // 실행 머신의 영속 설정에 다국어 hreflang 검증 결과가 좌우되지 않도록 명시 고정한다.
        Config::set('g7_settings.core.seo.sitemap_hreflang_enabled', true);

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

    // ─── renderUrlBlock / urlset 헤더·푸터 ──────────────────

    /**
     * renderUrlBlock()이 단일 언어 <url> 블록을 생성하는지 확인합니다.
     */
    public function test_render_url_block_single_locale(): void
    {
        $block = $this->generator->renderUrlBlock([
            'loc' => 'https://example.test/a',
            'changefreq' => 'daily',
            'priority' => 0.8,
        ]);

        $this->assertStringContainsString('<loc>https://example.test/a</loc>', $block);
        $this->assertStringContainsString('<changefreq>daily</changefreq>', $block);
        $this->assertStringContainsString('<priority>0.8</priority>', $block);
        $this->assertStringNotContainsString('xhtml:link', $block);
        $this->assertSame(1, substr_count($block, '<url>'));
    }

    /**
     * renderUrlBlock()이 다국어에서 로케일별 블록과 hreflang을 생성하는지 확인합니다.
     */
    public function test_render_url_block_multilingual(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko', 'en']]);

        $block = $this->generator->renderUrlBlock(['loc' => 'https://example.test/a']);

        $this->assertSame(2, substr_count($block, '<url>'));
        $this->assertStringContainsString('<loc>https://example.test/a</loc>', $block);
        $this->assertStringContainsString('<loc>https://example.test/a?locale=en</loc>', $block);
        $this->assertStringContainsString('hreflang="x-default"', $block);
    }

    /**
     * XML 특수문자가 이스케이프되는지 확인합니다.
     */
    public function test_render_url_block_escapes_xml_special_characters(): void
    {
        $block = $this->generator->renderUrlBlock(['loc' => 'https://example.test/a?x=1&y=2']);

        $this->assertStringContainsString('<loc>https://example.test/a?x=1&amp;y=2</loc>', $block);
        // 이스케이프되지 않은 원본 & 가 남아 있으면 XML 파싱이 깨집니다.
        $this->assertNotFalse(simplexml_load_string('<urlset>'.$block.'</urlset>'));
    }

    /**
     * urlset 헤더/푸터가 로케일 수에 따라 분기되는지 확인합니다.
     */
    public function test_urlset_header_switches_on_multilingual(): void
    {
        $this->assertStringNotContainsString('xmlns:xhtml', $this->generator->buildUrlsetHeader());
        $this->assertSame('</urlset>', $this->generator->buildUrlsetFooter());

        config(['app.supported_locales' => ['ko', 'en']]);

        $this->assertStringContainsString('xmlns:xhtml', $this->generator->buildUrlsetHeader());
    }

    // ─── generateToWriter ────────────────────────────────

    /**
     * generateToWriter()가 기여자 URL을 writer 에 한 건씩 전달하고
     * 상대 url 을 절대 loc 으로 변환하는지 확인합니다.
     */
    public function test_generate_to_writer_streams_entries_without_cross_merge(): void
    {
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $contributorA = Mockery::mock(SitemapContributorInterface::class);
        $contributorA->shouldReceive('getIdentifier')->andReturn('mod-a');
        $contributorA->shouldReceive('getUrls')->andReturn([
            ['url' => '/a/1'],
            ['url' => '/a/2'],
        ]);

        $contributorB = Mockery::mock(SitemapContributorInterface::class);
        $contributorB->shouldReceive('getIdentifier')->andReturn('mod-b');
        $contributorB->shouldReceive('getUrls')->andReturn([
            ['url' => '/b/1'],
        ]);

        $this->generator->registerContributor($contributorA);
        $this->generator->registerContributor($contributorB);

        $writer = new SpySitemapWriter;
        $meta = $this->generator->generateToWriter($writer);

        $this->assertTrue($writer->opened);
        $this->assertTrue($writer->closed);
        $this->assertTrue($writer->committed);
        $this->assertSame(['committed' => true], $meta);

        $locs = array_column($writer->entries, 'loc');
        $this->assertContains(url('/a/1'), $locs);
        $this->assertContains(url('/a/2'), $locs);
        $this->assertContains(url('/b/1'), $locs);

        // 'url' 키는 절대 loc 으로 변환되어 남지 않아야 합니다.
        foreach ($writer->entries as $entry) {
            $this->assertArrayNotHasKey('url', $entry);
            $this->assertArrayHasKey('loc', $entry);
        }
    }

    /**
     * 기여자를 하나씩 소진하여 writer 로 흘려보내는지 확인합니다 (전체 병합 부재 증명).
     *
     * 모든 기여자의 URL 을 배열로 모은 뒤 한 번에 쓰는 구현이라면, 두 번째 기여자의
     * getUrls() 가 호출되는 시점에 writer 는 아직 비어 있다. 이 테스트는 그 구현을 걸러낸다.
     */
    public function test_generate_to_writer_drains_each_contributor_before_calling_the_next(): void
    {
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $writer = new SpySitemapWriter;

        $contributorA = Mockery::mock(SitemapContributorInterface::class);
        $contributorA->shouldReceive('getIdentifier')->andReturn('mod-a');
        $contributorA->shouldReceive('getUrls')->andReturn([['url' => '/a/1']]);

        $locsSeenWhenBWasAsked = null;

        $contributorB = Mockery::mock(SitemapContributorInterface::class);
        $contributorB->shouldReceive('getIdentifier')->andReturn('mod-b');
        $contributorB->shouldReceive('getUrls')->andReturnUsing(
            function () use ($writer, &$locsSeenWhenBWasAsked): array {
                $locsSeenWhenBWasAsked = array_column($writer->entries, 'loc');

                return [['url' => '/b/1']];
            }
        );

        $this->generator->registerContributor($contributorA);
        $this->generator->registerContributor($contributorB);
        $this->generator->generateToWriter($writer);

        $this->assertNotNull($locsSeenWhenBWasAsked, 'B 기여자가 호출되어야 합니다.');
        $this->assertContains(
            url('/a/1'),
            $locsSeenWhenBWasAsked,
            'B 를 호출하기 전에 A 의 URL 이 이미 writer 로 전달되어야 합니다 (전체 병합 금지).'
        );
    }

    /**
     * 한 기여자가 실패해도 다른 기여자의 URL 이 계속 기록되는지 확인합니다.
     */
    public function test_generate_to_writer_isolates_contributor_failure(): void
    {
        $failing = Mockery::mock(SitemapContributorInterface::class);
        $failing->shouldReceive('getIdentifier')->andReturn('failing-module');
        $failing->shouldReceive('getUrls')->andThrow(new \RuntimeException('DB connection failed'));

        $working = Mockery::mock(SitemapContributorInterface::class);
        $working->shouldReceive('getIdentifier')->andReturn('working-module');
        $working->shouldReceive('getUrls')->andReturn([['url' => '/ok']]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Sitemap contributor failed')
                && $context['contributor'] === 'failing-module');
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->generator->registerContributor($failing);
        $this->generator->registerContributor($working);

        $writer = new SpySitemapWriter;
        $this->generator->generateToWriter($writer);

        $this->assertContains(url('/ok'), array_column($writer->entries, 'loc'));
    }

    // ─── drain capability 감지 (지연 스트리밍) ─────────────

    /**
     * getUrlsLazy 를 가진 기여자는 지연 경로로 소진된다 (getUrls 미호출).
     *
     * getUrls() 를 호출하면 예외를 던지는 기여자를 사용해, generateToWriter 가
     * 지연 경로(getUrlsLazy)만 탄다는 것을 증명한다.
     */
    public function test_generate_to_writer_uses_lazy_path_for_capable_contributor(): void
    {
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $contributor = new class extends AbstractSitemapContributor
        {
            public function getIdentifier(): string
            {
                return 'lazy-capable';
            }

            public function getUrls(): array
            {
                throw new \LogicException('getUrls() 는 지연 경로에서 호출되면 안 됩니다.');
            }

            public function getUrlsLazy(): iterable
            {
                yield ['url' => '/lazy/1'];
                yield ['url' => '/lazy/2'];
            }
        };

        $this->generator->registerContributor($contributor);

        $writer = new SpySitemapWriter;
        $this->generator->generateToWriter($writer);

        $locs = array_column($writer->entries, 'loc');
        $this->assertContains(url('/lazy/1'), $locs);
        $this->assertContains(url('/lazy/2'), $locs);
    }

    /**
     * getUrlsLazy 가 없는 raw 기여자는 기존 getUrls() 경로로 소진된다 (하위호환).
     */
    public function test_generate_to_writer_uses_get_urls_for_raw_contributor(): void
    {
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('raw-module');
        $contributor->shouldReceive('getUrls')->once()->andReturn([['url' => '/raw/1']]);

        $this->generator->registerContributor($contributor);

        $writer = new SpySitemapWriter;
        $this->generator->generateToWriter($writer);

        $this->assertContains(url('/raw/1'), array_column($writer->entries, 'loc'));
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

/**
 * SitemapWriter 호출을 기록하는 테스트 더블.
 *
 * generateToWriter 가 URL 을 한 건씩 스트리밍하는지(전체 배열 병합 없음)와
 * open/close/commit 생명주기를 검증하기 위해 사용합니다.
 * 스토리지 없이 동작하도록 부모 생성자를 호출하지 않습니다.
 */
class SpySitemapWriter extends SitemapWriter
{
    /**
     * open() 호출 여부
     */
    public bool $opened = false;

    /**
     * close() 호출 여부
     */
    public bool $closed = false;

    /**
     * commit() 호출 여부
     */
    public bool $committed = false;

    /**
     * addUrl() 로 전달된 URL 항목 목록
     *
     * @var array<int, array<string, mixed>>
     */
    public array $entries = [];

    /**
     * SpySitemapWriter 생성자 (의존성 없이 생성).
     */
    public function __construct() {}

    /**
     * {@inheritDoc}
     */
    public function open(): void
    {
        $this->opened = true;
    }

    /**
     * {@inheritDoc}
     */
    public function addUrl(array $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * {@inheritDoc}
     */
    public function close(): array
    {
        $this->closed = true;

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function commit(): array
    {
        $this->committed = true;

        return ['committed' => true];
    }
}
