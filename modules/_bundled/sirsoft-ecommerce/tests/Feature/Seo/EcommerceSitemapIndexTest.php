<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Seo;

use App\Jobs\GenerateSitemapJob;
use App\Models\SitemapUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Listeners\SeoCategoryCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\SeoProductCacheListener;
use Tests\TestCase;

/**
 * Ecommerce 리스너 사이트맵 증분 색인 테스트 (S4 ⑲)
 *
 * 검증 목적 (DoD: 공개→append / 비공개→remove):
 * - 전시 상품 생성/수정 → 색인(append), 숨김/삭제 → 색인 제거
 * - 활성 카테고리 변경 → 색인, 비활성/삭제 → 색인 제거
 * - 리스너가 사이트맵 재생성 잡을 디스패치
 */
class EcommerceSitemapIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateSitemapJob::class]);
    }

    /**
     * 전시 상품 생성 시 사이트맵에 색인(append)되고 잡이 디스패치된다.
     */
    public function test_visible_product_create_appends_to_sitemap(): void
    {
        $product = (object) ['id' => 42, 'display_status' => ProductDisplayStatus::VISIBLE, 'updated_at' => now()];

        (new SeoProductCacheListener)->onProductCreate($product);

        $row = SitemapUrl::where('resource_type', 'product')->where('resource_id', '42')->first();
        $this->assertNotNull($row, '전시 상품은 사이트맵에 색인돼야 합니다.');
        $this->assertStringEndsWith('/products/42', $row->loc);
        $this->assertSame('sirsoft-ecommerce', $row->contributor);

        Bus::assertDispatched(GenerateSitemapJob::class);
    }

    /**
     * 숨김 상품으로 수정 시 사이트맵 색인이 제거(remove)된다.
     */
    public function test_hidden_product_update_removes_from_sitemap(): void
    {
        // 먼저 전시로 색인
        (new SeoProductCacheListener)->onProductCreate(
            (object) ['id' => 7, 'display_status' => ProductDisplayStatus::VISIBLE, 'updated_at' => now()]
        );
        $this->assertSame(1, SitemapUrl::where('resource_id', '7')->count());

        // 숨김으로 전환
        (new SeoProductCacheListener)->onProductUpdate(
            (object) ['id' => 7, 'display_status' => ProductDisplayStatus::HIDDEN, 'updated_at' => now()]
        );

        $this->assertSame(0, SitemapUrl::where('resource_id', '7')->count(), '숨김 상품은 색인에서 제거돼야 합니다.');
    }

    /**
     * 상품 삭제 시 사이트맵 색인이 제거된다.
     */
    public function test_product_delete_removes_from_sitemap(): void
    {
        (new SeoProductCacheListener)->onProductCreate(
            (object) ['id' => 9, 'display_status' => ProductDisplayStatus::VISIBLE, 'updated_at' => now()]
        );
        $this->assertSame(1, SitemapUrl::where('resource_id', '9')->count());

        (new SeoProductCacheListener)->onProductDelete((object) ['id' => 9]);

        $this->assertSame(0, SitemapUrl::where('resource_type', 'product')->where('resource_id', '9')->count());
    }

    /**
     * 활성 카테고리 변경 시 색인, 비활성 시 색인 제거.
     */
    public function test_category_visibility_toggles_index(): void
    {
        $listener = new SeoCategoryCacheListener;

        $listener->onCategoryChange((object) ['id' => 3, 'slug' => 'shoes', 'is_active' => true, 'updated_at' => now()]);
        $row = SitemapUrl::where('resource_type', 'category')->where('resource_id', '3')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('/category/shoes', $row->loc);

        $listener->onCategoryChange((object) ['id' => 3, 'slug' => 'shoes', 'is_active' => false, 'updated_at' => now()]);
        $this->assertSame(0, SitemapUrl::where('resource_type', 'category')->where('resource_id', '3')->count());
    }

    /**
     * 카테고리 삭제(ID 전달) 시 색인이 제거된다.
     */
    public function test_category_delete_removes_index(): void
    {
        $listener = new SeoCategoryCacheListener;
        $listener->onCategoryChange((object) ['id' => 4, 'slug' => 'bags', 'is_active' => true, 'updated_at' => now()]);
        $this->assertSame(1, SitemapUrl::where('resource_type', 'category')->where('resource_id', '4')->count());

        // after_delete 훅은 모델이 아닌 ID(int)를 전달
        $listener->onCategoryDelete(4);

        $this->assertSame(0, SitemapUrl::where('resource_type', 'category')->where('resource_id', '4')->count());
    }

    /**
     * 'SEO 제공 페이지(상품 상세)' 토글이 OFF 면 전시 상품이어도 색인이 제거된다.
     *
     * 리스너의 공개상태 판정은 전시 여부 AND 토글을 함께 본다(기여자 getUrlsLazy 와 일치).
     */
    public function test_product_detail_toggle_off_removes_index_even_when_visible(): void
    {
        $listener = new SeoProductCacheListener;

        // 토글 ON 상태로 먼저 색인
        $listener->onProductCreate((object) ['id' => 50, 'display_status' => ProductDisplayStatus::VISIBLE, 'updated_at' => now()]);
        $this->assertSame(1, SitemapUrl::where('resource_id', '50')->count());

        // 토글 OFF → 전시 상태여도 색인 제거
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', false);
        $listener->onProductUpdate((object) ['id' => 50, 'display_status' => ProductDisplayStatus::VISIBLE, 'updated_at' => now()]);

        $this->assertSame(0, SitemapUrl::where('resource_id', '50')->count(), 'seo_product_detail OFF 면 전시 상품도 색인에서 제거돼야 합니다.');
    }
}
