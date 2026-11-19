<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use App\Extension\Auth\PasswordAuthDecision;
use App\Extension\HookManager;
use Plugins\Sirsoft\Sso\Services\Policy\EnforcementPolicy;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 비밀번호 인증 정책(W1~W4, D11, D22) — 강제 단위 4 × 1차/2차 × 비상 계정 × purpose 6.
 *
 * 1차(식별자 기준)는 시도 전에 거절해 실패 횟수가 늘지 않고, 2차(회원 기준)는 비밀번호가 맞을 때만 드러난다.
 *
 * @since 1.0.0
 */
class EnforcePasswordPolicyTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 판정 서비스.
     *
     * @return EnforcementPolicy 서비스
     */
    private function policy(): EnforcementPolicy
    {
        return app(EnforcementPolicy::class);
    }

    /**
     * 「이 도메인 비밀번호 로그인 막기」 IdP 도메인은 시도 전에 403 안내로 거절되고 실패 횟수가 늘지 않는지 확인한다.
     *
     * @scenario unit=domain, phase=identifier, guard=user
     *
     * @effects login_403_with_redirect, failed_attempts_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_domain_enforcement_blocks_before_attempt(): void
    {
        $this->makeIdp('corp', ['enforce_owned_domains' => true]);
        $member = $this->makeMember(['email' => 'kim@corp798.test']);

        $response = $this->postJson('/api/auth/login', ['email' => 'kim@corp798.test', 'password' => 'wrong-password']);

        $response->assertStatus(403);
        $this->assertSame('auth.password_auth_disabled', $response->json('errors.code'));
        $this->assertSame('/plugins/sirsoft-sso/login/corp?guard=user', $response->json('errors.redirect_url') ?? $response->json('data.redirect_url'));
        $this->assertSame(0, (int) $member->fresh()->failed_login_attempts);
    }

    /**
     * 관리자 로그인 경로의 안내 주소는 guard=admin 인지 확인한다.
     *
     * @scenario unit=domain, phase=identifier, guard=admin
     *
     * @effects redirect_guard_admin
     *
     * @return void 반환값 없음
     */
    public function test_domain_enforcement_admin_guard_redirect(): void
    {
        $this->makeIdp('corp', ['enforce_owned_domains' => true]);

        $decision = $this->policy()->decide(['identifier' => 'lee@corp798.test', 'user' => null, 'guard' => 'admin', 'purpose' => 'login']);

        $this->assertFalse($decision->allowed);
        $this->assertSame('/plugins/sirsoft-sso/login/corp?guard=admin', $decision->redirectUrl);
        $this->assertSame(PasswordAuthDecision::BASIS_IDENTIFIER, $decision->basis);
    }

    /**
     * 역할 강제는 비밀번호가 맞은 뒤에만 드러나는지 확인한다 (틀린 비밀번호는 일반 실패 — 열거 방지, W2).
     *
     * @scenario unit=role, phase=user, password=correct|wrong
     *
     * @effects wrong_password_generic_failure, correct_password_403
     *
     * @return void 반환값 없음
     */
    public function test_role_enforcement_only_after_password(): void
    {
        $this->makeIdp('corp');
        $role = $this->makeRole('sso-staff-798');
        $member = $this->makeMember();
        $member->roles()->attach($role->id);
        $this->setPolicy(['enforce_roles' => ['sso-staff-798']]);

        $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => 'wrong-password'])->assertStatus(401);

        $response = $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => $this->password]);
        $response->assertStatus(403);
        $this->assertSame('auth.password_auth_disabled', $response->json('errors.code'));

        $other = $this->makeMember();
        $this->postJson('/api/auth/login', ['email' => $other->email, 'password' => $this->password])->assertStatus(200);
    }

    /**
     * 「SSO 연결 회원 전원」 강제는 활성 연결이 있는 회원만 막는지 확인한다.
     *
     * @scenario unit=linked, phase=user, link=active|inactive|none
     *
     * @effects linked_member_403, inactive_link_allowed, unlinked_allowed
     *
     * @return void 반환값 없음
     */
    public function test_linked_enforcement(): void
    {
        $idp = $this->makeIdp('corp');
        $linked = $this->makeMember();
        $this->linkAccount($linked, $idp);
        $inactive = $this->makeMember();
        $this->linkAccount($inactive, $idp, null, ['active' => false]);
        $plain = $this->makeMember();
        $this->setPolicy(['enforce_linked' => true]);

        $this->postJson('/api/auth/login', ['email' => $linked->email, 'password' => $this->password])->assertStatus(403);
        $this->postJson('/api/auth/login', ['email' => $inactive->email, 'password' => $this->password])->assertStatus(200);
        $this->postJson('/api/auth/login', ['email' => $plain->email, 'password' => $this->password])->assertStatus(200);
    }

    /**
     * 사이트 전체 강제는 모든 회원을 시도 전에 막고 비상 계정만 2단계 인증을 조건으로 통과시키는지 확인한다.
     *
     * @scenario unit=site, phase=identifier|user, emergency=yes|no
     *
     * @effects all_blocked, emergency_allowed_with_2fa
     *
     * @return void 반환값 없음
     */
    public function test_site_enforcement_and_emergency(): void
    {
        $this->makeIdp('corp');
        $member = $this->makeMember();
        $emergency = $this->makeSuper();
        $this->setPolicy(['enforce_site' => true, 'emergency_user_ids' => [$emergency->id]]);

        $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => $this->password])->assertStatus(403);

        $first = $this->policy()->decide(['identifier' => $emergency->email, 'user' => null, 'guard' => 'admin', 'purpose' => 'login']);
        $second = $this->policy()->decide(['identifier' => $emergency->email, 'user' => $emergency, 'guard' => 'admin', 'purpose' => 'login']);

        $this->assertTrue($first->allowed);
        $this->assertTrue($first->requireTwoFactor);
        $this->assertTrue($second->allowed);
        $this->assertTrue($second->requireTwoFactor);
    }

    /**
     * 강제가 모두 꺼져 있으면 판정하지 않는지 확인한다 (코어가 허용, 비상 계정도 2단계 강요 없음).
     *
     * @scenario unit=none, emergency=yes
     *
     * @effects no_opinion
     *
     * @return void 반환값 없음
     */
    public function test_no_enforcement_no_opinion(): void
    {
        $emergency = $this->makeSuper();
        $this->setPolicy(['emergency_user_ids' => [$emergency->id]]);

        $this->assertNull($this->policy()->decide(['identifier' => $emergency->email, 'user' => null, 'guard' => 'user', 'purpose' => 'login']));
        $this->assertNull($this->policy()->decide(['identifier' => 'x@example.test', 'user' => null, 'guard' => 'user', 'purpose' => 'login']));
    }

    /**
     * purpose 6종의 회원 기준 판정을 확인한다 (D22 비밀번호 만들기, W36 재설정).
     *
     * @scenario purpose=login|login_password_change|reset_request|reset_complete|set_password|admin_set_password, allow_local_password=false|true
     *
     * @effects purpose_matrix
     *
     * @return void 반환값 없음
     */
    public function test_purpose_matrix(): void
    {
        $idp = $this->makeIdp('corp', ['allow_local_password' => false]);
        $withPassword = $this->makeMember();
        $this->linkAccount($withPassword, $idp);
        $passwordless = $this->makeMember(['password' => null]);
        $this->linkAccount($passwordless, $idp);

        $decide = fn ($user, string $purpose) => $this->policy()->decide(['identifier' => (string) $user->email, 'user' => $user, 'guard' => 'user', 'purpose' => $purpose]);

        // 강제가 꺼져 있으면 비밀번호 로그인, 로그인 중 변경, 비밀번호 있는 회원의 재설정은 판정하지 않는다.
        $this->assertNull($decide($withPassword, 'login'));
        $this->assertNull($decide($withPassword, 'login_password_change'));
        $this->assertNull($decide($withPassword, 'reset_request'));
        $this->assertNull($decide($withPassword, 'reset_complete'));

        // D22 — 연결 IdP 가 비밀번호 설정을 막으면 만들기, 지정, 비밀번호 없는 회원의 재설정을 거절한다.
        foreach (['set_password', 'admin_set_password'] as $purpose) {
            $this->assertFalse($decide($withPassword, $purpose)->allowed, $purpose);
        }
        foreach (['reset_request', 'reset_complete', 'set_password'] as $purpose) {
            $this->assertFalse($decide($passwordless, $purpose)->allowed, 'passwordless '.$purpose);
        }
        $this->assertSame(EnforcementPolicy::REASON_SET_PASSWORD, $decide($passwordless, 'set_password')->reasonKey);

        // IdP 가 허용하면 판정하지 않는다.
        $idp->update(['allow_local_password' => true]);
        $this->assertNull($decide($passwordless, 'set_password'));
        $this->assertNull($decide($passwordless, 'reset_request'));

        // 강제 대상이면 허용 IdP 여도 거절한다.
        $this->setPolicy(['enforce_linked' => true]);
        $this->assertFalse($decide($withPassword, 'login_password_change')->allowed);
        $this->assertFalse($decide($passwordless, 'set_password')->allowed);
    }

    /**
     * 다른 확장이 먼저 거절한 판정은 그대로 두는지 확인한다.
     *
     * @scenario prior_decision=deny
     *
     * @effects prior_deny_kept
     *
     * @return void 반환값 없음
     */
    public function test_prior_denial_is_kept(): void
    {
        $member = $this->makeMember();
        HookManager::addFilter('core.auth.password_auth_decision', fn ($decision = null) => PasswordAuthDecision::deny(reasonKey: 'other.denied'), 5);

        $result = HookManager::applyFilters('core.auth.password_auth_decision', null, ['identifier' => $member->email, 'user' => null, 'guard' => 'user', 'purpose' => 'login']);

        $this->assertInstanceOf(PasswordAuthDecision::class, $result);
        $this->assertFalse($result->allowed);
        $this->assertSame('other.denied', $result->reasonKey);
    }
}
