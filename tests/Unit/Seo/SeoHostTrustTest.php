<?php

namespace Tests\Unit\Seo;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Seo\ComponentHtmlMapper;
use App\Seo\DataSourceResolver;
use App\Seo\ExpressionEvaluator;
use App\Seo\PipeRegistry;
use App\Seo\SeoCacheManager;
use App\Seo\SeoConfigMerger;
use App\Seo\SeoMetaResolver;
use App\Seo\SeoRenderer;
use App\Seo\SeoUrl;
use App\Seo\TemplateRouteResolver;
use App\Services\LayoutService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use App\Services\TemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Mockery;
use Tests\TestCase;

/**
 * 봇 화면이 요청 Host 를 믿지 않는지 검증합니다 (KVE-2026-2333 캐시 오염 · 2334 SSRF).
 *
 * 위조 Host 는 `Request::create('https://site.example:6666/…')` 로 URL 생성기에 심는다 — 그
 * 상태에서 Laravel `url()` 은 `:6666` 을 따르므로(각 테스트가 전제로 확인한다), 봇 화면의
 * 산출물이 그것을 따르지 않는다는 것이 곧 조치의 증거다.
 */
class SeoHostTrustTest extends TestCase
{
    /**
     * 테스트 사이트 주소 (APP_URL).
     */
    private const SITE = 'https://site.example';

    /**
     * 위조 Host 로 들어온 요청의 루트.
     */
    private const FORGED_ROOT = 'https://site.example:6666';

    /**
     * 테스트 초기화 - 사이트 주소를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::SITE]);
    }

    /**
     * 현재 요청을 주어진 루트로 바꿔 URL 생성기가 그 Host 를 쓰게 합니다.
     *
     * @param  string  $root  요청 루트 (scheme://host[:port])
     * @param  string  $path  요청 경로
     * @return Request 바인딩된 요청
     */
    private function bindRequest(string $root, string $path = '/products/1'): Request
    {
        $request = Request::create($root.$path);
        $this->app->instance('request', $request);
        app('url')->setRequest($request);

        return $request;
    }

    /**
     * 데이터소스 1건을 해석하고 실제로 나간 요청의 URL·옵션을 돌려줍니다.
     *
     * @param  string  $endpoint  엔드포인트
     * @param  array<string, mixed>  $query  쿼리 파라미터
     * @return array{urls: array<int, string>, options: array<int, array<string, mixed>>} 나간 요청 기록
     */
    private function resolveOne(string $endpoint, array $query = []): array
    {
        $sent = ['urls' => [], 'options' => []];

        Http::fake(function ($request, array $options) use (&$sent) {
            $sent['urls'][] = $request->url();
            $sent['options'][] = $options;

            return Http::response(['data' => []], 200);
        });

        (new DataSourceResolver)->resolve(
            [['id' => 'ds', 'endpoint' => $endpoint, 'method' => 'GET']],
            ['ds'],
            [],
            'ko',
            $query,
        );

        return $sent;
    }

    /**
     * private 메서드를 호출합니다.
     *
     * @param  object  $target  대상 객체
     * @param  string  $method  메서드명
     * @param  mixed  ...$args  인자
     * @return mixed 반환값
     */
    private function callPrivate(object $target, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($target, ...$args);
    }

