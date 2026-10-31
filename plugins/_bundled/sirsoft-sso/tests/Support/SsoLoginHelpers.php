<?php

namespace Plugins\Sirsoft\Sso\Tests\Support;

use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;

/**
 * SSO 로그인 끝단(인계 발급 → 교환 → 코어 로그인)을 HTTP 없이 돌리는 시험 도우미 — 세션, 로그아웃 시험용.
 *
 * 실제 흐름과 같은 서비스(`LoginFlowService::issueHandoff`, `LoginHandoffService::redeem`, `AuthService::loginVerifiedUser`)를 그대로
 * 부른다 — 인계 문맥 필터와 로그인 완료 훅이 실제로 돈다.
 *
 * @since 1.0.0
 */
trait SsoLoginHelpers
{
    /**
     * SSO 로그인을 마치고 토큰 원문과 문맥을 돌려줍니다.
     *
     * @param  User  $user  회원
     * @param  SsoAccount  $account  연결
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  ExternalIdentity|null  $identity  신원 (null 이면 연결의 subject)
     * @return array{token: string, token_id: int, context: array<string, mixed>} 로그인 결과
     */
    protected function ssoLogin(User $user, SsoAccount $account, IdentityProvider $idp, ?ExternalIdentity $identity = null): array
    {
        $identity ??= new ExternalIdentity(subject: $account->subject);
        $issued = app(LoginFlowService::class)->issueHandoff($user, $account, $identity, $idp, 'user', 'login', forApi: true);

        $request = Request::create('/api/auth/login/handoff', 'POST', [], [LoginHandoffService::BIND_COOKIE => $issued->cookie->getValue()]);
        $redeemed = app(LoginHandoffService::class)->redeem($issued->code, 'user', $request);
        $this->assertIsArray($redeemed, '인계 코드 교환 실패');

        $result = app(AuthService::class)->loginVerifiedUser($redeemed['user'], $redeemed['context'], 'user');
        $this->assertIsString($result['token'] ?? null, 'SSO 로그인이 토큰을 내지 않았다');

        return [
            'token' => $result['token'],
            'token_id' => (int) explode('|', $result['token'], 2)[0],
            'context' => $redeemed['context'],
        ];
    }

    /**
     * 토큰으로 로그아웃합니다.
     *
     * @param  string  $token  토큰 원문
     * @return TestResponse 응답
     */
    protected function logoutWith(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/auth/logout');
    }
}
