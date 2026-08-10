<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Seo;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Seo\AbstractSitemapContributor;
use App\Seo\Contracts\SitemapContributorInterface;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Seo\EcommerceSitemapContributor;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * EcommerceSitemapContributor 단위 테스트
 *
 * 검증 목적:
 * - getIdentifier: 'sirsoft-ecommerce' 반환
 * - getUrls: 기본(토글 ON) 상태에서 목록/카테고리/상품 URL 포함
 * - getUrls: SEO 제공 페이지 토글 OFF 시 해당 URL 유형 제외 (회귀)
 */
#[Group('ecommerce')]
#[Group('unit')]
#[Group('seo')]
class EcommerceSitemapContributorTest extends ModuleTestCase
{
    private EcommerceSitemapContributor $contributor;

    private string $routePath = 'shop';

    protected function setUp(): void
    {
        parent::setUp();
        // Repository 주입이 필요하므로 컨테이너로 해석합니다.
        $this->contributor = $this->app->make(EcommerceSitemapContributor::class);
        $this->routePath = g7_module_settings('sirsoft-ecommerce', 'basic_info.route_path') ?? 'shop';
    }

    /**
     * 테스트용 활성 카테고리 1건을 생성합니다.
     */
    private function createTestCategory(): Category
    {
        return Category::create([
            'name' => ['ko' => '사이트맵 카테고리', 'en' => 'Sitemap Category'],
            'slug' => 'sitemap-cat',
            'is_active' => true,
            'path' => '/',
            'depth' => 0,
            'sort_order' => 0,
        ]);
    }

    /**
     * SitemapContributorInterface 를 구현한다
     */
    public function test_implements_sitemap_contributor_interface(): void
    {
        $this->assertInstanceOf(SitemapContributorInterface::class, $this->contributor);
    }

    /**
     * getIdentifier: 'sirsoft-ecommerce' 반환
     */
    public function test_get_identifier_returns_sirsoft_ecommerce(): void
    {
        $this->assertSame('sirsoft-ecommerce', $this->contributor->getIdentifier());
    }

    /**
     * getUrls: route_path 미설정 시 기본값 'shop' 을 사용한다
     */
    public function test_get_urls_uses_default_route_path_when_not_configured(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.route_path', null);

        $contributor = $this->app->make(EcommerceSitemapContributor::class);
        $urlPaths = array_column($contributor->getUrls(), 'url');

        $this->assertContains('/shop/products', $urlPaths);
    }