    /**
     * 위조 Host 로 들어온 봇 요청이라도 데이터소스 호출은 사이트 주소로만 나간다.
     *
     * 회귀: `url($endpoint)` 가 요청 Host 를 따라 서버가 공격자 포트로 요청을 보냈다.
     *
     * @scenario host_header=forged, sink=datasource_call
     *
     * @effects datasource_call_targets_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_datasource_call_ignores_forged_host(): void
    {
        $this->bindRequest(self::FORGED_ROOT);
        $this->assertStringContainsString(':6666', url('/api/boards'), '전제: url() 은 요청 Host 를 따른다');

        $sent = $this->resolveOne('/api/boards');

        $this->assertSame(['https://site.example/api/boards'], $sent['urls']);
    }

    /**
     * 정상 Host 요청의 데이터소스 호출도 같은 사이트 주소로 나가고 쿼리 파라미터가 붙는다.
     *
     * @scenario host_header=genuine, sink=datasource_call
     *
     * @effects datasource_call_targets_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_datasource_call_uses_site_origin_for_genuine_host(): void
    {
        $this->bindRequest(self::SITE);

        Http::fake(['*' => Http::response(['data' => ['ok' => true]], 200)]);

        $result = (new DataSourceResolver)->resolve(
            [['id' => 'ds', 'endpoint' => 'api/boards', 'method' => 'GET', 'params' => ['page' => '{{query.page}}']]],
            ['ds'],
            [],
            'ko',
            ['page' => '2'],
        );

        Http::assertSent(fn ($request) => $request->url() === 'https://site.example/api/boards?page=2');
        $this->assertSame(['data' => ['ok' => true]], $result['ds']);
    }

    /**
     * 엔드포인트 표현식이 방문자 쿼리로 절대 URL 을 만들어도 사이트 밖으로 나가지 않는다.
     *
     * @effects endpoint_expression_cannot_leave_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_endpoint_expression_cannot_point_outside_site(): void
    {
        foreach (['https://attacker.example/steal', '//attacker.example/steal', 'http://127.0.0.1:6379/'] as $payload) {
            $sent = $this->resolveOne('{{query.u}}', ['u' => $payload]);

            $this->assertCount(1, $sent['urls'], $payload);
            $this->assertSame('site.example', parse_url($sent['urls'][0], PHP_URL_HOST), $payload);
            $this->assertNull(parse_url($sent['urls'][0], PHP_URL_PORT), $payload);
        }
    }

    /**
     * 리다이렉트는 사이트 주소 안에서만 따른다 — 밖으로 나가는 대상은 거부한다.
     *
     * @effects redirect_outside_site_origin_is_refused
     *
     * @return void 반환값 없음
     */
    public function test_redirect_target_outside_site_is_refused(): void
    {
        $resolver = new DataSourceResolver;

        $this->assertNull($this->callPrivate($resolver, 'internalUrl', 'https://attacker.example/x', false));
        $this->assertNull($this->callPrivate($resolver, 'internalUrl', 'https://site.example@attacker.example/x', false));
        $this->assertNull($this->callPrivate($resolver, 'internalUrl', 'https://site.example:6666/x', false));
        $this->assertNull($this->callPrivate($resolver, 'internalUrl', 'https://evil.site.example/x', false));
        // 스킴 전환(http→https)과 기본 포트 명시는 같은 사이트다
        $this->assertSame('http://site.example/api', $this->callPrivate($resolver, 'internalUrl', 'http://site.example/api', false));
        $this->assertSame('https://site.example:443/api', $this->callPrivate($resolver, 'internalUrl', 'https://site.example:443/api', false));

        // 실제 호출에 리다이렉트 가드가 걸려 있다
        $sent = $this->resolveOne('/api/boards');
        $this->assertSame(3, $sent['options'][0]['allow_redirects']['max'] ?? null);
        $this->assertIsCallable($sent['options'][0]['allow_redirects']['on_redirect'] ?? null);
    }

    /**
     * TLS 검증은 사이트 주소가 루프백으로 풀릴 때만 생략하고, 그때는 연결을 그 주소로 고정한다.
     *
     * 회귀: `withoutVerifying()` 가 모든 호출의 검증을 껐다.
     *
     * @effects tls_verification_skipped_only_for_loopback_origin
     *
     * @return void 반환값 없음
     */
    public function test_tls_verification_is_skipped_only_for_loopback_origin(): void
    {
        $resolver = new DataSourceResolver;

        $this->assertSame([], $this->callPrivate($resolver, 'loopbackTransport', 'https://93.184.216.34/api'));
        $this->assertSame(['verify' => false], $this->callPrivate($resolver, 'loopbackTransport', 'https://127.0.0.1/api'));
        $this->assertSame(['verify' => false], $this->callPrivate($resolver, 'loopbackTransport', 'https://[::1]/api'));

        $pinned = $this->callPrivate($resolver, 'loopbackTransport', 'https://localhost:8443/api');
        $this->assertFalse($pinned['verify']);
        $this->assertContains('localhost:8443:127.0.0.1', $pinned['curl'][CURLOPT_RESOLVE]);
        $this->assertContains('localhost:443:127.0.0.1', $pinned['curl'][CURLOPT_RESOLVE]);

        // 외부 IP 로 풀리는 사이트 주소면 실제 호출에 verify 옵션을 끄지 않는다
        config(['app.url' => 'https://93.184.216.34']);
        $sent = $this->resolveOne('/api/boards');
        $this->assertNotSame(false, $sent['options'][0]['verify'] ?? true);
    }

