<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 사용자 일괄 상태 변경 테스트
 */
class UserBulkStatusTest extends TestCase
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

    protected function tearDown(): void
    {
        // 테스트에서 등록한 Hook 정리
        HookManager::clearAction('sirsoft-core.user.before_bulk_update');
        HookManager::clearAction('sirsoft-core.user.after_bulk_update');

        parent::tearDown();
    }

    /**
     * 관리자 역할 생성 및 할당
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        // 관리자 권한 생성
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
     * 비활성 사용자들을 활성으로 일괄 변경할 수 있다.
     */
    public function test_bulk_activate_users(): void
    {
        // 비활성 사용자 3명 생성
        $users = User::factory()->count(3)->create(['status' => 'inactive']);
        $ids = $users->pluck('uuid')->toArray();

        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => $ids,
            'status' => 'active',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'updated_count',
            ],
        ]);

        $response->assertJson([
            'success' => true,
            'data' => [
                'updated_count' => 3,
            ],
        ]);

        // DB에서 실제로 변경되었는지 확인
        foreach ($ids as $uuid) {
            $this->assertDatabaseHas('users', [
                'uuid' => $uuid,
                'status' => 'active',
            ]);
        }
    }

    /**
     * 활성 사용자들을 비활성으로 일괄 변경할 수 있다.
     */
    public function test_bulk_deactivate_users(): void
    {
        // 활성 사용자 3명 생성
        $users = User::factory()->count(3)->create(['status' => 'active']);
        $ids = $users->pluck('uuid')->toArray();

        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => $ids,
            'status' => 'inactive',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'updated_count' => 3,
            ],
        ]);

        // DB에서 실제로 변경되었는지 확인
        foreach ($ids as $uuid) {
            $this->assertDatabaseHas('users', [
                'uuid' => $uuid,
                'status' => 'inactive',
            ]);
        }
    }

    /**
     * 권한 없는 사용자의 요청은 403 오류를 반환한다.
     */
    public function test_bulk_status_requires_permission(): void
    {
        // 권한 없는 사용자 생성
        $normalUser = User::factory()->create();
        $normalToken = $normalUser->createToken('test-token')->plainTextToken;

        $users = User::factory()->count(2)->create(['status' => 'inactive']);
        $ids = $users->pluck('uuid')->toArray();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$normalToken,
            'Accept' => 'application/json',
        ])->patchJson('/api/admin/users/bulk-status', [
            'ids' => $ids,
            'status' => 'active',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 잘못된 요청 데이터는 422 오류를 반환한다.
     */
    public function test_bulk_status_validates_request(): void
    {
        // ids가 빈 배열인 경우
        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => [],
            'status' => 'active',
        ]);
        $response->assertStatus(422);

        // ids 필드 누락
        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'status' => 'active',
        ]);
        $response->assertStatus(422);

        // status 필드 누락
        $dummyUsers = User::factory()->count(3)->create();
        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => $dummyUsers->pluck('uuid')->toArray(),
        ]);
        $response->assertStatus(422);

        // 잘못된 status 값
        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => $dummyUsers->pluck('uuid')->toArray(),
            'status' => 'invalid_status',
        ]);
        $response->assertStatus(422);

        // 존재하지 않는 사용자 UUID
        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => ['00000000-0000-0000-0000-000000000000'],
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    /**
     * 훅이 올바르게 실행되는지 확인한다.
     */
    public function test_bulk_status_with_hooks(): void
    {
        $users = User::factory()->count(2)->create(['status' => 'inactive']);
        $ids = $users->pluck('uuid')->toArray();

        $beforeHookCalled = false;
        $afterHookCalled = false;

        // before_bulk_update 훅 등록
        app(\App\Extension\HookManager::class)->addAction('sirsoft-core.user.before_bulk_update', function ($hookIds, $hookStatus) use (&$beforeHookCalled, $ids) {
            $beforeHookCalled = true;
            $this->assertEquals($ids, $hookIds);
            $this->assertEquals('active', $hookStatus);
        });

        // after_bulk_update 훅 등록
        app(\App\Extension\HookManager::class)->addAction('sirsoft-core.user.after_bulk_update', function ($hookIds, $hookStatus, $hookUpdatedCount) use (&$afterHookCalled, $ids) {
            $afterHookCalled = true;
            $this->assertEquals($ids, $hookIds);
            $this->assertEquals('active', $hookStatus);
            $this->assertEquals(2, $hookUpdatedCount);
        });

        $response = $this->authRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => $ids,
            'status' => 'active',
        ]);

        $response->assertOk();
        $this->assertTrue($beforeHookCalled, 'before_bulk_update hook should be called');
        $this->assertTrue($afterHookCalled, 'after_bulk_update hook should be called');
    }

    /**
     * 인증되지 않은 사용자는 401 오류를 반환한다.
     */
    public function test_unauthenticated_user_cannot_bulk_update_status(): void
    {
        $users = User::factory()->count(2)->create(['status' => 'inactive']);
        $ids = $users->pluck('uuid')->toArray();

        $response = $this->patchJson('/api/admin/users/bulk-status', [
            'ids' => $ids,
            'status' => 'active',
        ]);

        $response->assertStatus(401);
    }
}
