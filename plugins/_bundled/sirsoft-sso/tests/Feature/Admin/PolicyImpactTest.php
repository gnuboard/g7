<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 정책 영향 미리보기 — 새로 강제 대상이 되는 비밀번호 회원 수와 로그아웃될 회원 수(비상 계정 제외, 저장하지 않음).
 *
 * @since 1.0.0
 */
class PolicyImpactTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 연결 회원 전원 강제의 영향을 세는지 확인한다.
     *
     * @scenario change=enforce_linked, members=linked_with_password|linked_passwordless|emergency_linked|unlinked
     *
     * @effects counts_password_users, counts_sessions, emergency_excluded, nothing_saved
     *
     * @return void 반환값 없음
     */
    public function test_impact_counts(): void
    {
        $super = $this->makeSuper();
        $idp = $this->makeIdp('corp');
        $linked = $this->makeMember();
        $this->linkAccount($linked, $idp);
        $linked->createToken('t')->accessToken->forceFill(['login_method' => 'password'])->save();
        $passwordless = $this->makeMember(['password' => null]);
        $this->linkAccount($passwordless, $idp);
        $this->linkAccount($super, $idp);
        $this->makeMember();
        $this->setPolicy(['emergency_user_ids' => [$super->id]]);
        Sanctum::actingAs($super);

        $impact = $this->getJson('/api/plugins/sirsoft-sso/admin/policy/impact?enforce_linked=1')->assertOk()->json('data');

        $this->assertSame(1, $impact['password_users']);
        $this->assertSame(1, $impact['password_sessions']);
        $this->assertFalse($this->getJson('/api/plugins/sirsoft-sso/admin/policy')->json('data.policy.enforce_linked'));
    }
}
