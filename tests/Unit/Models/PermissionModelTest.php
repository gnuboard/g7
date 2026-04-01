<?php

namespace Tests\Unit\Models;

use App\Enums\PermissionType;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permission 모델 테스트
 *
 * Permission 모델의 type 필드 및 관련 메서드를 검증합니다.
 */
class PermissionModelTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // type 필드 테스트
    // ========================================================================

    /**
     * Permission 생성 시 type 필드가 PermissionType Enum으로 캐스팅되는지 확인
     */
    public function test_type_field_is_cast_to_permission_type_enum(): void
    {
        $permission = Permission::create([
            'identifier' => 'test.permission',
            'name' => ['ko' => '테스트 권한', 'en' => 'Test Permission'],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'type' => 'admin',
        ]);

        $this->assertInstanceOf(PermissionType::class, $permission->type);
        $this->assertEquals(PermissionType::Admin, $permission->type);
    }

    /**
     * type 필드에 user 값 저장 및 조회 테스트
     */
    public function test_can_create_permission_with_user_type(): void
    {
        $permission = Permission::create([
            'identifier' => 'test.user.permission',
            'name' => ['ko' => '사용자 권한', 'en' => 'User Permission'],
            'description' => ['ko' => '사용자용', 'en' => 'For users'],
            'type' => 'user',
        ]);

        $this->assertEquals(PermissionType::User, $permission->type);
        $this->assertEquals('user', $permission->type->value);
    }

    /**
     * PermissionType Enum 인스턴스로 type 필드 설정 테스트
     */
    public function test_can_set_type_with_enum_instance(): void
    {
        $permission = Permission::create([
            'identifier' => 'test.enum.permission',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'type' => PermissionType::Admin,
        ]);

        $this->assertEquals(PermissionType::Admin, $permission->type);
    }

    // ========================================================================
    // isAdminPermission() 메서드 테스트
    // ========================================================================

    /**
     * admin 타입 권한에서 isAdminPermission()이 true 반환
     */
    public function test_is_admin_permission_returns_true_for_admin_type(): void
    {
        $permission = Permission::create([
            'identifier' => 'test.admin',
            'name' => ['ko' => '관리자 권한', 'en' => 'Admin Permission'],
            'type' => PermissionType::Admin,
        ]);

        $this->assertTrue($permission->isAdminPermission());
    }

    /**
     * user 타입 권한에서 isAdminPermission()이 false 반환
     */
    public function test_is_admin_permission_returns_false_for_user_type(): void
    {
        $permission = Permission::create([
            'identifier' => 'test.user',
            'name' => ['ko' => '사용자 권한', 'en' => 'User Permission'],
            'type' => PermissionType::User,
        ]);

        $this->assertFalse($permission->isAdminPermission());
    }

    // ========================================================================
    // isUserPermission() 메서드 테스트
    // ========================================================================

    /**
     * user 타입 권한에서 isUserPermission()이 true 반환
     */
    public function test_is_user_permission_returns_true_for_user_type(): void
    {
        $permission = Permission::create([
            'identifier' => 'test.user.perm',
            'name' => ['ko' => '사용자 권한', 'en' => 'User Permission'],
            'type' => PermissionType::User,
        ]);

        $this->assertTrue($permission->isUserPermission());
    }

    /**
     * admin 타입 권한에서 isUserPermission()이 false 반환
     */
    public function test_is_user_permission_returns_false_for_admin_type(): void
    {
        $permission = Permission::create([
            'identifier' => 'test.admin.perm',
            'name' => ['ko' => '관리자 권한', 'en' => 'Admin Permission'],
            'type' => PermissionType::Admin,
        ]);

        $this->assertFalse($permission->isUserPermission());
    }

    // ========================================================================
    // adminPermissions() 스코프 테스트
    // ========================================================================

    /**
     * adminPermissions() 스코프가 admin 타입만 조회하는지 확인
     */
    public function test_admin_permissions_scope_returns_only_admin_type(): void
    {
        // admin 타입 권한 2개 생성
        Permission::create([
            'identifier' => 'test.admin.1',
            'name' => ['ko' => '관리자1', 'en' => 'Admin1'],
            'type' => PermissionType::Admin,
        ]);
        Permission::create([
            'identifier' => 'test.admin.2',
            'name' => ['ko' => '관리자2', 'en' => 'Admin2'],
            'type' => PermissionType::Admin,
        ]);

        // user 타입 권한 1개 생성
        Permission::create([
            'identifier' => 'test.user.1',
            'name' => ['ko' => '사용자1', 'en' => 'User1'],
            'type' => PermissionType::User,
        ]);

        $adminPermissions = Permission::adminPermissions()->get();

        $this->assertCount(2, $adminPermissions);
        $adminPermissions->each(function ($permission) {
            $this->assertEquals(PermissionType::Admin, $permission->type);
        });
    }

    // ========================================================================
    // userPermissions() 스코프 테스트
    // ========================================================================

    /**
     * userPermissions() 스코프가 user 타입만 조회하는지 확인
     */
    public function test_user_permissions_scope_returns_only_user_type(): void
    {
        // admin 타입 권한 1개 생성
        Permission::create([
            'identifier' => 'test.admin.scope',
            'name' => ['ko' => '관리자', 'en' => 'Admin'],
            'type' => PermissionType::Admin,
        ]);

        // user 타입 권한 2개 생성
        Permission::create([
            'identifier' => 'test.user.scope.1',
            'name' => ['ko' => '사용자1', 'en' => 'User1'],
            'type' => PermissionType::User,
        ]);
        Permission::create([
            'identifier' => 'test.user.scope.2',
            'name' => ['ko' => '사용자2', 'en' => 'User2'],
            'type' => PermissionType::User,
        ]);

        $userPermissions = Permission::userPermissions()->get();

        $this->assertCount(2, $userPermissions);
        $userPermissions->each(function ($permission) {
            $this->assertEquals(PermissionType::User, $permission->type);
        });
    }

    // ========================================================================
    // fillable 및 casts 테스트
    // ========================================================================

    /**
     * type 필드가 fillable에 포함되어 있는지 확인
     */
    public function test_type_is_in_fillable(): void
    {
        $permission = new Permission();

        $this->assertContains('type', $permission->getFillable());
    }

    /**
     * type 필드가 올바르게 캐스팅되는지 확인 (casts 배열)
     */
    public function test_type_is_properly_cast(): void
    {
        $permission = Permission::create([
            'identifier' => 'test.cast',
            'name' => ['ko' => '캐스트 테스트', 'en' => 'Cast Test'],
            'type' => 'admin',
        ]);

        // DB에서 다시 조회
        $freshPermission = Permission::find($permission->id);

        // Enum으로 캐스팅되는지 확인
        $this->assertInstanceOf(PermissionType::class, $freshPermission->type);
    }
}
