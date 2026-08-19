<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Jobs\GenerateSitemapJob;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheManager;
use App\Seo\SitemapIndexer;
use Illuminate\Support\Facades\Bus;
use Mockery;
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

        // 사이트맵 색인 경로는 이 테스트 범위 밖 — spy 로 대체하고 잡을 fake 하여 DB/큐 부작용을 차단
        $this->app->instance(SitemapIndexer::class, Mockery::spy(SitemapIndexer::class));
        Bus::fake([GenerateSitemapJob::class]);

        $this->listener = new SeoPageCacheListener;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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
        // 버전 복원도 SEO 캐시 무효화가 필요하다 (복원 후 봇에 복원 전 버전 잔존 회귀 방지)
        $this->assertArrayHasKey('sirsoft-page.page.after_restore', $hooks);
        // 발행/미발행 전환(단건 setPublished·일괄 bulk-publish)은 after_publish 만 발화한다.
        // 미구독 시 발행 상태를 바꿔도 SEO 캐시가 이전 상태(soft-404/이전 콘텐츠)로 남고
        // 사이트맵 증분 색인도 수행되지 않는다 (7.0.7 사전점검 브라우저+curl 실측 발견).
        $this->assertArrayHasKey('sirsoft-page.page.after_publish', $hooks);

        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_create']['method']);
        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_update']['method']);
        // delete 는 모델 상태와 무관하게 색인을 제거해야 하므로 전용 메서드로 분리
        $this->assertEquals('onPageDelete', $hooks['sirsoft-page.page.after_delete']['method']);
        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_restore']['method']);
        // publish 는 모델의 published 최신 상태로 onPageChange 가 색인/해제를 판정한다
        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_publish']['method']);

        $this->assertEquals(20, $hooks['sirsoft-page.page.after_create']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_update']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_delete']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_restore']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_publish']['priority']);
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

    /**
     * onPageChange 호출 시 페이지 상세 URL 캐시가 URL 패턴으로 무효화되는지 확인합니다.
     *
     * 페이지 공개 URL은 단수형 /page/{slug} 이다 (PageSitemapContributor, SearchPagesListener 동일).
     * 레이아웃 태그 없이 put()으로 캐시된 항목은 invalidateByLayout('page/show')로는 지워지지 않으므로,
     * invalidateByUrl 의 와일드카드 패턴이 실제 URL(/page/{slug})과 일치해야만 무효화된다.
     *
     * 회귀 배경: 기존 패턴은 복수형 "*​/pages/{slug}" 라서 실제 단수형 URL과 매칭되지 않아
     * 레이아웃 태그가 없는 페이지 상세 캐시가 영구히 남았다.
     */
    public function test_on_page_change_invalidates_page_detail_url_by_pattern(): void
    {
        $page = (object) ['id' => 6, 'slug' => 'pattern-page'];

        // 레이아웃 태그 없이 페이지 상세 URL 캐시 (URL 패턴 매칭으로만 무효화 가능)
        $this->cache->put('/page/pattern-page', 'ko', '<html>detail</html>');
        $this->assertContains('/page/pattern-page', $this->cache->getCachedUrls());

        $this->listener->onPageChange($page);

        $this->assertNotContains('/page/pattern-page', $this->cache->getCachedUrls());
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
