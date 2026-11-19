<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Saml;

use App\Models\User;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Saml\SamlFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * IdP-initiated SAML — 꺼져 있으면 거절, 켜도 응답 1회·유효 시각 필수·복귀 경로는 같은 출처만.
 *
 * @since 1.0.0
 */
class SamlIdpInitiatedTest extends PluginTestCase
{
    use SamlFixtures;

    /**
     * 꺼진 IdP 의 요청하지 않은 응답은 saml_unsolicited 로 거절하고 회원은 생기지 않는다.
     *
     * @scenario idp_initiated=off
     *
     * @effects sso_error_saml_unsolicited, member_change_zero
     *
     * @return void 반환값 없음
     */
    public function test_disabled_idp_initiated_is_rejected(): void
    {
        $idp = $this->mockSamlIdp();
        $before = User::query()->count();

        $response = $this->postAcs($idp, $this->samlResponse($idp, 'unsolicited', null, ['email' => $this->uniqueEmail('corp798.test')]), '/mypage');

        $this->assertSame('saml_unsolicited', $this->redirectQuery($response)['sso_error'] ?? null);
        $this->assertSame($before, User::query()->count());
    }

    /**
     * 켠 IdP 는 로그인하고 같은 응답의 두 번째 POST 는 재전송으로 거절한다. RelayState 경로는 복귀 주소가 된다.
     *
     * @scenario idp_initiated=on, replay=second
     *
     * @effects first_login_handoff, second_response_replayed, relay_state_redirect
     *
     * @return void 반환값 없음
     */
    public function test_enabled_idp_initiated_logs_in_once(): void
    {
        $idp = $this->mockSamlIdp(['idp_initiated' => true]);
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test')]);
        $this->link($user, $idp, 'saml-idpi-1');
        $samlResponse = $this->samlResponse($idp, 'unsolicited', null, ['name_id' => 'saml-idpi-1']);

        $first = $this->postAcs($idp, $samlResponse, '/mypage');
        $this->assertNotSame('', $this->redirectQuery($first)['login_handoff'] ?? '');
        $this->assertSame('/mypage', $this->redirectQuery($first)['redirect'] ?? null);
        $this->assertSame($user->id, $this->redeemHandoff($first)['user']->id ?? null);

        $second = $this->postAcs($idp, $samlResponse, '/mypage');
        $this->assertSame('response_replayed', $this->redirectQuery($second)['sso_error'] ?? null);
    }

    /**
     * SP-initiated 응답(InResponseTo 있음)을 state 없이 다시 보내면 IdP-initiated 로 받지 않는다.
     *
     * @scenario idp_initiated=on, response=has_in_response_to, state=none
     *
     * @effects sso_error_saml_unsolicited
     *
     * @return void 반환값 없음
     */
    public function test_solicited_response_without_state_is_rejected(): void
    {
        $idp = $this->mockSamlIdp(['idp_initiated' => true]);

        $response = $this->postAcs($idp, $this->samlResponse($idp, 'ok', '_req_from_other_flow'), null);

        $this->assertSame('saml_unsolicited', $this->redirectQuery($response)['sso_error'] ?? null);
    }

    /**
     * 바깥 주소 RelayState 는 복귀 주소로 쓰지 않는다(열린 리디렉션 방지).
     *
     * @scenario relay_state=external_url
     *
     * @effects redirect_dropped
     *
     * @return void 반환값 없음
     */
    public function test_external_relay_state_is_not_followed(): void
    {
        $idp = $this->mockSamlIdp(['idp_initiated' => true]);
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test')]);
        $this->link($user, $idp, 'saml-idpi-2');

        $response = $this->postAcs($idp, $this->samlResponse($idp, 'unsolicited', null, ['name_id' => 'saml-idpi-2']), 'https://evil.example/steal');

        $this->assertSame('/login', $this->redirectPath($response));
        $this->assertArrayNotHasKey('redirect', $this->redirectQuery($response));
    }
}
