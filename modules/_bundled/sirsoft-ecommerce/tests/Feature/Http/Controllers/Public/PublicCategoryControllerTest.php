<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Modules\Sirsoft\Ecommerce\Models\Category;
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
     * 브레드크럼이 올바르게 반환되는지 테스트
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
        $child->update(['path' => $parent->id . '/' . $child->id]);

        // When
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/smartphones');

        // Then
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($parent->id, $data['parent_id']);
        $this->assertArrayHasKey('description_localized', $data);
        $this->assertNotEmpty($data['description_localized']);
    }

    /**
     * 인증 없이 접근 가능한지 테스트 (Public API)
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
