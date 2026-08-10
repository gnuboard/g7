<?php

namespace Tests\Feature\Menu;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 메뉴 수정 시 순환 참조 검증 테스트
 *
 * PUT /api/admin/menus/{menu}
 *
 * 검증 목적:
 * - 자기 자신을 부모로 지정할 수 없다 (기존 NotSelfParent 동작 유지)
 * - 자손(직계 자식 / 손자)을 부모로 지정할 수 없다 (신규 강화)
 * - 무관한 메뉴로의 이동은 허용된다
 *
 * 배경: 같은 리소스의 순서 변경 엔드포인트(UpdateMenuOrderRequest)는 이미
 * NotCircularParent 로 자손 전체를 검사하는데, 수정 엔드포인트는 자기 자신만
 * 검사해 두 엔드포인트의 검증 강도가 어긋나 있었다.
 */
class MenuCircularParentTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createMenuAdmin();
    }

    /**
     * 자기 자신을 부모로 지정하면 422
     *
     * @scenario entity=core_menu, parent_target=self
     *
     * @effects self_parent_rejected_422, parent_unchanged_on_rejection
     */
    public function test_cannot_set_self_as_parent(): void
    {
        $menu = $this->makeMenu('self-parent');

        $this->actingAs($this->adminUser)
            ->putJson($this->url($menu), $this->payload($menu, $menu->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertNull($menu->fresh()->parent_id);
    }

    /**
     * 직계 자식을 부모로 지정하면 422
     *
     * @scenario entity=core_menu, parent_target=descendant
     *
     * @effects descendant_parent_rejected_422, parent_unchanged_on_rejection
     */
    public function test_cannot_set_direct_child_as_parent(): void
    {
        $root = $this->makeMenu('cycle-root');
        $child = $this->makeMenu('cycle-child', $root);

        $this->actingAs($this->adminUser)
            ->putJson($this->url($root), $this->payload($root, $child->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertNull($root->fresh()->parent_id);
    }

    /**
     * 손자를 부모로 지정하면 422
     *
     * @scenario entity=core_menu, parent_target=descendant
     *
     * @effects descendant_parent_rejected_422, parent_unchanged_on_rejection
     */
    public function test_cannot_set_grandchild_as_parent(): void
    {
        $root = $this->makeMenu('deep-root');
        $child = $this->makeMenu('deep-child', $root);
        $grandChild = $this->makeMenu('deep-grandchild', $child);

        $this->actingAs($this->adminUser)
            ->putJson($this->url($root), $this->payload($root, $grandChild->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertNull($root->fresh()->parent_id);
    }

    /**
     * 무관한 메뉴로의 이동은 허용된다 (회귀 방지)
     *
     * @scenario entity=core_menu, parent_target=unrelated
     *
     * @effects unrelated_parent_move_succeeds
     */
    public function test_can_move_under_unrelated_menu(): void
    {
        $target = $this->makeMenu('move-target');
        $moving = $this->makeMenu('move-source');

        $this->actingAs($this->adminUser)
            ->putJson($this->url($moving), $this->payload($moving, $target->id))
            ->assertStatus(200);

        $this->assertSame($target->id, $moving->fresh()->parent_id);
    }

    /**
     * 최상위로 이동(parent_id = null)은 허용된다 (회귀 방지)
     *
     * @scenario entity=core_menu, parent_target=none
     *
     * @effects move_to_root_succeeds
     */
    public function test_can_move_to_root(): void
    {
        $root = $this->makeMenu('to-root-parent');
        $child = $this->makeMenu('to-root-child', $root);

        $this->actingAs($this->adminUser)
            ->putJson($this->url($child), $this->payload($child, null))
            ->assertStatus(200);

        $this->assertNull($child->fresh()->parent_id);
    }

    /**
     * 수정 / 순서 변경 두 엔드포인트가 동일한 순환 시나리오를 동일하게 거절한다
     *
     * 결함 8 의 본질은 "같은 리소스의 두 엔드포인트가 서로 다른 검증 강도를 갖는 것"이었다.
     * 수정 쪽만 검사하면 약한 쪽이 우회로가 되므로, 같은 자손 지정 시나리오를 양쪽에
     * 던져 두 응답이 모두 422 임을 대조한다. 한쪽 규칙이 약화되면 이 케이스가 깨진다.
     *
     * @scenario entity=core_menu, parent_target=descendant
     *
     * @effects descendant_parent_rejected_422, parent_unchanged_on_rejection
     */
    public function test_update_and_reorder_endpoints_reject_same_descendant_move(): void
    {
        $root = $this->makeMenu('parity-root');
        $child = $this->makeMenu('parity-child', $root);

        // ① 수정 엔드포인트 — root 를 자기 자식 아래로
        $this->actingAs($this->adminUser)
            ->putJson($this->url($root), $this->payload($root, $child->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        // ② 순서 변경 엔드포인트 — 동일 시나리오
        $this->actingAs($this->adminUser)
            ->putJson('/api/admin/menus/order', [
                'parent_menus' => [
                    ['id' => $root->id, 'order' => 1],
                ],
                'moved_items' => [
                    ['id' => $root->id, 'new_parent_id' => $child->id],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['moved_items.0.new_parent_id']);

        // 어느 경로로도 트리가 바뀌지 않아야 한다
        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame($root->id, $child->fresh()->parent_id);
    }

    /**
     * 메뉴 수정 권한을 가진 관리자를 생성합니다.
     *
     * @return User 관리자 사용자
     */
    private function createMenuAdmin(): User
    {
        $role = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'is_active' => true,
        ]);

        foreach (['admin.access', 'core.menus.read', 'core.menus.update'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'type' => 'admin',
                    'extension_type' => 'core',
                    'extension_identifier' => 'core',
                ]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * 테스트용 메뉴를 생성합니다.
     *
     * @param  string  $slug  메뉴 슬러그
     * @param  Menu|null  $parent  부모 메뉴
     * @return Menu 생성된 메뉴
     */
    private function makeMenu(string $slug, ?Menu $parent = null): Menu
    {
        return Menu::create([
            'name' => ['ko' => $slug, 'en' => $slug],
            'slug' => $slug,
            'parent_id' => $parent?->id,
            'order' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * 메뉴 수정 요청 본문을 생성합니다.
     *
     * @param  Menu  $menu  대상 메뉴
     * @param  int|null  $parentId  지정할 부모 ID
     * @return array<string, mixed> 요청 본문
     */
    private function payload(Menu $menu, ?int $parentId): array
    {
        return [
            'name' => ['ko' => $menu->slug, 'en' => $menu->slug],
            'slug' => $menu->slug,
            'parent_id' => $parentId,
        ];
    }

    /**
     * 메뉴 수정 엔드포인트 URL 을 생성합니다.
     *
     * @param  Menu  $menu  대상 메뉴
     * @return string 엔드포인트 URL
     */
    private function url(Menu $menu): string
    {
        return "/api/admin/menus/{$menu->id}";
    }
}
