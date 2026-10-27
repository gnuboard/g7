<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * OIDC 로그인 왕복 — 연결된 회원은 코어 로그인 인계로, 실패는 로그인 화면 오류로 돌아온다.
 *
 * @since 1.0.0
 */
class OidcLoginFlowTest extends PluginTestCase
{
    /**
     * 연결된 회원이 코어 로그인 인계로 넘어가고 문맥과 훅, 이력이 맞는지 확인한다.
     *
     * @scenario account=linked, guard=user, redirect=/mypage
     *
     * @effects handoff_issued, context_login_method, attempt_recorded, hooks_fired, last_login_updated
     *
     * @return void 반환값 없음
     */
    public function test_linked_member_gets_core_login_handoff(): void
    {
        // 2단계 인증 생략은 정책 리스너(TwoFactorContextListener)가 IdP 방식으로 정한다 — 생략 방식으로 두고 인계 문맥까지 가는지 본다.
        $idp = $this->mockOidcIdp(['two_factor_mode' => 'always_skip']);
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test')]);
        $account = $this->link($user, $idp, 'emp-100');

        $fired = [];
        HookManager::addAction(LoginFlowService::ACTION_AFTER_RESOLVE, function ($u, $a, $i, $outcome) use (&$fired) {
            $fired[] = 'resolve:'.$outcome;
        });
        HookManager::addAction(LoginFlowService::ACTION_AFTER_SSO_LOGIN, function () use (&$fired) {
            $fired[] = 'sso_login';
        });
        HookManager::addFilter(LoginFlowService::FILTER_HANDOFF_CONTEXT, fn (array $context) => ['login_method' => 'tampered'] + $context);

        $response = $this->roundTrip($idp, 'full', ['sub' => 'emp-100'], 'guard=user&redirect=%2Fmypage');

        $response->assertStatus(302);
        $this->assertSame('/login', $this->redirectPath($response));
        $this->assertSame('/mypage', $this->redirectQuery($response)['redirect']);

        $redeemed = $this->redeemHandoff($response);
        $this->assertNotNull($redeemed);
        $this->assertSame($user->id, $redeemed['user']->id);
        $this->assertSame('sso:mock_oidc', $redeemed['context']['login_method'], '확장 필터가 로그인 수단을 바꾸지 못한다');
        $this->assertTrue($redeemed['context']['skip_two_factor']);

        $this->assertSame(['resolve:login', 'sso_login'], $fired);
        $this->assertNotNull($account->fresh()->last_login_at);
        $this->assertDatabaseHas('sso_login_attempts', ['idp_id' => $idp->id, 'user_id' => $user->id, 'outcome' => SsoLoginAttempt::OUTCOME_HANDOFF]);
        $this->assertNotNull($idp->fresh()->last_success_at);
    }

    /**
     * 처음 온 브라우저에 묶음 쿠키를 굽는지 확인한다.
     *
     * @scenario browser=first_visit(no_bind_cookie)
     *
     * @effects bind_cookie_issued, state_bound_to_it
     *
     * @return void 반환값 없음
     */
    public function test_first_visit_issues_bind_cookie(): void
    {
        $idp = $this->mockOidcIdp();

        $start = $this->get('/plugins/sirsoft-sso/login/'.$idp->key);

        $start->assertStatus(302);
        $cookie = $start->getCookie('g7_sso_bind', false);
        $this->assertNotNull($cookie, '처음 온 브라우저에 묶음 쿠키를 굽는다');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
    }

    /**
     * 없는 state 나 이미 쓴 state 의 콜백을 거절하는지 확인한다.
     *
     * @scenario state=missing|reused
     *
     * @effects state_expired_error
     *
     * @return void 반환값 없음
     */
    public function test_callback_without_valid_state_is_rejected(): void
    {
        $idp = $this->mockOidcIdp();

        $response = $this->withBrowserBind()->get('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback?code=x&state=unknown');
        $this->assertSame('state_expired', $this->redirectQuery($response)['sso_error']);

        $user = User::factory()->create();
        $this->link($user, $idp, 'emp-101');
        $start = $this->withBrowserBind()->get('/plugins/sirsoft-sso/login/'.$idp->key);
        $callback = $this->approveAtMock((string) $start->headers->get('Location'), 'full', ['sub' => 'emp-101']);
        $this->withBrowserBind()->get('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback?'.http_build_query($callback))->assertStatus(302);

        // 같은 콜백 주소를 다시 열면 state 가 이미 소비됐다.
        $again = $this->withBrowserBind()->get('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback?'.http_build_query($callback));
        $this->assertSame('state_expired', $this->redirectQuery($again)['sso_error']);
    }

    /**
     * 시작한 브라우저와 다른 브라우저의 콜백을 거절하는지 확인한다.
     *
     * @scenario browser=other
     *
     * @effects bind_mismatch_error
     *
     * @return void 반환값 없음
     */
    public function test_callback_from_other_browser_is_rejected(): void
    {
        $idp = $this->mockOidcIdp();
        $start = $this->withBrowserBind()->get('/plugins/sirsoft-sso/login/'.$idp->key);
        $callback = $this->approveAtMock((string) $start->headers->get('Location'));

        $this->switchBrowser();
        $response = $this->withBrowserBind()->get('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback?'.http_build_query($callback));

        $this->assertSame('bind_mismatch', $this->redirectQuery($response)['sso_error']);
    }

