<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionOwnerType;
use App\Exceptions\ExtensionOwnedRoleDeleteException;
use App\Exceptions\SystemRoleDeleteException;
use App\Extension\HookManager;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RoleService 삭제 테스트
 *
 * 역할 삭제 시 관계 레코드 명시적 삭제, 예외 처리, 훅 실행을 검증합니다.
 */
class RoleServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoleService $roleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roleService = app(RoleService::class);
        HookManager::resetAll();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    // ========================================================================
    // deleteRole() - 예외 처리 검증
    // ========================================================================

    /**
     * 코어 역할 삭제 시 SystemRoleDeleteException 발생 확인
     */
    public function test_delete_core_role_throws_exception(): void
    {
        $coreRole = Role::create([
            'identifier' => 'core-admin',
            'name' => ['ko' => '코어 관리자', 'en' => 'Core Admin'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);

        $this->expectException(SystemRoleDeleteException::class);

        $this->roleService->deleteRole($coreRole);
    }

    /**
     * 확장 소유 역할 삭제 시 ExtensionOwnedRoleDeleteException 발생 확인
     */
    public function test_delete_extension_owned_role_throws_exception(): void
    {
        $extensionRole = Role::create([
            'identifier' => 'extension-role',
            'name' => ['ko' => '확장 역할', 'en' => 'Extension Role'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-ecommerce',
        ]);

        $this->expectException(ExtensionOwnedRoleDeleteException::class);

        $this->roleService->deleteRole($extensionRole);
    }

    // ========================================================================
    // deleteRole() - 관계 레코드 명시적 삭제 검증
    // ========================================================================

    /**
     * 역할 삭제 시 권한 연결이 해제되는지 확인
     */
    public function test_delete_role_detaches_permissions(): void
    {
        $role = Role::create([
            'identifier' => 'test-perm-detach',
            'name' => ['ko' => '권한 해제 테스트', 'en' => 'Permission Detach Test'],
        ]);

        $permission = Permission::create([
            'identifier' => 'test.permission.detach',
            'name' => ['ko' => '테스트 권한', 'en' => 'Test Permission'],
            'type' => \App\Enums\PermissionType::Admin,
        ]);

        $role->permissions()->attach($permission->id);

        $this->assertDatabaseHas('role_permissions', ['role_id' => $role->id, 'permission_id' => $permission->id]);

        $this->roleService->deleteRole($role);

        $this->assertDatabaseMissing('role_permissions', ['role_id' => $role->id]);
    }

    /**
     * 역할 삭제 시 메뉴 연결이 해제되는지 확인
     */
    public function test_delete_role_detaches_menus(): void
    {
        $role = Role::create([
            'identifier' => 'test-menu-detach',
            'name' => ['ko' => '메뉴 해제 테스트', 'en' => 'Menu Detach Test'],
        ]);

        $menu = Menu::create([
            'slug' => 'test-menu-for-role',
            'name' => ['ko' => '테스트 메뉴', 'en' => 'Test Menu'],
            'url' => '/test',
            'sort_order' => 1,
        ]);

        $role->menus()->attach($menu->id);

        $this->assertDatabaseHas('role_menus', ['role_id' => $role->id, 'menu_id' => $menu->id]);

        $this->roleService->deleteRole($role);

        $this->assertDatabaseMissing('role_menus', ['role_id' => $role->id]);
    }

    /**
     * 역할 삭제 시 사용자 연결이 해제되는지 확인
     */
    public function test_delete_role_detaches_users(): void
    {
        $role = Role::create([
            'identifier' => 'test-user-detach',
            'name' => ['ko' => '사용자 해제 테스트', 'en' => 'User Detach Test'],
        ]);

        $user = User::factory()->create();
        $role->users()->attach($user->id);

        $this->assertDatabaseHas('user_roles', ['role_id' => $role->id, 'user_id' => $user->id]);

        $this->roleService->deleteRole($role);

        $this->assertDatabaseMissing('user_roles', ['role_id' => $role->id]);
    }

    // ========================================================================
    // deleteRole() - 훅 실행 검증
    // ========================================================================

    /**
     * 역할 삭제 시 before_delete/after_delete 훅이 호출되는지 확인
     */
    public function test_delete_role_fires_hooks(): void
    {
        $role = Role::create([
            'identifier' => 'test-hook-role',
            'name' => ['ko' => '훅 테스트 역할', 'en' => 'Hook Test Role'],
        ]);

        $beforeCalled = false;
        $afterCalled = false;

        HookManager::addAction('core.role.before_delete', function ($r) use (&$beforeCalled, $role) {
            $beforeCalled = true;
            $this->assertEquals($role->id, $r->id);
        });

        HookManager::addAction('core.role.after_delete', function ($roleId) use (&$afterCalled, $role) {
            $afterCalled = true;
            $this->assertEquals($role->id, $roleId);
        });

        $this->roleService->deleteRole($role);

        $this->assertTrue($beforeCalled);
        $this->assertTrue($afterCalled);
    }
}
