<?php

namespace Plugins\Sirsoft\Sso\Services\Policy;

use App\Extension\Auth\LoginDiscoveryResult;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;

/**
 * 로그인 진입 판정(D14 ②③, P8, W19, W20) — 코어 필터 `core.auth.login_discovery`, `core.auth.login_auto_redirect` 의 답.
 *
 * 이메일 먼저 입력: 식별자 **도메인만** 본다(회원 존재 여부와 무관 — 열거 방지). 도메인이 활성 OIDC, SAML IdP 의 소유 도메인이면
 * 「그 IdP 로 계속」, LDAP IdP 도메인은 비밀번호 칸이 필요하므로 비밀번호 로그인으로 답한다.
 * 자동 이동: 사이트 전체 강제가 켜져 있고 자동 이동 IdP 가 정해져 있을 때만 그 IdP 로그인 경로. 로그인 화면의 `?local=1` 등 우회
 * 쿼리는 템플릿이 판정한다.
 *
 * @since 1.0.0
 */
class DiscoveryService
{
    /**
     * @param  PolicyService  $policy  정책
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  EnforcementPolicy  $enforcement  시작 경로 생성
     * @param  MockGate  $mockGate  모의 IdP 게이트
     */
    public function __construct(
        private readonly PolicyService $policy,
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly EnforcementPolicy $enforcement,
        private readonly MockGate $mockGate,
    ) {}

    /**
     * 식별자의 로그인 수단을 판정합니다.
     *
     * @param  string  $identifier  로그인 칸 입력
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return LoginDiscoveryResult|null 판정 (판정할 것이 없으면 null — 코어가 비밀번호로 답한다)
     */
    public function discover(string $identifier, string $guard): ?LoginDiscoveryResult
    {
        if (! $this->policy->get('discovery_enabled', true)) {
            return null;
        }

        $at = strrpos($identifier, '@');
        if ($at === false) {
            return null;
        }

        $domain = mb_strtolower(trim(substr($identifier, $at + 1)));
        if ($domain === '') {
            return null;
        }

        $mockEnabled = $this->mockGate->enabled();
        $idp = $this->idps->enabled()->first(
            fn (IdentityProvider $idp) => $idp->ownsDomain($domain) && ($mockEnabled || ! $idp->isMock())
        );

        if ($idp === null || $idp->protocol === 'ldap') {
            return null;
        }

        return LoginDiscoveryResult::external(
            is_array($idp->label) && $idp->label !== [] ? $idp->label : ['ko' => $idp->key, 'en' => $idp->key],
            $this->enforcement->startPath($idp, $guard),
        );
    }

    /**
     * 로그인 화면 자동 이동 경로를 돌려줍니다.
     *
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return string|null 같은 출처 경로, 자동 이동이 없으면 null
     */
    public function autoRedirect(string $guard): ?string
    {
        $policy = $this->policy->current();

        if (! $policy['enforce_site'] || ! is_string($policy['auto_redirect_idp'])) {
            return null;
        }

        $idp = $this->idps->findByKey($policy['auto_redirect_idp']);

        if ($idp === null || ! $idp->enabled || $idp->protocol === 'ldap' || ($idp->isMock() && ! $this->mockGate->enabled())) {
            return null;
        }

        return $this->enforcement->startPath($idp, $guard);
    }
}