    /**
     * 꺼진 IdP, 없는 IdP, 꺼진 모의 게이트, 티켓 없는 의도의 시작을 거절하는지 확인한다.
     *
     * @scenario idp=disabled|unknown|mock_gate_off, intent=link_without_ticket
     *
     * @effects start_refused
     *
     * @return void 반환값 없음
     */
    public function test_start_refusals(): void
    {
        $idp = $this->mockOidcIdp();

        $this->assertSame('idp_disabled', $this->redirectQuery($this->get('/plugins/sirsoft-sso/login/unknown_idp'))['sso_error']);
        $this->assertSame('ticket_required', $this->redirectQuery($this->get('/plugins/sirsoft-sso/login/'.$idp->key.'?intent=link'))['sso_error']);

        $idp->forceFill(['enabled' => false])->save();
        $this->assertSame('idp_disabled', $this->redirectQuery($this->get('/plugins/sirsoft-sso/login/'.$idp->key))['sso_error']);

        $idp->forceFill(['enabled' => true])->save();
        app(MockGate::class)->disable();
        $this->assertSame('idp_disabled', $this->redirectQuery($this->get('/plugins/sirsoft-sso/login/'.$idp->key))['sso_error']);
    }

    /**
     * IdP 거절이 로그인 화면 오류로 돌아오고 IdP 오류로 기록되는지 확인한다.
     *
     * @scenario idp_error=access_denied
     *
     * @effects error_recorded_on_idp
     *
     * @return void 반환값 없음
     */
    public function test_idp_denial_returns_to_login_with_error(): void
    {
        $idp = $this->mockOidcIdp();
        $response = $this->roundTrip($idp, 'deny');

        $this->assertSame('access_denied', $this->redirectQuery($response)['sso_error']);
        $this->assertSame('access_denied', $idp->fresh()->last_error_code);
        $this->assertDatabaseHas('sso_login_attempts', ['idp_id' => $idp->id, 'outcome' => SsoLoginAttempt::OUTCOME_FAILED, 'reason_code' => 'access_denied']);
    }

    /**
     * 실패·거절 기록마다 로그인 실패 훅이 오류 코드와 함께 한 번씩 나고, 성공에는 나지 않는지 확인한다.
     *
     * @scenario failure=idp_denial|start_refused|state_expired, success=handoff
     *
     * @effects login_failed_hook_fired, no_hook_on_success
     *
     * @return void 반환값 없음
     */
    public function test_login_failed_hook_fires_on_failures_only(): void
    {
        $events = [];
        HookManager::addAction(LoginFlowService::ACTION_LOGIN_FAILED, function ($idp, $code, $intent, $guard, $outcome) use (&$events) {
            $events[] = [$idp?->key, $code, $intent, $guard, $outcome];
        });
        $idp = $this->mockOidcIdp();

        $this->roundTrip($idp, 'deny');
        $this->get('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback?state=missing&code=x');
        $idp->forceFill(['enabled' => false])->save();
        $this->get('/plugins/sirsoft-sso/login/'.$idp->key);

        $this->assertSame([
            [$idp->key, 'access_denied', 'login', 'user', SsoLoginAttempt::OUTCOME_FAILED],
            [$idp->key, 'state_expired', 'login', 'user', SsoLoginAttempt::OUTCOME_FAILED],
            [$idp->key, 'idp_disabled', 'login', 'user', SsoLoginAttempt::OUTCOME_FAILED],
        ], $events);

        $events = [];
        $idp->forceFill(['enabled' => true])->save();
        $user = User::factory()->create(['email' => $this->uniqueEmail()]);
        $this->link($user, $idp, 'hook-ok');
        $this->roundTrip($idp, 'full', ['sub' => 'hook-ok']);
        $this->assertSame([], $events, '성공에는 나지 않는다');
    }

    /**
     * 공개 IdP 목록이 공개 칸만 싣고 모의 게이트를 따르는지 확인한다.
     *
     * @scenario api=public_idps, mock_gate=on|off
     *
     * @effects public_fields_only, mock_hidden_when_off
     *
     * @return void 반환값 없음
     */
    public function test_public_idp_list(): void
    {
        $idp = $this->mockOidcIdp(['button_style' => ['bg' => '#000000', 'fg' => '#ffffff']]);

        $response = $this->getJson('/api/plugins/sirsoft-sso/idps?guard=admin&redirect=%2Fadmin');
        $response->assertOk();
        $item = $response->json('data.0');
        $this->assertSame(['key', 'label', 'initial', 'button_style', 'start_url'], array_keys($item));
        $this->assertSame('/plugins/sirsoft-sso/login/'.$idp->key.'?guard=admin&redirect=%2Fadmin', $item['start_url']);
        $this->assertStringNotContainsString('mock-secret', (string) $response->getContent());

        app(MockGate::class)->disable();
        $this->assertSame([], $this->getJson('/api/plugins/sirsoft-sso/idps')->json('data'));
    }
}
