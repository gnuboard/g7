<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「데이터까지 삭제」 제거 때 확장이 만든 역할이 남지 않는지 검증합니다.
 *
 * 역할 제거는 「역할에 권한이 하나도 없을 때만」 지웠다. 다른 확장이 사용자 권한을 `roles: ['*']`(모든 활성 역할)로 붙이면
 * (이커머스) 그 확장의 역할에도 권한이 붙어, 확장을 데이터까지 삭제해도 역할이 남았다(실측: SSO 역할 `sirsoft-sso.manager`).
 * 데이터까지 삭제할 때는 그 확장이 소유한 역할의 권한·메뉴·회원 연결을 떼고 역할을 지운다. 다른 확장의 역할·권한·회원은 그대로다.
 * 이 경로는 제거의 「데이터까지 삭제」 갈래에서만 불린다(데이터 유지 제거는 역할을 건드리지 않는다).
 *
 * @scenario-manifest tests/scenarios/extension-role-removal-on-delete-data.yaml
 */
class ExtensionRoleRemovalOnDeleteDataTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 확장 소유 역할을 만듭니다.
     *
     * @param  ExtensionOwnerType  $type  확장 종류
     * @param  string  $extension  확장 식별자
     * @param  string  $identifier  역할 식별자
     * @return Role 역할
     */
    private function extensionRole(ExtensionOwnerType $type, string $extension, string $identifier): Role
    {
        return Role::create([
            'identifier' => $identifier,
            'name' => ['ko' => $identifier],
            'description' => ['ko' => $identifier],
            'extension_type' => $type,
            'extension_identifier' => $extension,
            'is_active' => true,
        ]);
    }

    /**
     * 다른 확장(이커머스처럼 `'*'` 로 붙이는 확장)의 권한을 만듭니다.
     *
     * @return Permission 권한
     */
    private function foreignPermission(): Permission
    {
        return Permission::create([
            'identifier' => 'zz-shop.user.orders.read',
            'name' => ['ko' => '주문 조회'],
            'description' => ['ko' => '주문 조회'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'zz-shop',
            'type' => PermissionType::User,
        ]);
    }

    /**
     * 역할에 다른 확장 권한과 회원을 붙여 둡니다.
     *
     * @param  Role  $role  역할
     * @param  Permission  $permission  권한
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    private function attachForeign(Role $role, Permission $permission, User $user): void
    {
        $role->permissions()->attach($permission->id);
        $role->users()->attach($user->id);
    }

    /**
     * 역할 제거 메서드(보호)를 부릅니다.
     *
     * @param  object  $manager  플러그인·모듈 관리자
     * @param  string  $method  메서드 이름
     * @param  object  $extension  확장 인스턴스
     * @return void 반환값 없음
     */
    private function removeRoles(object $manager, string $method, object $extension): void
    {
        $call = new \ReflectionMethod($manager, $method);
        $call->setAccessible(true);
        $call->invoke($manager, $extension);
    }

    /**
     * 플러그인을 데이터까지 삭제하면 다른 확장 권한이 붙은 역할도 연결을 떼고 지운다. 다른 플러그인 역할은 남는다.
     *
     * @scenario owner=plugin
     *
     * @effects extension_role_deleted_with_foreign_links, other_extension_role_untouched
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 플러그인_역할은_다른_확장_권한이_붙어도_지운다(): void
    {
        $permission = $this->foreignPermission();
        $user = User::factory()->create();
        $role = $this->extensionRole(ExtensionOwnerType::Plugin, 'zz-sso', 'zz-sso.manager');
        $other = $this->extensionRole(ExtensionOwnerType::Plugin, 'zz-other', 'zz-other.manager');
        $this->attachForeign($role, $permission, $user);
        $this->attachForeign($other, $permission, $user);

        $plugin = Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getIdentifier')->andReturn('zz-sso');
        $plugin->shouldReceive('getRoles')->andReturn([['identifier' => 'zz-sso.manager']]);

        $this->removeRoles(app(PluginManager::class), 'removePluginRoles', $plugin);

        $this->assertNull(Role::find($role->id));
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $role->id)->count());
        $this->assertSame(0, DB::table('user_roles')->where('role_id', $role->id)->count());
        // 다른 확장의 권한·회원·역할은 그대로
        $this->assertNotNull(Permission::find($permission->id));
        $this->assertNotNull(User::find($user->id));
        $this->assertNotNull(Role::find($other->id));
        $this->assertSame(1, DB::table('user_roles')->where('role_id', $other->id)->count());
    }

    /**
     * 모듈도 같은 규칙이다.
     *
     * @scenario owner=module
     *
     * @effects extension_role_deleted_with_foreign_links, other_extension_role_untouched
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 모듈_역할도_같은_규칙으로_지운다(): void
    {
        $permission = $this->foreignPermission();
        $user = User::factory()->create();
        $role = $this->extensionRole(ExtensionOwnerType::Module, 'zz-board', 'zz-board.manager');
        $other = $this->extensionRole(ExtensionOwnerType::Module, 'zz-other', 'zz-other.manager');
        $this->attachForeign($role, $permission, $user);
        $this->attachForeign($other, $permission, $user);

        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getIdentifier')->andReturn('zz-board');
        $module->shouldReceive('getRoles')->andReturn([['identifier' => 'zz-board.manager']]);

        $this->removeRoles(app(ModuleManager::class), 'removeModuleRoles', $module);

        $this->assertNull(Role::find($role->id));
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $role->id)->count());
        $this->assertSame(0, DB::table('user_roles')->where('role_id', $role->id)->count());
        $this->assertNotNull(Role::find($other->id));
    }

    /**
     * 역할 제거는 「데이터까지 삭제」 갈래(권한 제거)에서만 불린다 — 데이터 유지 제거는 역할을 건드리지 않는다.
     *
     * @scenario owner=plugin|module
     *
     * @effects role_removal_only_on_delete_data
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 역할_제거는_데이터_삭제_갈래에서만_불린다(): void
    {
        foreach ([[PluginManager::class, 'removePluginRoles(', 'removePluginPermissions'], [ModuleManager::class, 'removeModuleRoles(', 'removeModulePermissionsAndMenus']] as [$class, $call, $caller]) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());
            $this->assertSame(2, substr_count($source, $call), "{$class}: 역할 제거 호출은 정의 1 + 권한 제거 안 1 이어야 한다");
            $callerBody = (new \ReflectionMethod($class, $caller));
            $lines = array_slice(explode("\n", $source), $callerBody->getStartLine() - 1, $callerBody->getEndLine() - $callerBody->getStartLine() + 1);
            $this->assertStringContainsString($call, implode("\n", $lines), "{$class}: 역할 제거는 {$caller} 안에서만");
        }
    }
}
