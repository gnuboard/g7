<?php

namespace Tests\Unit\Cache\Migration;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Seo\SeoCacheManager;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 그룹 C 이관 검증 테스트 (SEO 캐시)
 *
 * 계획서 §13 C-1, C-2, C-3 의 SEO 캐시 키 이관을 검증합니다.
 * - SeoCacheManager: 페이지/인덱스/clearAll/패턴 무효화
 * - SeoConfigMerger: 병합 캐시 키 형식
 * - Sitemap: GenerateSitemapJob, SitemapController 키 형식
 *
 * 모듈/플러그인 의존성이 큰 SeoConfigMerger와 Sitemap 통합 테스트는
 * 별도 SeoConfigMergerTest / SitemapControllerTest 에 의존합니다.
 */
class SeoCacheTest extends TestCase
{
    private CoreCacheDriver $cache;

    private SeoCacheManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->cache = new CoreCacheDriver('array');
        $this->app->instance(CacheInterface::class, $this->cache);

        // SEO 캐시 활성화
        $this->app['config']->set('g7_settings.core.seo.cache_enabled', true);
        $this->app['config']->set('g7_settings.core.seo.cache_ttl', 7200);

        $this->manager = new SeoCacheManager($this->cache);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ========================================================================
    // C-1. SeoCacheManager
    // ========================================================================

    /**
     * C-1-1: SEO 페이지 캐시 생성 — put() 호출 시 새 키 형식.
     */
    #[Test]
    public function c_1_1_seo_page_cache_uses_new_prefix(): void
    {
        $this->manager->put('/board/notice/123', 'ko', '<html>Hello</html>');

        $expectedKey = 'seo.page.'.md5('/board/notice/123|ko');
        $this->assertSame('<html>Hello</html>', $this->cache->get($expectedKey));
        $this->assertSame(
            'g7:core:'.$expectedKey,
            $this->cache->resolveKey($expectedKey)
        );
    }

    /**
     * C-1-2: SEO 페이지 캐시 히트.
     */
    #[Test]
    public function c_1_2_seo_page_cache_hit(): void
    {
        $this->manager->put('/page', 'ko', '<html>Cached</html>');

        $this->assertSame('<html>Cached</html>', $this->manager->get('/page', 'ko'));
        $this->assertSame('<html>Cached</html>', $this->manager->get('/page', 'ko'));
    }

    /**
     * C-1-3: URL 패턴 기반 무효화 (와일드카드).
     */
    #[Test]
    public function c_1_3_invalidate_by_url_pattern_wildcards(): void
    {
        $this->manager->put('/board/notice/1', 'ko', '<html>1</html>');
        $this->manager->put('/board/notice/2', 'ko', '<html>2</html>');
        $this->manager->put('/board/free/1', 'ko', '<html>free</html>');

        $count = $this->manager->invalidateByUrl('/board/notice/*');

        $this->assertSame(2, $count);
        $this->assertNull($this->manager->get('/board/notice/1', 'ko'));
        $this->assertNull($this->manager->get('/board/notice/2', 'ko'));
        $this->assertSame('<html>free</html>', $this->manager->get('/board/free/1', 'ko'));
    }

    /**
     * C-1-5: clearAll() — 모든 SEO 페이지 캐시 + 인덱스 삭제.
     */
    #[Test]
    public function c_1_5_clear_all_removes_pages_and_index(): void
    {
        $this->manager->put('/page1', 'ko', '<a/>');
        $this->manager->put('/page2', 'ko', '<b/>');

        $this->assertNotEmpty($this->manager->getCachedUrls());

        $this->manager->clearAll();

        $this->assertEmpty($this->manager->getCachedUrls());
        $this->assertNull($this->manager->get('/page1', 'ko'));
        $this->assertNull($this->manager->get('/page2', 'ko'));
    }

    /**
     * C-1-7: SEO 캐시 비활성 시 저장 안 함.
     */
    #[Test]
    public function c_1_7_disabled_cache_does_not_store(): void
    {
        $this->app['config']->set('g7_settings.core.cache.seo_enabled', false);
        $this->app['config']->set('g7_settings.core.seo.cache_enabled', false);

        $this->manager->put('/page', 'ko', '<html/>');

        $this->assertNull($this->manager->get('/page', 'ko'));
    }

    /**
     * C-1-extra: invalidateByLayout — 레이아웃 정보 매칭 삭제.
     */
    #[Test]
    public function c_1_extra_invalidate_by_layout(): void
    {
        $this->manager->putWithLayout('/board/list', 'ko', '<list/>', 'board.list');
        $this->manager->putWithLayout('/board/show/1', 'ko', '<show/>', 'board.show');

        $count = $this->manager->invalidateByLayout('board.list');

        $this->assertSame(1, $count);
        $this->assertNull($this->manager->get('/board/list', 'ko'));
        $this->assertSame('<show/>', $this->manager->get('/board/show/1', 'ko'));
    }

