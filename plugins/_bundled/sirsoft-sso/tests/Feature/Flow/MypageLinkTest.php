<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 마이페이지 연결(로그인한 회원, `intent=link`) — 시작 티켓으로 시작해 회원에게 연결을 더하고, 다른 회원 신원이면 거절.
 *
 * @since 1.0.0
 */
class MypageLinkTest extends PluginTestCase
{
    /**
     * 회원의 시작 주소를 받습니다 (API 가 할 일 — 시험은 서비스를 바로 부른다).
     *
     * @param  User  $user  회원
     * @param  IdentityProvider  $idp  연결할 IdP
     * @return string 시작 경로
     */
    private function startUrl(User $user, IdentityProvider $idp): string
    {
        $this->withBrowserBind();
        $request = Request::create('/api/plugins/sirsoft-sso/me/links/start', 'POST', [], [StateStore::BIND_COOKIE => $this->browserBind]);

        return app(LoginFlowService::class)->issueStartUrl($user, $idp, LoginIntent::LINK, $request, '/mypage/profile')['start_url'];
    }

    /**
     * 시작 주소로 왕복합니다.
     *
     * @param  IdentityProvider  $idp  연결할 IdP
     * @param  string  $startUrl  시작 경로
     * @param  string  $subject  IdP 안의 고유 식별자
     * @return TestResponse 콜백 응답
     */
    private function linkRoundTrip(IdentityProvider $idp, string $startUrl, string $subject): TestResponse
    {
        $start = $this->withBrowserBind()->get($startUrl);
        $start->assertStatus(302);
        $callback = $this->approveAtMock((string) $start->headers->get('Location'), 'full', ['sub' => $subject]);

        return $this->withBrowserBind()->get('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback?'.http_build_query($callback));
    }

    /**
     * 마이페이지 연결 왕복이 시작 티켓의 회원에게 연결을 더하는지 확인한다.
     *
     * @scenario intent=link, subject=new
     *
     * @effects account_added, back_to_mypage
     *
     * @return void 반환값 없음
     */
    public function test_link_adds_account_to_ticket_member(): void
    {
        $idp = $this->mockOidcIdp();
        $user = User::factory()->create();

        $response = $this->linkRoundTrip($idp, $this->startUrl($user, $idp), 'my-1');

        $this->assertSame('/mypage/profile', $this->redirectPath($response));
        $this->assertSame($idp->key, $this->redirectQuery($response)['sso_linked']);
        $this->assertDatabaseHas('sso_accounts', ['idp_id' => $idp->id, 'user_id' => $user->id, 'subject' => 'my-1']);
        $this->assertDatabaseHas('sso_login_attempts', ['user_id' => $user->id, 'intent' => 'link', 'outcome' => 'linked']);
    }

    /**
     * 다른 회원에게 연결된 신원은 마이페이지 연결을 거절하는지 확인한다.
     *
     * @scenario intent=link, subject=owned_by_other
     *
     * @effects already_linked_error_on_mypage
     *
     * @return void 반환값 없음
     */
    public function test_identity_of_another_member_is_refused(): void
    {
        $idp = $this->mockOidcIdp();
        $this->link(User::factory()->create(), $idp, 'my-2');
        $user = User::factory()->create();

        $response = $this->linkRoundTrip($idp, $this->startUrl($user, $idp), 'my-2');

        $this->assertSame('/mypage/profile', $this->redirectPath($response));
        $this->assertSame('already_linked', $this->redirectQuery($response)['sso_error']);
        $this->assertDatabaseMissing('sso_accounts', ['user_id' => $user->id]);
    }
}
