<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 시작 티켓(W25) — link, reauth, test 는 티켓 없이 시작하지 못하고, 티켓은 1회용이며 발급 브라우저에서만 통한다.
 *
 * @since 1.0.0
 */
class StartTicketTest extends PluginTestCase
{
    /**
     * 로그인 상태가 필요한 의도.
     *
     * @return array<string, array{0: string}> 의도
     */
    public static function intents(): array
    {
        return ['link' => ['link'], 'reauth' => ['reauth'], 'test' => ['test']];
    }

    /**
     * 로그인 상태가 필요한 의도는 시작 티켓 없이 시작하지 못하는지 확인한다.
     *
     * @scenario intent=link|reauth|test, ticket=missing
     *
     * @effects ticket_required
     *
     * @param  string  $intent  의도
     * @return void 반환값 없음
     */
    #[DataProvider('intents')]
    public function test_intent_without_ticket_is_refused(string $intent): void
    {
        $idp = $this->mockOidcIdp();

        $response = $this->get('/plugins/sirsoft-sso/login/'.$idp->key.'?intent='.$intent);

        $this->assertSame('ticket_required', $this->redirectQuery($response)['sso_error']);
    }

    /**
     * 시작 티켓이 1회용이고 발급 브라우저와 IdP 에서만 통하는지 확인한다.
     *
     * @scenario ticket=reused|other_browser|other_idp
     *
     * @effects ticket_invalid
     *
     * @return void 반환값 없음
     */
    public function test_ticket_is_single_use_and_browser_bound(): void
    {
        $idp = $this->mockOidcIdp();
        $other = $this->mockOidcIdp([], 'mock_other');
        $user = User::factory()->create();
        $this->withBrowserBind();
        $issue = fn ($target) => app(LoginFlowService::class)->issueStartUrl(
            $user, $target, LoginIntent::LINK,
            Request::create('/api/x', 'POST', [], [StateStore::BIND_COOKIE => $this->browserBind]), '/mypage',
        )['start_url'];

        $url = $issue($idp);
        $this->withBrowserBind()->get($url)->assertStatus(302)->assertRedirectContains('/mock/oidc/authorize');
        $this->assertSame('ticket_invalid', $this->redirectQuery($this->withBrowserBind()->get($url))['sso_error'], '재사용');

        $url = $issue($idp);
        $this->switchBrowser();
        $this->assertSame('ticket_invalid', $this->redirectQuery($this->withBrowserBind()->get($url))['sso_error'], '다른 브라우저');

        $this->browserBind = null;
        $this->withBrowserBind();
        $url = $issue($idp);
        $wrongIdp = str_replace('/login/'.$idp->key, '/login/'.$other->key, $url);
        $this->assertSame('ticket_invalid', $this->redirectQuery($this->withBrowserBind()->get($wrongIdp))['sso_error'], '다른 IdP');
    }
}
