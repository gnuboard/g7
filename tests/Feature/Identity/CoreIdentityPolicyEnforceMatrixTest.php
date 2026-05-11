<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 코어 IDV 정책 enforce 매트릭스 회귀 (D2~D14).
 *
 * Part B-2 검증 차원:
 * - D2 enforce 매치 / D3 enabled=false skip
 * - D5/D6 signup_stage 매치/불일치
 * - D7/D8 changed_fields 매치/불일치
 * - D9 applies_to=self → admin 우회 / D10 applies_to=admin → 본인 우회 / D11 both 양쪽
 * - D12/D13 grace_minutes 윈도우 통과/차단
 * - D14 fail_mode=block (정책별 block 강제)
 */
class CoreIdentityPolicyEnforceMatrixTest extends TestCase
{
    use RefreshDatabase;

    private IdentityPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(IdentityPolicyService::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityPolicySeeder::class);
    }

    private function regularUser(): User
    {
        return User::factory()->create();
    }

    private function adminUser(): User
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

    /** policy 활성화 후 enforce — 인증 이력 없으므로 정책이 throw 해야 통과 */
    private function assertEnforceThrows(string $policyKey, ?User $user, array $context = []): void
    {
        $policy = $this->enabled($policyKey);

        try {
            $this->service->enforce($policy, $user, $context);
            $this->fail("정책 '{$policyKey}' 가 enforce 시 throw 해야 함");
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame($policyKey, $e->policyKey);
        }
    }

    /** policy 활성화 후 enforce — throw 하지 않아야 통과 */
    private function assertEnforceSkips(string $policyKey, ?User $user, array $context = []): void
    {
        $policy = $this->enabled($policyKey);

        $this->service->enforce($policy, $user, $context);
        $this->assertTrue(true, "정책 '{$policyKey}' enforce 시 throw 하지 않음");
    }

    private function enabled(string $key): IdentityPolicy
    {
        $policy = IdentityPolicy::where('key', $key)->first();
        $this->assertNotNull($policy, "정책 '{$key}' 가 DB 에 없음");
        $policy->enabled = true;
        $policy->save();

        return $policy->fresh();
    }

    private function recordVerified(?User $user, string $purpose, int $minutesAgo = 0): void
    {
        $when = Carbon::now()->subMinutes(max(0, $minutesAgo));

        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => $purpose,
            'channel' => 'email',
            'user_id' => $user?->id,
            'target_hash' => hash('sha256', mb_strtolower($user?->email ?? 'noemail@example.test')),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => $when,
            'expires_at' => $when->copy()->addMinutes(15),
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    // D3 — enabled=false 정책은 enforce 시 통과
    public function test_disabled_policy_skips_enforce(): void
    {
        $policy = IdentityPolicy::where('key', 'core.profile.password_change')->first();
        $policy->enabled = false;
        $policy->save();

        $user = $this->regularUser();
        $this->service->enforce($policy->fresh(), $user, []);
        $this->assertTrue(true);
    }

    // D2 — enabled 정책 + 인증 이력 없음 → throw
    public function test_password_change_enforces_without_verification(): void
    {
        $this->assertEnforceThrows('core.profile.password_change', $this->regularUser());
    }

    // D5/D6 — signup_stage 매치 / 불일치
    public function test_signup_before_submit_matches_only_for_before_submit_stage(): void
    {
        $this->assertEnforceThrows('core.auth.signup_before_submit', $this->regularUser(), [
            'signup_stage' => 'before_submit',
            'http_method' => 'POST',
        ]);
    }

    public function test_signup_before_submit_skips_for_after_create_stage_via_resolve(): void
    {
        // 조건 매칭은 enforce() 가 아닌 resolve() 경로에서 평가됨 (selectMatchingPolicy → policyMatchesContext)
        $this->enabled('core.auth.signup_before_submit');

        $resolved = $this->service->resolve('route', 'api.auth.register', [
            'signup_stage' => 'after_create',
            'http_method' => 'POST',
        ]);

        $this->assertNull($resolved, 'after_create stage 에서는 before_submit 정책이 매칭되지 않아야 함');
    }

    public function test_signup_after_create_matches_only_for_after_create_stage(): void
    {
        $this->assertEnforceThrows('core.auth.signup_after_create', $this->regularUser(), [
            'signup_stage' => 'after_create',
        ]);
    }

    public function test_signup_after_create_skips_for_before_submit_stage_via_resolve(): void
    {
        $this->enabled('core.auth.signup_after_create');

        $resolved = $this->service->resolve('hook', 'core.auth.after_register', [
            'signup_stage' => 'before_submit',
        ]);

        $this->assertNull($resolved, 'before_submit stage 에서는 after_create 정책이 매칭되지 않아야 함');
    }

    // D7/D8 — changed_fields 매치 / 불일치
    public function test_contact_change_matches_when_email_in_changed_fields(): void
    {
        $this->assertEnforceThrows('core.profile.contact_change', $this->regularUser(), [
            'changed_fields' => ['email'],
        ]);
    }

    public function test_contact_change_skips_when_only_nickname_changed_via_resolve(): void
    {
        $this->enabled('core.profile.contact_change');

        $resolved = $this->service->resolve('hook', 'core.user.before_update', [
            'changed_fields' => ['nickname'],
        ]);

        $this->assertNull($resolved, 'changed_fields 교집합이 없으면 정책이 매칭되지 않아야 함');
    }

    // D9 — applies_to=self → admin 우회
    public function test_password_change_self_only_skips_for_admin(): void
    {
        $this->assertEnforceSkips('core.profile.password_change', $this->adminUser(), []);
    }

    public function test_signup_before_submit_self_only_skips_for_admin(): void
    {
        $this->assertEnforceSkips('core.auth.signup_before_submit', $this->adminUser(), [
            'signup_stage' => 'before_submit',
            'http_method' => 'POST',
        ]);
    }

    // D10 — applies_to=admin → 본인 우회
    public function test_app_key_regenerate_admin_only_skips_for_regular_user(): void
    {
        $this->assertEnforceSkips('core.admin.app_key_regenerate', $this->regularUser(), []);
    }

    public function test_user_delete_admin_only_throws_for_admin(): void
    {
        $this->assertEnforceThrows('core.admin.user_delete', $this->adminUser());
    }

    public function test_extension_uninstall_admin_only_skips_for_regular_user(): void
    {
        $this->assertEnforceSkips('core.admin.extension_uninstall', $this->regularUser(), []);
    }

    // D11 — applies_to=both 정책은 self/admin 양쪽 모두 적용
    public function test_password_reset_both_applies_to_self(): void
    {
        $this->assertEnforceThrows('core.auth.password_reset', $this->regularUser(), []);
    }

    public function test_password_reset_both_applies_to_admin(): void
    {
        $this->assertEnforceThrows('core.auth.password_reset', $this->adminUser(), []);
    }

    // D12 — grace_minutes 윈도우 내 인증 이력이 있으면 enforce skip
    public function test_password_change_grace_window_skips_with_recent_verification(): void
    {
        $user = $this->regularUser();
        $this->recordVerified($user, 'sensitive_action', minutesAgo: 3); // grace=5 이내

        $this->assertEnforceSkips('core.profile.password_change', $user, []);
    }

    public function test_contact_change_grace_window_skips_with_recent_verification(): void
    {
        $user = $this->regularUser();
        $this->recordVerified($user, 'sensitive_action', minutesAgo: 4); // grace=5 이내

        $this->assertEnforceSkips('core.profile.contact_change', $user, [
            'changed_fields' => ['email'],
        ]);
    }

    // D13 — grace_minutes 경과 후 인증 이력은 enforce 발동
    public function test_password_change_grace_expired_throws(): void
    {
        $user = $this->regularUser();
        $this->recordVerified($user, 'sensitive_action', minutesAgo: 10); // grace=5 초과

        $this->assertEnforceThrows('core.profile.password_change', $user);
    }

    // D14 — fail_mode=block 인 정책은 throw 발생
    public function test_account_withdraw_fail_mode_block_throws(): void
    {
        $this->assertEnforceThrows('core.account.withdraw', $this->regularUser(), []);
    }

    /**
     * 회귀 — hook scope 정책의 IDV 검증 직후 retry 흐름에서 verification_token 이 enforce()
     * 우회로 동작해야 한다. 미들웨어(scope=route) 에만 적용된 token bypass 가 Service 에 통합되지
     * 않아 listener(scope=hook) 경로에서 동일 회귀가 재발하던 결함 차단.
     *
     * 사례: 사용자 삭제 → 428 IDV → 코드 입력 → verify 성공 → token 부착 후 retry →
     *       EnforceIdentityPolicy 미들웨어는 token 으로 통과 → UserService::deleteUser() →
     *       core.user.before_delete 훅 → Listener::handle → enforce() 가 token 무시 →
     *       grace_minutes=0 정책에서 즉시 다시 428 throw → "본인 확인이 필요합니다." 무한 루프.
     */
    public function test_enforce_bypasses_when_valid_verification_token_in_context(): void
    {
        $policy = $this->enabled('core.admin.user_delete');

        $admin = $this->adminUser();
        $token = 'tok-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => $policy->purpose,
            'channel' => 'email',
            'user_id' => $admin->id,
            'target_hash' => hash('sha256', mb_strtolower($admin->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
            'created_at' => now()->subSeconds(2),
            'updated_at' => now()->subSeconds(2),
        ]);

        // grace_minutes=0 이라 token 우회가 없으면 무조건 throw
        $this->service->enforce($policy, $admin, [
            'verification_token' => $token,
        ]);

        $this->assertTrue(true, 'verification_token 이 context 로 전달되면 enforce 가 통과해야 함');
    }

    /**
     * 회귀 — 관리자가 다른 사용자를 삭제할 때 EnforceIdentityPolicyListener 가
     * applies_to=admin 정책 (core.admin.user_delete) 을 정상 강제해야 한다.
     *
     * 사례: 관리자가 사용자 목록에서 삭제 버튼 클릭 → UserService::deleteUser() 가
     *       HookManager::doAction('core.user.before_delete', $target) 호출 →
     *       Listener::resolveUser() 가 args[0] 의 target 사용자(일반 유저)를 반환 →
     *       enforce() 가 isAdminContext(target)=false 로 판단해 applies_to=admin 정책을 skip →
     *       관리자가 인증 없이 사용자 삭제 가능.
     *
     * 수정: Listener 는 Auth::user() (실제 행위자) 를 우선해야 한다. 인증 컨텍스트가 없을
     *       때만(예: 게스트 password reset 흐름) args 의 User 로 폴백.
     */
    public function test_admin_user_delete_listener_enforces_for_admin_actor_on_other_target(): void
    {
        IdentityPolicy::where('key', 'core.admin.user_delete')->update(['enabled' => true]);

        $admin = $this->adminUser();
        $target = $this->regularUser();
        $this->actingAs($admin);

        $this->expectException(\App\Exceptions\IdentityVerificationRequiredException::class);

        \App\Extension\HookManager::doAction('core.user.before_delete', $target);
    }
}
