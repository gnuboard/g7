<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Saml;

use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Saml\SamlFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * SAML ACS 의 브라우저 묶음 쿠키 — IdP 의 교차 사이트 POST 에도 실리도록 HTTPS 사이트에서 SameSite=None; Secure, 다른 브라우저는 거절.
 *
 * @since 1.0.0
 */
class SamlBindingCookieTest extends PluginTestCase
{
    use SamlFixtures;

    /**
     * HTTPS 사이트에서 처음 온 브라우저에 SameSite=None; Secure; HttpOnly 묶음 쿠키를 굽는다.
     *
     * @scenario site=https, browser=first_visit
     *
     * @effects bind_cookie_samesite_none_secure
     *
     * @return void 반환값 없음
     */
    public function test_https_site_issues_samesite_none_cookie(): void
    {
        config(['app.url' => 'https://localhost']);
        $idp = $this->mockSamlIdp();

        $start = $this->get('/plugins/sirsoft-sso/login/'.$idp->key);

        $cookie = $start->getCookie(StateStore::BIND_COOKIE, false);
        $this->assertNotNull($cookie);
        $this->assertSame(Cookie::SAMESITE_NONE, $cookie->getSameSite());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
    }

    /**
     * 시작한 브라우저의 쿠키가 실린 ACS POST 만 통과하고, 쿠키가 없거나 다른 브라우저면 bind_mismatch 다.
     *
     * @scenario browser=same|none|other
     *
     * @effects same_ok, missing_bind_mismatch, other_bind_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_acs_requires_the_starting_browser(): void
    {
        $idp = $this->mockSamlIdp();

        $start = $this->startSaml($idp);
        $this->withoutBrowserCookies();
        $missing = $this->post('/plugins/sirsoft-sso/saml/'.$idp->key.'/acs', [
            'SAMLResponse' => $this->samlResponse($idp, 'ok', $start['request_id']),
            'RelayState' => $start['relay_state'],
        ]);
        $this->assertSame('bind_mismatch', $this->redirectQuery($missing)['sso_error'] ?? null);

        $start = $this->startSaml($idp);
        $this->switchBrowser();
        $other = $this->postAcs($idp, $this->samlResponse($idp, 'ok', $start['request_id']), $start['relay_state']);
        $this->assertSame('bind_mismatch', $this->redirectQuery($other)['sso_error'] ?? null);

        $start = $this->startSaml($idp);
        $same = $this->postAcs($idp, $this->samlResponse($idp, 'ok', $start['request_id'], ['email' => $this->uniqueEmail('corp798.test')]), $start['relay_state']);
        $this->assertNotSame('', $this->redirectQuery($same)['login_handoff'] ?? '');
    }
}
