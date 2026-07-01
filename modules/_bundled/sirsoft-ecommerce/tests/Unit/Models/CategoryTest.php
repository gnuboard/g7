<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * Category 모델 테스트
 *
 * selfAndDescendantIds() 의 path 기반 하위 카테고리 ID 산출을 검증합니다 (U6①).
 */
class CategoryTest extends ModuleTestCase
{
    /**
     * selfAndDescendantIds() 가 자기 자신 + 모든 하위 ID 를 반환하는지 테스트.
     */
    public function test_self_and_descendant_ids_includes_all_descendants(): void
    {
        [$furniture, $desk, $chair] = $this->createTree();

        $ids = Category::selfAndDescendantIds($furniture->id);

        $this->assertContains($furniture->id, $ids);
        $this->assertContains($desk->id, $ids);
        $this->assertContains($chair->id, $ids);
        $this->assertCount(3, $ids);
    }

    /**
     * 중간 노드 기준 시 자기 자신 + 하위만 반환(상위 미포함)하는지 테스트.
     */
    public function test_self_and_descendant_ids_from_mid_node_excludes_ancestor(): void
    {
        [$furniture, $desk, $chair] = $this->createTree();

        $ids = Category::selfAndDescendantIds($desk->id);

        $this->assertNotContains($furniture->id, $ids);
        $this->assertContains($desk->id, $ids);
        $this->assertContains($chair->id, $ids);
        $this->assertCount(2, $ids);
    }

    /**
     * 잎 노드는 자기 자신만 반환하는지 테스트.
     */
    public function test_self_and_descendant_ids_from_leaf_returns_self_only(): void
    {
        [, , $chair] = $this->createTree();

        $ids = Category::selfAndDescendantIds($chair->id);

        $this->assertSame([$chair->id], $ids);
    }

    /**
     * 존재하지 않는 ID 는 자기 자신만 반환하는지 테스트.
     */
    public function test_self_and_descendant_ids_for_missing_returns_self(): void
    {
        $ids = Category::selfAndDescendantIds(999999);

        $this->assertSame([999999], $ids);
    }

    /**
     * path 가 공백인 레거시 행은 자기 자신만 반환(폴백)하는지 테스트.
     */
    public function test_self_and_descendant_ids_handles_blank_path(): void
    {
        $blank = Category::create([
            'name' => ['ko' => '공백', 'en' => 'Blank'],
            'slug' => 'blank-path',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);

        $ids = Category::selfAndDescendantIds($blank->id);

        $this->assertSame([$blank->id], $ids);
    }

    /**
     * 가구 > 책상 > 의자 3단계 트리 생성.
     *
     * @return array{0: Category, 1: Category, 2: Category}
     */
    private function createTree(): array
    {
        $furniture = Category::create([
            'name' => ['ko' => '가구', 'en' => 'Furniture'],
            'slug' => 'furniture-cat',
            'is_active' => true,
            'depth' => 0,
            'sort_order' => 1,
            'path' => '',
        ]);
        $furniture->update(['path' => (string) $furniture->id]);

        $desk = Category::create([
            'name' => ['ko' => '책상', 'en' => 'Desk'],
            'slug' => 'desk-cat',
            'parent_id' => $furniture->id,
            'is_active' => true,
            'depth' => 1,
            'sort_order' => 1,
            'path' => '',
        ]);
        $desk->update(['path' => $furniture->path.'/'.$desk->id]);

        $chair = Category::create([
            'name' => ['ko' => '의자', 'en' => 'Chair'],
            'slug' => 'chair-cat',
            'parent_id' => $desk->id,
            'is_active' => true,
            'depth' => 2,
            'sort_order' => 1,
            'path' => '',
        ]);
        $chair->update(['path' => $desk->path.'/'.$chair->id]);

        return [$furniture, $desk, $chair];
    }
}
