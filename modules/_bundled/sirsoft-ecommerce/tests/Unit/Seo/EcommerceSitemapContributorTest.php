<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Seo;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Seo\EcommerceSitemapContributor;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * EcommerceSitemapContributor 단위 테스트
 *
 * 검증 목적:
 * - getIdentifier: 'sirsoft-ecommerce' 반환
 * - getUrls: 기본(토글 ON) 상태에서 목록/카테고리/상품 URL 포함
 * - getUrls: SEO 제공 페이지 토글 OFF 시 해당 URL 유형 제외 (회귀)
 *
 * @group ecommerce
 * @group unit
 * @group seo
 */
class EcommerceSitemapContributorTest extends ModuleTestCase
{
    private EcommerceSitemapContributor $contributor;

    private string $routePath = 'shop';

    protected function setUp(): void
    {
        parent::setUp();
        $this->contributor = new EcommerceSitemapContributor;
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
     * getIdentifier: 'sirsoft-ecommerce' 반환
     */
    public function test_get_identifier_returns_sirsoft_ecommerce(): void
    {
        $this->assertSame('sirsoft-ecommerce', $this->contributor->getIdentifier());
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
}
