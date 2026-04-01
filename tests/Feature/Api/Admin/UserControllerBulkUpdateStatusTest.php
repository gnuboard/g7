<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 사용자 상태 일괄 변경 테스트
 */
class UserControllerBulkUpdateStatusTest extends TestCase
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
     * 관리자 역할 생성 및 할당
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        // 사용자 업데이트 권한 생성
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.update'],
            [
                'name' => json_encode(['ko' => '사용자 수정', 'en' => 'Update Users']),
                'description' => json_encode(['ko' => '사용자 정보 수정 권한', 'en' => 'Permission to update users']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                    'type' => 'admin',
            ]
        );

        // 관리자 역할 생성
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                    'type' => 'admin',
                'is_active' => true,
            ]
        );

        // 역할에 권한 할당
        if (! $adminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
            $adminRole->permissions()->attach($permission->id);
        }

        // 사용자에게 역할 할당
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

    /**
     * 현재 로그인된 사용자가 일괄 변경 대상에 포함되면 422 오류를 반환한다.
     */
    public function test_cannot_bulk_update_current_user_status(): void
    {
        $otherUser = User::factory()->create(['status' => 'active']);

        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$this->admin->uuid, $otherUser->uuid],
            'status' => 'inactive',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);

        // 다른 사용자의 상태도 변경되지 않았는지 확인
        $otherUser->refresh();
        $this->assertEquals('active', $otherUser->status);
    }

    /**
     * 현재 로그인된 사용자를 제외하면 정상적으로 일괄 변경된다.
     */
    public function test_can_bulk_update_other_users_status(): void
    {
        $users = User::factory()->count(3)->create(['status' => 'active']);

        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => $users->pluck('uuid')->toArray(),
            'status' => 'inactive',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);

        // 모든 사용자의 상태가 변경되었는지 확인
        foreach ($users as $user) {
            $user->refresh();
            $this->assertEquals('inactive', $user->status);
        }
    }

    /**
     * 현재 로그인된 사용자만 포함된 경우 422 오류를 반환한다.
     */
    public function test_cannot_bulk_update_only_current_user(): void
    {
        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$this->admin->uuid],
            'status' => 'inactive',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    /**
     * 빈 ID 배열로 요청하면 422 오류를 반환한다.
     */
    public function test_validation_fails_for_empty_ids(): void
    {
        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => [],
            'status' => 'inactive',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 잘못된 상태 값으로 요청하면 422 오류를 반환한다.
     */
    public function test_validation_fails_for_invalid_status(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$user->uuid],
            'status' => 'invalid_status',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 존재하지 않는 사용자 ID로 요청하면 422 오류를 반환한다.
     */
    public function test_validation_fails_for_nonexistent_user_id(): void
    {
        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => ['00000000-0000-0000-0000-000000000000'],
            'status' => 'inactive',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 인증되지 않은 사용자는 401 오류를 반환한다.
     */
    public function test_unauthenticated_user_cannot_bulk_update(): void
    {
        $user = User::factory()->create();

        $response = $this->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$user->uuid],
            'status' => 'inactive',
        ]);

        $response->assertStatus(401);
    }
}
