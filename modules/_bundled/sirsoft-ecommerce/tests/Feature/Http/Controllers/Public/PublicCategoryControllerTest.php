<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 카테고리 API Feature 테스트
 *
 * 비로그인 사용자가 접근하는 카테고리 목록/상세 API 테스트
 */
class PublicCategoryControllerTest extends ModuleTestCase
{
    // ========================================
    // index() 테스트
    // ========================================

    /**
     * 공개 카테고리 트리 조회 테스트
     *
     * @scenario case=index_inactive_excluded
     *
     * @effects public_tree_returns_active_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_index_returns_active_category_tree(): void
    {
        // Given: 활성/비활성 카테고리 생성
        $activeCategory = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $activeCategory->update(['path' => (string) $activeCategory->id]);

        $inactiveCategory = Category::create([
            'name' => ['ko' => '비활성', 'en' => 'Inactive'],
            'slug' => 'inactive',
            'is_active' => false,
            'depth' => 0,
            'sort_order' => 2,
            'path' => '',
        ]);
        $inactiveCategory->update(['path' => (string) $inactiveCategory->id]);

        // When: 공개 카테고리 트리 API 호출 (인증 불필요)
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories');

        // Then: 활성 카테고리만 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'name_localized', 'slug', 'depth', 'products_count'],
            ],
        ]);

        $data = $response->json('data');
        $slugs = collect($data)->pluck('slug')->toArray();
        $this->assertContains('electronics', $slugs);
        $this->assertNotContains('inactive', $slugs);
    }

    /**
     * 빈 카테고리 조회 테스트
     *
     * @scenario case=index_empty
     *
     * @effects public_tree_returns_active_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_index_returns_empty_when_no_active_categories(): void
    {
        // Given: 카테고리 없음

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories');

        // Then
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertEmpty($response->json('data'));
    }

    /**
     * 계층 구조 카테고리 트리 조회 테스트
     *
     * @scenario case=index_hierarchy
     *
     * @effects public_tree_returns_active_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_index_returns_hierarchical_tree(): void
    {
        // Given: 부모-자식 카테고리 생성
        $parent = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $parent->update(['path' => (string) $parent->id]);

        $child = Category::create([
            'name' => ['ko' => '스마트폰', 'en' => 'Smartphones'],
            'slug' => 'smartphones',
            'parent_id' => $parent->id,
            'is_active' => true,
            'depth' => 1,
            'sort_order' => 1,
            'path' => '',
        ]);
        $child->update(['path' => $parent->id.'/'.$child->id]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories');

        // Then: 트리 구조로 반환 (부모에 children 포함)
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data); // 루트는 1개

        $parentData = $data[0];
        $this->assertEquals('electronics', $parentData['slug']);
        $this->assertNotEmpty($parentData['children']);
        $this->assertEquals('smartphones', $parentData['children'][0]['slug']);
    }

    // ========================================
    // show() 테스트
    // ========================================

    /**
     * slug로 카테고리 조회 테스트
     *
     * @scenario case=show_by_slug
     *
     * @effects public_show_returns_active_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_show_returns_category_by_slug(): void
    {
        // Given
        $category = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $category->update(['path' => (string) $category->id]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/electronics');

        // Then
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id', 'name', 'name_localized', 'slug', 'depth',
                'products_count', 'breadcrumb', 'children',
            ],
        ]);
        $this->assertEquals('electronics', $response->json('data.slug'));
    }

    /**
     * 존재하지 않는 slug 조회 시 404 테스트
     *
     * @scenario case=show_missing_404
     *
     * @effects public_show_returns_active_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_show_returns_404_for_nonexistent_slug(): void
    {
        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/nonexistent');

        // Then
        $response->assertStatus(404);
    }

    /**
     * 비활성 카테고리 slug 조회 시 404 테스트
     *
     * @scenario case=show_inactive_404
     *
     * @effects public_show_returns_active_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_show_returns_404_for_inactive_category(): void
    {
        // Given
        $category = Category::create([
            'name' => ['ko' => '비활성', 'en' => 'Inactive'],
            'slug' => 'inactive-cat',
            'is_active' => false,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $category->update(['path' => (string) $category->id]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/inactive-cat');

        // Then
        $response->assertStatus(404);
    }

    /**
     * 카테고리 상세에서 활성 자식만 반환하는지 테스트
     *
     * @scenario case=show_active_children
     *
     * @effects public_show_returns_active_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_show_returns_only_active_children(): void
    {
        // Given
        $parent = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $parent->update(['path' => (string) $parent->id]);

        $activeChild = Category::create([
            'name' => ['ko' => '스마트폰', 'en' => 'Smartphones'],
            'slug' => 'smartphones',
            'parent_id' => $parent->id,
            'is_active' => true,
            'depth' => 1,
            'sort_order' => 1,
            'path' => '',
        ]);
        $activeChild->update(['path' => $parent->id.'/'.$activeChild->id]);

        $inactiveChild = Category::create([
            'name' => ['ko' => '태블릿', 'en' => 'Tablets'],
            'slug' => 'tablets',
            'parent_id' => $parent->id,
            'is_active' => false,
            'depth' => 1,
            'sort_order' => 2,
            'path' => '',
        ]);
        $inactiveChild->update(['path' => $parent->id.'/'.$inactiveChild->id]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/electronics');

        // Then: 활성 자식만 반환
        $response->assertStatus(200);
        $children = $response->json('data.children');
        $this->assertCount(1, $children);
        $this->assertEquals('smartphones', $children[0]['slug']);
    }

    /**
     * 공개 상세 조회 결과 모델의 자식 수(children_count)는 7.1.0 과 같이 연결된 자식 전부를 센다
     *
     * 공개 상세 응답(PublicCategoryDetailResource)은 7.1.0 부터 이 칸을 싣지 않는다. 대신 조회 결과 모델이
     * `sirsoft-ecommerce.category.filter_public_show_result`·`after_public_show` 훅 인자로 확장에 넘어가므로, 7.1.0 의
     * `withCounts: true`(상품·자식 수)가 싣던 `children_count` 를 그대로 유지한다. 전시 상품 기준으로 상품 수를 바꾸며
     * 집계 옵션을 빼자 이 속성이 사라졌다(7.1.1 출시 전 검증).
     *
     * @scenario case=show_children_count
     *
     * @effects public_show_children_count_counts_all_children
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_show_children_count_counts_all_children(): void
    {
        $parent = Category::create([
            'name' => ['ko' => '가전', 'en' => 'Appliances'],
            'slug' => 'appliances',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $parent->update(['path' => (string) $parent->id]);

        foreach ([['tv', true], ['fridge', false]] as $i => [$slug, $active]) {
            $child = Category::create([
                'name' => ['ko' => $slug, 'en' => $slug],
                'slug' => $slug,
                'parent_id' => $parent->id,
                'is_active' => $active,
                'depth' => 1,
                'sort_order' => $i + 1,
                'path' => '',
            ]);
            $child->update(['path' => $parent->id.'/'.$child->id]);
        }

        // 확장이 받는 훅 인자(조회 결과 모델)를 실제 요청 경로로 잡는다
        $captured = null;
        $listener = function ($category) use (&$captured) {
            $captured = $category;
        };
        HookManager::addAction('sirsoft-ecommerce.category.after_public_show', $listener);

        try {
            $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/appliances');
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.category.after_public_show', $listener);
        }

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.children'), '응답의 자식 목록은 활성 자식만이어야 한다.');
        $this->assertNotNull($captured, '공개 상세 완료 훅이 발화하지 않았다.');
        $this->assertSame(2, $captured->children_count, '훅 인자 모델의 자식 수가 7.1.0 과 다릅니다(연결된 자식 전부를 세야 한다).');
    }

    /**
     * 브레드크럼이 올바르게 반환되는지 테스트
     *
     * @scenario case=show_breadcrumb
     *
     * @effects public_show_returns_active_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_show_returns_breadcrumb(): void
    {
        // Given: 3단 카테고리
        $root = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $root->update(['path' => (string) $root->id]);

        $mid = Category::create([
            'name' => ['ko' => '스마트폰', 'en' => 'Smartphones'],
            'slug' => 'smartphones',
            'parent_id' => $root->id,
            'is_active' => true,
            'depth' => 1,
            'sort_order' => 1,
            'path' => '',
        ]);
        $mid->update(['path' => $root->id.'/'.$mid->id]);

        $leaf = Category::create([
            'name' => ['ko' => '삼성', 'en' => 'Samsung'],
            'slug' => 'samsung',
            'parent_id' => $mid->id,
            'is_active' => true,
            'depth' => 2,
            'sort_order' => 1,
            'path' => '',
        ]);
        $leaf->update(['path' => $root->id.'/'.$mid->id.'/'.$leaf->id]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/samsung');

        // Then: 브레드크럼에 3개 요소 포함
        $response->assertStatus(200);
        $breadcrumb = $response->json('data.breadcrumb');
        $this->assertCount(3, $breadcrumb);
        $this->assertEquals('electronics', $breadcrumb[0]['slug']);
        $this->assertEquals('smartphones', $breadcrumb[1]['slug']);
        $this->assertEquals('samsung', $breadcrumb[2]['slug']);
    }

    /**
     * 카테고리 상세에서 description, parent_id 필드가 반환되는지 테스트
     *
     * @scenario case=show_by_slug
     *
     * @effects public_show_returns_active_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_show_returns_description_and_parent_id(): void
    {
        // Given
        $parent = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'description' => ['ko' => '전자제품 카테고리입니다', 'en' => 'Electronics category'],
            'slug' => 'electronics',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $parent->update(['path' => (string) $parent->id]);

        $child = Category::create([
            'name' => ['ko' => '스마트폰', 'en' => 'Smartphones'],
            'description' => ['ko' => '스마트폰 카테고리', 'en' => 'Smartphones category'],
            'slug' => 'smartphones',
            'parent_id' => $parent->id,
            'is_active' => true,
            'depth' => 1,
            'sort_order' => 1,
            'path' => '',
        ]);
        $child->update(['path' => $parent->id.'/'.$child->id]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/smartphones');

        // Then
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($parent->id, $data['parent_id']);
        $this->assertArrayHasKey('description_localized', $data);
        $this->assertNotEmpty($data['description_localized']);
    }

    // ========================================
    // products_count — 공개 상품(전시)만 센다
    // ========================================

    /**
     * 전시·숨김 상품이 섞인 부모–자식 카테고리를 만든다.
     *
     * 부모: 전시 2 + 숨김 1 / 자식: 전시 1 + 숨김 2
     *
     * @return array{0: Category, 1: Category} [부모, 자식]
     */
    private function createCategoriesWithMixedProducts(): array
    {
        // 트리 캐시는 테스트 사이에 남을 수 있다 — 앞 테스트의 트리를 읽지 않게 비운다
        Category::flushTreeCache();

        $parent = Category::create([
            'name' => ['ko' => '의류', 'en' => 'Clothing'],
            'slug' => 'clothing',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $parent->update(['path' => (string) $parent->id]);

        $child = Category::create([
            'name' => ['ko' => '스커트', 'en' => 'Skirts'],
            'slug' => 'skirts',
            'parent_id' => $parent->id,
            'is_active' => true,
            'depth' => 1,
            'sort_order' => 1,
            'path' => '',
        ]);
        $child->update(['path' => $parent->id.'/'.$child->id]);

        $parent->products()->attach(Product::factory()->count(2)->create()->pluck('id'));
        $parent->products()->attach(Product::factory()->hidden()->create()->id);
        $child->products()->attach(Product::factory()->create()->id);
        $child->products()->attach(Product::factory()->count(2)->hidden()->create()->pluck('id'));

        return [$parent, $child];
    }

    /**
     * 공개 트리의 상품 수는 숨김 상품을 세지 않는다 — 공개 상품 목록과 같은 기준
     *
     * @scenario case=index_hidden_excluded
     *
     * @effects public_products_count_excludes_hidden
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_index_products_count_excludes_hidden_products(): void
    {
        $this->createCategoriesWithMixedProducts();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.0.products_count'), '부모 카테고리 상품 수에 숨김 상품이 섞였습니다.');
        $this->assertSame(1, $response->json('data.0.children.0.products_count'), '자식 카테고리 상품 수에 숨김 상품이 섞였습니다.');
    }

    /**
     * 공개 상세의 상품 수(자신·자식)도 숨김 상품을 세지 않는다
     *
     * @scenario case=show_hidden_excluded
     *
     * @effects public_products_count_excludes_hidden
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_show_products_count_excludes_hidden_products(): void
    {
        $this->createCategoriesWithMixedProducts();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/clothing');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.products_count'));
        $this->assertSame(1, $response->json('data.children.0.products_count'));
    }

    /**
     * 전시 중지하면 캐시된 공개 트리의 상품 수도 바로 줄어든다
     *
     * @scenario case=index_hide_then_recount
     *
     * @effects public_products_count_drops_after_hiding
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_index_products_count_drops_right_after_hiding_product(): void
    {
        [, $child] = $this->createCategoriesWithMixedProducts();
        $this->getJson('/api/modules/sirsoft-ecommerce/categories')->assertStatus(200);

        $visible = $child->products()->where('display_status', 'visible')->first();
        app(ProductService::class)->bulkUpdate([
            'ids' => [$visible->id],
            'bulk_changes' => ['display_status' => 'hidden'],
        ]);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories');
        $this->assertSame(0, $response->json('data.0.children.0.products_count'), '전시 중지 뒤에도 캐시된 상품 수가 그대로입니다.');
    }

    /**
     * 관리자 트리(전체 조회)는 숨김 상품까지 센다 — 공개 기준이 관리자 집계로 새지 않는다
     *
     * @scenario case=admin_tree_includes_hidden
     *
     * @effects admin_products_count_includes_hidden
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_admin_tree_products_count_still_includes_hidden_products(): void
    {
        $this->createCategoriesWithMixedProducts();
        $this->getJson('/api/modules/sirsoft-ecommerce/categories')->assertStatus(200);

        foreach ([false, true] as $onlyActive) {
            $tree = Category::getTree(null, $onlyActive);
            $this->assertSame(3, $tree->first()->products_count);
            $this->assertSame(3, $tree->first()->children->first()->products_count);
        }
    }

    /**
     * 인증 없이 접근 가능한지 테스트 (Public API)
     *
     * @scenario case=index_without_auth
     *
     * @effects public_api_without_auth
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_public_api_accessible_without_authentication(): void
    {
        // When: 인증 없이 호출
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories');

        // Then: 200 OK (인증 불필요)
        $response->assertStatus(200);
    }
}
