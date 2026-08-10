<?php

namespace Tests\Unit\Seo;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Seo\Contracts\SitemapContributorInterface;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapGenerator;
use App\Seo\SitemapManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * SitemapManager::regenerate 단위 테스트
 *
 * regenerate() 는 S1 의 4대 산출물 중 하나이며 S2 의 Controller 가 반환 메타를 소비한다.
 * 기존 테스트(SeoCacheControllerTest / GenerateSitemapJobTest)는 SitemapManager 를 통째로
 * mock 하므로 본문이 한 번도 실행되지 않는다 — 이 테스트가 그 공백을 메운다.
 *
 * 검증 목적:
 * - sitemap_enabled=false 시 disabled 반환 + 훅 미발화
 * - 성공 시 반환 메타 5키 · 캐시(구 XML 키 정리 + 메타 저장) · last_updated_at 기록 · after_regenerate 훅
 * - 실패 시 failed 반환 + after_regenerate_failed 훅 발화(페이로드 포함)
 * - writer 가 설정(urls_per_file / gzip)을 반영
 * - ttl 폴백 사슬
 */
class SitemapManagerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 이 테스트가 등록·검증하는 sitemap 훅 목록
     */
    private const SITEMAP_HOOKS = [
        'core.seo.sitemap.before_regenerate',
        'core.seo.sitemap.after_regenerate',
        'core.seo.sitemap.after_regenerate_failed',
    ];

    private CoreStorageDriver $storage;

    /**
     * 테스트 초기화 - 가짜 디스크와 훅 레지스트리를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        Storage::fake('local');
        $this->storage = new CoreStorageDriver('local');

        $this->clearSitemapHooks();
    }

    /**
     * 테스트 정리 - 등록한 훅 리스너가 다른 테스트로 누출되지 않도록 제거합니다.
     */
    protected function tearDown(): void
    {
        $this->clearSitemapHooks();

        parent::tearDown();
    }

    /**
     * sitemap 훅 리스너를 모두 제거합니다.
     */
    private function clearSitemapHooks(): void
    {
        foreach (self::SITEMAP_HOOKS as $hook) {
            HookManager::clearAction($hook);
        }
    }

    /**
     * 지정한 URL 을 내보내는 가짜 contributor 를 만듭니다.
     *
     * @param  array<int, string>  $urls  상대 경로 목록
     * @return SitemapContributorInterface 가짜 기여자
     */
    private function fakeContributor(array $urls): SitemapContributorInterface
    {
        return new class($urls) implements SitemapContributorInterface
        {
            /**
             * @param  array<int, string>  $urls  상대 경로 목록
             */
            public function __construct(private array $urls) {}

            public function getIdentifier(): string
            {
                return 'fake-contributor';
            }

            public function getUrls(): array
            {
                return array_map(fn (string $url): array => ['url' => $url], $this->urls);
            }
        };
    }

    /**
     * 테스트용 SitemapManager 를 만듭니다.
     *
     * @param  array<int, string>  $urls  기여자가 내보낼 URL 목록
     * @param  CacheInterface|null  $cache  캐시 (미지정 시 기대 없는 spy)
     * @param  ConfigRepositoryInterface|null  $config  설정 Repository
     * @return SitemapManager manager 인스턴스
     */
    private function makeManager(
        array $urls = ['/a'],
        ?CacheInterface $cache = null,
        ?ConfigRepositoryInterface $config = null,
    ): SitemapManager {
        // generator 는 라우트 해석기/템플릿 서비스에 의존하므로 컨테이너로 해석합니다.
        $generator = $this->app->make(SitemapGenerator::class);
        $generator->registerContributor($this->fakeContributor($urls));

        return new SitemapManager(
            $generator,
            $cache ?? Mockery::spy(CacheInterface::class),
            $config ?? Mockery::spy(ConfigRepositoryInterface::class),
            $this->storage,
            $this->app->make(SitemapUrlRepositoryInterface::class),
            $this->app->make(\App\Seo\SitemapProgress::class),
        );
    }

    /**
     * regenerate: sitemap 이 비활성화되면 생성하지 않고 disabled 를 반환한다
     */
    public function test_regenerate_returns_disabled_when_sitemap_is_disabled(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', false);

        $fired = [];
        HookManager::addAction('core.seo.sitemap.before_regenerate', function () use (&$fired) {
            $fired[] = 'before';
        });

        $result = $this->makeManager()->regenerate();

        $this->assertFalse($result['success']);
        $this->assertSame('disabled', $result['status']);
        $this->assertArrayNotHasKey('data', $result);
        $this->assertSame([], $fired, '비활성화 시 훅이 발화하면 안 됩니다.');
        $this->assertFalse(
            $this->storage->exists(SitemapFileStore::CATEGORY, SitemapFileStore::LIVE_DIR.'/'.SitemapFileStore::MANIFEST_FILE),
            '비활성화 시 파일을 커밋하면 안 됩니다.'
        );
    }

    /**
     * regenerate: 성공 시 status=updated 와 메타 5키를 반환한다
     */
    public function test_regenerate_returns_updated_with_meta_payload(): void
    {
        $result = $this->makeManager(['/a', '/b'])->regenerate();

        $this->assertTrue($result['success']);
        $this->assertSame('updated', $result['status']);
        $this->assertSame(
            ['last_updated_at', 'size_bytes', 'url_count', 'child_count', 'ttl'],
            array_keys($result['data']),
            'S2 Controller 가 소비하는 메타 키 집합이 고정되어야 합니다.'
        );
        $this->assertSame(1, $result['data']['child_count']);
        $this->assertGreaterThan(0, $result['data']['size_bytes']);
    }

    /**
     * regenerate: 성공 시 sitemap 세트가 디스크에 커밋된다
     */
    public function test_regenerate_commits_sitemap_set_to_disk(): void
    {
        $this->makeManager(['/a'])->regenerate();

        $store = new SitemapFileStore($this->storage);

        $this->assertTrue($store->exists());
        $this->assertNotNull($store->indexResponse());
        $this->assertNotNull($store->childResponse(1));
    }

    /**
     * regenerate: 구 XML 본문 캐시를 정리하고 메타만 캐시에 저장한다
     */
    public function test_regenerate_forgets_legacy_xml_cache_and_puts_meta(): void
    {
        Config::set('g7_settings.core.cache.seo_sitemap_ttl', 3600);
        Config::set('g7_settings.core.seo.sitemap_cache_ttl', null);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('forget')->once()->with('seo.sitemap');
        $cache->shouldReceive('put')
            ->once()
            ->with(SitemapManager::META_CACHE_KEY, Mockery::type('array'), 3600);

        $this->makeManager(['/a'], $cache)->regenerate();
    }

    /**
     * regenerate: SEO 탭 지정이 없으면 고급 탭(cache.seo_sitemap_ttl) 값을 ttl 로 쓴다 (D19)
     */
    public function test_regenerate_uses_advanced_tab_ttl_when_seo_override_is_unset(): void
    {
        Config::set('g7_settings.core.cache.seo_sitemap_ttl', 7200);
        Config::set('g7_settings.core.seo.sitemap_cache_ttl', null);

        $result = $this->makeManager()->regenerate();

        $this->assertSame(7200, $result['data']['ttl']);
    }

    /**
     * regenerate: SEO 탭에 지정이 있으면 그 값이 고급 탭을 오버라이드한다 (D19)
     */
    public function test_regenerate_uses_seo_override_ttl_when_set(): void
    {
        Config::set('g7_settings.core.cache.seo_sitemap_ttl', 7200);
        Config::set('g7_settings.core.seo.sitemap_cache_ttl', 3600);

        $result = $this->makeManager()->regenerate();

        $this->assertSame(3600, $result['data']['ttl'], 'SEO 탭 지정이 고급 탭보다 우선해야 합니다.');
    }

    /**
     * regenerate: last_updated_at 을 seo 설정 카테고리에 기록한다
     */
    public function test_regenerate_records_last_updated_at_in_settings(): void
    {
        $config = Mockery::mock(ConfigRepositoryInterface::class);
        $config->shouldReceive('getCategory')->once()->with('seo')->andReturn(['existing' => 'keep']);
        $config->shouldReceive('saveCategory')
            ->once()
            ->with('seo', Mockery::on(function (array $saved): bool {
                // 기존 키를 보존하면서 타임스탬프만 추가해야 한다
                return $saved['existing'] === 'keep'
                    && ! empty($saved['sitemap_last_updated_at']);
            }));

        $result = $this->makeManager(['/a'], null, $config)->regenerate();

        $this->assertTrue($result['success']);
    }

    /**
     * regenerate: 설정 기록이 실패해도 생성 자체는 성공으로 유지된다
     */
    public function test_regenerate_survives_settings_write_failure(): void
    {
        $config = Mockery::mock(ConfigRepositoryInterface::class);
        $config->shouldReceive('getCategory')->andThrow(new \RuntimeException('설정 저장소 장애'));

        $result = $this->makeManager(['/a'], null, $config)->regenerate();

        $this->assertTrue($result['success'], 'last_updated_at 기록 실패가 sitemap 생성을 무효화하면 안 됩니다.');
        $this->assertSame('updated', $result['status']);
    }

    /**
     * regenerate: 성공 시 before/after_regenerate 훅이 순서대로 발화한다
     */
    public function test_regenerate_fires_before_and_after_hooks_in_order(): void
    {
        $order = [];
        HookManager::addAction('core.seo.sitemap.before_regenerate', function () use (&$order) {
            $order[] = 'before';
        });
        HookManager::addAction('core.seo.sitemap.after_regenerate', function () use (&$order) {
            $order[] = 'after';
        });

        $this->makeManager()->regenerate();

        $this->assertSame(['before', 'after'], $order);
    }

    /**
     * regenerate: 실패 시 failed 를 반환하고 after_regenerate_failed 훅이 발화한다
     *
     * 이 훅은 확장이 구독하는 공개 표면이므로 발화 여부와 페이로드 형태를 고정한다.
     */
    public function test_regenerate_fires_after_regenerate_failed_hook_on_failure(): void
    {
        $payloads = [];
        HookManager::addAction('core.seo.sitemap.after_regenerate_failed', function ($result) use (&$payloads) {
            $payloads[] = $result;
        });
        HookManager::addAction('core.seo.sitemap.after_regenerate', function () {
            throw new \LogicException('성공 훅이 발화하면 안 됩니다.');
        });

        // 생성 도중 예외를 던지는 generator 로 실패를 강제한다.
        $generator = Mockery::mock(SitemapGenerator::class);
        $generator->shouldReceive('generateToWriterFromEntries')->andThrow(new \RuntimeException('디스크 장애'));

        $manager = new SitemapManager(
            $generator,
            Mockery::spy(CacheInterface::class),
            Mockery::spy(ConfigRepositoryInterface::class),
            $this->storage,
            $this->incrementalRepository(),
            $this->app->make(\App\Seo\SitemapProgress::class),
        );

        $result = $manager->regenerate();

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('디스크 장애', $result['message']);

        $this->assertCount(1, $payloads, 'after_regenerate_failed 훅이 정확히 1회 발화해야 합니다.');
        $this->assertSame('failed', $payloads[0]['status']);
        $this->assertFalse($payloads[0]['success']);
    }

    /**
     * regenerate: 실패 시 캐시를 갱신하지 않는다 (구 세트 유지)
     */
    public function test_regenerate_does_not_touch_cache_on_failure(): void
    {
        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldNotReceive('put');
        $cache->shouldNotReceive('forget');

        $generator = Mockery::mock(SitemapGenerator::class);
        $generator->shouldReceive('generateToWriterFromEntries')->andThrow(new \RuntimeException('장애'));

        $manager = new SitemapManager(
            $generator,
            $cache,
            Mockery::spy(ConfigRepositoryInterface::class),
            $this->storage,
            $this->incrementalRepository(),
            $this->app->make(\App\Seo\SitemapProgress::class),
        );

        $this->assertFalse($manager->regenerate()['success']);
    }

    /**
     * incremental 모드로 진입시키는(테이블이 비어있지 않은) mock 저장소를 만듭니다.
     *
     * countVisible()>0 → auto 가 incremental 로 해석되어 rebuildStore(전체 재적재)를 건너뛰고
     * 곧바로 generateToWriterFromEntries 로 진입합니다(실패 경로 단순화).
     *
     * @return SitemapUrlRepositoryInterface mock 저장소
     */
    private function incrementalRepository(): SitemapUrlRepositoryInterface
    {
        $repository = Mockery::mock(SitemapUrlRepositoryInterface::class);
        $repository->shouldReceive('countVisible')->andReturn(1);
        $repository->shouldReceive('streamVisible')->andReturn([]);

        return $repository;
    }

    /**
     * regenerate: seo.sitemap_urls_per_file 설정이 자식 분할에 반영된다
     */
    public function test_regenerate_applies_urls_per_file_setting(): void
    {
        Config::set('g7_settings.core.seo.sitemap_urls_per_file', 2);

        // 정적 라우트 + 기여자 4건 → 파일당 2건이면 자식이 2개 이상으로 나뉜다
        $result = $this->makeManager(['/a', '/b', '/c', '/d'])->regenerate();

        $this->assertGreaterThan(1, $result['data']['child_count'], 'urls_per_file 설정이 분할에 반영돼야 합니다.');
    }

    /**
     * regenerate: seo.sitemap_gzip 설정이 커밋 파일명에 반영된다
     */
    public function test_regenerate_applies_gzip_setting(): void
    {
        Config::set('g7_settings.core.seo.sitemap_gzip', true);

        $this->makeManager(['/a'])->regenerate();

        $manifest = (new SitemapFileStore($this->storage))->getManifest();

        $this->assertStringEndsWith('.xml.gz', $manifest['children'][0]['file']);
    }

    /**
     * getStatus: 기록된 last_updated_at 을 반환하고, 미기록 시 null 을 반환한다
     */
    public function test_get_status_returns_last_updated_at_or_null(): void
    {
        $manager = $this->makeManager();

        Config::set('g7_settings.core.seo.sitemap_last_updated_at', '');
        $this->assertNull($manager->getStatus()['last_updated_at']);

        Config::set('g7_settings.core.seo.sitemap_last_updated_at', '2026-07-16T00:00:00+09:00');
        $this->assertSame('2026-07-16T00:00:00+09:00', $manager->getStatus()['last_updated_at']);
    }
}
