<?php

namespace Tests\Feature\Auth;

use App\Enums\ExtensionOwnerType;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 사용자 상태(UserStatus) 기반 접근 제한 테스트
 *
 * - 로그인 시 상태 체크
 * - CheckUserStatus 미들웨어
 * - 일괄 상태 변경 시 토큰/타임스탬프
 * - 개별 상태 변경 시 토큰/타임스탬프
 */
class UserStatusAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->adminToken = $this->admin->createToken('test-token')->plainTextToken;
    }

    protected function tearDown(): void
    {
        HookManager::clearAction('sirsoft-core.user.before_bulk_update');
        HookManager::clearAction('sirsoft-core.user.after_bulk_update');

        parent::tearDown();
    }

    /**
     * 관리자 사용자 생성 헬퍼
     *
     * @return User 관리자 사용자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

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

        if (! $adminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
            $adminRole->permissions()->attach($permission->id);
        }

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 관리자 인증 요청 헬퍼
     *
     * @return static
     */
    private function adminRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->adminToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * JSON 요청 헬퍼
     *
     * @return static
     */
    private function jsonRequest(): static
    {
        return $this->withHeaders([
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // A. 로그인 상태 체크 (4개)
    // ========================================================================

    /**
     * Active 사용자는 로그인할 수 있다.
     */
    public function test_active_user_can_login(): void
    {
        User::factory()->create([
            'email' => 'active@example.com',
            'password' => bcrypt('password123'),
            'status' => UserStatus::Active->value,
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'active@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['token']]);
    }

    /**
     * Inactive 사용자는 로그인할 수 없다.
     *
     * AuthService에서 ValidationException을 던지지만,
     * 컨트롤러에서 catch하여 401 unauthorized로 반환 (보안: 상태 미노출)
     */
    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => bcrypt('password123'),
            'status' => UserStatus::Inactive->value,
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    /**
     * Blocked 사용자는 로그인할 수 없다.
     */
    public function test_blocked_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'blocked@example.com',
            'password' => bcrypt('password123'),
            'status' => UserStatus::Blocked->value,
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'blocked@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    /**
     * Withdrawn 사용자는 로그인할 수 없다.
     */
    public function test_withdrawn_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'withdrawn@example.com',
            'password' => bcrypt('password123'),
            'status' => UserStatus::Withdrawn->value,
        ]);

        $response = $this->jsonRequest()->postJson('/api/auth/login', [
            'email' => 'withdrawn@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    // ========================================================================
    // B. CheckUserStatus 미들웨어 (4개)
    // ========================================================================

    /**
     * Active 사용자는 API에 정상 접근할 수 있다.
     */
    public function test_active_user_can_access_api(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Active->value,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/me');

        $response->assertStatus(200);
    }

    /**
     * Inactive 사용자는 API 접근 시 403 응답을 받는다.
     */
    public function test_inactive_user_gets_403(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Active->value,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        // 토큰 생성 후 상태 변경 (미들웨어 테스트용)
        $user->update(['status' => UserStatus::Inactive->value]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/me');

        $response->assertStatus(403);
    }

    /**
     * Blocked 사용자는 API 접근 시 403 응답을 받는다.
     */
    public function test_blocked_user_gets_403(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Active->value,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        // 토큰 생성 후 상태 변경 (미들웨어 테스트용)
        $user->update(['status' => UserStatus::Blocked->value]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/me');

        $response->assertStatus(403);
    }

    /**
     * Guest(미인증)는 미들웨어를 통과한다 (optional.sanctum 환경).
     */
    public function test_guest_passes_middleware(): void
    {
        // user 그룹은 optional.sanctum 적용 - 미인증 요청도 통과해야 함
        $response = $this->jsonRequest()->getJson('/api/user/notifications');

        // guest이므로 미들웨어는 통과하지만, 인증 없이 접근 시 빈 결과 또는 200 반환
        // (401이 아닌 것을 확인 — optional.sanctum이므로)
        $this->assertNotEquals(401, $response->getStatusCode());
        // 403이 아닌 것을 확인 — CheckUserStatus가 guest를 차단하지 않음
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    // ========================================================================
    // C. bulkUpdateStatus 토큰/타임스탬프 (3개)
    // ========================================================================

    /**
     * 일괄 차단 시 토큰이 삭제되고 blocked_at이 설정된다.
     */
    public function test_bulk_block_deletes_tokens_sets_timestamp(): void
    {
        $users = User::factory()->count(2)->create([
            'status' => UserStatus::Active->value,
        ]);

        // 각 사용자에 토큰 생성
        foreach ($users as $user) {
            $user->createToken('user-token');
        }

        $uuids = $users->pluck('uuid')->toArray();
        $intIds = $users->pluck('id')->toArray();

        // 토큰이 존재하는지 확인
        $tokenCount = PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $intIds)
            ->count();
        $this->assertEquals(2, $tokenCount);

        // 일괄 차단
        $response = $this->adminRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => $uuids,
            'status' => UserStatus::Blocked->value,
        ]);

        $response->assertOk();

        // 토큰 삭제 확인
        $tokenCount = PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $intIds)
            ->count();
        $this->assertEquals(0, $tokenCount);

        // blocked_at 설정 확인
        foreach ($users as $user) {
            $user->refresh();
            $this->assertEquals(UserStatus::Blocked->value, $user->status);
            $this->assertNotNull($user->blocked_at);
        }
    }

    /**
     * 일괄 활성화 시 blocked_at/withdrawn_at이 초기화된다.
     */
    public function test_bulk_activate_clears_timestamps(): void
    {
        $users = User::factory()->count(2)->create([
            'status' => UserStatus::Blocked->value,
            'blocked_at' => now(),
        ]);

        $ids = $users->pluck('uuid')->toArray();

        $response = $this->adminRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => $ids,
            'status' => UserStatus::Active->value,
        ]);

        $response->assertOk();

        // 타임스탬프 초기화 확인
        foreach ($users as $user) {
            $user->refresh();
            $this->assertEquals(UserStatus::Active->value, $user->status);
            $this->assertNull($user->blocked_at);
            $this->assertNull($user->withdrawn_at);
        }
    }

    /**
     * 일괄 비활성화 시 기존 타임스탬프가 유지된다.
     */
    public function test_bulk_inactive_preserves_timestamps(): void
    {
        $blockedAt = now()->subDays(5);
        $users = User::factory()->count(2)->create([
            'status' => UserStatus::Active->value,
            'blocked_at' => $blockedAt,
        ]);

        $ids = $users->pluck('uuid')->toArray();

        $response = $this->adminRequest()->patchJson('/api/admin/users/bulk-status', [
            'ids' => $ids,
            'status' => UserStatus::Inactive->value,
        ]);

        $response->assertOk();

        // Inactive 전환 시 타임스탬프 변경 없음 확인
        foreach ($users as $user) {
            $user->refresh();
            $this->assertEquals(UserStatus::Inactive->value, $user->status);
            // blocked_at은 기존 값 유지 (Inactive로 전환 시 건드리지 않음)
            $this->assertNotNull($user->blocked_at);
        }
    }

    // ========================================================================
    // D. updateUser 토큰/타임스탬프 (2개)
    // ========================================================================

    /**
     * 개별 사용자 상태를 Blocked로 변경하면 토큰이 삭제되고 blocked_at이 설정된다.
     */
    public function test_update_status_to_blocked_deletes_tokens(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Active->value,
        ]);

        // 사용자에게 역할 할당 (UpdateUserRequest 검증용)
        $userRole = Role::firstOrCreate(
            ['identifier' => 'user_default'],
            [
                'name' => json_encode(['ko' => '일반 사용자', 'en' => 'Default User']),
                'is_active' => true,
            ]
        );
        $user->roles()->attach($userRole->id, ['assigned_at' => now()]);

        // 사용자 토큰 생성
        $user->createToken('user-token');
        $this->assertEquals(1, $user->tokens()->count());

        // 관리자가 사용자 상태를 Blocked로 변경
        $response = $this->adminRequest()->putJson("/api/admin/users/{$user->uuid}", [
            'name' => $user->name,
            'email' => $user->email,
            'nickname' => $user->nickname,
            'status' => UserStatus::Blocked->value,
            'role_ids' => [$userRole->id],
        ]);

        $response->assertOk();

        // 토큰 삭제 확인
        $user->refresh();
        $this->assertEquals(0, $user->tokens()->count());
        $this->assertNotNull($user->blocked_at);
    }

    /**
     * 개별 사용자 상태를 Active로 변경하면 타임스탬프가 초기화된다.
     */
    public function test_update_status_to_active_clears_timestamps(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Blocked->value,
            'blocked_at' => now(),
        ]);

        // 사용자에게 역할 할당 (UpdateUserRequest 검증용)
        $userRole = Role::firstOrCreate(
            ['identifier' => 'user_default'],
            [
                'name' => json_encode(['ko' => '일반 사용자', 'en' => 'Default User']),
                'is_active' => true,
            ]
        );
        $user->roles()->attach($userRole->id, ['assigned_at' => now()]);

        // 관리자가 사용자 상태를 Active로 변경
        $response = $this->adminRequest()->putJson("/api/admin/users/{$user->uuid}", [
            'name' => $user->name,
            'email' => $user->email,
            'nickname' => $user->nickname,
            'status' => UserStatus::Active->value,
            'role_ids' => [$userRole->id],
        ]);

        $response->assertOk();

        // 타임스탬프 초기화 확인
        $user->refresh();
        $this->assertEquals(UserStatus::Active->value, $user->status);
        $this->assertNull($user->blocked_at);
        $this->assertNull($user->withdrawn_at);
    }
}
