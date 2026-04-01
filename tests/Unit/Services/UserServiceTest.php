<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Exceptions\CannotDeleteAdminException;
use App\Exceptions\CannotDeleteSuperAdminException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UserService 테스트
 *
 * UserService의 사용자 삭제 및 슈퍼 관리자 관련 기능을 검증합니다.
 */
class UserServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserService $userService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userService = app(UserService::class);
    }

    // ========================================================================
    // deleteUser() - 슈퍼 관리자 삭제 방지 테스트
    // ========================================================================

    /**
     * 슈퍼 관리자 삭제 시 CannotDeleteSuperAdminException 발생 확인
     */
    public function test_delete_super_admin_throws_cannot_delete_super_admin_exception(): void
    {
        $superAdmin = User::factory()->create(['is_super' => true]);

        $this->expectException(CannotDeleteSuperAdminException::class);

        $this->userService->deleteUser($superAdmin);
    }

    /**
     * 슈퍼 관리자 삭제 시 예외 메시지가 올바른지 확인
     */
    public function test_cannot_delete_super_admin_exception_has_correct_message(): void
    {
        $superAdmin = User::factory()->create(['is_super' => true]);

        try {
            $this->userService->deleteUser($superAdmin);
            $this->fail('Expected CannotDeleteSuperAdminException was not thrown');
        } catch (CannotDeleteSuperAdminException $e) {
            // 예외 메시지가 다국어 키로 번역되는지 확인
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // ========================================================================
    // deleteUser() - 관리자 삭제 방지 테스트
    // ========================================================================

    /**
     * 관리자 권한을 가진 사용자 삭제 시 CannotDeleteAdminException 발생 확인
     */
    public function test_delete_admin_user_throws_cannot_delete_admin_exception(): void
    {
        // admin 타입 권한 생성
        $adminPermission = Permission::create([
            'identifier' => 'core.admin.test',
            'name' => ['ko' => '관리자 권한', 'en' => 'Admin Permission'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'test-admin',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);
        $role->permissions()->attach($adminPermission->id);

        // 관리자 사용자 생성 (is_super=false이지만 admin 권한 보유)
        $adminUser = User::factory()->create(['is_super' => false]);
        $adminUser->roles()->attach($role->id);

        $this->expectException(CannotDeleteAdminException::class);

        $this->userService->deleteUser($adminUser);
    }

    /**
     * 관리자 삭제 시 예외 메시지가 올바른지 확인
     */
    public function test_cannot_delete_admin_exception_has_correct_message(): void
    {
        $adminPermission = Permission::create([
            'identifier' => 'core.admin.msg.test',
            'name' => ['ko' => '관리자 권한', 'en' => 'Admin Permission'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $role = Role::create([
            'identifier' => 'test-admin-msg',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);
        $role->permissions()->attach($adminPermission->id);

        $adminUser = User::factory()->create(['is_super' => false]);
        $adminUser->roles()->attach($role->id);

        try {
            $this->userService->deleteUser($adminUser);
            $this->fail('Expected CannotDeleteAdminException was not thrown');
        } catch (CannotDeleteAdminException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // ========================================================================
    // deleteUser() - 일반 사용자 삭제 테스트
    // ========================================================================

    /**
     * 일반 사용자는 정상적으로 삭제 가능
     */
    public function test_delete_regular_user_succeeds(): void
    {
        $regularUser = User::factory()->create(['is_super' => false]);

        $result = $this->userService->deleteUser($regularUser);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('users', ['id' => $regularUser->id]);
    }

    /**
     * user 타입 권한만 가진 사용자는 삭제 가능
     */
    public function test_delete_user_with_only_user_type_permissions_succeeds(): void
    {
        // user 타입 권한 생성
        $userPermission = Permission::create([
            'identifier' => 'frontend.user.test',
            'name' => ['ko' => '사용자 권한', 'en' => 'User Permission'],
            'type' => PermissionType::User,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'test-user',
            'name' => ['ko' => '테스트 사용자', 'en' => 'Test User'],
        ]);
        $role->permissions()->attach($userPermission->id);

        // 사용자 생성 및 역할 연결
        $user = User::factory()->create(['is_super' => false]);
        $user->roles()->attach($role->id);

        $result = $this->userService->deleteUser($user);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /**
     * 역할이 없는 사용자도 삭제 가능
     */
    public function test_delete_user_without_roles_succeeds(): void
    {
        $userWithoutRoles = User::factory()->create(['is_super' => false]);

        // 역할이 없는지 확인
        $this->assertEquals(0, $userWithoutRoles->roles()->count());

        $result = $this->userService->deleteUser($userWithoutRoles);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('users', ['id' => $userWithoutRoles->id]);
    }

    // ========================================================================
    // 삭제 우선순위 테스트 (슈퍼 관리자 > 관리자)
    // ========================================================================

    /**
     * 슈퍼 관리자이면서 관리자 권한도 있는 경우, 슈퍼 관리자 예외가 우선
     */
    public function test_super_admin_exception_takes_priority_over_admin_exception(): void
    {
        // admin 타입 권한 생성
        $adminPermission = Permission::create([
            'identifier' => 'core.priority.test',
            'name' => ['ko' => '우선순위 테스트', 'en' => 'Priority Test'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        // 역할 생성 및 권한 연결
        $role = Role::create([
            'identifier' => 'super-admin-role',
            'name' => ['ko' => '슈퍼관리자 역할', 'en' => 'Super Admin Role'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);
        $role->permissions()->attach($adminPermission->id);

        // 슈퍼 관리자이면서 관리자 역할도 가진 사용자
        $superAdminWithRole = User::factory()->create(['is_super' => true]);
        $superAdminWithRole->roles()->attach($role->id);

        // 슈퍼 관리자 예외가 먼저 발생해야 함
        $this->expectException(CannotDeleteSuperAdminException::class);

        $this->userService->deleteUser($superAdminWithRole);
    }

    // ========================================================================
    // deleteUser() - 관계 레코드 명시적 삭제 검증 (CASCADE 의존 금지)
    // ========================================================================

    /**
     * 사용자 삭제 시 역할 연결이 해제되는지 확인
     */
    public function test_delete_user_detaches_roles(): void
    {
        $role = Role::create([
            'identifier' => 'test-detach-role',
            'name' => ['ko' => '역할 해제 테스트', 'en' => 'Detach Test'],
        ]);

        $user = User::factory()->create(['is_super' => false]);
        $user->roles()->attach($role->id);

        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'role_id' => $role->id]);

        $this->userService->deleteUser($user);

        $this->assertDatabaseMissing('user_roles', ['user_id' => $user->id]);
    }

    /**
     * 사용자 삭제 시 약관 동의 이력이 삭제되는지 확인
     */
    public function test_delete_user_deletes_consents(): void
    {
        $user = User::factory()->create(['is_super' => false]);
        UserConsent::create([
            'user_id' => $user->id,
            'consent_type' => 'terms',
            'agreed_at' => now(),
        ]);

        $this->assertDatabaseHas('user_consents', ['user_id' => $user->id]);

        $this->userService->deleteUser($user);

        $this->assertDatabaseMissing('user_consents', ['user_id' => $user->id]);
    }

    /**
     * 사용자 삭제 시 API 토큰이 삭제되는지 확인
     */
    public function test_delete_user_deletes_tokens(): void
    {
        $user = User::factory()->create(['is_super' => false]);
        $user->createToken('test-token');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);

        $this->userService->deleteUser($user);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    // ========================================================================
    // createUser() 테스트 (is_super 필드 처리)
    // ========================================================================

    /**
     * 사용자 생성 시 is_super 필드가 정상적으로 저장되는지 확인
     */
    public function test_create_user_with_is_super_true(): void
    {
        $userData = [
            'name' => 'Super Admin Test',
            'email' => 'superadmin@test.com',
            'password' => 'password123',
            'is_super' => true,
        ];

        $user = $this->userService->createUser($userData);

        $this->assertTrue($user->is_super);
        $this->assertTrue($user->isSuperAdmin());
    }

    /**
     * 사용자 생성 시 is_super가 기본값 false인지 확인
     */
    #[Test]
    public function test_create_user_is_super_defaults_to_false(): void
    {
        $userData = [
            'name' => 'Regular User Test',
            'email' => 'regularuser@test.com',
            'password' => 'password123',
        ];

        $user = $this->userService->createUser($userData);

        $this->assertFalse($user->is_super);
        $this->assertFalse($user->isSuperAdmin());
    }

    // ========================================================================
    // updateUser() 테스트 (is_super 필드 처리)
    // ========================================================================

    /**
     * 사용자 업데이트 시 is_super 필드 변경 가능
     */
    public function test_update_user_can_change_is_super(): void
    {
        $user = User::factory()->create(['is_super' => false]);

        $this->assertFalse($user->is_super);

        $updatedUser = $this->userService->updateUser($user, ['is_super' => true]);

        $this->assertTrue($updatedUser->is_super);
        $this->assertTrue($updatedUser->isSuperAdmin());
    }

    /**
     * 슈퍼 관리자를 일반 사용자로 강등 가능
     */
    public function test_update_user_can_demote_super_admin(): void
    {
        $superAdmin = User::factory()->create(['is_super' => true]);

        $this->assertTrue($superAdmin->isSuperAdmin());

        $updatedUser = $this->userService->updateUser($superAdmin, ['is_super' => false]);

        $this->assertFalse($updatedUser->is_super);
        $this->assertFalse($updatedUser->isSuperAdmin());
    }

    // ========================================================================
    // updateUser() — 역할 할당 권한 체크 테스트
    // ========================================================================

    /**
     * core.permissions.update 권한이 있으면 자기 자신의 역할도 변경 가능
     */
    public function test_update_user_can_change_own_roles_with_permission(): void
    {
        // core.permissions.update 권한을 가진 사용자
        $permUpdatePermission = Permission::create([
            'identifier' => 'core.permissions.update',
            'name' => ['ko' => '권한 수정', 'en' => 'Update Permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $adminRole = Role::create([
            'identifier' => 'test-perm-admin',
            'name' => ['ko' => '권한 관리자', 'en' => 'Permission Admin'],
        ]);
        $adminRole->permissions()->attach($permUpdatePermission->id);

        $newRole = Role::create([
            'identifier' => 'new-role',
            'name' => ['ko' => '새 역할', 'en' => 'New Role'],
        ]);

        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

        // 자기 자신으로 인증
        Auth::login($user);

        // 자기 자신의 역할 변경 시도 (admin 역할이 아니므로 자기잠금 방지 해당 없음)
        $updatedUser = $this->userService->updateUser($user, [
            'name' => 'Updated Name',
            'role_ids' => [$adminRole->id, $newRole->id],
        ]);

        // 이름과 역할 모두 변경되어야 함
        $this->assertEquals('Updated Name', $updatedUser->name);
        $this->assertTrue($updatedUser->roles->contains('id', $adminRole->id));
        $this->assertTrue($updatedUser->roles->contains('id', $newRole->id));
    }

    /**
     * 마지막 admin 역할 사용자가 자기 admin 역할을 제거하려는 경우 차단
     */
    public function test_update_user_prevents_last_admin_role_removal(): void
    {
        $permUpdatePermission = Permission::create([
            'identifier' => 'core.permissions.update',
            'name' => ['ko' => '권한 수정', 'en' => 'Update Permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $adminRole = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Admin'],
        ]);
        $adminRole->permissions()->attach($permUpdatePermission->id);

        $otherRole = Role::create([
            'identifier' => 'other-role',
            'name' => ['ko' => '다른 역할', 'en' => 'Other Role'],
        ]);

        // 유일한 admin 사용자
        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

        Auth::login($user);

        // admin 역할을 제거하고 다른 역할만 남기려는 시도
        $this->expectException(ValidationException::class);

        $this->userService->updateUser($user, [
            'role_ids' => [$otherRole->id],
        ]);
    }

    /**
     * admin 역할을 가진 다른 사용자가 있으면 자기 admin 역할 제거 가능
     */
    public function test_update_user_allows_admin_role_removal_when_other_admins_exist(): void
    {
        $permUpdatePermission = Permission::create([
            'identifier' => 'core.permissions.update',
            'name' => ['ko' => '권한 수정', 'en' => 'Update Permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $adminRole = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Admin'],
        ]);
        $adminRole->permissions()->attach($permUpdatePermission->id);

        $otherRole = Role::create([
            'identifier' => 'other-role-2',
            'name' => ['ko' => '다른 역할', 'en' => 'Other Role'],
        ]);

        // admin 역할을 가진 사용자 2명
        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

        $otherAdmin = User::factory()->create();
        $otherAdmin->roles()->attach($adminRole->id);

        Auth::login($user);

        // 다른 admin이 있으므로 자기 admin 역할 제거 가능
        $updatedUser = $this->userService->updateUser($user, [
            'role_ids' => [$otherRole->id],
        ]);

        $this->assertFalse($updatedUser->roles->contains('id', $adminRole->id));
        $this->assertTrue($updatedUser->roles->contains('id', $otherRole->id));
    }

    /**
     * 마지막 admin 역할 제거 시 ValidationException에 role_ids 에러가 포함되는지 확인
     */
    public function test_last_admin_role_removal_has_role_ids_error_key(): void
    {
        $permUpdatePermission = Permission::create([
            'identifier' => 'core.permissions.update',
            'name' => ['ko' => '권한 수정', 'en' => 'Update Permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $adminRole = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Admin'],
        ]);
        $adminRole->permissions()->attach($permUpdatePermission->id);

        $otherRole = Role::create([
            'identifier' => 'user-role',
            'name' => ['ko' => '사용자', 'en' => 'User'],
        ]);

        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

        Auth::login($user);

        try {
            $this->userService->updateUser($user, [
                'role_ids' => [$otherRole->id],
            ]);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role_ids', $e->errors());
            $this->assertNotEmpty($e->errors()['role_ids']);
        }
    }

    /**
     * core.permissions.update 권한 없으면 타인의 역할 변경 불가 (무시됨)
     */
    public function test_update_user_ignores_role_ids_without_permission(): void
    {
        $originalRole = Role::create([
            'identifier' => 'original-role',
            'name' => ['ko' => '원래 역할', 'en' => 'Original Role'],
        ]);

        $newRole = Role::create([
            'identifier' => 'new-role-test',
            'name' => ['ko' => '새 역할', 'en' => 'New Role'],
        ]);

        $targetUser = User::factory()->create();
        $targetUser->roles()->attach($originalRole->id);

        // 권한 없는 사용자로 인증
        $authUser = User::factory()->create();
        Auth::login($authUser);

        // 타인의 역할 변경 시도
        $updatedUser = $this->userService->updateUser($targetUser, [
            'name' => 'Changed Name',
            'role_ids' => [$newRole->id],
        ]);

        // 이름은 변경되지만 역할은 변경되지 않아야 함
        $this->assertEquals('Changed Name', $updatedUser->name);
        $this->assertTrue($updatedUser->roles->contains('id', $originalRole->id));
        $this->assertFalse($updatedUser->roles->contains('id', $newRole->id));
    }

    /**
     * core.permissions.update 권한이 있으면 타인의 역할 변경 가능
     */
    public function test_update_user_allows_role_change_with_permission(): void
    {
        $permUpdatePermission = Permission::create([
            'identifier' => 'core.permissions.update',
            'name' => ['ko' => '권한 수정', 'en' => 'Update Permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $adminRole = Role::create([
            'identifier' => 'perm-admin-role',
            'name' => ['ko' => '권한 관리 역할', 'en' => 'Permission Admin Role'],
        ]);
        $adminRole->permissions()->attach($permUpdatePermission->id);

        $originalRole = Role::create([
            'identifier' => 'original-role-2',
            'name' => ['ko' => '원래 역할', 'en' => 'Original Role'],
        ]);

        $newRole = Role::create([
            'identifier' => 'target-role',
            'name' => ['ko' => '대상 역할', 'en' => 'Target Role'],
        ]);

        $targetUser = User::factory()->create();
        $targetUser->roles()->attach($originalRole->id);

        // 권한 있는 사용자로 인증
        $authUser = User::factory()->create();
        $authUser->roles()->attach($adminRole->id);
        Auth::login($authUser);

        // 타인의 역할 변경
        $updatedUser = $this->userService->updateUser($targetUser, [
            'role_ids' => [$newRole->id],
        ]);

        // 역할이 새 역할로 변경되어야 함
        $this->assertTrue($updatedUser->roles->contains('id', $newRole->id));
        $this->assertFalse($updatedUser->roles->contains('id', $originalRole->id));
    }

    // ========================================================================
    // createUser() — 역할 할당 권한 체크 테스트
    // ========================================================================

    /**
     * core.permissions.update 권한 없으면 기본 역할(user) 자동 할당
     */
    public function test_create_user_assigns_default_role_without_permission(): void
    {
        // 기본 user 역할 생성
        $userRole = Role::create([
            'identifier' => 'user',
            'name' => ['ko' => '사용자', 'en' => 'User'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        $customRole = Role::create([
            'identifier' => 'custom-role',
            'name' => ['ko' => '커스텀 역할', 'en' => 'Custom Role'],
        ]);

        // 권한 없는 사용자로 인증
        $authUser = User::factory()->create();
        Auth::login($authUser);

        $user = $this->userService->createUser([
            'name' => 'Test User',
            'email' => 'testcreate@test.com',
            'password' => 'password123',
            'role_ids' => [$customRole->id],
        ]);

        // 커스텀 역할 대신 기본 역할이 할당되어야 함
        $this->assertTrue($user->roles->contains('id', $userRole->id));
        $this->assertFalse($user->roles->contains('id', $customRole->id));
    }

    /**
     * core.permissions.update 권한이 있으면 지정한 역할 할당 가능
     */
    public function test_create_user_assigns_specified_roles_with_permission(): void
    {
        $permUpdatePermission = Permission::create([
            'identifier' => 'core.permissions.update',
            'name' => ['ko' => '권한 수정', 'en' => 'Update Permissions'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $adminRole = Role::create([
            'identifier' => 'create-perm-admin',
            'name' => ['ko' => '권한 관리자', 'en' => 'Permission Admin'],
        ]);
        $adminRole->permissions()->attach($permUpdatePermission->id);

        $customRole = Role::create([
            'identifier' => 'assigned-role',
            'name' => ['ko' => '할당 역할', 'en' => 'Assigned Role'],
        ]);

        // 권한 있는 사용자로 인증
        $authUser = User::factory()->create();
        $authUser->roles()->attach($adminRole->id);
        Auth::login($authUser);

        $user = $this->userService->createUser([
            'name' => 'New User',
            'email' => 'newuser@test.com',
            'password' => 'password123',
            'role_ids' => [$customRole->id],
        ]);

        // 지정한 역할이 할당되어야 함
        $this->assertTrue($user->roles->contains('id', $customRole->id));
    }
}
