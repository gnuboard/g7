<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\MenuPermissionType;
use App\Extension\HookManager;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

/**
 * 메뉴 관리 「여러 개 선택」 — 메뉴 역할 일괄 추가·제거 (#708)
 *
 * `PATCH /api/admin/menus/bulk-roles` 는 라우트 모델이 없는 정적 경로라 미들웨어의 범위 검사가
 * 빠진다. 서비스가 범위 밖 메뉴가 섞이면 전량 거부하는지, 부모·자식을 맞추는지, 관리자 역할을
 * 지키는지, 같은 역할을 다시 더해도 행이 겹치지 않는지를 찌른다.
 *
 * 시나리오 SSoT: tests/scenarios/role-menu-bulk-assignment.yaml
 */
#[Group('core')]
#[Group('admin')]
class MenuBulkRolesTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    /**
     * 일괄 요청을 보냅니다.
     *
     * @param  User  $actor  요청자
     * @param  array<int, int>  $menuIds  메뉴 ID
     * @param  array<int, int>  $roleIds  역할 ID
     * @param  string  $mode  작업 (add 추가 · remove 제거)
     * @return TestResponse 응답
     */
    private function bulk(User $actor, array $menuIds, array $roleIds, string $mode): TestResponse
    {
        return $this->actingAs($actor)->patchJson('/api/admin/menus/bulk-roles', [
            'menu_ids' => $menuIds,
            'role_ids' => $roleIds,
            'mode' => $mode,
        ]);
    }

    /**
     * 역할이 가진 read 행 수 (중복 검사용).
     *
     * @param  Menu  $menu  메뉴
     * @param  Role  $role  역할
     * @return int 행 수
     */
    private function pivotRows(Menu $menu, Role $role): int
    {
        return DB::table('role_menus')
            ->where('menu_id', $menu->id)
            ->where('role_id', $role->id)
            ->where('permission_type', MenuPermissionType::Read->value)
            ->count();
    }

    /**
     * 최상위 메뉴에 역할을 더하면 붙고, 같은 요청을 다시 보내도 행이 겹치지 않는다.
     *
     * @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
     *
     * @effects user_overrides_recorded_per_menu, sidebar_reflects_assignment
     *
     * @return void 반환값 없음
     */
    public function test_add_to_top_level_is_idempotent(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $a = $this->menu();
        $b = $this->menu();

        $this->bulk($actor, [$a->id, $b->id], [$role->id], 'add')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2);
        $this->bulk($actor, [$a->id, $b->id], [$role->id], 'add')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 0);

        $this->assertSame(1, $this->pivotRows($a, $role));
        $this->assertSame(1, $this->pivotRows($b, $role));
        $this->assertContains($role->identifier, $a->fresh()->user_overrides ?? []);

        $member = User::factory()->create();
        $member->roles()->attach($role->id, ['assigned_at' => now()]);
        $sidebar = app(MenuService::class)->getAccessibleNavigationMenus($member->fresh())->pluck('id')->all();
        $this->assertContains($a->id, $sidebar);
    }

    /**
     * 자식 메뉴만 골라 더하면 부모에도 더하고, 자동 포함한 부모 ID 를 응답에 싣는다.
     *
     * @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=child_only
     *
     * @effects parent_auto_included
     *
     * @return void 반환값 없음
     */
    public function test_add_to_child_includes_parent(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $parent = $this->menu();
        $child = $this->menu(['parent_id' => $parent->id]);

        $this->bulk($actor, [$child->id], [$role->id], 'add')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.auto_included_parent_ids', [$parent->id]);

        $this->assertSame([$parent->id, $child->id], $this->menuIdsOf($role));
    }

    /**
     * 영문 결과 문구는 메뉴 1개만 바뀌면 단수다 — 「1 menus」·「1 menu(s)」 가 아니라 「1 menu」 (2026-10-08 결정: 1개면 단수·여러 개면 복수).
     *
     * @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
     *
     * @effects english_result_message_follows_menu_count
     *
     * @return void 반환값 없음
     */
    public function test_english_success_message_reads_naturally_for_one_menu(): void
    {
        $actor = $this->actor('super_admin');
        $actor->forceFill(['language' => 'en'])->save();
        $role = $this->roleWith([]);
        $menu = $this->menu();

        $this->bulk($actor->fresh(), [$menu->id], [$role->id], 'add')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('message', 'Updated the roles of 1 menu.');
    }

    /**
     * 관리 목록은 행마다 부여된 역할을 ID 로 싣는다 — 일괄로 자식에 더한 역할이 부모·자식 행 모두에 보이고,
     * 하위 메뉴의 역할 객체(roles)는 #76 정리대로 여전히 싣지 않는다.
     *
     * @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=child_only
     *
     * @effects menu_list_shows_assigned_roles
     *
     * @return void 반환값 없음
     */
    public function test_menu_list_carries_role_ids_per_row(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $parent = $this->menu();
        $child = $this->menu(['parent_id' => $parent->id]);

        $this->bulk($actor, [$child->id], [$role->id], 'add')->assertOk();

        $rows = collect($this->actingAs($actor)->getJson('/api/admin/menus')->assertOk()->json('data.data'));
        $parentRow = $rows->firstWhere('id', $parent->id);
        $childRow = collect($parentRow['children'] ?? [])->firstWhere('id', $child->id);
        $expected = DB::table('role_menus')->where('menu_id', $child->id)->orderBy('role_id')->pluck('role_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($role->id, $parentRow['role_ids']);
        $this->assertSame($expected, $childRow['role_ids']);
        $this->assertContains($role->id, $childRow['role_ids']);
        $this->assertArrayNotHasKey('roles', $childRow);
    }

    /**
     * 부모와 자식을 함께 골라 더하면 모두 붙고 자동 포함은 없다. 메뉴마다 활동 로그 훅이 1회.
     *
     * @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=parent_with_children
     *
     * @effects activity_log_per_menu
     *
     * @return void 반환값 없음
     */
    public function test_add_to_parent_and_children(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $parent = $this->menu();
        $c1 = $this->menu(['parent_id' => $parent->id]);
        $c2 = $this->menu(['parent_id' => $parent->id]);

        $fired = [];
        $counter = function ($menu) use (&$fired) {
            $fired[] = $menu->id;
        };
        HookManager::addAction('core.menu.after_sync_roles', $counter);
        try {
            $this->bulk($actor, [$parent->id, $c1->id, $c2->id], [$role->id], 'add')
                ->assertOk()
                ->assertJsonPath('data.auto_included_parent_ids', []);
        } finally {
            HookManager::removeAction('core.menu.after_sync_roles', $counter);
        }

        sort($fired);
        $this->assertSame([$parent->id, $c1->id, $c2->id], $fired);
        $this->assertSame([$parent->id, $c1->id, $c2->id], $this->menuIdsOf($role));
    }

    /**
     * 확장 개발자용 일괄 훅이 요청 앞뒤로 한 번씩 발화하고, 뒤 훅은 적용 결과(자동 포함 부모)를 받는다.
     *
     * @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=child_only
     *
     * @effects parent_auto_included
     *
     * @return void 반환값 없음
     */
    public function test_bulk_hooks_fire_once_around_the_request(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $parent = $this->menu();
        $child = $this->menu(['parent_id' => $parent->id]);

        $calls = [];
        $before = function ($menuIds, $roleIds, $mode) use (&$calls) {
            $calls[] = ['before', $menuIds, $roleIds, $mode];
        };
        $after = function ($menuIds, $roleIds, $mode, $result) use (&$calls) {
            $calls[] = ['after', $menuIds, $roleIds, $mode, $result['auto_included_parent_ids']];
        };
        HookManager::addAction('core.menu.before_bulk_update_roles', $before);
        HookManager::addAction('core.menu.after_bulk_update_roles', $after);
        try {
            $this->bulk($actor, [$child->id], [$role->id], 'add')->assertOk();
        } finally {
            HookManager::removeAction('core.menu.before_bulk_update_roles', $before);
            HookManager::removeAction('core.menu.after_bulk_update_roles', $after);
        }

        $this->assertSame([
            ['before', [$child->id], [$role->id], 'add'],
            ['after', [$child->id], [$role->id], 'add', [$parent->id]],
        ], $calls);
    }

    /**
     * 비활성 확장 메뉴(관리 목록 밖)가 섞이면 422 로 전부 거부한다.
     *
     * @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=inactive_extension
     *
     * @effects unmanageable_menu_rejected
     *
     * @return void 반환값 없음
     */
    public function test_add_including_inactive_extension_menu_is_rejected(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $visible = $this->menu();
        $hidden = $this->inactivePluginMenu();

        $this->bulk($actor, [$visible->id, $hidden->id], [$role->id], 'add')->assertStatus(422);

        $this->assertSame([], $this->menuIdsOf($role));
    }

    /**
     * 최상위 메뉴에서 역할을 빼면 떨어지고, 가지지 않은 메뉴는 바뀐 수에 세지 않는다.
     *
     * @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=top_level
     *
     * @effects user_overrides_recorded_per_menu
     *
     * @return void 반환값 없음
     */
    public function test_remove_from_top_level(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $a = $this->menu([], [$role]);
        $b = $this->menu();

        $this->bulk($actor, [$a->id, $b->id], [$role->id], 'remove')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->assertSame([], $this->menuIdsOf($role));
    }

    /**
     * 자식만 골라 빼면 그 자식에서만 빠지고 부모는 그대로다.
     *
     * @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=child_only
     *
     * @effects children_auto_removed
     *
     * @return void 반환값 없음
     */
    public function test_remove_from_child_keeps_parent(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $parent = $this->menu([], [$role]);
        $child = $this->menu(['parent_id' => $parent->id], [$role]);

        $this->bulk($actor, [$child->id], [$role->id], 'remove')
            ->assertOk()
            ->assertJsonPath('data.auto_removed_child_ids', []);

        $this->assertSame([$parent->id], $this->menuIdsOf($role));
    }

    /**
     * 부모에서 빼면 자식에서도 빠지고, 자동으로 뺀 자식 ID 를 응답에 싣는다.
     *
     * @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=parent_with_children
     *
     * @effects children_auto_removed
     *
     * @return void 반환값 없음
     */
    public function test_remove_from_parent_removes_children(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $parent = $this->menu([], [$role]);
        $c1 = $this->menu(['parent_id' => $parent->id], [$role]);
        $c2 = $this->menu(['parent_id' => $parent->id], [$role]);

        $response = $this->bulk($actor, [$parent->id], [$role->id], 'remove')->assertOk();

        $autoRemoved = $response->json('data.auto_removed_child_ids');
        sort($autoRemoved);
        $this->assertSame([$c1->id, $c2->id], $autoRemoved);
        $this->assertSame([], $this->menuIdsOf($role));
    }

    /**
     * 관리자 역할을 빼는 요청은 422 이고, 비활성 확장 메뉴의 할당은 일괄 경로로도 닿지 않는다.
     *
     * @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=inactive_extension
     *
     * @effects admin_role_locked, invisible_assignment_preserved
     *
     * @return void 반환값 없음
     */
    public function test_remove_admin_role_is_rejected_and_hidden_assignment_untouched(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $visible = $this->menu([], [$role]);
        $hidden = $this->inactivePluginMenu([$role]);

        $this->bulk($actor, [$visible->id], [$this->adminRole()->id], 'remove')
            ->assertStatus(422)
            ->assertJsonPath('errors.role_ids.0', __('menu.admin_role_required'));
        $this->assertContains('admin', $this->roleIdentifiersOf($visible));

        $this->bulk($actor, [$hidden->id], [$role->id], 'remove')->assertStatus(422);
        $this->assertSame([$visible->id, $hidden->id], $this->menuIdsOf($role));
    }

    /**
     * 범위 제한 관리자(메뉴 수정 self)가 남이 만든 메뉴를 한 건이라도 섞으면 전체를 403 으로 거부한다.
     *
     * @scenario entry=menu_bulk_add, actor=scoped_admin, menu_shape=top_level
     *
     * @effects out_of_scope_whole_rejected
     *
     * @return void 반환값 없음
     */
    public function test_scoped_admin_add_with_out_of_scope_menu_rejects_whole(): void
    {
        $actor = $this->actor('scoped_admin');
        $actorRole = $actor->roles()->first();
        $role = $this->roleWith([]);
        $own = $this->menu(['created_by' => $actor->id], [$actorRole]);
        $others = $this->menu(['created_by' => User::factory()->create()->id], [$actorRole]);

        $this->bulk($actor, [$own->id, $others->id], [$role->id], 'add')->assertForbidden();
        $this->assertSame([], $this->menuIdsOf($role));

        $this->bulk($actor, [$own->id], [$role->id], 'add')->assertOk();
        $this->assertSame([$own->id], $this->menuIdsOf($role));
    }

    /**
     * 범위 제한 관리자의 제거도 범위 밖 메뉴가 섞이면 전체 403 이다.
     *
     * @scenario entry=menu_bulk_remove, actor=scoped_admin, menu_shape=top_level
     *
     * @effects out_of_scope_whole_rejected
     *
     * @return void 반환값 없음
     */
    public function test_scoped_admin_remove_with_out_of_scope_menu_rejects_whole(): void
    {
        $actor = $this->actor('scoped_admin');
        $actorRole = $actor->roles()->first();
        $role = $this->roleWith([]);
        $own = $this->menu(['created_by' => $actor->id], [$actorRole, $role]);
        $others = $this->menu(['created_by' => User::factory()->create()->id], [$actorRole, $role]);

        $this->bulk($actor, [$own->id, $others->id], [$role->id], 'remove')->assertForbidden();

        $this->assertSame([$own->id, $others->id], $this->menuIdsOf($role));
    }

    /**
     * 메뉴 수정 권한이 없으면 일괄 추가는 라우트에서 403 이다.
     *
     * @scenario entry=menu_bulk_add, actor=no_menu_permission, menu_shape=top_level
     *
     * @effects out_of_scope_whole_rejected
     *
     * @return void 반환값 없음
     */
    public function test_add_without_menu_permission_is_forbidden(): void
    {
        $actor = $this->actor('no_menu_permission');
        $role = $this->roleWith([]);
        $top = $this->menu();

        $this->bulk($actor, [$top->id], [$role->id], 'add')->assertForbidden();

        $this->assertSame([], $this->menuIdsOf($role));
    }

    /**
     * 메뉴 수정 권한이 없으면 일괄 제거도 라우트에서 403 이다.
     *
     * @scenario entry=menu_bulk_remove, actor=no_menu_permission, menu_shape=top_level
     *
     * @effects out_of_scope_whole_rejected
     *
     * @return void 반환값 없음
     */
    public function test_remove_without_menu_permission_is_forbidden(): void
    {
        $actor = $this->actor('no_menu_permission');
        $role = $this->roleWith([]);
        $top = $this->menu([], [$role]);

        $this->bulk($actor, [$top->id], [$role->id], 'remove')->assertForbidden();

        $this->assertSame([$top->id], $this->menuIdsOf($role));
    }

    /**
     * 모드·목록 형식이 틀리면 422 다.
     *
     * @effects unmanageable_menu_rejected
     *
     * @return void 반환값 없음
     */
    public function test_invalid_payload_is_rejected(): void
    {
        $actor = $this->actor('super_admin');

        $this->actingAs($actor)->patchJson('/api/admin/menus/bulk-roles', ['menu_ids' => [], 'role_ids' => [], 'mode' => 'toggle'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['menu_ids', 'role_ids', 'mode']);
    }
}
