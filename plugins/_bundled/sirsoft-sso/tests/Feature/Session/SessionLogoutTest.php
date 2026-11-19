<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Session;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcKeys;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Services\SessionService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;
use Plugins\Sirsoft\Sso\Tests\Support\SsoLoginHelpers;

/**
 * 로그아웃 범위(D15) — `local` 은 G7 만, `rp` 와 `both` 는 1회용 티켓 주소로 IdP 로그아웃, `both` 는 IdP 발 로그아웃(back-channel,
 * SAML SLS 공용 `endFromIdp`)도 받는다. 토큰 id 가 없는 세션은 그 회원의 그 IdP 세션만 끝낸다.
 *
 * @since 1.0.0
 */
class SessionLogoutTest extends PluginTestCase
{
    use ProvisioningTestHelpers;
    use SsoLoginHelpers;

    /**
     * `local` — 로그아웃 응답 `redirect_url` null, 세션 종료.
     *
     * @scenario logout_mode=local
     *
     * @effects redirect_url_null, session_ended
     *
     * @return void 반환값 없음
     */
    public function test_local_logout_ends_session_without_idp_redirect(): void
    {
        $idp = $this->mockOidcIdp(['logout_mode' => 'local']);
        $user = User::factory()->create();
        $login = $this->ssoLogin($user, $this->link($user, $idp, 'lo-local'), $idp);

        $this->logoutWith($login['token'])->assertOk()->assertJsonPath('data.redirect_url', null);

        $session = SsoSession::query()->where('user_id', $user->id)->sole();
        $this->assertNotNull($session->ended_at);
        $this->assertSame(SsoSession::END_LOGOUT, $session->end_reason);
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * `rp` — 티켓 주소 → IdP end-session 302 → 돌아오면 로그인 화면.
     *
     * @scenario logout_mode=rp
     *
     * @effects ticket_url, idp_end_session_redirect, return_to_login
     *
     * @return void 반환값 없음
     */
    public function test_rp_logout_redirects_to_idp_and_returns_to_login(): void
    {
        $idp = $this->mockOidcIdp(['logout_mode' => 'rp']);
        $user = User::factory()->create();
        $login = $this->ssoLogin($user, $this->link($user, $idp, 'lo-rp'), $idp);

        $url = (string) $this->logoutWith($login['token'])->assertOk()->json('data.redirect_url');
        $this->assertStringStartsWith('/plugins/sirsoft-sso/logout/'.$idp->key.'?ticket=', $url);
        $this->assertNotNull(SsoSession::query()->where('user_id', $user->id)->sole()->ended_at);

        $toIdp = $this->get($url)->assertStatus(302);
        $this->assertStringStartsWith(MockOidcController::issuer().'/end-session', (string) $toIdp->headers->get('Location'));

        $back = $this->get('/plugins/sirsoft-sso/logout/return')->assertStatus(302);
        $this->assertSame('/login', $this->redirectPath($back));
    }

    /**
     * 비 SSO 회원의 로그아웃은 그대로(`redirect_url` null).
     *
     * @scenario member=password_login
     *
     * @effects redirect_url_null
     *
     * @return void 반환값 없음
     */
    public function test_non_sso_logout_unchanged(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('plain')->plainTextToken;

        $this->logoutWith($token)->assertOk()->assertJsonPath('data.redirect_url', null);
    }

    /**
     * OIDC back-channel(`both`) — 서명된 logout_token 의 sid 세션 토큰이 사라지고 세션이 끝난다. 같은 jti 재사용 400.
     *
     * @scenario logout_mode=both, channel=backchannel
     *
     * @effects token_revoked, session_ended_idp_logout, replay_400
     *
     * @return void 반환값 없음
     */
    public function test_backchannel_logout_revokes_session_token(): void
    {
        $idp = $this->mockOidcIdp(['logout_mode' => 'both']);
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 'lo-bc');
        $login = $this->ssoLogin($user, $account, $idp, new ExternalIdentity(subject: 'lo-bc', sessionRef: 'sid-bc'));
        $other = $this->ssoLogin($user, $account, $idp, new ExternalIdentity(subject: 'lo-bc', sessionRef: 'sid-other'));

        $logoutToken = app(MockOidcKeys::class)->sign([
            'iss' => MockOidcController::issuer(),
            'aud' => (string) $idp->configValue('client_id'),
            'iat' => time(),
            'jti' => Str::random(32),
            'sid' => 'sid-bc',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass],
        ]);

        $this->post('/plugins/sirsoft-sso/oidc/'.$idp->key.'/backchannel-logout', ['logout_token' => $logoutToken])->assertOk();
        $this->post('/plugins/sirsoft-sso/oidc/'.$idp->key.'/backchannel-logout', ['logout_token' => $logoutToken])->assertStatus(400);

        $tokenIds = $user->tokens()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($login['token_id'], $tokenIds);
        $this->assertContains($other['token_id'], $tokenIds);
        $this->assertSame(SsoSession::END_IDP_LOGOUT, SsoSession::query()->where('external_session', 'sid-bc')->sole()->end_reason);
    }

    /**
     * back-channel 은 `both` 가 아닌 IdP 에서 400.
     *
     * @scenario logout_mode=rp, channel=backchannel
     *
     * @effects backchannel_400
     *
     * @return void 반환값 없음
     */
    public function test_backchannel_requires_both_mode(): void
    {
        $idp = $this->mockOidcIdp(['logout_mode' => 'rp']);

        $this->post('/plugins/sirsoft-sso/oidc/'.$idp->key.'/backchannel-logout', ['logout_token' => 'x.y.z'])->assertStatus(400);
    }

    /**
     * IdP 발 로그아웃(SAML SLS 공용 진입점) — 토큰 id 가 없는 세션이면 그 회원의 그 IdP 열린 세션을 모두 끝내고 다른 IdP 세션은 둔다.
     *
     * @scenario channel=endFromIdp, token_id=null
     *
     * @effects same_idp_sessions_ended, other_idp_session_kept
     *
     * @return void 반환값 없음
     */
    public function test_end_from_idp_without_token_ends_user_idp_sessions(): void
    {
        $a = $this->makeIdp();
        $b = $this->makeIdp();
        $user = User::factory()->create();
        [$t1, $t2] = $this->tokens($user, 2);
        $pending = SsoSession::query()->create(['idp_id' => $a->id, 'user_id' => $user->id, 'token_id' => null, 'name_id' => 'n']);
        $sameIdp = SsoSession::query()->create(['idp_id' => $a->id, 'user_id' => $user->id, 'token_id' => $t1, 'name_id' => 'n']);
        $otherIdp = SsoSession::query()->create(['idp_id' => $b->id, 'user_id' => $user->id, 'token_id' => $t2, 'name_id' => 'n']);

        $ended = app(SessionService::class)->endFromIdp([$pending], SsoSession::END_IDP_LOGOUT);

        $this->assertSame(2, $ended);
        $this->assertNotNull($pending->fresh()->ended_at);
        $this->assertNotNull($sameIdp->fresh()->ended_at);
        $this->assertNull($otherIdp->fresh()->ended_at);
        $this->assertSame([$t2], $user->tokens()->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * JWT 라이브러리 정합 — 모의 키가 RS256 으로 서명한다(시험 전제).
     *
     * @scenario fixture=mock_keys
     *
     * @effects rs256_signed
     *
     * @return void 반환값 없음
     */
    public function test_mock_keys_sign_rs256(): void
    {
        $this->mockOidcIdp();
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', app(MockOidcKeys::class)->sign(['a' => 1]))[0]), true);

        $this->assertSame('RS256', $header['alg']);
    }
}