    /**
     * 데이터소스 호출 제한 시간은 설정값을 따른다 (기본 2초).
     *
     * @effects datasource_timeout_is_configurable
     *
     * @return void 반환값 없음
     */
    public function test_datasource_timeout_follows_config(): void
    {
        $sent = $this->resolveOne('/api/boards');
        $this->assertSame(2, $sent['options'][0]['timeout']);

        config(['core.seo_datasource.timeout' => 7]);
        $sent = $this->resolveOne('/api/boards');
        $this->assertSame(7, $sent['options'][0]['timeout']);
    }

    /**
     * 위조 Host 요청으로 그린 canonical·og:url·hreflang·site_url 이 사이트 주소를 쓴다.
     *
     * @scenario host_header=forged, sink=rendered_urls
     *
     * @effects rendered_urls_use_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_rendered_urls_ignore_forged_host(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko', 'en']]);
        app()->setLocale('ko');
        $request = $this->bindRequest(self::FORGED_ROOT, '/products/123');

        $captured = null;
        $renderer = $this->makeRenderer('/products/123', function (array $data) use (&$captured) {
            $captured = $data;

            return true;
        });

        $this->assertNotNull($renderer->render($request));
        $this->assertSame('https://site.example/products/123', $captured['canonicalUrl']);
        $this->assertStringContainsString('<meta property="og:url" content="https://site.example/products/123">', $captured['ogTags']);
        $this->assertStringContainsString('href="https://site.example/products/123?locale=en"', $captured['hreflangTags']);
        $this->assertStringNotContainsString(':6666', $captured['canonicalUrl'].$captured['ogTags'].$captured['hreflangTags']);

        // 메타·본문 이미지 절대화도 같은 기준
        $meta = new SeoMetaResolver(Mockery::mock(ExpressionEvaluator::class));
        $this->assertSame('https://site.example/storage/og.png', $this->callPrivate($meta, 'absoluteUrl', '/storage/og.png'));
        $mapper = new ComponentHtmlMapper;
        $this->assertSame(' src="https://site.example/img/a.png"', $this->callPrivate($mapper, 'buildIterateItemAttributes', ['src' => '{img}'], ['img' => '/img/a.png']));
    }

    /**
     * 정상 Host 에서는 SeoUrl 이 Laravel url() 과 같은 모양을 낸다 (절대화 규칙 패리티).
     *
     * @scenario host_header=genuine, sink=rendered_urls
     *
     * @effects site_url_parity_with_laravel_url, rendered_urls_use_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_site_url_matches_laravel_url_for_genuine_host(): void
    {
        $this->bindRequest(self::SITE);

        foreach (['/', '', '/products/1', 'products/1/', '/search?q=a&page=2', 'https://cdn.example/a.png', '//cdn.example/a.png', 'mailto:a@b.c'] as $path) {
            $this->assertSame(url($path), SeoUrl::absolute($path), $path);
        }

        config(['app.url' => 'https://Site.Example:8443/']);
        $this->assertSame('https://site.example:8443', SeoUrl::origin());
        $this->assertSame('https://Site.Example:8443/a', SeoUrl::absolute('/a'));

        config(['app.url' => '']);
        $this->assertSame('/a', SeoUrl::absolute('/a'), 'APP_URL 이 비면 요청 Host 로 되돌아가지 않고 상대 경로를 낸다');
    }

    /**
     * 캐시 칸은 요청 Host 로 갈리지 않는다 — 포트만 바꾼 요청으로 변종 상한을 채울 수 없다.
     *
     * @scenario host_header=forged, sink=cache_slot
     *
     * @effects cache_key_namespaced_by_site_origin, forged_host_render_does_not_poison_cached_page
     *
     * @return void 반환값 없음
     */
    public function test_cache_slot_is_not_split_by_forged_host(): void
    {
        $manager = new SeoCacheManager($this->makeArrayCache());

        $this->bindRequest(self::FORGED_ROOT);
        $manager->put('/products/1', 'ko', '<html>site</html>');

        $this->bindRequest(self::SITE);
        $this->assertSame('<html>site</html>', $manager->get('/products/1', 'ko'));
        $this->assertCount(1, $manager->getCachedUrls());
    }

