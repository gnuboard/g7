<?php

namespace Tests\Unit\Models;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * User 모델 테스트
 *
 * User 모델의 is_super 필드 및 관련 메서드를 검증합니다.
 */
class UserModelTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // is_super 필드 테스트
    // ========================================================================

    /**
     * is_super 필드가 fillable에 포함되어 있는지 확인
     */
    public function test_is_super_is_in_fillable(): void
    {
        $user = new User();

        $this->assertContains('is_super', $user->getFillable());
    }

    /**
     * is_super 필드가 boolean으로 캐스팅되는지 확인
     */
    public function test_is_super_is_cast_to_boolean(): void
    {
        $user = User::factory()->create(['is_super' => 1]);

        $this->assertIsBool($user->is_super);
        $this->assertTrue($user->is_super);
    }

    /**
     * is_super 기본값이 false인지 확인
     */
    public function test_is_super_defaults_to_false(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->is_super);
    }

    // ========================================================================
    // isSuperAdmin() 메서드 테스트
    // ========================================================================

    /**
     * is_super가 true인 사용자에서 isSuperAdmin()이 true 반환
     */
    public function test_is_super_admin_returns_true_when_is_super_is_true(): void
    {
        $user = User::factory()->create(['is_super' => true]);

        $this->assertTrue($user->isSuperAdmin());
    }

    /**
     * is_super가 false인 사용자에서 isSuperAdmin()이 false 반환
     */
    public function test_is_super_admin_returns_false_when_is_super_is_false(): void
    {
        $user = User::factory()->create(['is_super' => false]);

        $this->assertFalse($user->isSuperAdmin());
    }

    /**
     * is_super가 null인 사용자에서 isSuperAdmin()이 false 반환
     */
    public function test_is_super_admin_returns_false_when_is_super_is_null(): void
    {
        $user = User::factory()->create();
        // 직접 null로 설정 (DB에서 기본값이 false이지만 null 상황 테스트)
        $user->is_super = null;

        $this->assertFalse($user->isSuperAdmin());
    }

    // ========================================================================
    // isAdmin() 메서드 테스트
    // ========================================================================

    /**
     * admin 타입 권한을 가진 역할이 있으면 isAdmin()이 true 반환
     */
    public function test_is_admin_returns_true_when_user_has_admin_type_permission(): void
    {
        // admin 타입 권한 생성
        $adminPermission = Permission::create([
            'identifier' => 'test.admin.permission',
            'name' => ['ko' => '관리자 권한', 'en' => 'Admin Permission'],
            'type' => PermissionType::Admin,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'test-admin-role',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);
        $role->permissions()->attach($adminPermission->id);

        // 사용자 생성 및 역할 연결
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        $this->assertTrue($user->isAdmin());
    }

    /**
     * user 타입 권한만 가진 경우 isAdmin()이 false 반환
     */
    public function test_is_admin_returns_false_when_user_has_only_user_type_permission(): void
    {
        // user 타입 권한 생성
        $userPermission = Permission::create([
            'identifier' => 'test.user.permission',
            'name' => ['ko' => '사용자 권한', 'en' => 'User Permission'],
            'type' => PermissionType::User,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'test-user-role',
            'name' => ['ko' => '테스트 사용자', 'en' => 'Test User'],
        ]);
        $role->permissions()->attach($userPermission->id);

        // 사용자 생성 및 역할 연결
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        $this->assertFalse($user->isAdmin());
    }

    /**
     * 권한이 없는 사용자에서 isAdmin()이 false 반환
     */
    public function test_is_admin_returns_false_when_user_has_no_permissions(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isAdmin());
    }

    /**
     * 역할이 없는 사용자에서 isAdmin()이 false 반환
     */
    public function test_is_admin_returns_false_when_user_has_no_roles(): void
    {
        $user = User::factory()->create();

        // 역할 없음 확인
        $this->assertEquals(0, $user->roles()->count());
        $this->assertFalse($user->isAdmin());
    }

    /**
     * admin과 user 타입 권한을 모두 가진 경우 isAdmin()이 true 반환
     */
    public function test_is_admin_returns_true_when_user_has_both_admin_and_user_permissions(): void
    {
        // admin 타입 권한
        $adminPermission = Permission::create([
            'identifier' => 'test.mixed.admin',
            'name' => ['ko' => '관리자 권한', 'en' => 'Admin Permission'],
            'type' => PermissionType::Admin,
        ]);

        // user 타입 권한
        $userPermission = Permission::create([
            'identifier' => 'test.mixed.user',
            'name' => ['ko' => '사용자 권한', 'en' => 'User Permission'],
            'type' => PermissionType::User,
        ]);

        // 역할 생성 및 두 권한 모두 연결
        $role = Role::create([
            'identifier' => 'test-mixed-role',
            'name' => ['ko' => '혼합 역할', 'en' => 'Mixed Role'],
        ]);
        $role->permissions()->attach([$adminPermission->id, $userPermission->id]);

        // 사용자 생성 및 역할 연결
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        $this->assertTrue($user->isAdmin());
    }

    // ========================================================================
    // scopeSuperAdmins() 스코프 테스트
    // ========================================================================

    /**
     * superAdmins() 스코프가 is_super=true인 사용자만 조회하는지 확인
     */
    public function test_super_admins_scope_returns_only_super_admins(): void
    {
        // 슈퍼 관리자 2명 생성
        User::factory()->create(['is_super' => true, 'email' => 'super1@test.com']);
        User::factory()->create(['is_super' => true, 'email' => 'super2@test.com']);

        // 일반 사용자 3명 생성
        User::factory()->create(['is_super' => false, 'email' => 'user1@test.com']);
        User::factory()->create(['is_super' => false, 'email' => 'user2@test.com']);
        User::factory()->create(['is_super' => false, 'email' => 'user3@test.com']);

        $superAdmins = User::superAdmins()->get();

        $this->assertCount(2, $superAdmins);
        $superAdmins->each(function ($user) {
            $this->assertTrue($user->is_super);
        });
    }

    /**
     * 슈퍼 관리자가 없으면 빈 컬렉션 반환
     */
    #[Test]
    public function test_super_admins_scope_returns_empty_when_no_super_admins(): void
    {
        // 일반 사용자만 생성
        User::factory()->count(3)->create(['is_super' => false]);

        $superAdmins = User::superAdmins()->get();

        $this->assertCount(0, $superAdmins);
    }

    // ========================================================================
    // 복합 시나리오 테스트
    // ========================================================================

    /**
     * 슈퍼 관리자이면서 관리자 권한도 가진 사용자 테스트
     */
    public function test_user_can_be_both_super_admin_and_have_admin_permissions(): void
    {
        // admin 타입 권한 생성
        $adminPermission = Permission::create([
            'identifier' => 'core.users.read',
            'name' => ['ko' => '사용자 조회', 'en' => 'Read Users'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);
        $role->permissions()->attach($adminPermission->id);

        // 슈퍼 관리자 생성 및 역할 연결
        $user = User::factory()->create(['is_super' => true]);
        $user->roles()->attach($role->id);

        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->isAdmin());
    }

    /**
     * 일반 관리자(is_super=false)이지만 관리자 권한은 가진 사용자 테스트
     */
    public function test_regular_admin_has_admin_permissions_but_not_super(): void
    {
        // admin 타입 권한 생성
        $adminPermission = Permission::create([
            'identifier' => 'core.settings.read',
            'name' => ['ko' => '설정 조회', 'en' => 'Read Settings'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'manager',
            'name' => ['ko' => '매니저', 'en' => 'Manager'],
        ]);
        $role->permissions()->attach($adminPermission->id);

        // 일반 관리자 생성 (is_super=false)
        $user = User::factory()->create(['is_super' => false]);
        $user->roles()->attach($role->id);

        $this->assertFalse($user->isSuperAdmin());
        $this->assertTrue($user->isAdmin());
    }

    // ========================================================================
    // hasPermission() 메서드 type 파라미터 테스트
    // ========================================================================

    /**
     * hasPermission()에서 type을 지정하면 해당 타입의 권한만 체크합니다.
     */
    public function test_has_permission_with_type_checks_specific_type(): void
    {
        // admin 타입 권한 생성
        $adminPermission = Permission::create([
            'identifier' => 'test.permission',
            'name' => ['ko' => '테스트 권한', 'en' => 'Test Permission'],
            'type' => PermissionType::Admin,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'test-role',
            'name' => ['ko' => '테스트 역할', 'en' => 'Test Role'],
        ]);
        $role->permissions()->attach($adminPermission->id);

        // 사용자 생성 및 역할 연결
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        // admin 타입으로 체크하면 true
        $this->assertTrue($user->hasPermission('test.permission', PermissionType::Admin));

        // user 타입으로 체크하면 false
        $this->assertFalse($user->hasPermission('test.permission', PermissionType::User));

        // type 없이 체크하면 true (기존 동작 유지)
        $this->assertTrue($user->hasPermission('test.permission'));
    }

    /**
     * hasPermission()에서 type을 null로 지정하면 모든 타입을 체크합니다.
     */
    public function test_has_permission_without_type_checks_all_types(): void
    {
        // user 타입 권한 생성
        $userPermission = Permission::create([
            'identifier' => 'test.user.only',
            'name' => ['ko' => '사용자 전용 권한', 'en' => 'User Only Permission'],
            'type' => PermissionType::User,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'user-role',
            'name' => ['ko' => '사용자 역할', 'en' => 'User Role'],
        ]);
        $role->permissions()->attach($userPermission->id);

        // 사용자 생성 및 역할 연결
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        // type 없이 체크하면 true
        $this->assertTrue($user->hasPermission('test.user.only'));
        $this->assertTrue($user->hasPermission('test.user.only', null));
    }

    // ========================================================================
    // hasPermissions() 메서드 type 파라미터 테스트
    // ========================================================================

    /**
     * hasPermissions()에서 type을 지정하면 해당 타입의 권한만 체크합니다.
     */
    public function test_has_permissions_with_type_checks_specific_type(): void
    {
        // admin 타입 권한 2개 생성
        $adminPerm1 = Permission::create([
            'identifier' => 'admin.perm1',
            'name' => ['ko' => '관리자 권한1', 'en' => 'Admin Permission 1'],
            'type' => PermissionType::Admin,
        ]);

        $adminPerm2 = Permission::create([
            'identifier' => 'admin.perm2',
            'name' => ['ko' => '관리자 권한2', 'en' => 'Admin Permission 2'],
            'type' => PermissionType::Admin,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'multi-admin-role',
            'name' => ['ko' => '다중 권한 역할', 'en' => 'Multi Permission Role'],
        ]);
        $role->permissions()->attach([$adminPerm1->id, $adminPerm2->id]);

        // 사용자 생성 및 역할 연결
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        // admin 타입으로 AND 체크
        $this->assertTrue(
            $user->hasPermissions(['admin.perm1', 'admin.perm2'], true, PermissionType::Admin)
        );

        // user 타입으로 체크하면 실패
        $this->assertFalse(
            $user->hasPermissions(['admin.perm1', 'admin.perm2'], true, PermissionType::User)
        );
    }

    /**
     * hasPermissions()에서 OR 로직과 type을 함께 사용합니다.
     */
    public function test_has_permissions_with_or_logic_and_type(): void
    {
        // admin 타입 권한 1개만 생성
        $adminPerm = Permission::create([
            'identifier' => 'admin.single',
            'name' => ['ko' => '단일 관리자 권한', 'en' => 'Single Admin Permission'],
            'type' => PermissionType::Admin,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'single-perm-role',
            'name' => ['ko' => '단일 권한 역할', 'en' => 'Single Permission Role'],
        ]);
        $role->permissions()->attach($adminPerm->id);

        // 사용자 생성 및 역할 연결
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        // OR 로직으로 admin 타입 체크 - 하나만 있어도 true
        $this->assertTrue(
            $user->hasPermissions(['admin.single', 'admin.nonexistent'], false, PermissionType::Admin)
        );

        // OR 로직으로 user 타입 체크 - 해당 권한 없으므로 false
        $this->assertFalse(
            $user->hasPermissions(['admin.single', 'admin.nonexistent'], false, PermissionType::User)
        );
    }
}
