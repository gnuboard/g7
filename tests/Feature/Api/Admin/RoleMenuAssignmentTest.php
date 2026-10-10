<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

/**
 * 역할 폼 「접근 가능 메뉴」 — 역할 등록·수정 요청의 menu_ids (#708)
 *
 * 메뉴의 접근 역할을 메뉴 쪽에서 한 건씩만 고칠 수 있던 것을 역할 쪽에서 통째로 지정하게 했다.
 * 부모·자식 정규화, 관리자 역할 잠금, 보이지 않는 할당 보존, 메뉴 권한 게이트, 트랜잭션을 찌른다.
 *
 * 로컬 MySQL 의 migrate:fresh OOM 을 피하려 트랜잭션 격리를 쓴다 — 식별자는 매 실행 고유값이다.
 *
 * 시나리오 SSoT: tests/scenarios/role-menu-bulk-assignment.yaml
 */
#[Group('core')]
#[Group('admin')]
class RoleMenuAssignmentTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    /**
     * 역할 등록 요청을 보냅니다.
     *
     * @param  User  $actor  요청자
     * @param  array<string, mixed>  $extra  추가 본문
     * @return TestResponse 응답
     */
    private function createRole(User $actor, array $extra = []): TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/admin/roles', array_merge([
            'identifier' => 'rma_'.Str::lower(Str::random(10)),
            'name' => ['ko' => '새 역할', 'en' => 'New Role'],
        ], $extra));
    }

    /**
     * 역할 수정 요청을 보냅니다.
     *
     * @param  User  $actor  요청자
     * @param  Role  $role  대상 역할
     * @param  array<string, mixed>  $body  본문
     * @return TestResponse 응답
     */
    private function updateRole(User $actor, Role $role, array $body): TestResponse
    {
        return $this->actingAs($actor)->putJson('/api/admin/roles/'.$role->id, $body);
    }

    /**
     * 최상위 메뉴 하나를 지정해 역할을 만들면 그 메뉴만 붙고, 메뉴마다 user_overrides 가 남고,
     * 그 역할 회원의 사이드바에 메뉴가 나온다.
     *
     * @scenario entry=role_form_create, actor=super_admin, menu_shape=top_level
     *
     * @effects user_overrides_recorded_per_menu, sidebar_reflects_assignment
     *
     * @return void 반환값 없음
     */
    public function test_create_with_top_level_menu_assigns_it_and_shows_in_sidebar(): void
    {
        $actor = $this->actor('super_admin');
        $top = $this->menu();

        $response = $this->createRole($actor, ['menu_ids' => [$top->id]]);

        $response->assertCreated();
        $role = Role::where('identifier', $response->json('data.identifier'))->firstOrFail();
        $this->assertSame([$top->id], $this->menuIdsOf($role));
        $this->assertContains($role->identifier, $top->fresh()->user_overrides ?? []);

        $member = User::factory()->create();
        $member->roles()->attach($role->id, ['assigned_at' => now()]);
        $sidebar = app(MenuService::class)->getAccessibleNavigationMenus($member->fresh())->pluck('id')->all();
        $this->assertContains($top->id, $sidebar);
    }

    /**
     * 자식 메뉴만 지정해 역할을 만들면 부모 메뉴도 함께 붙는다.
     *
     * @scenario entry=role_form_create, actor=super_admin, menu_shape=child_only
     *
     * @effects parent_auto_included
     *
     * @return void 반환값 없음
     */
    public function test_create_with_child_only_includes_parent(): void
    {
        $actor = $this->actor('super_admin');
        $parent = $this->menu();
        $child = $this->menu(['parent_id' => $parent->id]);

        $response = $this->createRole($actor, ['menu_ids' => [$child->id]]);

        $response->assertCreated();
        $role = Role::where('identifier', $response->json('data.identifier'))->firstOrFail();
        $this->assertSame([$parent->id, $child->id], $this->menuIdsOf($role));
    }

    /**
     * 부모와 자식을 함께 지정하면 그대로 붙고, 메뉴마다 after_sync_roles 가 1회씩 발화한다.
     *
     * @scenario entry=role_form_create, actor=super_admin, menu_shape=parent_with_children
     *
     * @effects activity_log_per_menu
     *
     * @return void 반환값 없음
     */
    public function test_create_with_parent_and_children_fires_hook_once_per_menu(): void
    {
        $actor = $this->actor('super_admin');
        $parent = $this->menu();
        $c1 = $this->menu(['parent_id' => $parent->id]);
        $c2 = $this->menu(['parent_id' => $parent->id]);

        $fired = [];
        $counter = function ($menu) use (&$fired) {
            $fired[] = $menu->id;
        };
        HookManager::addAction('core.menu.after_sync_roles', $counter);

        try {
            $response = $this->createRole($actor, ['menu_ids' => [$parent->id, $c1->id, $c2->id]]);
        } finally {
            // 실제 리스너(user_overrides·활동 로그)는 남겨 두고 이 테스트의 계수기만 뗀다.
            HookManager::removeAction('core.menu.after_sync_roles', $counter);
        }

        $response->assertCreated();
        sort($fired);
        $this->assertSame([$parent->id, $c1->id, $c2->id], $fired);
    }

    /**
     * 수정에서 최상위 메뉴를 바꾸면 새 메뉴는 붙고 빠진 메뉴는 떨어진다.
     *
     * @scenario entry=role_form_update, actor=super_admin, menu_shape=top_level
     *
     * @effects user_overrides_recorded_per_menu
     *
     * @return void 반환값 없음
     */
    public function test_update_swaps_top_level_menus(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $old = $this->menu([], [$role]);
        $new = $this->menu();

        $this->updateRole($actor, $role, ['menu_ids' => [$new->id]])->assertOk();

        $this->assertSame([$new->id], $this->menuIdsOf($role));
        $this->assertSame(['admin'], $this->roleIdentifiersOf($old));
    }

    /**
     * 수정에서 자식만 새로 고르면 부모도 함께 붙는다.
     *
     * @scenario entry=role_form_update, actor=super_admin, menu_shape=child_only
     *
     * @effects parent_auto_included
     *
     * @return void 반환값 없음
     */
    public function test_update_with_new_child_includes_parent(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $parent = $this->menu();
        $child = $this->menu(['parent_id' => $parent->id]);

        $this->updateRole($actor, $role, ['menu_ids' => [$child->id]])->assertOk();

        $this->assertSame([$parent->id, $child->id], $this->menuIdsOf($role));
    }

    /**
     * 부모를 빼고 자식만 남겨 보내면 자식도 함께 빠진다.
     *
     * @scenario entry=role_form_update, actor=super_admin, menu_shape=parent_with_children
     *
     * @effects children_auto_removed
     *
     * @return void 반환값 없음
     */
    public function test_update_removing_parent_removes_children(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $parent = $this->menu([], [$role]);
        $c1 = $this->menu(['parent_id' => $parent->id], [$role]);
        $c2 = $this->menu(['parent_id' => $parent->id], [$role]);

        $this->updateRole($actor, $role, ['menu_ids' => [$c1->id, $c2->id]])->assertOk();

        $this->assertSame([], $this->menuIdsOf($role));
    }

    /**
     * 비활성 플러그인 메뉴(관리 목록에 없는 메뉴)의 기존 할당은 메뉴를 전부 비워 저장해도 남고,
     * 화면이 그 id 를 그대로 돌려보내도 거부하지 않는다.
     *
     * @scenario entry=role_form_update, actor=super_admin, menu_shape=inactive_extension
     *
     * @effects invisible_assignment_preserved
     *
     * @return void 반환값 없음
     */
    public function test_update_preserves_assignment_on_inactive_extension_menu(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $hidden = $this->inactivePluginMenu([$role]);
        $visible = $this->menu([], [$role]);

        $this->updateRole($actor, $role, ['menu_ids' => []])->assertOk();
        $this->assertSame([$hidden->id], $this->menuIdsOf($role));

        $this->updateRole($actor, $role, ['menu_ids' => [$hidden->id, $visible->id]])->assertOk();
        $this->assertSame([$hidden->id, $visible->id], $this->menuIdsOf($role));
    }

    /**
     * 범위 제한 관리자(메뉴 수정 self)는 자기가 만든 메뉴는 지정하고, 남이 만든 메뉴는 새로 넣지 못한다.
     *
     * @scenario entry=role_form_create, actor=scoped_admin, menu_shape=top_level
     *
     * @effects unmanageable_menu_rejected
     *
     * @return void 반환값 없음
     */
    public function test_scoped_admin_create_rejects_menu_outside_scope(): void
    {
        $actor = $this->actor('scoped_admin');
        $actorRole = $actor->roles()->first();
        $own = $this->menu(['created_by' => $actor->id], [$actorRole]);
        $others = $this->menu(['created_by' => User::factory()->create()->id], [$actorRole]);

        $this->createRole($actor, ['menu_ids' => [$others->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.menu_ids.0', __('validation.role.menu_ids.unmanageable'));

        $response = $this->createRole($actor, ['menu_ids' => [$own->id]]);
        $response->assertCreated();
        $role = Role::where('identifier', $response->json('data.identifier'))->firstOrFail();
        $this->assertSame([$own->id], $this->menuIdsOf($role));
    }

    /**
     * 범위 제한 관리자가 범위 밖 메뉴를 넣은 수정은 422 이고, 같은 요청의 이름 변경도 되돌린다.
     *
     * @scenario entry=role_form_update, actor=scoped_admin, menu_shape=top_level
     *
     * @effects unmanageable_menu_rejected
     *
     * @return void 반환값 없음
     */
    public function test_scoped_admin_update_rejects_and_rolls_back_role_attributes(): void
    {
        $actor = $this->actor('scoped_admin');
        $actorRole = $actor->roles()->first();
        $role = $this->roleWith([]);
        $others = $this->menu(['created_by' => User::factory()->create()->id], [$actorRole]);

        $this->updateRole($actor, $role, [
            'name' => ['ko' => '바뀐 이름', 'en' => 'Renamed'],
            'menu_ids' => [$others->id],
        ])->assertStatus(422);

        $this->assertSame('테스트 역할', $role->fresh()->name['ko']);
        $this->assertSame([], $this->menuIdsOf($role));
    }

    /**
     * 메뉴 수정 권한이 없으면 menu_ids 를 보낸 역할 등록은 403 이고 역할이 만들어지지 않는다.
     * menu_ids 를 보내지 않으면 등록은 그대로 된다.
     *
     * @scenario entry=role_form_create, actor=no_menu_permission, menu_shape=top_level
     *
     * @effects unmanageable_menu_rejected
     *
     * @return void 반환값 없음
     */
    public function test_create_without_menu_permission_is_forbidden_only_with_menu_ids(): void
    {
        $actor = $this->actor('no_menu_permission');
        $top = $this->menu();
        $identifier = 'rma_'.Str::lower(Str::random(10));

        $this->createRole($actor, ['identifier' => $identifier, 'menu_ids' => [$top->id]])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['identifier' => $identifier]);

        $this->createRole($actor)->assertCreated();
    }

    /**
     * 메뉴 수정 권한이 없으면 menu_ids 를 보낸 역할 수정은 403 이고 메뉴는 그대로다.
     *
     * @scenario entry=role_form_update, actor=no_menu_permission, menu_shape=top_level
     *
     * @effects unmanageable_menu_rejected
     *
     * @return void 반환값 없음
     */
    public function test_update_without_menu_permission_is_forbidden_only_with_menu_ids(): void
    {
        $actor = $this->actor('no_menu_permission');
        $role = $this->roleWith([]);
        $top = $this->menu();

        $this->updateRole($actor, $role, ['menu_ids' => [$top->id]])->assertForbidden();
        $this->assertSame([], $this->menuIdsOf($role));

        $this->updateRole($actor, $role, ['name' => ['ko' => '권한 없음 저장', 'en' => 'Saved']])->assertOk();
    }

    /**
     * 관리자 역할에서 메뉴를 빼는 수정은 422 로 거부한다.
     *
     * @effects admin_role_locked
     *
     * @return void 반환값 없음
     */
    public function test_admin_role_cannot_drop_menus(): void
    {
        $actor = $this->actor('super_admin');
        $a = $this->menu();
        $this->menu();

        $this->updateRole($actor, $this->adminRole(), ['menu_ids' => [$a->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.menu_ids.0', __('menu.admin_role_required'));
    }

    /**
     * 상세 응답에는 역할의 메뉴 ID 가 실리고, 목록 응답에는 실리지 않는다(목록 페이로드 축소).
     *
     * @effects menu_ids_on_show_not_list
     *
     * @return void 반환값 없음
     */
    public function test_show_includes_menu_ids_but_list_does_not(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $top = $this->menu([], [$role]);

        $this->actingAs($actor)->getJson('/api/admin/roles/'.$role->id)
            ->assertOk()
            ->assertJsonPath('data.menu_ids', [$top->id]);

        $list = $this->actingAs($actor)->getJson('/api/admin/roles?per_page=100')->assertOk()->json('data.data');
        foreach ($list as $row) {
            $this->assertArrayNotHasKey('menu_ids', $row);
        }
    }

    /**
     * menu_ids 를 보내지 않은 수정은 메뉴 할당을 건드리지 않는다.
     *
     * @effects omitted_field_keeps_assignment
     *
     * @return void 반환값 없음
     */
    public function test_update_without_menu_ids_keeps_menus(): void
    {
        $actor = $this->actor('super_admin');
        $role = $this->roleWith([]);
        $top = $this->menu([], [$role]);

        $this->updateRole($actor, $role, ['name' => ['ko' => '이름만', 'en' => 'Name only']])->assertOk();

        $this->assertSame([$top->id], $this->menuIdsOf($role));
    }
}
