<?php

namespace Tests\Feature\Security;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 메뉴 조회 보조 경로(계층·확장별)가 목록과 같은 범위를 따르는지 검증합니다 (7.1.1, 원장 감사로 발견).
 *
 * `GET admin/menus` 목록은 `core.menus.read` 범위를 건다. 같은 권한으로 열리는 `menus/hierarchy`·
 * `menus/extension/{type}/{identifier}` 는 범위 없이 전체 메뉴와 만든 회원(creator)을 줬다 — 「본인만」 으로 제한된
 * 위임 관리자가 다른 관리자가 만든 메뉴와 그 회원 정보를 받았다.
 */
class MenuReadScopeSidePathTest extends TestCase
{
    use DatabaseTransactions;

    private const EXTENSION = 'zz-menu-scope-test';

    private User $manager;

    private User $superAdmin;

    private Menu $ownMenu;

    private Menu $othersMenu;

    /**
     * 매니저의 메뉴 조회를 「본인만」 으로 좁히고, 매니저·다른 관리자가 만든 메뉴를 하나씩 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        PermissionHelper::clearPermissionScopeCache();

        $managerRole = Role::where('identifier', 'manager')->firstOrFail();
        $permission = Permission::where('identifier', 'core.menus.read')->firstOrFail();
        $managerRole->permissions()->updateExistingPivot($permission->id, ['scope_type' => ScopeType::Self->value]);

        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->roles()->attach($managerRole->id, ['assigned_at' => now()]);
        $this->superAdmin = User::factory()->create(['status' => 'active', 'is_super' => true]);
        $this->superAdmin->roles()->attach(Role::where('identifier', 'admin')->value('id'), ['assigned_at' => now()]);
        $other = User::factory()->create(['status' => 'active']);

        $extension = ['extension_type' => ExtensionOwnerType::Module, 'extension_identifier' => self::EXTENSION];
        $this->ownMenu = Menu::factory()->create(['created_by' => $this->manager->id] + $extension);
        $this->othersMenu = Menu::factory()->create(['created_by' => $other->id] + $extension);
    }

    /**
     * 응답에 실린 메뉴 ID 를 모읍니다(자식 포함).
     *
     * @param  array  $items  응답 data 배열
     * @return array<int, int> 메뉴 ID 목록
     */
    private function menuIds(array $items): array
    {
        $ids = [];
        array_walk_recursive($items, function ($value, $key) use (&$ids) {
            if ($key === 'id' && is_int($value)) {
                $ids[] = $value;
            }
        });

        return $ids;
    }

    /**
     * 메뉴 계층 조회는 「본인만」 관리자에게 본인이 만든 메뉴만 준다.
     *
     * @scenario case=hierarchy_self
     *
     * @effects menu_hierarchy_follows_list_scope
     *
     * @return void 반환값 없음
     */
    public function test_hierarchy_is_scoped_for_self_scoped_manager(): void
    {
        $ids = $this->menuIds((array) $this->actingAs($this->manager)->getJson('/api/admin/menus/hierarchy')->assertStatus(200)->json('data'));

        $this->assertContains($this->ownMenu->id, $ids);
        $this->assertNotContains($this->othersMenu->id, $ids);
    }

    /**
     * 확장별 메뉴 조회도 같은 범위를 따른다.
     *
     * @scenario case=by_extension_self
     *
     * @effects menus_by_extension_follow_list_scope
     *
     * @return void 반환값 없음
     */
    public function test_by_extension_is_scoped_for_self_scoped_manager(): void
    {
        $ids = $this->menuIds((array) $this->actingAs($this->manager)->getJson('/api/admin/menus/extension/module/'.self::EXTENSION)->assertStatus(200)->json('data'));

        $this->assertSame([$this->ownMenu->id], array_values(array_intersect($ids, [$this->ownMenu->id, $this->othersMenu->id])));
    }

    /**
     * 슈퍼 관리자는 두 경로 모두 전체를 본다(과차단 무회귀).
     *
     * @scenario case=super_admin_unscoped
     *
     * @effects super_admin_sees_all_menus
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_sees_all_menus_on_both_paths(): void
    {
        $hierarchy = $this->menuIds((array) $this->actingAs($this->superAdmin)->getJson('/api/admin/menus/hierarchy')->assertStatus(200)->json('data'));
        $byExtension = $this->menuIds((array) $this->actingAs($this->superAdmin)->getJson('/api/admin/menus/extension/module/'.self::EXTENSION)->assertStatus(200)->json('data'));

        foreach ([$hierarchy, $byExtension] as $ids) {
            $this->assertContains($this->ownMenu->id, $ids);
            $this->assertContains($this->othersMenu->id, $ids);
        }
    }
}