    /**
     * 캐시 칸은 사이트 주소로 이름공간이 갈린다 — 주소가 바뀌면 이전 주소로 그린 화면을 쓰지 않는다.
     *
     * @scenario host_header=genuine, sink=cache_slot
     *
     * @effects cache_key_namespaced_by_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_cache_slot_is_namespaced_by_site_origin(): void
    {
        $this->bindRequest(self::SITE);
        $manager = new SeoCacheManager($this->makeArrayCache());

        $manager->put('/products/1', 'ko', '<html>old</html>');
        $this->assertSame('<html>old</html>', $manager->get('/products/1', 'ko'));

        config(['app.url' => 'https://moved.example']);
        $this->assertNull($manager->get('/products/1', 'ko'));
    }

    /**
     * 테스트 전용 배열 캐시 드라이버를 만듭니다 (SEO 캐시 활성).
     *
     * @return CacheInterface 캐시 드라이버
     */
    private function makeArrayCache(): CacheInterface
    {
        config()->set('g7_settings.core.seo.cache_enabled', true);
        config()->set('g7_settings.core.seo.cache_ttl', 600);
        Cache::flush();

        return new CoreCacheDriver('array');
    }

    /**
     * 뷰 데이터를 가로채는 SeoRenderer 를 만듭니다 (의존성은 최소 목).
     *
     * @param  string  $path  요청 경로
     * @param  \Closure  $viewDataAssert  seo 뷰 데이터 검사기 (true 반환)
     * @return SeoRenderer 렌더러
     */
    private function makeRenderer(string $path, \Closure $viewDataAssert): SeoRenderer
    {
        $routeResolver = Mockery::mock(TemplateRouteResolver::class);
        $routeResolver->shouldReceive('resolve')->with($path)->andReturn([
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $layoutService = Mockery::mock(LayoutService::class);
        $layoutService->shouldReceive('getLayout')->andReturn([
            'meta' => ['seo' => ['enabled' => true, 'data_sources' => []]],
            'data_sources' => [],
            'components' => [],
        ]);

        $templateService = Mockery::mock(TemplateService::class);
        $templateService->shouldReceive('getLanguageDataWithModules')->andReturn(['success' => true, 'data' => [], 'error' => null]);

        $dataSourceResolver = Mockery::mock(DataSourceResolver::class);
        $dataSourceResolver->shouldReceive('resolve')->andReturn([]);

        $metaResolver = Mockery::mock(SeoMetaResolver::class);
        $metaResolver->shouldReceive('resolveLocalizedValue')->andReturnUsing(fn ($value) => is_string($value) ? $value : '');
        $metaResolver->shouldReceive('resolve')->andReturn([
            'title' => '상품', 'titleSuffix' => '', 'description' => '', 'keywords' => '',
            'og' => [], 'twitter' => [], 'structured_data' => null, 'extraMetaTags' => [],
            'ogTags' => '', 'twitterTags' => '', 'jsonLd' => '',
            'googleAnalyticsId' => '', 'googleVerification' => '', 'naverVerification' => '',
        ]);

        $htmlMapper = Mockery::mock(ComponentHtmlMapper::class)->shouldIgnoreMissing();
        $htmlMapper->shouldReceive('render')->andReturn('');

        $pipeRegistry = Mockery::mock(PipeRegistry::class)->shouldIgnoreMissing();
        $evaluator = Mockery::mock(ExpressionEvaluator::class)->shouldIgnoreMissing();
        $evaluator->shouldReceive('getPipeRegistry')->andReturn($pipeRegistry);

        $seoConfigMerger = Mockery::mock(SeoConfigMerger::class);
        $seoConfigMerger->shouldReceive('getMergedConfig')->andReturn([]);

        $settingsService = Mockery::mock(SettingsService::class);
        $settingsService->shouldReceive('getFrontendSettings')->andReturn([]);

        $pluginSettingsService = Mockery::mock(PluginSettingsService::class);
        $pluginSettingsService->shouldReceive('getAllActiveSettings')->andReturn([]);

        $view = Mockery::mock(\Illuminate\View\View::class);
        $view->shouldReceive('render')->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::on($viewDataAssert))->andReturn($view);

        return new SeoRenderer(
            $routeResolver,
            $layoutService,
            $templateService,
            $dataSourceResolver,
            $metaResolver,
            $htmlMapper,
            $evaluator,
            $seoConfigMerger,
            $settingsService,
            $pluginSettingsService,
            Mockery::mock(ModuleManagerInterface::class)->shouldIgnoreMissing(),
            Mockery::mock(PluginManagerInterface::class)->shouldIgnoreMissing(),
        );
    }
}