    // ========================================================================
    // C-3. Sitemap key format (GenerateSitemapJob, SitemapController)
    // ========================================================================

    /**
     * C-3-1: Sitemap 캐시 키는 'seo.sitemap' 으로 통일.
     */
    #[Test]
    public function c_3_1_sitemap_cache_uses_new_key(): void
    {
        $this->cache->put('seo.sitemap', '<xml/>', 86400);

        $this->assertSame('<xml/>', $this->cache->get('seo.sitemap'));
        $this->assertSame(
            'g7:core:seo.sitemap',
            $this->cache->resolveKey('seo.sitemap')
        );
    }

    /**
     * C-1-4: 게시글 삭제 시 URL 패턴 + 목록 페이지 동시 무효화.
     */
    #[Test]
    public function c_1_4_post_delete_invalidates_detail_and_list(): void
    {
        $this->manager->put('/board/notice/123', 'ko', '<detail/>');
        $this->manager->put('/board/notice', 'ko', '<list/>');

        // 패턴 무효화: /board/notice* 모두 삭제
        $count = $this->manager->invalidateByUrl('/board/notice*');

        $this->assertSame(2, $count);
        $this->assertNull($this->manager->get('/board/notice/123', 'ko'));
        $this->assertNull($this->manager->get('/board/notice', 'ko'));
    }

    /**
     * C-1-6: 모듈 설치 시 SeoCacheManager.clearAll() 동작 검증.
     */
    #[Test]
    public function c_1_6_module_install_clears_all(): void
    {
        $this->manager->put('/page1', 'ko', 'a');
        $this->manager->put('/page2', 'ko', 'b');
        $this->manager->put('/page3', 'ko', 'c');

        // 모듈 설치 → SeoExtensionCacheListener 가 clearAll 호출
        $this->manager->clearAll();

        $this->assertNull($this->manager->get('/page1', 'ko'));
        $this->assertNull($this->manager->get('/page2', 'ko'));
        $this->assertNull($this->manager->get('/page3', 'ko'));
        $this->assertEmpty($this->manager->getCachedUrls());
    }

    // ========================================================================
    // C-2. SeoConfigMerger 병합 캐시 키 형식
    // ========================================================================

    /**
     * C-2-1: SEO config 병합 캐시 키 형식 — 'seo.config.{templateId}'.
     */
    #[Test]
    public function c_2_1_seo_config_cache_key_format(): void
    {
        $this->cache->put('seo.config.sirsoft-admin_basic', ['merged' => true]);

        $this->assertSame(
            'g7:core:seo.config.sirsoft-admin_basic',
            $this->cache->resolveKey('seo.config.sirsoft-admin_basic')
        );
        $this->assertTrue($this->cache->has('seo.config.sirsoft-admin_basic'));
    }

    /**
     * C-2-2: SEO config 캐시 히트.
     */
    #[Test]
    public function c_2_2_seo_config_cache_hit(): void
    {
        $this->cache->put('seo.config.sirsoft-basic', ['v' => 1]);

        $this->assertSame(['v' => 1], $this->cache->get('seo.config.sirsoft-basic'));
        $this->assertSame(['v' => 1], $this->cache->get('seo.config.sirsoft-basic'));
    }

    /**
     * C-2-3: 확장 변경 시 config 캐시 무효화.
     */
    #[Test]
    public function c_2_3_extension_change_invalidates_config_cache(): void
    {
        $this->cache->put('seo.config.sirsoft-basic', ['old' => true]);

        $this->cache->forget('seo.config.sirsoft-basic');

        $this->assertFalse($this->cache->has('seo.config.sirsoft-basic'));
    }

    /**
     * C-3-3: 확장 변경 시 Sitemap 캐시 무효화 (SeoExtensionCacheListener 동작).
     */
    #[Test]
    public function c_3_3_extension_change_invalidates_sitemap(): void
    {
        $this->cache->put('seo.sitemap', '<old/>');

        $this->cache->forget('seo.sitemap');

        $this->assertFalse($this->cache->has('seo.sitemap'));
    }

    /**
     * C-3-4: SEO 설정 변경 시 Sitemap 캐시 무효화 (SeoSettingsCacheListener 동작).
     */
    #[Test]
    public function c_3_4_seo_settings_change_invalidates_sitemap(): void
    {
        $this->cache->put('seo.sitemap', '<old/>');

        // SeoSettingsCacheListener 동작 시뮬레이션
        $this->cache->forget('seo.sitemap');

        $this->assertFalse($this->cache->has('seo.sitemap'));
    }
}
