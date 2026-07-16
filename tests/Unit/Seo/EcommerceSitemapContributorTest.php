<?php

namespace Tests\Unit\Seo;

use App\Seo\Contracts\SitemapContributorInterface;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Seo\EcommerceSitemapContributor;
use Tests\TestCase;

/**
 * EcommerceSitemapContributor 단위 테스트
 *
 * Ecommerce 모듈의 Sitemap 기여자가 올바른 식별자를 반환하고,
 * 상품/카테고리 URL을 올바르게 생성하는지 검증합니다.
 */
class EcommerceSitemapContributorTest extends TestCase
{
    private EcommerceSitemapContributor $contributor;

    /**
     * 테스트 초기화 - EcommerceSitemapContributor 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->contributor = new EcommerceSitemapContributor;
    }

    /**
     * 테스트 종료 - Mockery 리소스를 정리합니다.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * SitemapContributorInterface를 구현하는지 확인합니다.
     */
    public function test_implements_sitemap_contributor_interface(): void
    {
        $this->assertInstanceOf(SitemapContributorInterface::class, $this->contributor);
    }

    /**
     * getIdentifier()가 'sirsoft-ecommerce'를 반환하는지 확인합니다.
     */
    public function test_get_identifier_returns_correct_value(): void
    {
        $this->assertSame('sirsoft-ecommerce', $this->contributor->getIdentifier());
    }

