<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheManager;
use Modules\Sirsoft\Page\Listeners\SeoPageCacheListener;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 SEO 캐시 리스너 단위 테스트
 *
 * SeoPageCacheListener의 캐시 무효화 로직을 검증합니다.
 * 실제 SeoCacheManager 를 통해 상태 변화를 검증합니다 (mock 사용 안 함).
 */
class SeoPageCacheListenerTest extends ModuleTestCase
{
    private SeoPageCacheListener $listener;

    private SeoCacheManagerInterface $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->app->make(SeoCacheManagerInterface::class);
        $this->listener = new SeoPageCacheListener;
    }

    // ─── 훅 구독 등록 ──────────────────────────────────────

    /**
     * 훅 구독이 올바르게 등록되어 있는지 확인합니다.
     */
    public function test_get_subscribed_hooks_all_hooks_map_to_on_page_change(): void
    {
        $hooks = SeoPageCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-page.page.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-page.page.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-page.page.after_delete', $hooks);

        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_create']['method']);
        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_update']['method']);
        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_delete']['method']);

        $this->assertEquals(20, $hooks['sirsoft-page.page.after_create']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_update']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_delete']['priority']);
    }

    // ─── onPageChange: 캐시 무효화 상태 검증 ──────────────

    /**
     * onPageChange 호출 시 page/show 레이아웃 캐시가 무효화되는지 확인합니다.
     *
     * putWithLayout()으로 캐시를 넣고, onPageChange 후 getCachedUrls()로 확인합니다.
     */
    public function test_on_page_change_invalidates_page_show_layout_cache(): void
    {
        $page = (object) ['id' => 1, 'slug' => 'about-us'];

        $this->cache->putWithLayout('/page/about-us', 'ko', '<html>about</html>', 'page/show');
        $this->assertContains('/page/about-us', $this->cache->getCachedUrls());

        $this->listener->onPageChange($page);

        $this->assertNotContains('/page/about-us', $this->cache->getCachedUrls());
    }

    /**
     * onPageChange 호출 시 home 레이아웃 캐시가 무효화되는지 확인합니다.
     */
    public function test_on_page_change_invalidates_home_layout_cache(): void
    {
        $page = (object) ['id' => 2, 'slug' => 'test-page'];

        $this->cache->putWithLayout('/', 'ko', '<html>home</html>', 'home');
        $this->assertContains('/', $this->cache->getCachedUrls());

        $this->listener->onPageChange($page);

        $this->assertNotContains('/', $this->cache->getCachedUrls());
    }

    /**
     * onPageChange 호출 시 search/index 레이아웃 캐시가 무효화되는지 확인합니다.
     */
    public function test_on_page_change_invalidates_search_layout_cache(): void
    {
        $page = (object) ['id' => 3, 'slug' => 'search-page'];

        $this->cache->putWithLayout('/search', 'ko', '<html>search</html>', 'search/index');
        $this->assertContains('/search', $this->cache->getCachedUrls());

        $this->listener->onPageChange($page);

        $this->assertNotContains('/search', $this->cache->getCachedUrls());
    }

    /**
     * onPageChange 호출 시 seo.sitemap 캐시가 무효화되는지 확인합니다.
     */
    public function test_on_page_change_invalidates_sitemap_cache(): void
    {
        $page = (object) ['id' => 4, 'slug' => 'sitemap-test'];

        $cacheDriver = $this->app->make(CacheInterface::class);
        $cacheDriver->put('seo.sitemap', 'sitemap-content', 3600);
        $this->assertNotNull($cacheDriver->get('seo.sitemap'));

        $this->listener->onPageChange($page);

        $this->assertNull($cacheDriver->get('seo.sitemap'));
    }

    /**
     * page가 null이어도 예외 없이 실행되는지 확인합니다.
     */
    public function test_on_page_change_handles_null_page_without_exception(): void
    {
        $this->expectNotToPerformAssertions();
        $this->listener->onPageChange(null);
    }

    /**
     * slug가 없는 page도 예외 없이 실행되는지 확인합니다.
     */
    public function test_on_page_change_handles_page_without_slug_without_exception(): void
    {
        $page = (object) ['id' => 5];

        $this->expectNotToPerformAssertions();
        $this->listener->onPageChange($page);
    }

    // ─── handle (인터페이스 준수) ───────────────────────────

    /**
     * handle 메서드가 존재하는지 확인합니다 (HookListenerInterface 준수).
     */
    public function test_handle_method_exists(): void
    {
        $this->assertTrue(method_exists($this->listener, 'handle'));

        $this->listener->handle();
    }
}
