<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 카테고리 순서 변경 API 테스트
 *
 * Issue #10: 카테고리 순서 변경 불가 오류 수정 검증
 * 원인: ReorderCategoriesRequest에서 exists 검증 테이블명 오류
 *       (product_categories → ecommerce_categories)
 */
class CategoryReorderTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.categories.read',
            'sirsoft-ecommerce.categories.create',
            'sirsoft-ecommerce.categories.update',
        ]);
    }

    // ========================================
    // reorder() 테스트
    // ========================================

    /**
     * 부모 카테고리 순서 변경 성공 테스트
     */
    #[Test]
    public function test_reorder_parent_categories_successfully(): void
    {
        // Given: 부모 카테고리 2개 생성
        $category1 = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'sort_order' => 1,
            'is_active' => true,
            'depth' => 0,
            'path' => '',
        ]);

        $category2 = Category::create([
            'name' => ['ko' => '의류', 'en' => 'Clothing'],
            'slug' => 'clothing',
            'sort_order' => 2,
            'is_active' => true,
            'depth' => 0,
            'path' => '',
        ]);

        // When: 순서 변경 API 호출 (순서 반전)
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/categories/order', [
                'parent_menus' => [
                    ['id' => $category2->id, 'order' => 1],
                    ['id' => $category1->id, 'order' => 2],
                ],
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
    }

    /**
     * 자식 카테고리 순서 변경 성공 테스트
     */
    #[Test]
    public function test_reorder_child_categories_successfully(): void
    {
        // Given: 부모 카테고리와 자식 카테고리 생성
        $parent = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'sort_order' => 1,
            'is_active' => true,
            'depth' => 0,
            'path' => '',
        ]);

        $child1 = Category::create([
            'name' => ['ko' => '노트북', 'en' => 'Laptops'],
            'slug' => 'laptops',
            'parent_id' => $parent->id,
            'sort_order' => 1,
            'is_active' => true,
            'depth' => 1,
            'path' => $parent->id,
        ]);

        $child2 = Category::create([
            'name' => ['ko' => '스마트폰', 'en' => 'Smartphones'],
            'slug' => 'smartphones',
            'parent_id' => $parent->id,
            'sort_order' => 2,
            'is_active' => true,
            'depth' => 1,
            'path' => $parent->id,
        ]);

        // When: 자식 카테고리 순서 변경
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/categories/order', [
                'child_menus' => [
                    (string) $parent->id => [
                        ['id' => $child2->id, 'order' => 1],
                        ['id' => $child1->id, 'order' => 2],
                    ],
                ],
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
    }

    /**
     * 존재하지 않는 카테고리 ID로 순서 변경 시 검증 실패 테스트
     *
     * Issue #10의 핵심 수정사항 검증:
     * exists 규칙이 ecommerce_categories 테이블을 올바르게 참조하는지 확인
     */
    #[Test]
    public function test_reorder_fails_with_non_existent_category_id(): void
    {
        // When: 존재하지 않는 카테고리 ID로 순서 변경 요청
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/categories/order', [
                'parent_menus' => [
                    ['id' => 99999, 'order' => 1],
                ],
            ]);

        // Then: 422 검증 오류 (테이블 미존재가 아닌 정상 검증 실패)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['parent_menus.0.id']);
    }

    /**
     * 자식 카테고리를 최상위로 이동 시 parent_id가 null로 변경되는지 테스트
     *
     * Issue #144: isset()이 null에 대해 false를 반환하여
     * parent_id = null 변경이 무시되던 버그 수정 검증
     */
    #[Test]
    public function test_reorder_moves_child_to_top_level(): void
    {
        // Given: 부모 카테고리와 자식 카테고리 생성
        $parent = Category::create([
            'name' => ['ko' => '식품', 'en' => 'Food'],
            'slug' => 'food',
            'sort_order' => 1,
            'is_active' => true,
            'depth' => 0,
            'path' => '',
        ]);

        $child = Category::create([
            'name' => ['ko' => '채소', 'en' => 'Vegetables'],
            'slug' => 'vegetables',
            'parent_id' => $parent->id,
            'sort_order' => 1,
            'is_active' => true,
            'depth' => 1,
            'path' => (string) $parent->id,
        ]);

        // When: 자식 카테고리를 최상위로 이동 (parent_menus에 포함)
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/categories/order', [
                'parent_menus' => [
                    ['id' => $child->id, 'order' => 1],
                    ['id' => $parent->id, 'order' => 2],
                ],
                'child_menus' => [],
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);

        // parent_id가 null로 변경되었는지 확인
        $child->refresh();
        $this->assertNull($child->parent_id, '자식 카테고리의 parent_id가 null로 변경되어야 합니다');
        $this->assertEquals(0, $child->depth, '최상위로 이동 시 depth가 0이어야 합니다');
    }

    /**
     * 최상위 카테고리를 다른 카테고리의 자식으로 이동하는 테스트
     */
    #[Test]
    public function test_reorder_moves_top_level_to_child(): void
    {
        // Given: 최상위 카테고리 2개 생성
        $category1 = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'sort_order' => 1,
            'is_active' => true,
            'depth' => 0,
            'path' => '',
        ]);

        $category2 = Category::create([
            'name' => ['ko' => '스마트폰', 'en' => 'Smartphones'],
            'slug' => 'smartphones',
            'sort_order' => 2,
            'is_active' => true,
            'depth' => 0,
            'path' => '',
        ]);

        // When: category2를 category1의 자식으로 이동
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/categories/order', [
                'parent_menus' => [
                    ['id' => $category1->id, 'order' => 1],
                ],
                'child_menus' => [
                    (string) $category1->id => [
                        ['id' => $category2->id, 'order' => 1],
                    ],
                ],
            ]);

        // Then: 성공 응답
        $response->assertStatus(200);

        // parent_id가 변경되었는지 확인
        $category2->refresh();
        $this->assertEquals($category1->id, $category2->parent_id, '최상위 카테고리가 자식으로 이동되어야 합니다');
        $this->assertEquals(1, $category2->depth, '자식으로 이동 시 depth가 1이어야 합니다');
    }

    /**
     * 권한 없는 사용자의 순서 변경 시 403 반환 테스트
     */
    #[Test]
    public function test_reorder_fails_without_permission(): void
    {
        // Given: 카테고리 업데이트 권한 없는 사용자
        $userWithoutPermission = $this->createAdminUser([
            'sirsoft-ecommerce.categories.read',
        ]);

        $category = Category::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'slug' => 'electronics',
            'sort_order' => 1,
            'is_active' => true,
            'depth' => 0,
            'path' => '',
        ]);

        // When: 권한 없는 사용자가 순서 변경 시도
        $response = $this->actingAs($userWithoutPermission)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/categories/order', [
                'parent_menus' => [
                    ['id' => $category->id, 'order' => 1],
                ],
            ]);

        // Then: 403 Forbidden
        $response->assertStatus(403);
    }
}
