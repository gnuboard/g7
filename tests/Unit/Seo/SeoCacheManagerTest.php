<?php

namespace Tests\Unit\Seo;

use App\Extension\Cache\CoreCacheDriver;
use App\Seo\SeoCacheManager;
use App\Seo\SeoUrl;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SeoCacheManagerTest extends TestCase
{
    private SeoCacheManager $cacheManager;

    /**
     * 시험 저장소 잠금 동작 — normal(실제 배열 잠금)·timeout(대기 초과)·error(저장소 오류)
     */
    public static string $i161LockMode = 'normal';

    /**
     * 시험 저장소가 받은 잠금 이름 기록
     *
     * @var array<int, string>
     */
    public static array $i161LockNames = [];

    /**
     * 인덱스를 쓰거나 지울 때마다 그 순간 인덱스 잠금이 잡혀 있었는지 기록
     *
     * @var array<int, bool>
     */
    public static array $i161IndexWritesUnderLock = [];

    /**
     * 테스트 초기화 - SeoCacheManager 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
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
     *
     * @effects page_cache_round_trip
     *
     * @return void 반환값 없음
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
     *
     * @effects page_cache_round_trip
     *
     * @return void 반환값 없음
     */
    public function test_get_returns_null_when_not_cached(): void
    {
        $result = $this->cacheManager->get('/nonexistent', 'ko');

        $this->assertNull($result);
    }

    /**
     * cache_enabled=false일 때 put이 무시되고 get이 null을 반환합니다.
     *
     * @effects disabled_cache_is_noop
     *
     * @return void 반환값 없음
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
     *
     * @effects invalidation_by_url_layout_and_all
     *
     * @return void 반환값 없음
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
     *
     * @effects invalidation_by_url_layout_and_all
     *
     * @return void 반환값 없음
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
     *
     * @effects invalidation_by_url_layout_and_all
     *
     * @return void 반환값 없음
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
     *
     * @effects locale_is_a_separate_key_axis
     *
     * @return void 반환값 없음
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
     *
     * @effects page_cache_round_trip
     *
     * @return void 반환값 없음
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
     *
     * @effects page_cache_round_trip
     *
     * @return void 반환값 없음
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
     *
     * @effects invalidation_by_url_layout_and_all
     *
     * @return void 반환값 없음
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
     * 무효화 패턴의 와일드카드는 `*` 하나뿐이고 `?`·`.` 은 글자 그대로 비교한다 (공개#156 D5).
     *
     * `?` 를 정규식 수량자로 해석하면 `/board/free?*` 가 `/board/fre` 로 시작하는 모든 키를,
     * `/boards?*` 가 모든 `/board*` 키를 지웠다.
     *
     * @effects invalidation_pattern_treats_only_asterisk_as_wildcard
     *
     * @return void 반환값 없음
     */
    public function test_invalidate_by_url_treats_question_mark_and_dot_literally(): void
    {
        foreach (['/board/free', '/board/free?page=2', '/board/free/123', '/board/freedom', '/page/axb', '/page/a.b'] as $url) {
            $this->cacheManager->put($url, 'ko', '<html>'.$url.'</html>');
        }

        $this->assertSame(1, $this->cacheManager->invalidateByUrl('/board/free?*'));
        $this->assertNull($this->cacheManager->get('/board/free?page=2', 'ko'));
        $this->assertNotNull($this->cacheManager->get('/board/free/123', 'ko'), '다른 경로의 글 상세는 남는다');
        $this->assertNotNull($this->cacheManager->get('/board/freedom', 'ko'), '이름이 같은 접두의 다른 게시판은 남는다');
        $this->assertNotNull($this->cacheManager->get('/board/free', 'ko'), '쿼리 없는 목록은 `?*` 대상이 아니다');

        $this->assertSame(0, $this->cacheManager->invalidateByUrl('/boards?*'));
        $this->assertNotNull($this->cacheManager->get('/board/free', 'ko'), '`/boards?*` 가 `/board*` 를 지우면 안 된다');

        $this->assertSame(1, $this->cacheManager->invalidateByUrl('*/page/a.b'));
        $this->assertNotNull($this->cacheManager->get('/page/axb', 'ko'), '`.` 은 아무 글자와 일치하지 않는다');
        $this->assertNull($this->cacheManager->get('/page/a.b', 'ko'));
    }

    /**
     * cache_enabled=false일 때 putWithLayout도 무시됩니다.
     *
     * @effects disabled_cache_is_noop
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * 문자열로만 저장된 항목(이전 값 형식)도 그대로 읽힌다.
     *
     * 키에 사이트 origin 이 들어간 뒤(KVE-2026-2333)로는 그 이전 키의 항목은 의도적으로 읽지 않는다
     * — 위조 Host 로 오염됐을 수 있는 항목을 배포와 함께 버리기 위해서다. 이 테스트는 값 형식
     * 호환만 고정한다.
     *
     * @effects cache_hit_carries_layout_name_from_cache_entry
     *
     * @return void 반환값 없음
     */
    public function test_get_reads_legacy_string_entries(): void
    {
        $driver = new CoreCacheDriver('array');
        $manager = new SeoCacheManager($driver);

        $driver->put('seo.page.'.md5(SeoUrl::origin().'|/legacy|ko'), '<html>legacy</html>', 3600);
        $driver->put('seo.page.'.md5('/stale|ko'), '<html>pre-origin key</html>', 3600);

        $this->assertSame('<html>legacy</html>', $manager->get('/legacy', 'ko'));
        $this->assertSame(['html' => '<html>legacy</html>', 'layout' => null], $manager->getEntry('/legacy', 'ko'));
        $this->assertNull($manager->get('/stale', 'ko'), 'origin 없는 이전 키의 항목은 읽지 않는다');
    }

    /**
     * 경로당 변종 상한은 언어별로 따로 센다 — 인덱스 항목은 url|locale 별인데 경로만 보고
     * 합산하면 언어 수만큼 실효 상한이 줄어, 다국어 사이트의 목록 뒤쪽 페이지가 캐시에서 빠진다.
     *
     * @effects store_counts_path_variants_per_locale
     *
     * @return void 반환값 없음
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

    /**
     * 잠금 동작을 바꿀 수 있는 시험 저장소(`i161_seo_lock`)를 등록하고 그 저장소를 쓰는 매니저를 돌려줍니다.
     *
     * 저장소는 배열 저장소를 그대로 쓰되 잠금 이름을 기록하고, 인덱스(`seo.cached_urls`)를 쓰거나 지울 때 그 순간
     * 인덱스 잠금이 잡혀 있었는지 남긴다. `$i161LockMode` 로 잠금을 대기 초과·저장소 오류로 바꿀 수 있다.
     *
     * @return array{0: SeoCacheManager, 1: CoreCacheDriver} 매니저와 그 캐시 드라이버
     */
    private function makeI161LockManager(): array
    {
        self::$i161LockMode = 'normal';
        self::$i161LockNames = [];
        self::$i161IndexWritesUnderLock = [];

        config(['cache.stores.i161_seo_lock' => ['driver' => 'i161_seo_lock']]);

        Cache::extend('i161_seo_lock', fn () => Cache::repository(new class extends ArrayStore
        {
            /**
             * 잠금 이름을 기록하고, 시험 모드에 맞는 잠금을 돌려준다.
             *
             * @param  string  $name  잠금 이름
             * @param  int  $seconds  잠금 유지 시간(초)
             * @param  string|null  $owner  소유자
             * @return \Illuminate\Contracts\Cache\Lock 잠금
             */
            public function lock($name, $seconds = 0, $owner = null)
            {
                SeoCacheManagerTest::$i161LockNames[] = $name;

                if (SeoCacheManagerTest::$i161LockMode === 'normal') {
                    return parent::lock($name, $seconds, $owner);
                }

                return new class($name, $seconds, $owner, SeoCacheManagerTest::$i161LockMode) extends Lock
                {
                    /**
                     * 시험 잠금을 만든다.
                     *
                     * @param  string  $name  잠금 이름
                     * @param  int  $seconds  잠금 유지 시간(초)
                     * @param  string|null  $owner  소유자
                     * @param  string  $mode  timeout 또는 error
                     */
                    public function __construct($name, $seconds, $owner, private string $mode)
                    {
                        parent::__construct($name, $seconds, $owner);
                    }

                    /**
                     * 저장소 오류 모드면 던지고, 대기 초과 모드면 남이 쥐고 있는 것처럼 실패한다.
                     *
                     * @return bool 언제나 false
                     *
                     * @throws \RuntimeException 저장소 오류 모드일 때
                     */
                    public function acquire()
                    {
                        if ($this->mode === 'error') {
                            throw new \RuntimeException('i161 lock store down C:\\fake\\g7');
                        }

                        return false;
                    }

                    /**
                     * 대기 초과 모드는 기다리지 않고 바로 시간 초과를 낸다(2초 대기를 시험에서 생략).
                     *
                     * @param  int  $seconds  기다릴 시간(초)
                     * @param  callable|null  $callback  잠금 안에서 실행할 콜백
                     * @return mixed 콜백 반환값 (대기 초과 모드에서는 반환하지 않음)
                     *
                     * @throws LockTimeoutException 대기 초과 모드일 때
                     */
                    public function block($seconds, $callback = null)
                    {
                        if ($this->mode === 'timeout') {
                            throw new LockTimeoutException('i161 index lock timeout');
                        }

                        return parent::block($seconds, $callback);
                    }

                    /**
                     * 잠금을 푼다.
                     *
                     * @return bool 언제나 true
                     */
                    public function release()
                    {
                        return true;
                    }

                    /**
                     * 소유자와 무관하게 잠금을 푼다.
                     *
                     * @return void 반환값 없음
                     */
                    public function forceRelease() {}

                    /**
                     * 현재 소유자를 돌려준다.
                     *
                     * @return string|null 소유자 없음
                     */
                    protected function getCurrentOwner()
                    {
                        return null;
                    }
                };
            }

            /**
             * 값을 쓴다 — 인덱스를 쓰는 순간 인덱스 잠금이 잡혀 있었는지 기록한다.
             *
             * @param  string  $key  키
             * @param  mixed  $value  값
             * @param  int  $seconds  유지 시간(초)
             * @return bool 저장 여부
             */
            public function put($key, $value, $seconds)
            {
                if (str_ends_with($key, 'seo.cached_urls')) {
                    SeoCacheManagerTest::$i161IndexWritesUnderLock[] = isset($this->locks[$key.':lock']);
                }

                return parent::put($key, $value, $seconds);
            }

            /**
             * 값을 지운다 — 인덱스를 지우는 순간 인덱스 잠금이 잡혀 있었는지 기록한다.
             *
             * @param  string  $key  키
             * @return bool 삭제 여부
             */
            public function forget($key)
            {
                if (str_ends_with($key, 'seo.cached_urls')) {
                    SeoCacheManagerTest::$i161IndexWritesUnderLock[] = isset($this->locks[$key.':lock']);
                }

                return parent::forget($key);
            }
        }));

        $driver = new CoreCacheDriver('i161_seo_lock');

        return [new SeoCacheManager($driver), $driver];
    }

    /**
     * 인덱스 잠금 이름 — 매니저가 쓰는 캐시 드라이버의 인덱스 키 뒤에 `:lock` 을 붙인다.
     *
     * @param  CoreCacheDriver  $driver  매니저가 쓰는 캐시 드라이버
     * @return string 인덱스 잠금 이름
     */
    private function indexLockName(CoreCacheDriver $driver): string
    {
        return $driver->resolveKey('seo.cached_urls').':lock';
    }

    /**
     * 기록된 잠금 이름 중 인덱스 잠금의 횟수를 셉니다.
     *
     * @param  string  $lockName  인덱스 잠금 이름
     * @return int 인덱스 잠금을 요청한 횟수
     */
    private function countIndexLocks(string $lockName): int
    {
        return count(array_filter(self::$i161LockNames, fn (string $name) => $name === $lockName));
    }

    /**
     * 인덱스를 고치는 세 경로(저장·무효화 재구성·전체 비우기)가 모두 인덱스 잠금을 잡은 채 인덱스를 쓴다 — 동시 저장 두 건이
     * 같은 인덱스를 읽고 각자 덮어쓰면 한쪽 항목이 사라져 그 페이지가 무효화에서 빠졌다(#161 F1).
     *
     * @scenario store_state=under_caps
     *
     * @effects seo_index_mutations_run_inside_index_lock
     *
     * @return void 반환값 없음
     */
    public function test_index_mutations_run_inside_index_lock(): void
    {
        [$manager, $driver] = $this->makeI161LockManager();
        $lockName = $this->indexLockName($driver);

        $manager->putWithLayout('/a', 'ko', '<html>a</html>', 'la');
        $manager->putWithLayout('/b', 'ko', '<html>b</html>', 'lb');
        $manager->putWithLayout('/c', 'ko', '<html>c</html>', 'lc');
        $this->assertSame(1, $manager->invalidateByUrl('/a'));
        $this->assertSame(1, $manager->invalidateByLayout('lb'));
        $this->assertSame(['/c'], array_values($manager->getCachedUrls()));
        $manager->clearAll();

        // 저장 3 + 무효화 재구성 2 + 전체 비우기 1
        $this->assertSame(6, $this->countIndexLocks($lockName));
        $this->assertCount(6, self::$i161IndexWritesUnderLock, '인덱스 쓰기·지우기가 모두 기록돼야 한다');
        $this->assertNotContains(false, self::$i161IndexWritesUnderLock, '인덱스 쓰기·지우기는 모두 잠금 안에서 일어난다');
        $this->assertSame([], $manager->getCachedUrls());
    }

    /**
     * 인덱스 잠금을 기다리다 시간이 지나면 페이지도 인덱스도 쓰지 않는다 — 잠금 없이 쓰면 다른 저장의 인덱스 항목을
     * 덮어쓸 수 있고, 성능 캐시라 한 번 쓰지 않는 쪽이 안전하다.
     *
     * @scenario store_state=under_caps
     *
     * @effects seo_store_skipped_when_index_lock_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_store_page_skips_when_index_lock_times_out(): void
    {
        [$manager] = $this->makeI161LockManager();
        Log::spy();

        self::$i161LockMode = 'timeout';
        $manager->putWithLayout('/a', 'ko', '<html>a</html>', 'la');

        $this->assertNull($manager->get('/a', 'ko'), '페이지를 쓰지 않는다');
        $this->assertSame([], $manager->getCachedUrls(), '인덱스를 쓰지 않는다');
        $this->assertSame([], self::$i161IndexWritesUnderLock);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, '잠금 대기 시간 초과'))
            ->once();
    }

    /**
     * 잠금 저장소가 오류를 내도 저장을 건너뛰고 예외를 올려 보내지 않는다 — 봇 응답 경로가 캐시 잠금 장애로 실패하면 안 된다.
     *
     * @scenario store_state=under_caps
     *
     * @effects seo_store_skipped_when_index_lock_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_store_page_skips_when_lock_store_errors(): void
    {
        [$manager] = $this->makeI161LockManager();
        Log::spy();

        self::$i161LockMode = 'error';
        $manager->put('/a', 'ko', '<html>a</html>');
        $manager->putWithLayout('/b', 'ko', '<html>b</html>', 'lb');

        $this->assertNull($manager->get('/a', 'ko'));
        $this->assertNull($manager->get('/b', 'ko'));
        $this->assertSame([], $manager->getCachedUrls());
        $this->assertSame([], self::$i161IndexWritesUnderLock);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, '잠금 저장소 오류')
                && str_contains((string) ($context['error'] ?? ''), 'i161 lock store down'))
            ->twice();
    }

    /**
     * 잠금을 잡지 못해도 무효화의 페이지 삭제는 그대로 일어난다 — 페이지 삭제는 잠금 밖이고, 건너뛰는 것은 인덱스 재구성뿐이다
     * (남은 인덱스 항목은 다음 재구성이 걷어 낸다). 글을 고쳤는데 낡은 화면이 남으면 안 된다.
     *
     * @scenario store_state=under_caps
     *
     * @effects seo_invalidate_forgets_pages_without_index_lock
     *
     * @return void 반환값 없음
     */
    public function test_invalidate_forgets_pages_when_lock_unavailable(): void
    {
        [$manager] = $this->makeI161LockManager();

        $manager->putWithLayout('/products/1', 'ko', '<html>1</html>', 'shop/show');
        $manager->putWithLayout('/products/2', 'ko', '<html>2</html>', 'shop/show');
        $manager->putWithLayout('/categories/1', 'ko', '<html>c</html>', 'shop/category');

        self::$i161LockMode = 'error';

        $this->assertSame(1, $manager->invalidateByUrl('/products/1'));
        $this->assertNull($manager->get('/products/1', 'ko'), '잠금 없이도 페이지는 지운다');

        $this->assertSame(1, $manager->invalidateByLayout('shop/category'));
        $this->assertNull($manager->get('/categories/1', 'ko'));
        $this->assertSame('<html>2</html>', $manager->get('/products/2', 'ko'), '대상이 아닌 페이지는 남는다');

        // 인덱스 재구성은 건너뛰었다 — 지워진 페이지의 항목이 남아 있다
        $this->assertContains('/products/1', $manager->getCachedUrls());

        self::$i161LockMode = 'timeout';
        $this->assertSame(1, $manager->invalidateByUrl('/products/2'));
        $this->assertNull($manager->get('/products/2', 'ko'), '대기 초과여도 페이지는 지운다');
    }

    /**
     * 전체 비우기는 잠금을 잡지 못해도 인덱스까지 지운다 — 운영자가 명시한 동작이다.
     *
     * @scenario store_state=under_caps
     *
     * @effects seo_clear_all_forgets_index_without_index_lock
     *
     * @return void 반환값 없음
     */
    public function test_clear_all_forgets_index_when_lock_unavailable(): void
    {
        [$manager] = $this->makeI161LockManager();

        $manager->putWithLayout('/a', 'ko', '<html>a</html>', 'la');
        $manager->putWithLayout('/b', 'ko', '<html>b</html>', 'lb');

        self::$i161LockMode = 'error';
        $manager->clearAll();

        $this->assertNull($manager->get('/a', 'ko'));
        $this->assertNull($manager->get('/b', 'ko'));
        $this->assertSame([], $manager->getCachedUrls());
    }

    /**
     * 저장 상한에서 저장 안의 인덱스 정리는 잠금을 다시 잡지 않는다 — 배열·Redis 잠금은 재진입이 안 돼 다시 잡으면 2초를
     * 기다린 뒤 시간 초과로 저장을 버린다. 한 번의 저장은 잠금을 한 번만 잡고, 정리 뒤 자리가 나면 저장한다.
     *
     * @scenario store_state=path_cap
     *
     * @effects seo_prune_inside_store_reuses_held_lock
     *
     * @return void 반환값 없음
     */
    public function test_prune_inside_store_does_not_reacquire_lock(): void
    {
        [$manager, $driver] = $this->makeI161LockManager();
        $lockName = $this->indexLockName($driver);

        config([
            'core.seo_cache_limits.max_variants_per_path' => 3,
            'core.seo_cache_limits.max_entries' => 20000,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $manager->putWithLayout('/shop?page='.$i, 'ko', '<html>'.$i.'</html>', 'shop');
        }

        $this->expirePages($driver);
        self::$i161LockNames = [];
        self::$i161IndexWritesUnderLock = [];

        $started = microtime(true);
        $manager->putWithLayout('/shop?page=4', 'ko', '<html>4</html>', 'shop');
        $elapsed = microtime(true) - $started;

        $this->assertSame(1, $this->countIndexLocks($lockName), '저장 한 번에 잠금은 한 번');
        $this->assertSame([true, true], self::$i161IndexWritesUnderLock, '정리 쓰기와 저장 쓰기 모두 같은 잠금 안');
        $this->assertSame('<html>4</html>', $manager->get('/shop?page=4', 'ko'));
        $this->assertSame(['/shop?page=4'], array_values($manager->getCachedUrls()));
        $this->assertLessThan(1.5, $elapsed, '잠금 재진입 대기(2초)가 없어야 한다');
    }
}
