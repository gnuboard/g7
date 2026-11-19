<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 사전 등록 회원만(D5) — 연결이 없는 신원은 거절(`not_registered`), 연결된 회원은 로그인.
 *
 * @since 1.0.0
 */
class PreregisteredOnlyTest extends PluginTestCase
{
    /**
     * 사전 등록 회원만 받는 IdP 에서 연결 없는 신원을 거절하는지 확인한다.
     *
     * @scenario provisioning=preregistered, account=none|linked
     *
     * @effects not_registered_error, linked_logs_in
     *
     * @return void 반환값 없음
     */
    public function test_unlinked_identity_is_refused(): void
    {
        $idp = $this->mockOidcIdp(['provisioning_mode' => 'preregistered']);

        $response = $this->roundTrip($idp, 'full', ['sub' => 'pre-1', 'email' => $this->uniqueEmail('corp798.test')]);
        $this->assertSame('not_registered', $this->redirectQuery($response)['sso_error']);
        $this->assertDatabaseHas('sso_login_attempts', ['idp_id' => $idp->id, 'outcome' => 'denied', 'reason_code' => 'not_registered']);

        $user = User::factory()->create();
        $this->link($user, $idp, 'pre-2');
        $this->switchBrowser();
        $this->assertSame($user->id, $this->redeemHandoff($this->roundTrip($idp, 'full', ['sub' => 'pre-2']))['user']->id);
    }
}
