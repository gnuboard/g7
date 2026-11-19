<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 강제를 켤 때 이미 있는 비밀번호 로그인 토큰 회수(W34) — 정책 저장, IdP 「도메인 비밀번호 로그인 막기」 저장 두 경로.
 *
 * @since 1.0.0
 */
class EnforcementTokenRevokeTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 회원의 토큰을 로그인 수단과 함께 만듭니다.
     *
     * @param  \App\Models\User  $user  회원
     * @param  string  $method  로그인 수단 값(login_method)
     * @return int 만든 토큰 번호
     */
    private function token($user, string $method): int
    {
        $token = $user->createToken('t')->accessToken;
        $token->forceFill(['login_method' => $method])->save();

        return (int) $token->id;
    }

    /**
     * 정책 저장으로 새로 강제된 회원의 비밀번호 토큰만 지우는지 확인한다.
     *
     * @scenario path=policy, token=password|sso, member=newly_enforced|emergency|not_enforced
     *
     * @effects password_tokens_revoked, sso_tokens_kept, emergency_kept, others_kept
     *
     * @return void 반환값 없음
     */
    public function test_policy_save_revokes_password_tokens(): void
    {
        $super = $this->makeSuper();
        $idp = $this->makeIdp('corp');
        $linked = $this->makeMember();
        $this->linkAccount($linked, $idp);
        $this->linkAccount($super, $idp);
        $other = $this->makeMember();
        $revoked = $this->token($linked, 'password');
        $kept = $this->token($linked, 'sso:corp');
        $emergencyToken = $this->token($super, 'password');
        $otherToken = $this->token($other, 'password');
        $this->setPolicy(['emergency_user_ids' => [$super->id]]);
        Sanctum::actingAs($super);

        $response = $this->putJson('/api/plugins/sirsoft-sso/admin/policy', ['enforce_linked' => true])->assertOk();

        $this->assertSame(1, $response->json('data.revoked_tokens'));
        $this->assertNull(PersonalAccessToken::query()->find($revoked));
        $this->assertNotNull(PersonalAccessToken::query()->find($kept));
        $this->assertNotNull(PersonalAccessToken::query()->find($emergencyToken));
        $this->assertNotNull(PersonalAccessToken::query()->find($otherToken));
    }

    /**
     * IdP 에 「이 도메인 비밀번호 로그인 막기」를 켜면 그 도메인 회원의 비밀번호 토큰을 지우는지 확인한다.
     *
     * @scenario path=idp_update, enforce_owned_domains=false→true
     *
     * @effects domain_password_tokens_revoked
     *
     * @return void 반환값 없음
     */
    public function test_idp_domain_enforcement_revokes(): void
    {
        $super = $this->makeSuper();
        $idp = $this->makeIdp('corp');
        $member = $this->makeMember(['email' => 'park@corp798.test']);
        $outsider = $this->makeMember();
        $revoked = $this->token($member, 'password');
        $kept = $this->token($outsider, 'password');
        $this->setPolicy(['emergency_user_ids' => [$super->id]]);
        Sanctum::actingAs($super);

        $this->putJson('/api/plugins/sirsoft-sso/admin/idps/'.$idp->id, ['enforce_owned_domains' => true])->assertOk();

        $this->assertNull(PersonalAccessToken::query()->find($revoked));
        $this->assertNotNull(PersonalAccessToken::query()->find($kept));
    }
}
