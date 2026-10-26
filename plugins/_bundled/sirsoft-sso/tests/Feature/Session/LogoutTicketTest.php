<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Session;

use App\Models\User;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;
use Plugins\Sirsoft\Sso\Tests\Support\SsoLoginHelpers;

/**
 * 로그아웃 티켓(축 H-8) — 티켓 없음, 재사용, 다른 IdP 경로, 지어낸 값은 IdP 로 보내지 않고 로그인 화면으로. 주소에 세션 id 가 없다.
 *
 * @since 1.0.0
 */
class LogoutTicketTest extends PluginTestCase
{
    use ProvisioningTestHelpers;
    use SsoLoginHelpers;

    /**
     * 티켓 없음, 지어낸 값, 재사용, 다른 IdP 경로 모두 로그인 화면.
     *
     * @scenario ticket=missing|forged|reused|other_idp
     *
     * @effects login_redirect, no_idp_request
     *
     * @return void 반환값 없음
     */
    public function test_invalid_tickets_go_to_login(): void
    {
        $idp = $this->mockOidcIdp(['logout_mode' => 'rp']);
        $other = $this->mockOidcIdp(['logout_mode' => 'rp'], 'mock_oidc_b');
        $user = User::factory()->create();
        $login = $this->ssoLogin($user, $this->link($user, $idp, 'tk-1'), $idp);
        $url = (string) $this->logoutWith($login['token'])->json('data.redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('session', $query);
        $this->assertSame(['ticket'], array_keys($query));

        $this->assertSame('/login', $this->redirectPath($this->get('/plugins/sirsoft-sso/logout/'.$idp->key)));
        $this->assertSame('/login', $this->redirectPath($this->get('/plugins/sirsoft-sso/logout/'.$idp->key.'?ticket=1')));
        $this->assertSame('/login', $this->redirectPath($this->get('/plugins/sirsoft-sso/logout/'.$other->key.'?ticket='.$query['ticket'])));

        $this->assertSame('/login', $this->redirectPath($this->get($url)), '다른 IdP 경로에서 한 번 쓴 티켓은 소멸한다');
    }

    /**
     * 같은 티켓을 두 번 쓰면 두 번째는 로그인 화면.
     *
     * @scenario ticket=reused
     *
     * @effects first_idp_redirect, second_login_redirect
     *
     * @return void 반환값 없음
     */
    public function test_ticket_is_single_use(): void
    {
        $idp = $this->mockOidcIdp(['logout_mode' => 'rp']);
        $user = User::factory()->create();
        $login = $this->ssoLogin($user, $this->link($user, $idp, 'tk-2'), $idp);
        $url = (string) $this->logoutWith($login['token'])->json('data.redirect_url');

        $this->assertStringStartsWith(MockOidcController::issuer(), (string) $this->get($url)->headers->get('Location'));
        $this->assertSame('/login', $this->redirectPath($this->get($url)));
    }
}
