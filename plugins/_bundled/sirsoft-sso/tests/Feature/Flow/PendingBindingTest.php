<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 대기 토큰 브라우저 묶기(W8) — 동의 대기, 연결 확인 토큰을 다른 브라우저(다른 묶음 쿠키)가 쓰면 거절하고 토큰을 폐기한다.
 *
 * 묶지 않으면 공격자 신원의 연결 확인 주소를 피해자에게 보내 피해자가 자기 비밀번호를 넣게 하는 계정 탈취가 된다.
 *
 * @since 1.0.0
 */
class PendingBindingTest extends PluginTestCase
{
    /**
     * 다른 브라우저가 쓴 동의 대기 토큰을 거절하고 폐기하는지 확인한다.
     *
     * @scenario pending=consent, browser=other
     *
     * @effects bind_mismatch, token_destroyed
     *
     * @return void 반환값 없음
     */
    public function test_consent_token_from_other_browser_is_destroyed(): void
    {
        $idp = $this->mockOidcIdp();
        $token = $this->redirectQuery($this->roundTrip($idp, 'full', ['sub' => 'pb-1', 'email' => $this->uniqueEmail('corp798.test')]))['sso_pending'];
        $original = $this->browserBind;

        $this->switchBrowser();
        $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/pending/'.$token.'/complete', ['agree_terms' => true, 'agree_privacy' => true])
            ->assertStatus(403)->assertJsonPath('errors.code', 'bind_mismatch');

        // 원래 브라우저도 더는 쓸 수 없다 — 토큰이 폐기됐다.
        $this->browserBind = $original;
        $this->withBrowserBind()->getJson('/api/plugins/sirsoft-sso/pending/'.$token)->assertStatus(404);
        $this->assertDatabaseMissing('sso_accounts', ['subject' => 'pb-1']);
    }

    /**
     * 묶음 쿠키 없는 브라우저가 쓴 연결 확인 토큰을 거절하고 폐기하는지 확인한다.
     *
     * @scenario pending=link, browser=none
     *
     * @effects bind_mismatch, victim_password_never_checked
     *
     * @return void 반환값 없음
     */
    public function test_link_token_without_bind_cookie_is_destroyed(): void
    {
        $victim = User::factory()->create(['email' => $this->uniqueEmail('corp798.test'), 'password' => 'Victim-798!']);
        $idp = $this->mockOidcIdp();
        $token = $this->redirectQuery($this->roundTrip($idp, 'full', ['sub' => 'attacker', 'email' => $victim->email]))['sso_link'];
        $attackerBrowser = $this->browserBind;

        // 주소만 받은 피해자 브라우저(묶음 쿠키 없음)
        $this->withoutBrowserCookies()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/verify', ['method' => 'password', 'password' => 'Victim-798!'])
            ->assertStatus(403)->assertJsonPath('errors.code', 'bind_mismatch');

        $this->browserBind = $attackerBrowser;
        $this->withBrowserBind()->postJson('/api/plugins/sirsoft-sso/link/'.$token.'/verify', ['method' => 'password', 'password' => 'Victim-798!'])
            ->assertStatus(404);
        $this->assertDatabaseMissing('sso_accounts', ['subject' => 'attacker']);
    }
}
