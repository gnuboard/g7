<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Saml;

use App\Models\User;
use App\Support\SiteUrl;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlKeys;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlResponses;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Saml\SamlFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * SAML 단일 로그아웃 — SP 발(LogoutRequest 302)과 IdP 발(SLS 수신: 서명 필수, NameID·SessionIndex 로 세션 종료, LogoutResponse).
 *
 * @since 1.0.0
 */
class SamlSloTest extends PluginTestCase
{
    use SamlFixtures;

    /**
     * 끝나지 않은 SSO 세션 1행을 그 세션의 G7 토큰과 함께 만듭니다.
     *
     * 토큰이 없는 세션(2단계 인증 중 이탈)은 세션 서비스가 그 회원의 그 IdP 세션 전체를 끝내므로(계획서 §6.14), 세션 하나만 고르는
     * 시험은 토큰을 붙인다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  User  $user  회원
     * @param  string  $nameId  세션의 NameID
     * @param  string  $sessionIndex  SAML 세션 색인(SessionIndex)
     * @return SsoSession 세션
     */
    private function openSession(IdentityProvider $idp, User $user, string $nameId, string $sessionIndex): SsoSession
    {
        return SsoSession::query()->create([
            'idp_id' => $idp->id,
            'user_id' => $user->id,
            'token_id' => $user->createToken('saml-slo-'.$sessionIndex)->accessToken->getKey(),
            'external_session' => $sessionIndex,
            'name_id' => $nameId,
        ]);
    }

    /**
     * 서명된 IdP 발 LogoutRequest 는 그 NameID·SessionIndex 세션만 끝내고 IdP 의 SLO 로 LogoutResponse 를 보낸다.
     *
     * @scenario direction=idp_to_sp, signed=true
     *
     * @effects matching_session_ended, other_session_kept, logout_response_redirect
     *
     * @return void 반환값 없음
     */
    public function test_signed_idp_logout_ends_matching_session(): void
    {
        $idp = $this->mockSamlIdp();
        $user = User::factory()->create();
        $target = $this->openSession($idp, $user, 'saml-slo-1', '_s_target');
        $other = $this->openSession($idp, $user, 'saml-slo-1', '_s_other');

        $url = MockSamlResponses::logoutRequestUrl(SiteUrl::absolute(SamlDriver::slsPath($idp)), MockSamlController::entityId(), 'saml-slo-1', '_s_target', app(MockSamlKeys::class)->pair(), 'rs-1');
        $response = $this->get(substr($url, strlen(SiteUrl::base())));

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(MockSamlController::entityId().'/slo?SAMLResponse=', $location);
        $this->assertStringContainsString('RelayState=rs-1', $location);
        $this->assertNotNull($target->fresh()->ended_at, '같은 SessionIndex 세션은 끝난다');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $target->token_id]);
        $this->assertNull($other->fresh()->ended_at, '다른 SessionIndex 세션은 남는다');
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->token_id]);
    }

    /**
     * 서명 없는 요청과 다른 키로 서명한 요청은 400 이고 세션을 끝내지 않는다.
     *
     * @scenario direction=idp_to_sp, signed=false|wrong_key
     *
     * @effects rejected_400, session_kept
     *
     * @return void 반환값 없음
     */
    public function test_unsigned_or_forged_logout_is_rejected(): void
    {
        $idp = $this->mockSamlIdp();
        $user = User::factory()->create();
        $session = $this->openSession($idp, $user, 'saml-slo-2', '_s2');
        $sls = SiteUrl::absolute(SamlDriver::slsPath($idp));

        $unsigned = MockSamlResponses::logoutRequestUrl($sls, MockSamlController::entityId(), 'saml-slo-2', '_s2', null);
        $this->get(substr($unsigned, strlen(SiteUrl::base())))->assertStatus(400);

        $forged = MockSamlResponses::logoutRequestUrl($sls, MockSamlController::entityId(), 'saml-slo-2', '_s2', app(MockSamlKeys::class)->pair('other'));
        $this->get(substr($forged, strlen(SiteUrl::base())))->assertStatus(400);

        $this->assertNull($session->fresh()->ended_at);
    }

    /**
     * SP 발 로그아웃은 NameID·SessionIndex 를 담은 LogoutRequest 로 IdP SLO 에 302 하고, IdP 의 답은 복귀 경로로 보낸다.
     *
     * @scenario direction=sp_to_idp
     *
     * @effects logout_request_redirect, logout_response_to_return_path
     *
     * @return void 반환값 없음
     */
    public function test_sp_initiated_logout_round_trip(): void
    {
        $idp = $this->mockSamlIdp();
        $user = User::factory()->create();
        $this->link($user, $idp, 'saml-slo-3', ['subject_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent']);
        $session = $this->openSession($idp, $user, 'saml-slo-3', '_s3');

        $redirect = app(ProtocolRegistry::class)->get('saml')->beginLogout($idp, $session);
        $this->assertNotNull($redirect);
        $location = (string) $redirect->headers->get('Location');
        $this->assertStringStartsWith(MockSamlController::entityId().'/slo?SAMLRequest=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $xml = (string) gzinflate((string) base64_decode((string) $query['SAMLRequest']));
        $this->assertStringContainsString('>saml-slo-3</saml:NameID>', $xml);
        $this->assertStringContainsString('>_s3</samlp:SessionIndex>', $xml);
        $this->assertSame(SiteUrl::absolute(SamlDriver::LOGOUT_RETURN_PATH), $query['RelayState'] ?? null);

        // 모의 IdP 가 답한다 → SLS 가 복귀 경로로 보낸다.
        $answer = $this->get(substr($location, strlen(SiteUrl::base())));
        $answer->assertStatus(302);
        $back = $this->get(substr((string) $answer->headers->get('Location'), strlen(SiteUrl::base())));
        $back->assertStatus(302);
        $this->assertStringStartsWith(SiteUrl::absolute('/'), (string) $back->headers->get('Location'));
        $this->assertContains(parse_url((string) $back->headers->get('Location'), PHP_URL_PATH), [SamlDriver::LOGOUT_RETURN_PATH, '/login']);
    }

    /**
     * IdP 가 로그아웃 주소를 알리지 않거나 세션에 NameID 가 없으면 SP 발 로그아웃을 하지 않는다(null).
     *
     * @scenario direction=sp_to_idp, name_id=missing
     *
     * @effects null_response
     *
     * @return void 반환값 없음
     */
    public function test_sp_logout_without_name_id_is_skipped(): void
    {
        $idp = $this->mockSamlIdp();
        $session = new SsoSession(['idp_id' => $idp->id, 'user_id' => 0, 'name_id' => null]);

        $this->assertNull(app(ProtocolRegistry::class)->get('saml')->beginLogout($idp, $session));
    }

    /**
     * SAMLRequest 와 SAMLResponse 가 함께 오거나 둘 다 없으면 400 이다.
     *
     * @scenario sls_input=both|none
     *
     * @effects rejected_400
     *
     * @return void 반환값 없음
     */
    public function test_malformed_sls_request(): void
    {
        $idp = $this->mockSamlIdp();

        $this->get(SamlDriver::slsPath($idp))->assertStatus(400);
        $this->get(SamlDriver::slsPath($idp).'?SAMLRequest=a&SAMLResponse=b')->assertStatus(400);
    }
}
