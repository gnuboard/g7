<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 카테고리 순환 참조 방지 테스트
 *
 * 검증 목적:
 * - 자기 자신을 부모로 지정할 수 없다 (422)
 * - 직계 자식 / 손자를 부모로 지정할 수 없다 (422)
 * - 무관한 카테고리로의 정상 이동은 허용되고 path 가 갱신된다 (200)
 * - 최상위로 이동(parent_id = null)은 허용된다 (200)
 */
class CategoryCycleTest extends ModuleTestCase
{
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.categories.update',
            'sirsoft-ecommerce.categories.create',
        ]);
    }

    /**
     * 자기 자신을 부모로 지정하면 422
     *
     * @scenario entity=ec_category, parent_target=self
     *
     * @effects self_parent_rejected_422, parent_unchanged_on_rejection
     */
    public function test_cannot_set_self_as_parent(): void
    {
        $category = $this->makeCategory('self-parent');

        $this->actingAs($this->adminUser)
            ->putJson($this->url($category), $this->payload($category, $category->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertNull($category->fresh()->parent_id, 'parent_id 가 변경되면 안 됩니다.');
    }

    /**
     * 직계 자식을 부모로 지정하면 422
     *
     * @scenario entity=ec_category, parent_target=descendant
     *
     * @effects descendant_parent_rejected_422, parent_unchanged_on_rejection
     */
    public function test_cannot_set_direct_child_as_parent(): void
    {
        $root = $this->makeCategory('cycle-root');
        $child = $this->makeCategory('cycle-child', $root);

        $this->actingAs($this->adminUser)
            ->putJson($this->url($root), $this->payload($root, $child->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertNull($root->fresh()->parent_id, 'root 의 parent_id 가 변경되면 안 됩니다.');
    }

    /**
     * 손자(3노드 사이클)를 부모로 지정하면 422
     *
     * @scenario entity=ec_category, parent_target=descendant
     *
     * @effects descendant_parent_rejected_422, parent_unchanged_on_rejection
     */
    public function test_cannot_set_grandchild_as_parent(): void
    {
        $root = $this->makeCategory('deep-root');
        $child = $this->makeCategory('deep-child', $root);
        $grandChild = $this->makeCategory('deep-grandchild', $child);

        $this->actingAs($this->adminUser)
            ->putJson($this->url($root), $this->payload($root, $grandChild->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame($child->id, $grandChild->fresh()->parent_id);
    }

    /**
     * 무관한 카테고리로의 이동은 허용되고 path 가 갱신된다
     *
     * @scenario entity=ec_category, parent_target=unrelated
     *
     * @effects unrelated_parent_move_succeeds, descendant_paths_rebuilt_on_valid_move
     */
    public function test_can_move_under_unrelated_category(): void
    {
        $target = $this->makeCategory('move-target');
        $moving = $this->makeCategory('move-source');
        $movingChild = $this->makeCategory('move-source-child', $moving);

        $this->actingAs($this->adminUser)
            ->putJson($this->url($moving), $this->payload($moving, $target->id))
            ->assertStatus(200);

        $moved = $moving->fresh();
        $this->assertSame($target->id, $moved->parent_id);
        $this->assertSame("{$target->id}/{$moved->id}", $moved->path, '이동된 카테고리의 path 가 갱신되어야 합니다.');
        $this->assertSame(
            "{$target->id}/{$moved->id}/{$movingChild->id}",
            $movingChild->fresh()->path,
            '자손의 path 도 함께 갱신되어야 합니다.'
        );
    }

    /**
     * 최상위로 이동(parent_id = null)은 허용된다
     *
     * @scenario entity=ec_category, parent_target=none
     *
     * @effects move_to_root_succeeds, path_and_depth_rebuilt_on_root_move, descendant_paths_rebuilt_on_root_move
     */
    public function test_can_move_to_root(): void
    {
        $root = $this->makeCategory('to-root-parent');
        $child = $this->makeCategory('to-root-child', $root);
        $grandChild = $this->makeCategory('to-root-grandchild', $child);

        $this->actingAs($this->adminUser)
            ->putJson($this->url($child), $this->payload($child, null))
            ->assertStatus(200);

        $movedChild = $child->fresh();
        $this->assertNull($movedChild->parent_id);
        // 루트 이동 시 path/depth 가 재계산되어야 한다 (isset 이 null 을 미설정으로 취급하던 회귀 방지)
        $this->assertSame((string) $child->id, $movedChild->path, '루트로 이동한 카테고리의 path 는 자기 ID 여야 합니다.');
        $this->assertSame(0, $movedChild->depth, '루트로 이동한 카테고리의 depth 는 0 이어야 합니다.');
        // 자손 path 도 새 루트 기준으로 재계산되어야 한다
        $this->assertSame("{$child->id}/{$grandChild->id}", $grandChild->fresh()->path, '루트 이동 시 자손 path 도 갱신되어야 합니다.');
    }

    /**
     * 순서 변경 엔드포인트로도 조상을 자식으로 만들 수 없다 (동일 리소스 검증 강도 일치)
     *
     * @scenario entity=ec_category, parent_target=descendant
     *
     * @effects descendant_parent_rejected_422, parent_unchanged_on_rejection
     */
    public function test_reorder_cannot_create_cycle(): void
    {
        $root = $this->makeCategory('reorder-root');
        $child = $this->makeCategory('reorder-child', $root);

        // root 를 child 의 자식으로 밀어 넣으려는 시도
        $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/categories/order', [
                'child_menus' => [
                    (string) $child->id => [
                        ['id' => $root->id, 'order' => 1],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(["child_menus.{$child->id}.0.id"]);

        $this->assertNull($root->fresh()->parent_id, 'root 의 parent_id 가 변경되면 안 됩니다.');
    }

    /**
     * 순서 변경으로 무관한 카테고리를 자식으로 지정하는 것은 허용된다 (회귀 방지)
     *
     * @scenario entity=ec_category, parent_target=unrelated
     *
     * @effects unrelated_parent_move_succeeds
     */
    public function test_reorder_allows_valid_move(): void
    {
        $target = $this->makeCategory('reorder-target');
        $moving = $this->makeCategory('reorder-moving');

        $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/categories/order', [
                'child_menus' => [
                    (string) $target->id => [
                        ['id' => $moving->id, 'order' => 1],
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame($target->id, $moving->fresh()->parent_id);
    }

    /**
     * 테스트용 카테고리를 생성합니다.
     *
     * @param  string  $slug  카테고리 슬러그
     * @param  Category|null  $parent  부모 카테고리
     * @return Category 생성된 카테고리
     */
    private function makeCategory(string $slug, ?Category $parent = null): Category
    {
        $category = Category::create([
            'name' => ['ko' => $slug, 'en' => $slug],
            'slug' => $slug,
            'parent_id' => $parent?->id,
            'path' => '',
            'depth' => $parent ? $parent->depth + 1 : 0,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $category->update([
            'path' => $parent ? $parent->path.'/'.$category->id : (string) $category->id,
        ]);

        return $category->fresh();
    }

    /**
     * 카테고리 수정 요청 본문을 생성합니다.
     *
     * @param  Category  $category  대상 카테고리
     * @param  int|null  $parentId  지정할 부모 ID
     * @return array<string, mixed> 요청 본문
     */
    private function payload(Category $category, ?int $parentId): array
    {
        return [
            'name' => ['ko' => $category->slug, 'en' => $category->slug],
            'slug' => $category->slug,
            'parent_id' => $parentId,
        ];
    }

    /**
     * 카테고리 수정 엔드포인트 URL 을 생성합니다.
     *
     * @param  Category  $category  대상 카테고리
     * @return string 엔드포인트 URL
     */
    private function url(Category $category): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/categories/{$category->id}";
    }
}
