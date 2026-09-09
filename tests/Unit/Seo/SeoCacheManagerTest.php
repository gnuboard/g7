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

    /**
     * 같은 경로의 변종 수가 상한에 닿으면 페이지도 인덱스도 쓰지 않는다.
     *
     * @effects store_skips_write_at_path_variant_cap
     */
    public function test_put_with_layout_skips_write_when_path_variant_cap_reached(): void
    {
        config([
            'core.seo_cache_limits.max_variants_per_path' => 3,
            'core.seo_cache_limits.max_entries' => 20000,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->cacheManager->putWithLayout('/shop?page='.$i, 'ko', '<html>'.$i.'</html>', 'shop');
        }

        $before = $this->cacheManager->getCachedUrls();

        $this->cacheManager->putWithLayout('/shop?page=4', 'ko', '<html>4</html>', 'shop');

        $this->assertSame($before, $this->cacheManager->getCachedUrls());
        $this->assertNull($this->cacheManager->get('/shop?page=4', 'ko'));
    }

    /**
     * 전체 항목 수 상한에 닿으면 새 URL 을 저장하지 않는다.
     *
     * @effects store_skips_write_at_global_cap
     */
    public function test_put_with_layout_skips_write_when_global_cap_reached(): void
    {
        config([
            'core.seo_cache_limits.max_entries' => 2,
            'core.seo_cache_limits.max_variants_per_path' => 50,
        ]);

        $this->cacheManager->putWithLayout('/a', 'ko', '<html>a</html>', 'la');
        $this->cacheManager->putWithLayout('/b', 'ko', '<html>b</html>', 'lb');
        $this->cacheManager->putWithLayout('/c', 'ko', '<html>c</html>', 'lc');

        $this->assertNull($this->cacheManager->get('/c', 'ko'));
        $this->assertCount(2, $this->cacheManager->getCachedUrls());
    }

    /**
     * 이미 있는 키의 **갱신**은 상한과 무관하다 — 저장 규모가 늘지 않는다.
     *
     * @effects store_skips_write_at_global_cap
     */
    public function test_put_with_layout_updates_existing_key_regardless_of_caps(): void
    {
        config([
            'core.seo_cache_limits.max_entries' => 1,
            'core.seo_cache_limits.max_variants_per_path' => 1,
        ]);

        $this->cacheManager->putWithLayout('/a', 'ko', '<html>old</html>', 'la');
        $this->cacheManager->putWithLayout('/a', 'ko', '<html>new</html>', 'la');

        $this->assertSame('<html>new</html>', $this->cacheManager->get('/a', 'ko'));
        $this->assertCount(1, $this->cacheManager->getCachedUrls());
    }

    /**
     * 페이지만 만료시킵니다 — 인덱스 항목은 그대로 남는 실제 만료 상태를 만듭니다.
     *
     * @param  CoreCacheDriver  $driver  매니저가 쓰는 캐시 드라이버
     */
    private function expirePages(CoreCacheDriver $driver): void
    {
        foreach ($driver->get('seo.cached_urls', []) as $entry) {
            $driver->forget($entry['key']);
        }
    }

    /**
     * 상한이 세는 항목에는 페이지가 이미 만료된 것이 섞인다 — 인덱스는 페이지보다 훨씬
     * 오래 살고(30일 vs 2시간) 스스로 줄지 않는다. 상한에 닿았을 때 한 번 정리하고 다시
     * 판정하지 않으면, 한 번 닿은 경로는 실제 캐시가 비어도 영영 저장이 막힌다.
     *
     * @effects expired_index_entries_are_pruned_before_cap_verdict
     */
    public function test_put_with_layout_prunes_expired_entries_when_path_variant_cap_reached(): void
    {
        $driver = new CoreCacheDriver('array');
        $manager = new SeoCacheManager($driver);

        config([
            'core.seo_cache_limits.max_variants_per_path' => 3,
            'core.seo_cache_limits.max_entries' => 20000,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $manager->putWithLayout('/shop?page='.$i, 'ko', '<html>'.$i.'</html>', 'shop');
        }

        $this->expirePages($driver);

        $manager->putWithLayout('/shop?page=4', 'ko', '<html>4</html>', 'shop');

        $this->assertSame('<html>4</html>', $manager->get('/shop?page=4', 'ko'));
        $this->assertSame(['/shop?page=4'], array_values($manager->getCachedUrls()));
    }

    /**
     * 전체 항목 수 상한에서도 같다 — 정리 후 자리가 나면 저장한다.
     *
     * @effects expired_index_entries_are_pruned_before_cap_verdict
     */
    public function test_put_with_layout_prunes_expired_entries_when_global_cap_reached(): void
    {
        $driver = new CoreCacheDriver('array');
        $manager = new SeoCacheManager($driver);

        config([
            'core.seo_cache_limits.max_entries' => 2,
            'core.seo_cache_limits.max_variants_per_path' => 50,
        ]);

        $manager->putWithLayout('/a', 'ko', '<html>a</html>', 'la');
        $manager->putWithLayout('/b', 'ko', '<html>b</html>', 'lb');

        $this->expirePages($driver);

        $manager->putWithLayout('/c', 'ko', '<html>c</html>', 'lc');

        $this->assertSame('<html>c</html>', $manager->get('/c', 'ko'));
        $this->assertCount(1, $manager->getCachedUrls());
    }

    /**
     * `put()` 은 `putWithLayout()` 과 같은 자원(페이지 + 인덱스)을 쓰는 형제 공개 메서드다.
     * 상한이 한쪽에만 있으면 다른 쪽이 우회로가 된다 — 확장은 인터페이스를 직접 호출한다.
     *
     * @effects put_and_put_with_layout_share_the_storage_cap
     */
    public function test_put_applies_the_same_storage_cap_as_put_with_layout(): void
    {
        config([
            'core.seo_cache_limits.max_entries' => 2,
            'core.seo_cache_limits.max_variants_per_path' => 50,
        ]);

        $this->cacheManager->put('/a', 'ko', '<html>a</html>');
        $this->cacheManager->put('/b', 'ko', '<html>b</html>');
        $this->cacheManager->put('/c', 'ko', '<html>c</html>');

        $this->assertNull($this->cacheManager->get('/c', 'ko'));
        $this->assertCount(2, $this->cacheManager->getCachedUrls());
    }

    /**
     * 만료 항목 정리도 형제 메서드가 함께 갖는다.
     *
     * @effects put_and_put_with_layout_share_the_storage_cap
     */
    public function test_put_prunes_expired_entries_when_cap_reached(): void
    {
        $driver = new CoreCacheDriver('array');
        $manager = new SeoCacheManager($driver);

        config([
            'core.seo_cache_limits.max_entries' => 2,
            'core.seo_cache_limits.max_variants_per_path' => 50,
        ]);

        $manager->put('/a', 'ko', '<html>a</html>');
        $manager->put('/b', 'ko', '<html>b</html>');

        $this->expirePages($driver);

        $manager->put('/c', 'ko', '<html>c</html>');

        $this->assertSame('<html>c</html>', $manager->get('/c', 'ko'));
        $this->assertCount(1, $manager->getCachedUrls());
    }

    /**
     * 정리는 인덱스 전체를 훑으므로(항목마다 캐시 조회) 상한에 닿을 때마다 돌면 안 된다 —
     * 살아 있는 항목만으로 상한에 닿은 경로는 저장 시도마다 그 스캔을 반복하게 되고,
     * 그 빈도는 봇 미스 렌더 예산만큼이다. 그래서 정리는 간격 표식으로 묶는다.
     *
     * @effects index_prune_is_throttled_to_one_scan_per_interval
     */
    public function test_index_prune_is_throttled_to_one_scan_per_interval(): void
    {
        $driver = new CoreCacheDriver('array');
        $manager = new SeoCacheManager($driver);

        config([
            'core.seo_cache_limits.max_variants_per_path' => 3,
            'core.seo_cache_limits.max_entries' => 20000,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $manager->putWithLayout('/shop?page='.$i, 'ko', '<html>'.$i.'</html>', 'shop');
        }

        // 전부 살아 있는 상태에서 상한 도달 → 1회 스캔하고 표식을 남긴다 (저장은 안 됨)
        $manager->putWithLayout('/shop?page=4', 'ko', '<html>4</html>', 'shop');
        $this->assertNull($manager->get('/shop?page=4', 'ko'));

        // 이후 만료되어도 표식이 살아 있는 동안은 다시 훑지 않는다
        $this->expirePages($driver);
        $manager->putWithLayout('/shop?page=5', 'ko', '<html>5</html>', 'shop');
        $this->assertNull($manager->get('/shop?page=5', 'ko'));

        // 표식이 사라지면 다시 정리하고 저장한다
        $driver->forget('seo.index_pruned_at');
        $manager->putWithLayout('/shop?page=6', 'ko', '<html>6</html>', 'shop');
        $this->assertSame('<html>6</html>', $manager->get('/shop?page=6', 'ko'));
    }

    /**
     * 페이지와 함께 저장한 레이아웃명을 꺼낼 수 있다 — 캐시 적중(HIT)은 렌더러를 거치지
     * 않으므로 요청 속성에 레이아웃명이 없고, 캐시 항목이 그것을 알아야 통계가 화면별로 귀속된다.
     *
     * @effects cache_hit_carries_layout_name_from_cache_entry
     */
    public function test_get_entry_returns_layout_stored_with_page(): void
    {
        $this->cacheManager->putWithLayout('/products/1', 'ko', '<html>p</html>', 'shop/show');

        $this->assertSame(
            ['html' => '<html>p</html>', 'layout' => 'shop/show'],
            $this->cacheManager->getEntry('/products/1', 'ko')
        );
        $this->assertSame('<html>p</html>', $this->cacheManager->get('/products/1', 'ko'));
        $this->assertNull($this->cacheManager->getEntry('/nope', 'ko'));
    }

    /**
     * 이전 버전이 문자열로만 저장한 항목도 그대로 읽힌다 — 배포 직후 살아 있는 캐시를 버리지 않는다.
     *
     * @effects cache_hit_carries_layout_name_from_cache_entry
     */
    public function test_get_reads_legacy_string_entries(): void
    {
        $driver = new CoreCacheDriver('array');
        $manager = new SeoCacheManager($driver);

        $driver->put('seo.page.'.md5('/legacy|ko'), '<html>legacy</html>', 3600);

        $this->assertSame('<html>legacy</html>', $manager->get('/legacy', 'ko'));
        $this->assertSame(['html' => '<html>legacy</html>', 'layout' => null], $manager->getEntry('/legacy', 'ko'));
    }

    /**
     * 경로당 변종 상한은 언어별로 따로 센다 — 인덱스 항목은 url|locale 별인데 경로만 보고
     * 합산하면 언어 수만큼 실효 상한이 줄어, 다국어 사이트의 목록 뒤쪽 페이지가 캐시에서 빠진다.
     *
     * @effects store_counts_path_variants_per_locale
     */
    public function test_path_variant_cap_is_counted_per_locale(): void
    {
        config([
            'core.seo_cache_limits.max_variants_per_path' => 3,
            'core.seo_cache_limits.max_entries' => 20000,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->cacheManager->putWithLayout('/shop?page='.$i, 'ko', '<html>ko'.$i.'</html>', 'shop');
        }

        // ko 는 상한 도달, en 은 자기 예산을 따로 쓴다
        $this->cacheManager->putWithLayout('/shop?page=4', 'ko', '<html>ko4</html>', 'shop');
        $this->cacheManager->putWithLayout('/shop?page=1', 'en', '<html>en1</html>', 'shop');

        $this->assertNull($this->cacheManager->get('/shop?page=4', 'ko'));
        $this->assertSame('<html>en1</html>', $this->cacheManager->get('/shop?page=1', 'en'));
    }
}