    /**
     * getUrls()가 배열을 반환하는지 확인합니다.
     *
     * Category/Product 모델 쿼리를 Mock하여 DB 의존성 없이 테스트합니다.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_get_urls_returns_array_with_mocked_models(): void
    {
        // Category::where('is_active', true)->get() Mock
        $categoryCollection = collect([
            (object) ['id' => 1, 'slug' => 'electronics', 'updated_at' => now()],
            (object) ['id' => 2, 'slug' => 'fashion', 'updated_at' => now()],
        ]);

        $categoryQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $categoryQuery->shouldReceive('get')
            ->with(['id', 'slug', 'updated_at'])
            ->andReturn($categoryCollection);

        // Category 모델 정적 호출 Mock (alias)
        $categoryMock = Mockery::mock('alias:'.Category::class);
        $categoryMock->shouldReceive('where')
            ->with('is_active', true)
            ->andReturn($categoryQuery);

        // Product::where('display_status', ProductDisplayStatus::VISIBLE)->get() Mock
        $productCollection = collect([
            (object) ['id' => 10, 'updated_at' => now()],
            (object) ['id' => 20, 'updated_at' => now()],
        ]);

        $productQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $productQuery->shouldReceive('get')
            ->with(['id', 'updated_at'])
            ->andReturn($productCollection);

        $productMock = Mockery::mock('alias:'.Product::class);
        $productMock->shouldReceive('where')
            ->with('display_status', ProductDisplayStatus::VISIBLE)
            ->andReturn($productQuery);

        // route_path 설정
        $this->app['config']->set('g7_settings.modules.sirsoft-ecommerce.basic_info.route_path', 'shop');

        $contributor = new EcommerceSitemapContributor;
        $urls = $contributor->getUrls();

        $this->assertIsArray($urls);
        $this->assertNotEmpty($urls);

        // 첫 번째는 정적 상품 목록 URL
        $this->assertSame('/shop/products', $urls[0]['url']);
        $this->assertSame('daily', $urls[0]['changefreq']);
        $this->assertSame(0.7, $urls[0]['priority']);

        // 카테고리 URL 확인 (2개)
        $this->assertSame('/shop/category/electronics', $urls[1]['url']);
        $this->assertSame('/shop/category/fashion', $urls[2]['url']);

        // 상품 URL 확인 (2개)
        $this->assertSame('/shop/products/10', $urls[3]['url']);
        $this->assertSame('/shop/products/20', $urls[4]['url']);

        // 총 5개 URL (1 정적 + 2 카테고리 + 2 상품)
        $this->assertCount(5, $urls);
    }

    /**
     * getUrls()가 route_path 기본값 'shop'을 사용하는지 확인합니다.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_get_urls_uses_default_route_path_when_not_configured(): void
    {
        // Category 빈 컬렉션 반환
        $categoryQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $categoryQuery->shouldReceive('get')
            ->with(['id', 'slug', 'updated_at'])
            ->andReturn(collect([]));

        $categoryMock = Mockery::mock('alias:'.Category::class);
        $categoryMock->shouldReceive('where')
            ->with('is_active', true)
            ->andReturn($categoryQuery);

        // Product 빈 컬렉션 반환
        $productQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $productQuery->shouldReceive('get')
            ->with(['id', 'updated_at'])
            ->andReturn(collect([]));

        $productMock = Mockery::mock('alias:'.Product::class);
        $productMock->shouldReceive('where')
            ->with('display_status', ProductDisplayStatus::VISIBLE)
            ->andReturn($productQuery);

        // route_path 미설정 (기본값 'shop' 사용)
        $this->app['config']->set('g7_settings.modules.sirsoft-ecommerce.basic_info.route_path', null);

        $contributor = new EcommerceSitemapContributor;
        $urls = $contributor->getUrls();

        $this->assertCount(1, $urls);
        $this->assertSame('/shop/products', $urls[0]['url']);
    }

    /**
     * 각 URL 항목이 올바른 키 구조를 가지는지 확인합니다.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_url_entries_have_correct_structure(): void
    {
        $categoryCollection = collect([
            (object) ['id' => 1, 'slug' => 'test-cat', 'updated_at' => now()],
        ]);

        $categoryQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $categoryQuery->shouldReceive('get')
            ->with(['id', 'slug', 'updated_at'])
            ->andReturn($categoryCollection);

        $categoryMock = Mockery::mock('alias:'.Category::class);
        $categoryMock->shouldReceive('where')
            ->with('is_active', true)
            ->andReturn($categoryQuery);

        $productCollection = collect([
            (object) ['id' => 5, 'updated_at' => now()],
        ]);

        $productQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $productQuery->shouldReceive('get')
            ->with(['id', 'updated_at'])
            ->andReturn($productCollection);

        $productMock = Mockery::mock('alias:'.Product::class);
        $productMock->shouldReceive('where')
            ->with('display_status', ProductDisplayStatus::VISIBLE)
            ->andReturn($productQuery);

        $this->app['config']->set('g7_settings.modules.sirsoft-ecommerce.basic_info.route_path', 'shop');

        $contributor = new EcommerceSitemapContributor;
        $urls = $contributor->getUrls();

        // 정적 URL 구조 확인 (lastmod 없음)
        $this->assertArrayHasKey('url', $urls[0]);
        $this->assertArrayHasKey('changefreq', $urls[0]);
        $this->assertArrayHasKey('priority', $urls[0]);
        $this->assertArrayNotHasKey('lastmod', $urls[0]);

        // 카테고리 URL 구조 확인 (lastmod 있음)
        $this->assertArrayHasKey('url', $urls[1]);
        $this->assertArrayHasKey('lastmod', $urls[1]);
        $this->assertArrayHasKey('changefreq', $urls[1]);
        $this->assertArrayHasKey('priority', $urls[1]);
        $this->assertSame('weekly', $urls[1]['changefreq']);
        $this->assertSame(0.6, $urls[1]['priority']);

        // 상품 URL 구조 확인 (lastmod 있음)
        $this->assertArrayHasKey('url', $urls[2]);
        $this->assertArrayHasKey('lastmod', $urls[2]);
        $this->assertArrayHasKey('changefreq', $urls[2]);
        $this->assertArrayHasKey('priority', $urls[2]);
        $this->assertSame('weekly', $urls[2]['changefreq']);
        $this->assertSame(0.8, $urls[2]['priority']);
    }
}
