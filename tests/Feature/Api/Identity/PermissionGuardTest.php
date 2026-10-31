<?php

namespace Tests\Feature\Api\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Concerns\CapturesSentMail;
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
    use CapturesSentMail;
    use RefreshDatabase;

    /**
     * 메일을 격리하고 역할·권한 시드와 본인인증 알림 정의를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 본인인증 메일도 알림이다(7.1.0) — Notification::fake() 는 그 발송 경로 자체를 건너뛰어
        // challenge 가 failed(502) 가 된다. 메일은 Mail::fake() 로 격리하고 정의·메일 채널을 준비한다.
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->prepareIdentityNotifications();
    }

    /**
     * 비로그인 요청도 공개 목적 목록(/api/identity/purposes)을 200 으로 받는다.
     *
     * @effects public_purposes_endpoint_open_without_auth
     *
     * @return void 반환값 없음
     */
    public function test_public_purposes_endpoint_is_accessible_without_auth(): void
    {
        $response = $this->getJson('/api/identity/purposes');
        $response->assertStatus(200);
    }

    /**
     * 비로그인 요청도 공개 프로바이더 목록(/api/identity/providers)을 200 으로 받는다.
     *
     * @effects public_providers_endpoint_open_without_auth
     *
     * @return void 반환값 없음
     */
    public function test_public_providers_endpoint_is_accessible_without_auth(): void
    {
        $response = $this->getJson('/api/identity/providers');
        $response->assertStatus(200);
    }

    /**
     * 비로그인 게스트가 signup 목적 challenge 를 요청하면 guest 역할 권한으로 201 을 받는다.
     *
     * @scenario actor_role=guest, challenge_owner=none, endpoint=request
     *
     * @effects guest_can_request_challenge
     *
     * @return void 반환값 없음
     */
    public function test_challenge_request_works_for_unauthenticated_guests(): void
    {
        // IDV 의 핵심 설계: 가입 전 Mode B 에서도 challenge 발급 가능해야 하므로 optional.sanctum
        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'signup',
            'target' => ['email' => 'guest@example.com'],
        ]);

        $response->assertStatus(201);
    }

    /**
     * 관리자 권한이 없는 일반 회원 토큰은 관리자 본인인증 정책 목록에서 403 을 받는다.
     *
     * @effects member_gets_403_on_admin_identity_policies_index
     *
     * @return void 반환값 없음
     */
    public function test_member_cannot_access_admin_policies_index(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/identity/policies');

        // 일반 사용자는 admin 미들웨어 통과 불가 → 403
        $response->assertStatus(403);
    }

    /**
     * 관리자 권한이 없는 일반 회원 토큰은 관리자 본인인증 이력 목록에서 403 을 받는다.
     *
     * @effects member_gets_403_on_admin_identity_logs_index
     *
     * @return void 반환값 없음
     */
    public function test_member_cannot_access_admin_logs_index(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/identity/logs');

        $response->assertStatus(403);
    }

    /**
     * admin 역할 관리자 토큰은 관리자 본인인증 정책 목록을 200 으로 조회한다.
     *
     * @effects admin_can_access_admin_identity_policies_index
     *
     * @return void 반환값 없음
     */
    public function test_admin_with_policies_permission_can_access_policies_index(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/identity/policies');

        $response->assertStatus(200);
    }

    /**
     * admin 역할 관리자 토큰은 관리자 본인인증 이력 목록을 200 으로 조회한다.
     *
     * @effects admin_can_access_admin_identity_logs_index
     *
     * @return void 반환값 없음
     */
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
     *
     * @return User admin 역할이 붙은 슈퍼 관리자
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

    /**
     * 토큰 없는 요청은 관리자 본인인증 정책·이력·프로바이더 엔드포인트에서 모두 401 을 받는다.
     *
     * @effects unauthenticated_admin_identity_endpoints_return_401
     *
     * @return void 반환값 없음
     */
    public function test_unauthenticated_admin_endpoints_return_401(): void
    {
        $this->getJson('/api/admin/identity/policies')->assertStatus(401);
        $this->getJson('/api/admin/identity/logs')->assertStatus(401);
        $this->getJson('/api/admin/identity/providers')->assertStatus(401);
    }

    /**
     * 게스트의 verify 요청은 권한 가드를 통과하고, 없는 challenge 라 401·403 이 아닌 404 를 받는다.
     *
     * @scenario actor_role=guest, challenge_owner=other, endpoint=verify
     *
     * @effects guest_can_verify_any_challenge, missing_challenge_returns_404_after_guard_pass
     *
     * @return void 반환값 없음
     */
    public function test_guest_can_verify_challenge(): void
    {
        // guest 역할에 core.identity.verify 권한 부여됨 — Mode B 가입 흐름
        // challenge 자체는 미생성이라 ModelNotFoundException → 404. 권한 가드 통과 후 라우트 모델 바인딩이 처리.
        $response = $this->postJson('/api/identity/challenges/00000000-0000-0000-0000-000000000000/verify', [
            'code' => '123456',
        ]);

        $response->assertStatus(404); // 401(권한 거부) 아닌 404(모델 미존재) — 가드는 통과
    }

    /**
     * 게스트의 cancel 요청은 권한 가드를 통과한 뒤 라우트 모델 바인딩에서 404 를 받는다.
     *
     * @scenario actor_role=guest, challenge_owner=other, endpoint=cancel
     *
     * @effects guest_can_cancel_any_challenge, permission_middleware_resolves_challenge_via_route_model_binding
     *
     * @return void 반환값 없음
     */
    public function test_guest_can_cancel_challenge(): void
    {
        // guest 의 cancel 도 동일 — 권한 가드 통과 + 모델 미존재로 404
        $response = $this->postJson('/api/identity/challenges/00000000-0000-0000-0000-000000000000/cancel');

        $response->assertStatus(404);
    }

    /**
     * user 역할 회원은 본인 challenge 를 취소할 수 있다(200).
     *
     * @scenario actor_role=user_with_perm, challenge_owner=self, endpoint=cancel
     *
     * @effects user_with_perm_can_cancel_own_challenge
     *
     * @return void 반환값 없음
     */
    public function test_user_can_cancel_own_challenge(): void
    {
        $user = $this->createUserWithUserRole();
        $token = $user->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/cancel");

        $response->assertStatus(200);
    }

    /**
     * user 역할 회원이 남의 challenge 를 취소하면 scope=self 가드가 403 으로 막는다.
     *
     * @scenario actor_role=user_with_perm, challenge_owner=other, endpoint=cancel
     *
     * @effects user_with_perm_cannot_cancel_others_challenge_403
     *
     * @return void 반환값 없음
     */
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

    /**
     * admin 역할은 scope=null 이라 다른 회원의 challenge 도 취소한다(200).
     *
     * @scenario actor_role=admin, challenge_owner=other, endpoint=cancel
     *
     * @effects admin_can_cancel_any_challenge_via_scope_null
     *
     * @return void 반환값 없음
     */
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

    /**
     * user 역할에서 cancel 권한을 떼면 본인 challenge 취소도 403 이 된다.
     *
     * @scenario actor_role=user_without_perm, challenge_owner=self, endpoint=cancel
     *
     * @effects user_without_perm_gets_403_on_cancel
     *
     * @return void 반환값 없음
     */
    public function test_user_without_identity_permission_gets_403_on_cancel(): void
    {
        $user = $this->createUserWithUserRole();
        $token = $user->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($user);

        // user 역할에서 IDV cancel 권한만 제거 → 가드 차단
        $userRole = Role::where('identifier', 'user')->first();
        $cancelPerm = Permission::where('identifier', 'core.identity.cancel')->first();
        $userRole->permissions()->detach($cancelPerm->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/cancel");

        $response->assertStatus(403);
    }

    /**
     * IDV 권한을 가진 user 역할 회원은 sensitive_action challenge 를 요청해 201 을 받는다.
     *
     * @scenario actor_role=user_with_perm, challenge_owner=none, endpoint=request
     *
     * @effects user_with_perm_can_request_challenge
     *
     * @return void 반환값 없음
     */
    public function test_user_with_permission_can_request_challenge(): void
    {
        $user = $this->createUserWithUserRole();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/identity/challenges', [
                'purpose' => 'sensitive_action',
                'target' => ['email' => $user->email],
            ]);

        $response->assertStatus(201);
    }

    /**
     * user 역할 회원의 본인 challenge 검증은 scope=self 가드를 통과한다(403 아님).
     *
     * @scenario actor_role=user_with_perm, challenge_owner=self, endpoint=verify
     *
     * @effects user_with_perm_can_verify_own_challenge
     *
     * @return void 반환값 없음
     */
    public function test_user_can_verify_own_challenge(): void
    {
        $user = $this->createUserWithUserRole();
        $token = $user->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/verify", [
                'code' => '123456',
            ]);

        // scope=self 가드 통과 — 권한 거부(403)가 아니어야 한다
        $this->assertNotSame(403, $response->getStatusCode());
    }

    /**
     * user 역할 회원이 남의 challenge 를 검증하면 코드가 맞아도 scope=self 가드가 403 으로 막는다.
     *
     * @scenario actor_role=user_with_perm, challenge_owner=other, endpoint=verify
     *
     * @effects user_with_perm_cannot_verify_others_challenge_403
     *
     * @return void 반환값 없음
     */
    public function test_user_cannot_verify_others_challenge(): void
    {
        $owner = $this->createUserWithUserRole();
        $intruder = $this->createUserWithUserRole();
        $token = $intruder->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/verify", [
                'code' => '123456',
            ]);

        // 남의 challenge 는 코드가 맞아도 scope=self 가드에서 먼저 차단된다
        $response->assertStatus(403);
    }

    /**
     * user 역할에서 request 권한을 떼면 challenge 요청이 403 이 된다.
     *
     * @scenario actor_role=user_without_perm, challenge_owner=none, endpoint=request
     *
     * @effects user_without_perm_gets_403_on_request
     *
     * @return void 반환값 없음
     */
    public function test_user_without_permission_gets_403_on_request(): void
    {
        $user = $this->createUserWithUserRole();
        $token = $user->createToken('test')->plainTextToken;

        $this->detachIdentityPermission('core.identity.request');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/identity/challenges', [
                'purpose' => 'sensitive_action',
                'target' => ['email' => $user->email],
            ]);

        $response->assertStatus(403);
    }

    /**
     * user 역할에서 verify 권한을 떼면 본인 challenge 검증도 403 이 된다.
     *
     * @scenario actor_role=user_without_perm, challenge_owner=self, endpoint=verify
     *
     * @effects user_without_perm_gets_403_on_verify
     *
     * @return void 반환값 없음
     */
    public function test_user_without_permission_gets_403_on_verify_own(): void
    {
        $user = $this->createUserWithUserRole();
        $token = $user->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($user);

        $this->detachIdentityPermission('core.identity.verify');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/verify", [
                'code' => '123456',
            ]);

        // 본인 challenge 여도 권한 자체가 없으면 403
        $response->assertStatus(403);
    }

    /**
     * verify 권한이 없는 회원이 남의 challenge 를 검증하면 403 이 된다.
     *
     * @scenario actor_role=user_without_perm, challenge_owner=other, endpoint=verify
     *
     * @effects user_without_perm_gets_403_on_verify
     *
     * @return void 반환값 없음
     */
    public function test_user_without_permission_gets_403_on_verify_others(): void
    {
        $owner = $this->createUserWithUserRole();
        $intruder = $this->createUserWithUserRole();
        $token = $intruder->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($owner);

        $this->detachIdentityPermission('core.identity.verify');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/verify", [
                'code' => '123456',
            ]);

        $response->assertStatus(403);
    }

    /**
     * cancel 권한이 없는 회원이 남의 challenge 를 취소하면 403 이 된다.
     *
     * @scenario actor_role=user_without_perm, challenge_owner=other, endpoint=cancel
     *
     * @effects user_without_perm_gets_403_on_cancel
     *
     * @return void 반환값 없음
     */
    public function test_user_without_permission_gets_403_on_cancel_others(): void
    {
        $owner = $this->createUserWithUserRole();
        $intruder = $this->createUserWithUserRole();
        $token = $intruder->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($owner);

        $this->detachIdentityPermission('core.identity.cancel');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/cancel");

        $response->assertStatus(403);
    }

    /**
     * admin 역할 관리자도 challenge 를 요청해 201 을 받는다.
     *
     * @scenario actor_role=admin, challenge_owner=none, endpoint=request
     *
     * @effects user_with_perm_can_request_challenge
     *
     * @return void 반환값 없음
     */
    public function test_admin_can_request_challenge(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/identity/challenges', [
                'purpose' => 'sensitive_action',
                'target' => ['email' => $admin->email],
            ]);

        $response->assertStatus(201);
    }

    /**
     * admin 역할 관리자의 본인 challenge 검증은 권한 가드를 통과한다(403 아님).
     *
     * @scenario actor_role=admin, challenge_owner=self, endpoint=verify
     *
     * @effects admin_can_verify_any_challenge_via_scope_null
     *
     * @return void 반환값 없음
     */
    public function test_admin_can_verify_own_challenge(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/verify", [
                'code' => '123456',
            ]);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    /**
     * admin 역할은 scope=null 이라 다른 회원의 challenge 검증도 권한 가드를 통과한다(403 아님).
     *
     * @scenario actor_role=admin, challenge_owner=other, endpoint=verify
     *
     * @effects admin_can_verify_any_challenge_via_scope_null
     *
     * @return void 반환값 없음
     */
    public function test_admin_can_verify_any_challenge(): void
    {
        $owner = $this->createUserWithUserRole();
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/verify", [
                'code' => '123456',
            ]);

        // admin 은 scope=null 이라 남의 challenge 도 가드를 통과한다
        $this->assertNotSame(403, $response->getStatusCode());
    }

    /**
     * admin 역할 관리자는 본인 challenge 를 취소할 수 있다(200).
     *
     * @scenario actor_role=admin, challenge_owner=self, endpoint=cancel
     *
     * @effects admin_can_cancel_any_challenge_via_scope_null
     *
     * @return void 반환값 없음
     */
    public function test_admin_can_cancel_own_challenge(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;
        $challenge = $this->createChallengeFor($admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/identity/challenges/{$challenge->id}/cancel");

        $response->assertStatus(200);
    }

    /**
     * user 역할에서 지정 IDV 권한을 떼어냅니다 (권한 미보유 상태 재현).
     *
     * @param  string  $identifier  떼어낼 권한 식별자
     * @return void 반환값 없음
     */
    private function detachIdentityPermission(string $identifier): void
    {
        $userRole = Role::where('identifier', 'user')->first();
        $permission = Permission::where('identifier', $identifier)->first();
        $userRole->permissions()->detach($permission->id);
    }

    /**
     * user 역할이 attach 된 사용자를 생성합니다.
     *
     * RolePermissionSeeder 가 시드한 user 역할 + IDV 권한(scope=self) 부여.
     *
     * @return User user 역할이 붙은 사용자
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
     *
     * @param  User  $user  challenge 소유자
     * @return IdentityVerificationLog 코드 123456 으로 검증 가능한 sent 상태 challenge
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
