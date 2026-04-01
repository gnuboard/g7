<?php

namespace Tests\Unit\Models;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserScopeTest extends TestCase
{
    use RefreshDatabase;

    private Permission $permission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permission = Permission::create([
            'identifier' => 'test.scope.read',
            'name' => ['ko' => '테스트 스코프 권한', 'en' => 'Test Scope Permission'],
            'description' => ['ko' => '스코프 테스트용', 'en' => 'For scope testing'],
            'type' => PermissionType::Admin,
        ]);
    }

    /**
     * 역할 1개, scope_type=null → null 반환
     */
    public function test_single_role_with_null_scope_returns_null(): void
    {
        $user = $this->createUserWithScope(null);
        $this->assertNull($user->getEffectiveScopeForPermission('test.scope.read'));
    }

    /**
     * 역할 1개, scope_type='self' → 'self' 반환
     */
    public function test_single_role_with_self_scope_returns_self(): void
    {
        $user = $this->createUserWithScope('self');
        $this->assertSame('self', $user->getEffectiveScopeForPermission('test.scope.read'));
    }

    /**
     * 역할 1개, scope_type='role' → 'role' 반환
     */
    public function test_single_role_with_role_scope_returns_role(): void
    {
        $user = $this->createUserWithScope('role');
        $this->assertSame('role', $user->getEffectiveScopeForPermission('test.scope.read'));
    }

    /**
     * 역할 2개: null + self → null (union: 가장 넓은 범위)
     */
    public function test_two_roles_null_and_self_returns_null(): void
    {
        $user = User::factory()->create();

        $role1 = $this->createRoleWithScope('role_null', null);
        $role2 = $this->createRoleWithScope('role_self', 'self');

        $user->roles()->attach([$role1->id, $role2->id]);

        $this->assertNull($user->getEffectiveScopeForPermission('test.scope.read'));
    }

    /**
     * 역할 2개: role + self → 'role' (union)
     */
    public function test_two_roles_role_and_self_returns_role(): void
    {
        $user = User::factory()->create();

        $role1 = $this->createRoleWithScope('role_r', 'role');
        $role2 = $this->createRoleWithScope('role_s', 'self');

        $user->roles()->attach([$role1->id, $role2->id]);

        $this->assertSame('role', $user->getEffectiveScopeForPermission('test.scope.read'));
    }

    /**
     * 역할 2개: null + role → null (union)
     */
    public function test_two_roles_null_and_role_returns_null(): void
    {
        $user = User::factory()->create();

        $role1 = $this->createRoleWithScope('role_null2', null);
        $role2 = $this->createRoleWithScope('role_r2', 'role');

        $user->roles()->attach([$role1->id, $role2->id]);

        $this->assertNull($user->getEffectiveScopeForPermission('test.scope.read'));
    }

    /**
     * 역할 3개: self + self + self → 'self'
     */
    public function test_three_roles_all_self_returns_self(): void
    {
        $user = User::factory()->create();

        $role1 = $this->createRoleWithScope('role_s1', 'self');
        $role2 = $this->createRoleWithScope('role_s2', 'self');
        $role3 = $this->createRoleWithScope('role_s3', 'self');

        $user->roles()->attach([$role1->id, $role2->id, $role3->id]);

        $this->assertSame('self', $user->getEffectiveScopeForPermission('test.scope.read'));
    }

    /**
     * 권한 미보유 → null 반환 (기본값)
     */
    public function test_no_permission_returns_null(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->getEffectiveScopeForPermission('test.scope.read'));
    }

    private function createUserWithScope(?string $scopeType): User
    {
        $user = User::factory()->create();
        $role = $this->createRoleWithScope('role_' . ($scopeType ?? 'null'), $scopeType);
        $user->roles()->attach($role->id);
        return $user;
    }

    private function createRoleWithScope(string $identifier, ?string $scopeType): Role
    {
        $role = Role::create([
            'identifier' => $identifier . '_' . uniqid(),
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'is_active' => true,
        ]);

        $role->permissions()->attach($this->permission->id, [
            'scope_type' => $scopeType,
        ]);

        return $role;
    }
}
