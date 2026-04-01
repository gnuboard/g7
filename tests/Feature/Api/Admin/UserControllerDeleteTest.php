<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin UserController 삭제 테스트
 *
 * 관리자/슈퍼관리자 사용자 삭제 시 구체적 에러 메시지 응답을 검증합니다.
 */
class UserControllerDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 및 삭제 권한을 가진 사용자 생성
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create(['is_super' => true]);

        $permissions = [];
        foreach (['core.users.read', 'core.users.delete'] as $identifier) {
            $permissions[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            );
        }

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        foreach ($permissions as $permission) {
            if (! $adminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
                $adminRole->permissions()->attach($permission->id);
            }
        }

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼 메서드
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 슈퍼 관리자 삭제 시 구체적 에러 메시지 응답
    // ========================================================================

    /**
     * 슈퍼 관리자 삭제 시 422 상태 코드와 구체적 에러 메시지 반환
     */
    public function test_delete_super_admin_returns_specific_error_message(): void
    {
        $superAdmin = User::factory()->create(['is_super' => true]);

        $response = $this->authRequest()
            ->deleteJson("/api/admin/users/{$superAdmin->uuid}");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', __('exceptions.cannot_delete_super_admin'));
    }

    // ========================================================================
    // 관리자 삭제 시 구체적 에러 메시지 응답
    // ========================================================================

    /**
     * 관리자 역할을 가진 사용자 삭제 시 422 상태 코드와 구체적 에러 메시지 반환
     */
    public function test_delete_admin_user_returns_specific_error_message(): void
    {
        // admin 타입 권한 생성
        $adminPermission = Permission::create([
            'identifier' => 'core.admin.delete.test',
            'name' => json_encode(['ko' => '관리자 권한', 'en' => 'Admin Permission']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $role = Role::create([
            'identifier' => 'test-admin-delete',
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
        ]);
        $role->permissions()->attach($adminPermission->id);

        // 관리자 사용자 생성 (is_super=false이지만 admin 권한 보유)
        $adminUser = User::factory()->create(['is_super' => false]);
        $adminUser->roles()->attach($role->id);

        $response = $this->authRequest()
            ->deleteJson("/api/admin/users/{$adminUser->uuid}");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', __('user.delete_admin_forbidden'));
    }

    // ========================================================================
    // 일반 사용자 삭제 성공
    // ========================================================================

    /**
     * 일반 사용자 삭제 시 성공 메시지 반환
     */
    public function test_delete_regular_user_returns_success(): void
    {
        $regularUser = User::factory()->create(['is_super' => false]);

        $response = $this->authRequest()
            ->deleteJson("/api/admin/users/{$regularUser->uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('user.delete_success'));
    }
}
