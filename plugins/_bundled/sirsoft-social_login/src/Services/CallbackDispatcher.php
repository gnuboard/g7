<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;

/**
 * 콜백 뒤 의도별 분기 — 로그인(연결 있음 → 로그인 인계 / 없음 → 가입), 연결, 본인 재확인.
 *
 * OAuth 왕복(state·코드 교환·프로필 정규화)은 `OAuthFlowService` 가 끝낸 뒤 여기로 넘긴다. 관리자 연결 테스트(test)는
 * 왕복 서비스가 직접 처리한다.
 *
 * @since 1.0.0
 */
class CallbackDispatcher
{
    /**
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  SocialLoginService  $login  로그인 인계
     * @param  SignupFlowService  $signup  가입 흐름
     * @param  AccountLinkService  $links  연결 관리
     * @param  SocialReauthService  $reauth  본인 재확인
     */
    public function __construct(
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly SocialLoginService $login,
        private readonly SignupFlowService $signup,
        private readonly AccountLinkService $links,
        private readonly SocialReauthService $reauth,
    ) {}

    /**
     * 의도에 맞게 처리하고 브라우저가 갈 주소를 돌려줍니다.
     *
     * @param  AuthorizationRequest  $state  시작 정보
     * @param  NormalizedProfile  $profile  정규화 프로필
     * @param  TokenSet|null  $tokens  토큰
     * @return string 이동할 주소
     */
    public function dispatch(AuthorizationRequest $state, NormalizedProfile $profile, ?TokenSet $tokens): string
    {
        return match ($state->intent) {
            AttemptIntent::Link->value => $this->links->completeLink($state, $profile, $tokens),
            AttemptIntent::Reauth->value => $this->reauth->complete($state, $profile),
            default => $this->login($state, $profile, $tokens),
        };
    }

    /**
     * 왕복이 분기 전에 실패했을 때 의도에 맞는 화면으로 돌아갈 주소를 돌려줍니다.
     *
     * 연결·재확인은 로그인한 회원이 시작한 왕복이라 출발한 화면으로, 로그인은 로그인 화면으로 보낸다(test 의도는 왕복
     * 서비스가 결과 화면으로 처리한다).
     *
     * @param  AuthorizationRequest  $state  시작 정보
     * @param  string  $errorCode  오류 코드
     * @return string 이동할 주소
     */
    public function failure(AuthorizationRequest $state, string $errorCode): string
    {
        return match ($state->intent) {
            AttemptIntent::Link->value => $this->links->failedLinkUrl($state->providerKey, $errorCode),
            AttemptIntent::Reauth->value => $this->reauth->abandon($state, $errorCode),
            default => OAuthFlowService::loginErrorUrl($errorCode, $state->redirect),
        };
    }

    /**
     * 로그인 의도 — 연결된 회원이 있으면 로그인 인계, 없으면 가입 흐름.
     *
     * @param  AuthorizationRequest  $state  시작 정보
     * @param  NormalizedProfile  $profile  프로필
     * @param  TokenSet|null  $tokens  토큰
     * @return string 이동할 주소
     */
    private function login(AuthorizationRequest $state, NormalizedProfile $profile, ?TokenSet $tokens): string
    {
        $account = $this->accounts->findByProviderUid($state->providerKey, $profile->providerUserId);

        if ($account !== null) {
            return $this->login->loginLinked($account, $profile, $tokens, $state->redirect);
        }

        return $this->signup->begin($state, $profile, $tokens);
    }
}
