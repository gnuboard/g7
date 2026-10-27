<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 자동 생성 이메일은 그 IdP 소유 도메인 주소만 쓴다 — 아니면 subject 해시의 받을 수 없는 주소(W26).
 *
 * @since 1.0.0
 */
class JitEmailDomainTest extends PluginTestCase
{
    /**
     * 자동 생성 회원 이메일은 소유 도메인 주소만 쓰고 밖이면 받을 수 없는 주소를 쓰는지 확인한다.
     *
     * @scenario provisioning=jit, email_domain=owned|foreign
     *
     * @effects owned_used, foreign_replaced_by_invalid
     *
     * @return void 반환값 없음
     */
    public function test_foreign_domain_email_is_not_used(): void
    {
        $idp = $this->mockOidcIdp(['require_consent' => false]);

        $this->roundTrip($idp, 'full', ['sub' => 'dom-1', 'email' => 'boss@gmail.example']);
        $foreign = SsoAccount::query()->where('subject', 'dom-1')->firstOrFail();
        $this->assertStringEndsWith('@sso.invalid', $foreign->user->email);
        $this->assertSame('boss@gmail.example', $foreign->email, 'IdP 가 준 주소는 연결 행에만 남는다');

        $owned = $this->uniqueEmail('corp798.test');
        $this->switchBrowser();
        $this->roundTrip($idp, 'full', ['sub' => 'dom-2', 'email' => $owned]);
        $this->assertSame($owned, SsoAccount::query()->where('subject', 'dom-2')->firstOrFail()->user->email);
    }
}
