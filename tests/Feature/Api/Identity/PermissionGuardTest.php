<?php

namespace Tests\Feature\Api\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * IDV 라우트 권한 가드 테스트.
 *
 * permission:user,core.identity.* 미들웨어 + scope=self (verify/cancel) 가드 동작 검증.
 * - 게스트: guest 역할에 IDV 권한 부여되어 request/verify/cancel 모두 통과 (Mode B 가입 흐름)
 * - 일반 user: 본인 challenge 만 verify/cancel 가능 (scope=self)
 * - admin: 다른 사용자 challenge 도 다룰 수 있음 (scope=null)
 * - 관리자 전용 엔드포인트(/api/admin/identity/*): 멤버 토큰은 403, 관리자 토큰은 200
 */
class PermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_public_purposes_endpoint_is_accessible_without_auth(): void
    {
        $response = $this->getJson('/api/identity/purposes');
        $response->assertStatus(200);
    }

    public function test_public_providers_endpoint_is_accessible_without_auth(): void
    {
        $response = $this->getJson('/api/identity/providers');
        $response->assertStatus(200);
    }

    public function test_challenge_request_works_for_unauthenticated_guests(): void
    {
        // IDV 의 핵심 설계: 가입 전 Mode B 에서도 challenge 발급 가능해야 하므로 optional.sanctum
        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'signup',
            'target' => ['email' => 'guest@example.com'],
        ]);

        $response->assertStatus(201);
    }

    public function test_member_cannot_access_admin_policies_index(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/identity/policies');

        // 일반 사용자는 admin 미들웨어 통과 불가 → 403
        $response->assertStatus(403);
    }

    public function test_member_cannot_access_admin_logs_index(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/identity/logs');

        $response->assertStatus(403);
    }

    public function test_admin_with_policies_permission_can_access_policies_index(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/identity/policies');

        $response->assertStatus(200);
    }

    public function test_admin_with_logs_permission_can_access_logs_index(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/identity/logs');

        $response->assertStatus(200);
    }

    /**
     * Admin 역할을 부여한 User 를 생성합니다.
     */
    private function createAdmin(): User
    {
        $admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }

        return $admin->fresh();
    }

    public function test_unauthenticated_admin_endpoints_return_401(): void
    {
        $this->getJson('/api/admin/identity/policies')->assertStatus(401);
        $this->getJson('/api/admin/identity/logs')->assertStatus(401);
        $this->getJson('/api/admin/identity/providers')->assertStatus(401);
    }

    public function test_guest_can_verify_challenge(): void
    {
        // guest 역할에 core.identity.verify 권한 부여됨 — Mode B 가입 흐름
        // challenge 자체는 미생성이라 ModelNotFoundException → 404. 권한 가드 통과 후 라우트 모델 바인딩이 처리.
        $response = $this->postJson('/api/identity/challenges/00000000-0000-0000-0000-000000000000/verify', [
            'code' => '123456',
        ]);

        $response->assertStatus(404); // 401(권한 거부) 아닌 404(모델 미존재) — 가드는 통과
    }

    public function test_guest_can_cancel_challenge(): void
    {
        // guest 의 cancel 도 동일 — 권한 가드 통과 + 모델 미존재로 404
        $response = $this->postJson('/api/identity/challenges/00000000-0000-0000-0000-000000000000/cancel');

        $response->assertStatus(404);
    }

    public function test_user_can_cancel_own_challenge(): void
    {
        $user = $this->createUserWithUserRole();
        $token = $user->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/cancel");

        $response->assertStatus(200);
    }

    public function test_user_cannot_cancel_others_challenge(): void
    {
        $owner = $this->createUserWithUserRole();
        $intruder = $this->createUserWithUserRole();
        $token = $intruder->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/cancel");

        // PermissionMiddleware 의 scope=self 가드 — 본인 user_id 불일치 → 403
        $response->assertStatus(403);
    }

    public function test_admin_can_cancel_any_challenge(): void
    {
        $owner = $this->createUserWithUserRole();
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/cancel");

        // admin 역할은 all_leaf + scope=null → 다른 사용자 challenge 도 cancel 가능
        $response->assertStatus(200);
    }

    public function test_user_without_identity_permission_gets_403_on_cancel(): void
    {
        $user = $this->createUserWithUserRole();
        $token = $user->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($user);

        // user 역할에서 IDV cancel 권한만 제거 → 가드 차단
        $userRole = Role::where('identifier', 'user')->first();
        $cancelPerm = \App\Models\Permission::where('identifier', 'core.identity.cancel')->first();
        $userRole->permissions()->detach($cancelPerm->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/cancel");

        $response->assertStatus(403);
    }

    /**
     * user 역할이 attach 된 사용자를 생성합니다.
     *
     * RolePermissionSeeder 가 시드한 user 역할 + IDV 권한(scope=self) 부여.
     */
    private function createUserWithUserRole(): User
    {
        $user = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $user->roles()->attach($userRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }

        return $user->fresh();
    }

    /**
     * 지정된 사용자 소유의 검증 가능한 challenge 를 생성합니다.
     */
    private function createChallengeFor(User $user): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', $user->email),
            'status' => IdentityVerificationStatus::Sent->value,
            'attempts' => 0,
            'max_attempts' => 5,
            'metadata' => ['code_hash' => Hash::make('123456')],
            'expires_at' => now()->addMinutes(10),
        ]);
    }
}
