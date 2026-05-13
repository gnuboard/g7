<?php

namespace Tests\Unit\Seo;

use App\Extension\Cache\CoreCacheDriver;
use App\Seo\SeoCacheManager;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SeoCacheManagerTest extends TestCase
{
    private SeoCacheManager $cacheManager;

    /**
     * 테스트 초기화 - SeoCacheManager 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 캐시 활성화 기본 설정
        config()->set('g7_settings.core.seo.cache_enabled', true);
        config()->set('g7_settings.core.seo.cache_ttl', 7200);

        // 이전 테스트 캐시 잔여물 제거
        Cache::flush();

        $this->cacheManager = new SeoCacheManager(new CoreCacheDriver('array'));
    }

    /**
     * put 후 get 호출 시 저장된 HTML이 반환되는지 확인합니다.
     */
    public function test_put_and_get_returns_stored_html(): void
    {
        $url = '/products/123';
        $locale = 'ko';
        $html = '<html><body>상품 상세</body></html>';

        $this->cacheManager->put($url, $locale, $html);

        $result = $this->cacheManager->get($url, $locale);

        $this->assertSame($html, $result);
    }

    /**
     * 캐시되지 않은 URL에 대해 get 호출 시 null을 반환합니다.
     */
    public function test_get_returns_null_when_not_cached(): void
    {
        $result = $this->cacheManager->get('/nonexistent', 'ko');

        $this->assertNull($result);
    }

    /**
     * cache_enabled=false일 때 put이 무시되고 get이 null을 반환합니다.
     */
    public function test_cache_disabled_ignores_put_and_returns_null(): void
    {
        config()->set('g7_settings.core.cache.seo_enabled', false);
        config()->set('g7_settings.core.seo.cache_enabled', false);

        $url = '/products/123';
        $locale = 'ko';
        $html = '<html><body>상품 상세</body></html>';

        $this->cacheManager->put($url, $locale, $html);

        $result = $this->cacheManager->get($url, $locale);

        $this->assertNull($result);
    }

    /**
     * invalidateByUrl로 정확한 URL의 캐시만 제거됩니다.
     */
    public function test_invalidate_by_url_removes_exact_url_cache(): void
    {
        $this->cacheManager->put('/products/1', 'ko', '<html>상품1</html>');
        $this->cacheManager->put('/products/2', 'ko', '<html>상품2</html>');
        $this->cacheManager->put('/categories/1', 'ko', '<html>카테고리1</html>');

        $count = $this->cacheManager->invalidateByUrl('/products/1');

        $this->assertSame(1, $count);
        $this->assertNull($this->cacheManager->get('/products/1', 'ko'));
        $this->assertNotNull($this->cacheManager->get('/products/2', 'ko'));
        $this->assertNotNull($this->cacheManager->get('/categories/1', 'ko'));
    }

    /**
     * invalidateByLayout으로 해당 레이아웃의 캐시가 모두 제거됩니다.
     */
    public function test_invalidate_by_layout_removes_matching_layout_caches(): void
    {
        $this->cacheManager->putWithLayout('/products/1', 'ko', '<html>상품1</html>', 'shop/show');
        $this->cacheManager->putWithLayout('/products/2', 'ko', '<html>상품2</html>', 'shop/show');
        $this->cacheManager->putWithLayout('/categories/1', 'ko', '<html>카테고리1</html>', 'shop/category');

        $count = $this->cacheManager->invalidateByLayout('shop/show');

        $this->assertSame(2, $count);
        $this->assertNull($this->cacheManager->get('/products/1', 'ko'));
        $this->assertNull($this->cacheManager->get('/products/2', 'ko'));
        $this->assertNotNull($this->cacheManager->get('/categories/1', 'ko'));
    }

    /**
     * clearAll로 모든 SEO 캐시가 제거됩니다.
     */
    public function test_clear_all_removes_all_seo_caches(): void
    {
        $this->cacheManager->put('/products/1', 'ko', '<html>상품1</html>');
        $this->cacheManager->put('/products/2', 'ko', '<html>상품2</html>');
        $this->cacheManager->put('/categories/1', 'en', '<html>Category1</html>');

        $this->cacheManager->clearAll();

        $this->assertNull($this->cacheManager->get('/products/1', 'ko'));
        $this->assertNull($this->cacheManager->get('/products/2', 'ko'));
        $this->assertNull($this->cacheManager->get('/categories/1', 'en'));
        $this->assertEmpty($this->cacheManager->getCachedUrls());
    }

    /**
     * 캐시 키에 로케일이 포함되어 다른 로케일은 별도로 저장됩니다.
     */
    public function test_cache_key_includes_locale_stores_separately(): void
    {
        $url = '/products/1';
        $htmlKo = '<html><body>상품 상세 (한국어)</body></html>';
        $htmlEn = '<html><body>Product Detail (English)</body></html>';

        $this->cacheManager->put($url, 'ko', $htmlKo);
        $this->cacheManager->put($url, 'en', $htmlEn);

        $resultKo = $this->cacheManager->get($url, 'ko');
        $resultEn = $this->cacheManager->get($url, 'en');

        $this->assertSame($htmlKo, $resultKo);
        $this->assertSame($htmlEn, $resultEn);
        $this->assertNotSame($resultKo, $resultEn);
    }

    /**
     * getCachedUrls가 캐시된 URL 목록을 반환합니다.
     */
    public function test_get_cached_urls_returns_list(): void
    {
        $this->cacheManager->put('/products/1', 'ko', '<html>상품1</html>');
        $this->cacheManager->put('/categories/1', 'ko', '<html>카테고리1</html>');

        $urls = $this->cacheManager->getCachedUrls();

        $this->assertCount(2, $urls);
        $this->assertContains('/products/1', $urls);
        $this->assertContains('/categories/1', $urls);
    }

    /**
     * putWithLayout이 레이아웃 정보를 인덱스에 함께 저장합니다.
     */
    public function test_put_with_layout_stores_layout_info_in_index(): void
    {
        $this->cacheManager->putWithLayout('/products/1', 'ko', '<html>상품1</html>', 'shop/show');

        // 캐시된 HTML 확인
        $result = $this->cacheManager->get('/products/1', 'ko');
        $this->assertSame('<html>상품1</html>', $result);

        // 인덱스에서 URL 확인
        $urls = $this->cacheManager->getCachedUrls();
        $this->assertContains('/products/1', $urls);

        // invalidateByLayout으로 레이아웃 정보가 저장되었는지 간접 확인
        $count = $this->cacheManager->invalidateByLayout('shop/show');
        $this->assertSame(1, $count);
    }

    /**
     * invalidateByUrl에 와일드카드 패턴을 사용하여 여러 URL을 제거합니다.
     */
    public function test_invalidate_by_url_with_wildcard_pattern(): void
    {
        $this->cacheManager->put('/products/1', 'ko', '<html>상품1</html>');
        $this->cacheManager->put('/products/2', 'ko', '<html>상품2</html>');
        $this->cacheManager->put('/categories/1', 'ko', '<html>카테고리1</html>');

        $count = $this->cacheManager->invalidateByUrl('/products/*');

        $this->assertSame(2, $count);
        $this->assertNull($this->cacheManager->get('/products/1', 'ko'));
        $this->assertNull($this->cacheManager->get('/products/2', 'ko'));
        $this->assertNotNull($this->cacheManager->get('/categories/1', 'ko'));
    }

    /**
     * cache_enabled=false일 때 putWithLayout도 무시됩니다.
     */
    public function test_put_with_layout_ignored_when_cache_disabled(): void
    {
        config()->set('g7_settings.core.cache.seo_enabled', false);
        config()->set('g7_settings.core.seo.cache_enabled', false);

        $this->cacheManager->putWithLayout('/products/1', 'ko', '<html>상품1</html>', 'shop/show');

        $result = $this->cacheManager->get('/products/1', 'ko');
        $this->assertNull($result);
        $this->assertEmpty($this->cacheManager->getCachedUrls());
    }
}
