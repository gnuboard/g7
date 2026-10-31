<?php

namespace Tests\Unit\Seo;

use App\Seo\AbstractSitemapContributor;
use App\Seo\Contracts\SitemapContributorInterface;
use App\Seo\SeoDeclarationCollector;
use App\Seo\SeoToggleEvaluator;
use App\Seo\SitemapGenerator;
use App\Seo\SitemapWriter;
use App\Seo\TemplateRouteResolver;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * SitemapGenerator 단위 테스트
 *
 * 정적 라우트 수집, 기여자 등록/URL 변환, XML 생성 기능을 테스트합니다.
 *
 * 정적 화면은 SEO 선언 수집기(SeoDeclarationCollector)와 토글 판정기(SeoToggleEvaluator)를 목으로 넣고
 * 실제 generate()/generateToWriter() 로 검증한다. 예전에는 운영 로직을 테스트 안에 복사해 우회했기 때문에
 * 정적 호출 오류로 정적 화면이 통째로 빠지는 결함(#155)을 잡지 못했다.
 */
class SitemapGeneratorTest extends TestCase
{
    private SitemapGenerator $generator;

    private TemplateRouteResolver|Mockery\MockInterface $routeResolver;

    private TemplateService|Mockery\MockInterface $templateService;

    private SeoDeclarationCollector|Mockery\MockInterface $collector;

    private SeoToggleEvaluator|Mockery\MockInterface $toggleEvaluator;

    /**
     * 테스트 초기화 - SitemapGenerator 인스턴스와 의존성 목(Mock)을 준비합니다.
     *
     * @return void 반환값 없음
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
        $this->collector = Mockery::mock(SeoDeclarationCollector::class);
        $this->toggleEvaluator = Mockery::mock(SeoToggleEvaluator::class);

        // 정적 화면이 없다는 가정에 기대는 기여자 테스트를 위해 기본은 「선언 없음」·「토글 켜짐」
        $this->collector->shouldReceive('collect')->andReturn([])->byDefault();
        $this->toggleEvaluator->shouldReceive('isEnabled')->andReturn(true)->byDefault();

        $this->generator = new SitemapGenerator(
            $this->routeResolver,
            $this->templateService,
            $this->collector,
            $this->toggleEvaluator,
        );
    }

    /**
     * 테스트 종료 - Mockery 리소스를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── 기여자 등록 ──────────────────────────────────────

    /**
     * 기여자를 등록하면 getContributors()에 포함되는지 확인합니다.
     *
     * @scenario generator=register_contributor_adds_to_list
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=register_contributor_overwrites_same_identifier
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=generate_returns_valid_xml
     *
     * @return void 반환값 없음
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

    // ─── 정적 화면 (SEO 선언 기반, #155) ─────────────────────

    /**
     * 선언된 정적 화면이 레이아웃의 priority·changefreq 로 실제 generate() 결과에 실린다.
     *
     * 수정 전에는 활성 템플릿을 정적 호출로 조회하다 Error 가 나 정적 화면이 0건이었다.
     *
     * @scenario static=declared_home
     *
     * @effects static_route_uses_layout_priority_and_changefreq
     *
     * @return void 반환값 없음
     */
    public function test_generate_includes_declared_static_route_with_layout_priority(): void
    {
        $this->collector->shouldReceive('collect')->andReturn([
            $this->declaration('/', ['enabled' => true, 'priority' => 1, 'changefreq' => 'daily']),
        ]);

        $entries = $this->entriesOf($this->generator->generate());

        $this->assertCount(1, $entries);
        $this->assertSame(url('/'), $entries[0]['loc']);
        $this->assertSame('1.0', $entries[0]['priority']);
        $this->assertSame('daily', $entries[0]['changefreq']);
    }

    /**
     * 동적 파라미터(`:`)·템플릿 표현식(`{{`)·경로 없는 선언은 싣지 않고, `*` 접두는 떼어 낸다.
     *
     * @scenario static=dynamic_path
     *
     * @effects static_route_skips_dynamic_and_expression_paths
     *
     * @return void 반환값 없음
     */
    public function test_generate_excludes_dynamic_and_expression_paths(): void
    {
        $this->collector->shouldReceive('collect')->andReturn([
            $this->declaration('*/about'),
            $this->declaration('/products/:id'),
            $this->declaration("/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop'}}/products"),
            $this->declaration(null),
        ]);

        $locs = array_column($this->entriesOf($this->generator->generate()), 'loc');

        $this->assertSame([url('/about')], $locs);
    }

    /**
     * 레이아웃 toggle_setting 이 꺼진 화면은 싣지 않는다 (봇 화면 기준과 동일, D2).
     *
     * @scenario static=toggle_off
     *
     * @effects static_route_follows_toggle_setting
     *
     * @return void 반환값 없음
     */
    public function test_generate_excludes_route_whose_toggle_is_off(): void
    {
        $search = $this->declaration('/search', ['enabled' => true, 'toggle_setting' => '$module_settings:sirsoft-ecommerce:seo.seo_search_result']);
        $home = $this->declaration('/');

        $this->collector->shouldReceive('collect')->andReturn([$home, $search]);
        $this->toggleEvaluator->shouldReceive('isEnabled')->with(null, null, $search['seo'])->andReturn(false);
        $this->toggleEvaluator->shouldReceive('isEnabled')->with(null, null, $home['seo'])->andReturn(true);

        $locs = array_column($this->entriesOf($this->generator->generate()), 'loc');

        $this->assertSame([url('/')], $locs);
    }

    /**
     * priority 가 0~1 밖이거나 숫자가 아니고 changefreq 가 비표준이면 0.5·weekly 로 둔다.
     *
     * @scenario static=invalid_priority
     *
     * @effects static_route_falls_back_to_default_priority_and_changefreq
     *
     * @return void 반환값 없음
     */
    public function test_generate_falls_back_when_priority_or_changefreq_invalid(): void
    {
        $this->collector->shouldReceive('collect')->andReturn([
            $this->declaration('/a', ['enabled' => true, 'priority' => 1.5, 'changefreq' => 'sometimes']),
            $this->declaration('/b', ['enabled' => true, 'priority' => 'high']),
            $this->declaration('/c', ['enabled' => true, 'priority' => 0, 'changefreq' => ' Monthly ']),
        ]);

        $entries = $this->entriesOf($this->generator->generate());

        $this->assertSame(['0.5', 'weekly'], [$entries[0]['priority'], $entries[0]['changefreq']]);
        $this->assertSame(['0.5', 'weekly'], [$entries[1]['priority'], $entries[1]['changefreq']]);
        $this->assertSame(['0.0', 'monthly'], [$entries[2]['priority'], $entries[2]['changefreq']]);
    }

    /**
     * 같은 주소를 정적 화면과 기여자가 함께 내면 정적 화면 1건만 남는다 (D6).
     *
     * @scenario static=duplicate_contributor
     *
     * @effects static_route_dedupes_contributor_same_loc
     *
     * @return void 반환값 없음
     */
    public function test_generate_writes_same_loc_once_when_contributor_repeats_static_route(): void
    {
        $this->collector->shouldReceive('collect')->andReturn([
            $this->declaration('/boards', ['enabled' => true, 'priority' => 0.6, 'changefreq' => 'daily']),
        ]);

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('sirsoft-board');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['url' => '/boards', 'changefreq' => 'hourly', 'priority' => 0.9],
            ['url' => '/board/free'],
        ]);
        $this->generator->registerContributor($contributor);

        $entries = $this->entriesOf($this->generator->generate());
        $boards = array_values(array_filter($entries, fn (array $e): bool => $e['loc'] === url('/boards')));

        $this->assertCount(1, $boards);
        // 정적 화면이 먼저 적히므로 레이아웃 값이 남는다
        $this->assertSame(['0.6', 'daily'], [$boards[0]['priority'], $boards[0]['changefreq']]);
        $this->assertContains(url('/board/free'), array_column($entries, 'loc'));
    }

    /**
     * 기여자가 끝 슬래시 없는 홈(`https://사이트`)·끝 슬래시 홈·대문자 스킴 홈을 내도 정적 홈과 1건으로 합친다.
     *
     * 제보자의 임시 우회 기여자가 SiteUrl::absolute('') · url('/') 로 홈을 내는 경우다. 다른 경로의 끝 슬래시는
     * 다른 주소로 남긴다.
     *
     * @scenario static=duplicate_home_slash
     *
     * @effects static_route_dedupes_home_without_trailing_slash
     *
     * @return void 반환값 없음
     */
    public function test_generate_dedupes_home_regardless_of_trailing_slash(): void
    {
        $this->collector->shouldReceive('collect')->andReturn([
            $this->declaration('/', ['enabled' => true, 'priority' => 1, 'changefreq' => 'daily']),
            $this->declaration('/boards'),
        ]);

        $home = rtrim(url('/'), '/');
        $upperScheme = preg_replace_callback('#^[a-z]+#', fn (array $m): string => strtoupper($m[0]), $home);
        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('custom-home');
        $contributor->shouldReceive('getUrls')->andReturn([
            ['loc' => $home],
            ['loc' => $home.'/'],
            ['loc' => $upperScheme.'/'],
            ['loc' => url('/boards').'/'],
        ]);
        $this->generator->registerContributor($contributor);

        $locs = array_column($this->entriesOf($this->generator->generate()), 'loc');

        $homeLocs = array_filter($locs, fn (string $loc): bool => strtolower(rtrim($loc, '/')) === strtolower($home));
        $this->assertCount(1, $homeLocs, '홈은 정적 화면 1건만 남아야 합니다: '.implode(', ', $locs));
        // `/boards/` 는 `/boards` 와 다른 주소로 남는다
        $this->assertContains(url('/boards').'/', $locs);
        $this->assertContains(url('/boards'), $locs);
    }

    /**
     * generateToWriter·generateToWriterFromEntries 도 정적 화면을 먼저 쓰고 같은 주소를 건너뛴다.
     *
     * @scenario static=duplicate_writer_paths
     *
     * @effects static_route_dedupes_on_every_generation_path
     *
     * @return void 반환값 없음
     */
    public function test_writer_paths_write_static_routes_first_and_dedupe(): void
    {
        $this->collector->shouldReceive('collect')->andReturn([$this->declaration('/')]);

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('custom-home');
        // 기여자·저장소가 홈을 뒤에 내도 정적 홈이 맨 앞에 한 번만 적힌다
        $contributor->shouldReceive('getUrls')->andReturn([['url' => '/about'], ['url' => '/']]);
        $this->generator->registerContributor($contributor);

        $writer = new SpySitemapWriter;
        $this->generator->generateToWriter($writer);
        $this->assertSame([url('/'), url('/about')], array_column($writer->entries, 'loc'));

        $fromEntries = new SpySitemapWriter;
        $this->generator->generateToWriterFromEntries($fromEntries, [
            ['loc' => url('/about')],
            ['loc' => url('/'), 'resource_type' => 'home'],
        ]);
        $this->assertSame([url('/'), url('/about')], array_column($fromEntries->entries, 'loc'));
    }

    /**
     * 대용량 저장소 스트림(150,000건)을 흘려도 중복 판정 집합이 정적 화면 수에 머물러 메모리가 유계다.
     *
     * 중복 판정 집합에 모든 loc 를 담는 구현(회귀)이면 URL 수에 비례해 수십 MB 가 늘어난다.
     *
     * @scenario static=bounded_dedupe
     *
     * @effects static_dedupe_keeps_streaming_memory_bounded
     *
     * @scale n=150000 asserts=bounded_peak_memory, static_routes_written_first
     *
     * @return void 반환값 없음
     */
    public function test_dedupe_keeps_streaming_memory_bounded_for_large_entry_stream(): void
    {
        $this->collector->shouldReceive('collect')->andReturn([$this->declaration('/'), $this->declaration('/boards')]);

        $writer = new CountingSitemapWriter;
        $entries = (function (): \Generator {
            yield ['loc' => url('/')];
            for ($i = 1; $i <= 150000; $i++) {
                yield ['loc' => url("/p/{$i}")];
            }
        })();

        memory_reset_peak_usage();
        $before = memory_get_peak_usage(true);

        $this->generator->generateToWriterFromEntries($writer, $entries);

        $growth = memory_get_peak_usage(true) - $before;

        // 정적 2건 + 저장소 150,000건(정적 홈과 겹치는 1건 제외)
        $this->assertSame([url('/'), url('/boards')], $writer->first);
        $this->assertSame(150002, $writer->count);
        // 150,000개 loc 를 집합에 담으면 20MB 이상 는다 — 정적 화면만 담으면 거의 늘지 않는다
        $this->assertLessThan(8 * 1024 * 1024, $growth, '중복 판정 집합이 스트림 크기에 비례해 커졌습니다: '.$growth.' bytes');
    }

    /**
     * 선언 수집이 예외를 던지면 오류로 보고하고, 기여자 URL 은 그대로 싣는다 (D3).
     *
     * @scenario static=collector_failure
     *
     * @effects sitemap_failures_are_reported_and_generation_continues
     *
     * @return void 반환값 없음
     */
    public function test_static_collection_failure_is_reported_and_contributors_remain(): void
    {
        Exceptions::fake();

        $this->collector->shouldReceive('collect')->andThrow(new \LogicException('layout broken'));

        $contributor = Mockery::mock(SitemapContributorInterface::class);
        $contributor->shouldReceive('getIdentifier')->andReturn('working-module');
        $contributor->shouldReceive('getUrls')->andReturn([['url' => '/ok']]);
        $this->generator->registerContributor($contributor);

        $locs = array_column($this->entriesOf($this->generator->generate()), 'loc');

        $this->assertSame([url('/ok')], $locs);
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getPrevious() instanceof \LogicException
            && $e->getPrevious()->getMessage() === 'layout broken');
    }

    // ─── generate() - 기여자 예외 처리 ──────────────────────

    /**
     * 기여자에서 예외 발생 시 다른 기여자의 URL이 정상 수집되고, 실패가 오류로 보고되는지 확인합니다.
     *
     * 경고 한 줄로 삼키면 운영 로그 수준(error)에서 보이지 않는다(#155) — report() 로 올리고 계속 진행한다.
     *
     * @scenario static=contributor_failure
     *
     * @effects sitemap_failures_are_reported_and_generation_continues
     *
     * @return void 반환값 없음
     */
    public function test_generate_handles_contributor_exception_gracefully(): void
    {
        Exceptions::fake();

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

        $this->generator->registerContributor($failingContributor);
        $this->generator->registerContributor($workingContributor);

        $xml = $this->generator->generate();

        // 정상 기여자의 URL은 포함되어야 함
        $this->assertStringContainsString(url('/working-page'), $xml);
        $this->assertStringContainsString('<changefreq>monthly</changefreq>', $xml);

        // 실패한 기여자 식별자와 원인 예외가 함께 보고된다
        Exceptions::assertReported(fn (\RuntimeException $e): bool => str_contains($e->getMessage(), 'failing-module')
            && $e->getPrevious()?->getMessage() === 'DB connection failed');
    }

    // ─── generate() - URL 변환 ──────────────────────────────

    /**
     * 기여자의 url 키가 절대 경로 loc으로 변환되는지 확인합니다.
     *
     * @scenario generator=generate_converts_contributor_url_to_absolute_loc
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=generate_with_no_contributors_returns_empty_urlset
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=multilingual_xml_includes_xhtml_namespace
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=multilingual_xml_generates_url_per_locale
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=multilingual_xml_includes_hreflang_alternates
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=single_locale_uses_standard_xml_format
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=render_url_block_single_locale
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=render_url_block_multilingual
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=render_url_block_escapes_xml_special_characters
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=urlset_header_switches_on_multilingual
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=generate_to_writer_streams_entries_without_cross_merge
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=generate_to_writer_drains_each_contributor_before_calling_the_next
     *
     * @return void 반환값 없음
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
     *
     * @scenario generator=generate_to_writer_isolates_contributor_failure
     *
     * @return void 반환값 없음
     */
    public function test_generate_to_writer_isolates_contributor_failure(): void
    {
        $failing = Mockery::mock(SitemapContributorInterface::class);
        $failing->shouldReceive('getIdentifier')->andReturn('failing-module');
        $failing->shouldReceive('getUrls')->andThrow(new \RuntimeException('DB connection failed'));

        $working = Mockery::mock(SitemapContributorInterface::class);
        $working->shouldReceive('getIdentifier')->andReturn('working-module');
        $working->shouldReceive('getUrls')->andReturn([['url' => '/ok']]);

        Exceptions::fake();

        $this->generator->registerContributor($failing);
        $this->generator->registerContributor($working);

        $writer = new SpySitemapWriter;
        $this->generator->generateToWriter($writer);

        $this->assertContains(url('/ok'), array_column($writer->entries, 'loc'));
        Exceptions::assertReported(fn (\RuntimeException $e): bool => str_contains($e->getMessage(), 'failing-module')
            && $e->getPrevious()?->getMessage() === 'DB connection failed');
    }

    // ─── drain capability 감지 (지연 스트리밍) ─────────────

    /**
     * getUrlsLazy 를 가진 기여자는 지연 경로로 소진된다 (getUrls 미호출).
     *
     * getUrls() 를 호출하면 예외를 던지는 기여자를 사용해, generateToWriter 가
     * 지연 경로(getUrlsLazy)만 탄다는 것을 증명한다.
     *
     * @scenario generator=generate_to_writer_uses_lazy_path_for_capable_contributor
     *
     * @return void 반환값 없음
     */
    public function test_generate_to_writer_uses_lazy_path_for_capable_contributor(): void
    {
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $contributor = new class extends AbstractSitemapContributor
        {
            /**
             * 기여자 식별자를 돌려줍니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return 'lazy-capable';
            }

            /**
             * 지연 경로에서는 호출되면 안 되는 배열 경로입니다.
             *
             * @return array<int, array<string, mixed>> 호출되면 예외
             */
            public function getUrls(): array
            {
                throw new \LogicException('getUrls() 는 지연 경로에서 호출되면 안 됩니다.');
            }

            /**
             * URL 을 한 건씩 내보냅니다.
             *
             * @return iterable<array<string, mixed>> URL 항목
             */
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
     *
     * @scenario generator=generate_to_writer_uses_get_urls_for_raw_contributor
     *
     * @return void 반환값 없음
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
     * SeoDeclarationCollector::collect() 가 돌려주는 선언 한 건을 만듭니다.
     *
     * @param  string|null  $routePath  라우트 경로
     * @param  array<string, mixed>  $seo  병합 레이아웃 meta.seo
     * @return array<string, mixed> 선언
     */
    private function declaration(?string $routePath, array $seo = ['enabled' => true]): array
    {
        return [
            'layoutName' => 'sample',
            'templateIdentifier' => 'sirsoft-basic',
            'moduleIdentifier' => null,
            'pluginIdentifier' => null,
            'seo' => $seo,
            'routePath' => $routePath,
        ];
    }

    /**
     * sitemap XML 의 <url> 항목을 loc·priority·changefreq 로 풀어냅니다.
     *
     * @param  string  $xml  사이트맵 XML 본문
     * @return array<int, array<string, string|null>> 항목 목록(loc·priority·changefreq)
     */
    private function entriesOf(string $xml): array
    {
        $document = simplexml_load_string($xml);
        $this->assertNotFalse($document, 'sitemap XML 을 해석할 수 없습니다');

        $entries = [];
        foreach ($document->url as $url) {
            $entries[] = [
                'loc' => (string) $url->loc,
                'priority' => isset($url->priority) ? (string) $url->priority : null,
                'changefreq' => isset($url->changefreq) ? (string) $url->changefreq : null,
            ];
        }

        return $entries;
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
     *
     * @return void 반환값 없음
     */
    public function open(): void
    {
        $this->opened = true;
    }

    /**
     * {@inheritDoc}
     *
     * @return void 반환값 없음
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

/**
 * URL 수와 앞부분 몇 건만 기억하는 SitemapWriter 테스트 더블.
 *
 * 대용량 스트림 시험에서 기록 항목을 모두 담으면 더블 자체가 메모리를 키우므로 개수만 셉니다.
 */
class CountingSitemapWriter extends SitemapWriter
{
    /**
     * addUrl() 로 전달된 URL 수
     */
    public int $count = 0;

    /**
     * 앞부분 두 건의 loc
     *
     * @var array<int, string>
     */
    public array $first = [];

    /**
     * CountingSitemapWriter 생성자 (의존성 없이 생성).
     */
    public function __construct() {}

    /**
     * {@inheritDoc}
     */
    public function open(): void {}

    /**
     * {@inheritDoc}
     */
    public function addUrl(array $entry): void
    {
        $this->count++;
        if (count($this->first) < 2) {
            $this->first[] = $entry['loc'];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function close(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function commit(): array
    {
        return [];
    }
}
