<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase C.1 (v6) 회귀 방지: helper `cleanupStale*` 가 user_overrides **무관** 으로 삭제하는지.
 *
 * 요구사항 재정의 (v6):
 *  - config/확장 정의에 없는 row 는 user_overrides 유무 무관 삭제
 *  - 필드 단위 보존은 upsert 시점(`syncMenu`/`syncRole`) 에서만 작동
 *  - 예외: Role 에 `user_roles` 피벗 참조 사용자 있으면 삭제 차단 (안전 가드)
 */
class ExtensionSyncHelperUserOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanupStaleMenus_deletes_even_with_user_overrides(): void
    {
        Menu::create([
            'slug' => 'stale-with-overrides',
            'name' => ['ko' => 'user_overrides 있어도 삭제'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'url' => '/admin/stale-overrides',
            'user_overrides' => ['name', 'url'],
            'is_active' => true,
            'order' => 1,
        ]);
        Menu::create([
            'slug' => 'stale-without-overrides',
            'name' => ['ko' => 'user_overrides 없는 삭제'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'url' => '/admin/stale',
            'user_overrides' => [],
            'is_active' => true,
            'order' => 2,
        ]);

        $helper = app(ExtensionMenuSyncHelper::class);
        $deleted = $helper->cleanupStaleMenus(ExtensionOwnerType::Core, 'core', []);

        $this->assertGreaterThanOrEqual(2, $deleted);
        $this->assertDatabaseMissing('menus', ['slug' => 'stale-with-overrides']);
        $this->assertDatabaseMissing('menus', ['slug' => 'stale-without-overrides']);
    }

    public function test_cleanupStalePermissions_deletes_by_pure_diff(): void
    {
        Permission::create([
            'identifier' => 'core.stale.to_keep',
            'name' => ['ko' => '유지'],
            'description' => ['ko' => '유지'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);
        Permission::create([
            'identifier' => 'core.stale.to_delete',
            'name' => ['ko' => '삭제'],
            'description' => ['ko' => '삭제'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $helper = app(ExtensionRoleSyncHelper::class);
        $deleted = $helper->cleanupStalePermissions(
            ExtensionOwnerType::Core,
            'core',
            ['core.stale.to_keep'],
        );

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertDatabaseHas('permissions', ['identifier' => 'core.stale.to_keep']);
        $this->assertDatabaseMissing('permissions', ['identifier' => 'core.stale.to_delete']);
    }

    public function test_cleanupStaleRoles_deletes_even_with_user_overrides(): void
    {
        Role::create([
            'identifier' => 'test-stale-with-overrides',
            'name' => ['ko' => 'user_overrides 있어도 삭제'],
            'description' => ['ko' => '삭제됨'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
            'user_overrides' => ['name'],
        ]);
        Role::create([
            'identifier' => 'test-stale-no-overrides',
            'name' => ['ko' => 'user_overrides 없이 삭제'],
            'description' => ['ko' => '삭제됨'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
            'user_overrides' => [],
        ]);

        $helper = app(ExtensionRoleSyncHelper::class);
        $deleted = $helper->cleanupStaleRoles(ExtensionOwnerType::Core, 'core', []);

        $this->assertGreaterThanOrEqual(2, $deleted);
        $this->assertDatabaseMissing('roles', ['identifier' => 'test-stale-with-overrides']);
        $this->assertDatabaseMissing('roles', ['identifier' => 'test-stale-no-overrides']);
    }

    public function test_cleanupStaleRoles_blocks_deletion_when_users_reference(): void
    {
        $role = Role::create([
            'identifier' => 'test-blocked-role',
            'name' => ['ko' => '차단 역할'],
            'description' => ['ko' => '차단'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
            'user_overrides' => [],
        ]);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        $helper = app(ExtensionRoleSyncHelper::class);
        $helper->cleanupStaleRoles(ExtensionOwnerType::Core, 'core', []);

        $this->assertDatabaseHas('roles', [
            'identifier' => 'test-blocked-role',
        ]);
    }
}
