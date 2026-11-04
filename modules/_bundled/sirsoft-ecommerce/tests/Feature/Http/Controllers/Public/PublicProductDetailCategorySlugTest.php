<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 상품 상세의 분류 slug (2026-11-03)
 *
 * 상품명 위 분류명을 그 분류 화면으로 잇는 링크를 템플릿이 그리려면 분류 주소(slug)가 필요한데, 공개 상세 응답의
 * categories 에는 이름·경로만 있었다. 분류마다 slug 를 싣는다.
 *
 * @scenario-manifest tests/scenarios/public-product-detail-category-slug.yaml
 */
class PublicProductDetailCategorySlugTest extends ModuleTestCase
{
    /**
     * 분류 하나를 만든다
     *
     * @param  string  $slug  분류 주소
     * @return Category 분류
     */
    private function category(string $slug): Category
    {
        $category = Category::create([
            'name' => ['ko' => $slug, 'en' => $slug],
            'slug' => $slug,
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $category->update(['path' => (string) $category->id]);

        return $category;
    }

    /**
     * 대표 분류와 함께 걸린 분류를 단 상품의 상세 응답 categories
     *
     * @return array<int, array<string, mixed>> 상세 응답의 분류 목록
     */
    private function detailCategories(): array
    {
        $product = Product::factory()->onSale()->create();
        $product->categories()->attach($this->category('drums')->id, ['is_primary' => true]);
        $product->categories()->attach($this->category('gifts')->id, ['is_primary' => false]);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/'.$product->id);
        $response->assertStatus(200);

        return $response->json('data.categories');
    }

    /**
     * 대표 분류에 slug 가 실린다
     *
     * @scenario category_role=primary
     *
     * @effects detail_categories_include_slug
     *
     * @return void 반환값 없음
     */
    public function test_primary_category_has_slug(): void
    {
        $primary = collect($this->detailCategories())->firstWhere('is_primary', true);

        $this->assertSame('drums', $primary['slug'] ?? null);
    }

    /**
     * 함께 걸린 분류에도 slug 가 실린다
     *
     * @scenario category_role=secondary
     *
     * @effects detail_categories_include_slug
     *
     * @return void 반환값 없음
     */
    public function test_secondary_category_has_slug(): void
    {
        $secondary = collect($this->detailCategories())->first(fn ($c) => ! $c['is_primary']);

        $this->assertSame('gifts', $secondary['slug'] ?? null);
    }
}