    /**
     * getUrls: 주소 없이 운영하는 상점(no_route)은 세그먼트 없이 루트 경로를 싣는다 (공개 #85)
     *
     * route_path 만 읽고 no_route 를 무시하면 사이트맵이 `/shop/...` 을 검색엔진에
     * 제출하지만 실제 화면은 `/...` 이다 — 색인된 URL 전부가 404 로 수집된다.
     */
    public function test_get_urls_omits_route_segment_when_no_route(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info', [
            'route_path' => 'shop',
            'no_route' => true,
        ]);

        $contributor = $this->app->make(EcommerceSitemapContributor::class);
        $urlPaths = array_column($contributor->getUrls(), 'url');

        $this->assertContains('/products', $urlPaths);
        $this->assertNotContains('/shop/products', $urlPaths);
    }

    /**
     * getUrls: 정적/카테고리/상품 URL 항목이 각각 올바른 키 구조를 가진다
     */
    public function test_url_entries_have_correct_structure(): void
    {
        $category = $this->createTestCategory();
        $product = Product::factory()->create();

        $urls = collect($this->contributor->getUrls());

        // 정적 목록 URL — lastmod 없음
        $index = $urls->firstWhere('url', "/{$this->routePath}/products");
        $this->assertNotNull($index);
        $this->assertSame('daily', $index['changefreq']);
        $this->assertSame(0.7, $index['priority']);
        $this->assertArrayNotHasKey('lastmod', $index);

        // 카테고리 URL — lastmod 있음
        $categoryEntry = $urls->firstWhere('url', "/{$this->routePath}/category/{$category->slug}");
        $this->assertNotNull($categoryEntry);
        $this->assertArrayHasKey('lastmod', $categoryEntry);
        $this->assertSame('weekly', $categoryEntry['changefreq']);
        $this->assertSame(0.6, $categoryEntry['priority']);

        // 상품 URL — lastmod 있음
        $productEntry = $urls->firstWhere('url', "/{$this->routePath}/products/{$product->id}");
        $this->assertNotNull($productEntry);
        $this->assertArrayHasKey('lastmod', $productEntry);
        $this->assertSame('weekly', $productEntry['changefreq']);
        $this->assertSame(0.8, $productEntry['priority']);
    }

    /**
     * getUrls: 기여자당 URL 안전 상한을 초과하면 상품 URL이 잘린다
     */
    public function test_get_urls_truncates_at_max_urls_per_contributor(): void
    {
        Product::factory()->count(3)->create();

        // 정적 목록 URL 1건이 이미 담기므로 상품은 1건까지만 담긴다.
        // g7_core_settings 는 Config 파사드 기반이므로 Config::set 으로 주입합니다.
        Config::set('g7_settings.core.seo.sitemap_max_urls_per_contributor', 2);

        $this->assertCount(2, $this->contributor->getUrls());
    }

    /**
     * getUrls: 상한이 카테고리 루프에도 적용된다 (회귀)
     *
     * 상한 검사가 상품 루프에만 있으면, 카테고리만으로 상한을 초과해도 잘리지 않는다.
     */
    public function test_get_urls_truncates_within_category_loop(): void
    {
        $this->createTestCategory();
        Category::create([
            'name' => ['ko' => '사이트맵 카테고리2', 'en' => 'Sitemap Category 2'],
            'slug' => 'sitemap-cat-2',
            'is_active' => true,
            'path' => '/',
            'depth' => 0,
            'sort_order' => 1,
        ]);
        Product::factory()->count(2)->create();

        // 정적 목록 1건 + 카테고리 2건 = 3건이지만 상한은 2건.
        Config::set('g7_settings.core.seo.sitemap_max_urls_per_contributor', 2);

        $urls = $this->contributor->getUrls();

        $this->assertCount(2, $urls, '카테고리 루프에서도 상한이 지켜져야 합니다.');
        // 상한 도달 후에는 상품 URL 이 하나도 담기지 않아야 한다
        $this->assertEmpty(
            array_filter($urls, fn (array $u): bool => str_contains($u['url'], '/products/')),
            '상한 도달 후 상품 URL 이 추가되면 안 됩니다.'
        );
    }

    /**
     * getUrls: 기본(토글 ON) 상태에서 목록/카테고리/상품 URL이 모두 포함된다 (비파괴 회귀)
     */
    public function test_get_urls_includes_all_when_toggles_default_on(): void
    {
        $category = $this->createTestCategory();
        $product = Product::factory()->create();

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertContains("/{$this->routePath}/products", $urlPaths);
        $this->assertContains("/{$this->routePath}/category/{$category->slug}", $urlPaths);
        $this->assertContains("/{$this->routePath}/products/{$product->id}", $urlPaths);
    }

    /**
     * getUrls: seo_shop_index 토글 OFF 시 상품 목록 URL이 제외된다 (회귀)
     */
    public function test_get_urls_excludes_shop_index_when_toggle_off(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_shop_index', false);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains("/{$this->routePath}/products", $urlPaths);
    }

    /**
     * getUrls: seo_category 토글 OFF 시 카테고리 URL이 제외된다 (회귀)
     */
    public function test_get_urls_excludes_category_when_toggle_off(): void
    {
        $category = $this->createTestCategory();
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_category', false);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains("/{$this->routePath}/category/{$category->slug}", $urlPaths);
    }

    /**
     * getUrls: seo_product_detail 토글 OFF 시 상품 상세 URL이 제외된다 (회귀)
     */
    public function test_get_urls_excludes_product_detail_when_toggle_off(): void
    {
        $product = Product::factory()->create();
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', false);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains("/{$this->routePath}/products/{$product->id}", $urlPaths);
    }

    /**
     * getUrlsLazy: 배열을 실체화하지 않는 지연 제너레이터로 URL 을 흘려보낸다 (⑭ 스트리밍)
     */
    public function test_get_urls_lazy_streams_entries(): void
    {
        $product = Product::factory()->create();

        $this->assertInstanceOf(AbstractSitemapContributor::class, $this->contributor);

        $lazy = $this->contributor->getUrlsLazy();
        $this->assertInstanceOf(\Traversable::class, $lazy);

        $urlPaths = array_column(iterator_to_array($lazy, false), 'url');
        $this->assertContains("/{$this->routePath}/products/{$product->id}", $urlPaths);
    }
}
