<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 이메일 먼저 입력 판정(K5, W20, P8) — 도메인만 보고, 응답 모양은 회원 유무와 무관하다.
 *
 * @since 1.0.0
 */
class DiscoveryListenerTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 활성 OIDC IdP 소유 도메인이면 외부 수단과 그 IdP 시작 경로로 답하는지 확인한다.
     *
     * @scenario domain=owned_oidc, member=exists|absent
     *
     * @effects external_with_redirect, same_shape_regardless_of_member
     *
     * @return void 반환값 없음
     */
    public function test_owned_domain_answers_external(): void
    {
        $this->makeIdp('corp');
        $this->makeMember(['email' => 'kim@corp798.test']);

        $existing = $this->postJson('/api/auth/login/discover', ['identifier' => 'kim@corp798.test'])->assertOk()->json('data');
        $absent = $this->postJson('/api/auth/login/discover', ['identifier' => 'nobody@corp798.test'])->assertOk()->json('data');

        $this->assertSame('external', $existing['method']);
        $this->assertSame('/plugins/sirsoft-sso/login/corp?guard=user', $existing['redirect_url']);
        $this->assertSame('본사 계정', $existing['label']['ko']);
        $this->assertSame($existing, $absent);

        $admin = $this->postJson('/api/auth/admin/login/discover', ['identifier' => 'kim@corp798.test'])->assertOk()->json('data');
        $this->assertSame('/plugins/sirsoft-sso/login/corp?guard=admin', $admin['redirect_url']);
    }

    /**
     * 남의 도메인, 꺼진 IdP, LDAP IdP 도메인, 이메일 먼저 입력 끔은 비밀번호로 답하는지 확인한다.
     *
     * @scenario domain=other|disabled_idp|ldap, discovery_enabled=false
     *
     * @effects password_method
     *
     * @return void 반환값 없음
     */
    public function test_password_for_other_cases(): void
    {
        $this->makeIdp('corp');
        $this->makeIdp('off', ['enabled' => false, 'owned_domains' => ['off798.test']]);
        $this->makeIdp('dir', ['protocol' => 'ldap', 'owned_domains' => ['dir798.test']]);

        foreach (['a@example.test', 'b@off798.test', 'c@dir798.test', 'not-an-email'] as $identifier) {
            $this->assertSame('password', $this->postJson('/api/auth/login/discover', ['identifier' => $identifier])->json('data.method'), $identifier);
        }

        $this->setPolicy(['discovery_enabled' => false]);
        $this->assertSame('password', $this->postJson('/api/auth/login/discover', ['identifier' => 'kim@corp798.test'])->json('data.method'));
    }
}
