<?php

namespace Tests\Concerns;

use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Helpers\PermissionHelper;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 역할↔메뉴 일괄 지정 테스트의 공용 픽스처.
 *
 * 테스트 DB 는 시드 없이 트랜잭션으로 격리되므로 역할·권한·메뉴를 테스트가 직접 만든다.
 * 액터 세 종류(최고관리자 · 범위 제한 관리자 · 메뉴 권한 없는 관리자)와 메뉴 모양 네 종류
 * (최상위 · 자식 · 부모+자식 · 비활성 확장)를 만든다.
 */
trait MenuRoleFixtures
{
    /**
     * 코어 권한 정의와 같은 범위 메타데이터로 권한을 만듭니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return Permission 권한
     */
    protected function permission(string $identifier): Permission
    {
        [$routeKey, $ownerKey] = match (true) {
            str_starts_with($identifier, 'core.menus.') => ['menu', 'created_by'],
            str_starts_with($identifier, 'core.users.') => ['user', 'id'],
            default => [null, null],
        };

        $permission = Permission::firstOrCreate(
            ['identifier' => $identifier],
            [
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'type' => 'admin',
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'order' => 1,
                'resource_route_key' => $routeKey,
                'owner_key' => $ownerKey,
            ]
        );
        PermissionHelper::clearPermissionScopeCache();

        return $permission;
    }

    /**
     * 관리자(admin) 역할을 준비합니다.
     *
     * @return Role admin 역할
     */
    protected function adminRole(): Role
    {
        return Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );
    }

    /**
     * 권한 묶음을 가진 역할을 만듭니다.
     *
     * @param  array<string, string|null>  $grants  [권한 식별자 => scope_type(null=전체)]
     * @param  string|null  $identifier  역할 식별자 (null 이면 고유값)
     * @return Role 역할
     */
    protected function roleWith(array $grants, ?string $identifier = null): Role
    {
        $role = Role::create([
            'identifier' => $identifier ?? 'mrf_'.Str::lower(Str::random(10)),
            'name' => ['ko' => '테스트 역할', 'en' => 'Test Role'],
            'is_active' => true,
        ]);

        foreach ($grants as $permissionIdentifier => $scope) {
            $role->permissions()->syncWithoutDetaching([
                $this->permission($permissionIdentifier)->id => ['scope_type' => $scope, 'granted_at' => now()],
            ]);
        }

        return $role;
    }

    /**
     * 종류별 액터를 만듭니다.
     *
     * - super_admin: 최고관리자 + admin 역할(역할·메뉴·회원 권한 전체)
     * - scoped_admin: 역할 권한 전체 + 메뉴 조회 전체 + 메뉴 수정은 본인이 만든 메뉴만(self)
     * - no_menu_permission: 역할 조회·등록·수정만 — 메뉴 조회·수정 권한 없음
     *
     * @param  string  $kind  액터 종류
     * @return User 액터
     */
    protected function actor(string $kind): User
    {
        $user = User::factory()->create(['is_super' => $kind === 'super_admin']);

        $roleGrants = ['core.permissions.read' => null, 'core.permissions.create' => null, 'core.permissions.update' => null];

        $role = match ($kind) {
            'super_admin' => $this->grantToAdmin(array_merge($roleGrants, [
                'core.menus.read' => null, 'core.menus.update' => null,
                'core.users.read' => null, 'core.users.update' => null,
            ])),
            'scoped_admin' => $this->roleWith(array_merge($roleGrants, [
                'core.menus.read' => null, 'core.menus.update' => 'self',
            ])),
            'no_menu_permission' => $this->roleWith($roleGrants),
        };

        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * admin 역할에 권한을 더합니다.
     *
     * @param  array<string, string|null>  $grants  [권한 식별자 => scope_type]
     * @return Role admin 역할
     */
    protected function grantToAdmin(array $grants): Role
    {
        $admin = $this->adminRole();
        foreach ($grants as $permissionIdentifier => $scope) {
            $admin->permissions()->syncWithoutDetaching([
                $this->permission($permissionIdentifier)->id => ['scope_type' => $scope, 'granted_at' => now()],
            ]);
        }

        return $admin;
    }

    /**
     * 메뉴를 만들고 역할을 붙입니다 (admin 역할은 항상 포함).
     *
     * @param  array<string, mixed>  $attributes  메뉴 속성
     * @param  array<int, Role>  $roles  추가로 붙일 역할
     * @return Menu 메뉴
     */
    protected function menu(array $attributes = [], array $roles = []): Menu
    {
        $menu = Menu::create(array_merge([
            'name' => ['ko' => '메뉴', 'en' => 'Menu'],
            'slug' => 'mrf-'.Str::lower(Str::random(12)),
            'url' => '/admin/mrf-'.Str::lower(Str::random(6)),
            'icon' => 'fas fa-circle',
            'order' => 1,
            'is_active' => true,
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ], $attributes));

        foreach (array_merge([$this->adminRole()], $roles) as $role) {
            $menu->roles()->syncWithoutDetaching([$role->id => ['permission_type' => MenuPermissionType::Read->value]]);
        }

        return $menu;
    }

    /**
     * 비활성 플러그인 소유 메뉴를 만듭니다.
     *
     * @param  array<int, Role>  $roles  붙일 역할
     * @return Menu 메뉴
     */
    protected function inactivePluginMenu(array $roles = []): Menu
    {
        $identifier = 'mrf-'.Str::lower(Str::random(6)).'_plugin';
        Plugin::query()->create([
            'identifier' => $identifier,
            'vendor' => 'mrf',
            'name' => ['ko' => '비활성 플러그인', 'en' => 'Inactive Plugin'],
            'version' => '1.0.0',
            'status' => 'inactive',
        ]);

        return $this->menu([
            'extension_type' => ExtensionOwnerType::Plugin,
            'extension_identifier' => $identifier,
        ], $roles);
    }

    /**
     * 역할이 가진 메뉴 ID 목록.
     *
     * @param  Role  $role  역할
     * @return array<int, int> 메뉴 ID (정렬)
     */
    protected function menuIdsOf(Role $role): array
    {
        $ids = $role->menus()->pluck('menus.id')->map(fn ($id) => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    /**
     * 메뉴가 가진 역할 식별자 목록.
     *
     * @param  Menu  $menu  메뉴
     * @return array<int, string> 역할 식별자 (정렬)
     */
    protected function roleIdentifiersOf(Menu $menu): array
    {
        $identifiers = $menu->roles()->pluck('identifier')->all();
        sort($identifiers);

        return $identifiers;
    }
}
