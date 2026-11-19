<?php

namespace Plugins\Sirsoft\Sso\Services\Policy;

use App\Extension\Auth\PasswordAuthDecision;
use App\Models\User;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\SitePolicyRepositoryInterface;

/**
 * 비밀번호 인증 정책 판정 — 코어 필터 `core.auth.password_auth_decision` 의 답(D11, D12, D22, W1~W4, W27~W29, W36).
 *
 * 판정은 진입 경로가 아니라 회원 기준이다(W1 — 관리자가 사용자 로그인 화면으로 받은 토큰도 관리자 API 에서 통하므로).
 *  - 1차(식별자 기준, 시도 전 — `user` 가 null): 비상 계정 이메일 → 허용(강제 중 2단계 필수) / 사이트 전체 강제 → 거절 /
 *    식별자 도메인이 「비밀번호 로그인 막기」를 켠 OIDC, SAML IdP 소유 → 거절 + 그 IdP 로 가는 경로 / 같은 조건의 LDAP IdP 소유 →
 *    로그인은 거절(경로 없음 — 여기 왔다는 것은 디렉터리 검증자가 일치하지 않았다는 뜻, W27), 비밀번호 찾기는 회사 이메일 로그인 안내.
 *  - 2차(회원 기준, 비밀번호가 맞은 뒤): 비상 계정 → 허용(같은 2단계 필수) / 역할 강제 또는 「연결 회원 전원」 + 활성 연결 → 거절.
 *
 * 안내할 IdP 가 LDAP 이면 버튼 경로가 없다. LDAP 은 같은 로그인 폼에 사내 아이디(또는 회사 이메일)와 회사 비밀번호를 넣는 방식이라
 * 거절 사유를 그 폼 로그인 안내(`REASON_DIRECTORY_*`)로 준다. 회원이 리디렉션 IdP 와 LDAP 에 함께 연결돼 있으면 버튼이 있는 쪽을 안내한다.
 *  - 비밀번호를 만드는 목적(`set_password`, `admin_set_password`, 비밀번호 없는 회원의 `reset_request`, `reset_complete`):
 *    활성 연결 IdP 중 하나라도 「G7 비밀번호 설정 허용」이 꺼져 있거나(D22 기본) 강제 대상이면 거절.
 *
 * 판정할 것이 없으면 null(코어가 허용으로 본다).
 *
 * @since 1.0.0
 */
class EnforcementPolicy
{
    /** 로그인 중 비밀번호 변경 관문까지 포함한 「비밀번호로 로그인」 목적 */
    private const LOGIN_PURPOSES = ['login', 'login_password_change'];

    /** 비밀번호를 만들거나 지정하는 목적 */
    private const SET_PURPOSES = ['set_password', 'admin_set_password'];

    /** 비밀번호 재설정 목적 */
    private const RESET_PURPOSES = ['reset_request', 'reset_complete'];

    /** LDAP 도메인 강제인데 디렉터리 검증자가 일치하지 않았을 때의 사유 */
    public const REASON_LDAP_UNAVAILABLE = 'sirsoft-sso::policy.ldap_unavailable';

    /** 사이트 전체 강제인데 안내할 IdP 가 없을 때의 사유 */
    public const REASON_SITE = 'sirsoft-sso::policy.site_enforced';

    /** 회원 기준 강제(역할, 연결 회원)인데 안내할 로그인 수단이 없을 때의 사유 */
    public const REASON_ACCOUNT = 'sirsoft-sso::policy.account_enforced';

    /** LDAP IdP 로 로그인 — 로그인 폼에 사내 아이디와 회사 비밀번호 */
    public const REASON_DIRECTORY_USERNAME = 'sirsoft-sso::policy.directory_login_username';

    /** LDAP IdP 로 로그인 — 로그인 폼에 회사 이메일과 회사 비밀번호 */
    public const REASON_DIRECTORY_EMAIL = 'sirsoft-sso::policy.directory_login_email';

    /** D22 비밀번호 설정 거절 사유 */
    public const REASON_SET_PASSWORD = 'sirsoft-sso::policy.password_set_disabled';

    /**
     * @param  PolicyService  $policy  사이트 정책
     * @param  EmergencyAccess  $emergency  비상 계정
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  SitePolicyRepositoryInterface  $repository  정책 조회 저장소
     */
    public function __construct(
        private readonly PolicyService $policy,
        private readonly EmergencyAccess $emergency,
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly SitePolicyRepositoryInterface $repository,
    ) {}

    /**
     * 비밀번호 인증을 판정합니다.
     *
     * @param  array{identifier?: string, user?: User|null, guard?: string, purpose?: string}  $subject  판정 대상
     * @return PasswordAuthDecision|null 판정 (판정할 것이 없으면 null)
     */
    public function decide(array $subject): ?PasswordAuthDecision
    {
        $identifier = (string) ($subject['identifier'] ?? '');
        $user = ($subject['user'] ?? null) instanceof User ? $subject['user'] : null;
        $guard = ($subject['guard'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $purpose = (string) ($subject['purpose'] ?? 'login');

        if (in_array($purpose, self::SET_PURPOSES, true)) {
            return $user !== null ? $this->decideSetPassword($user, $guard) : null;
        }

        if (in_array($purpose, self::RESET_PURPOSES, true) && $user !== null && ! $user->hasPassword()) {
            // 비밀번호 없는 회원의 재설정은 「처음 만들기」다 — D22 규칙(W36).
            return $this->decideSetPassword($user, $guard);
        }

        if (! in_array($purpose, self::LOGIN_PURPOSES, true) && ! in_array($purpose, self::RESET_PURPOSES, true)) {
            return null;
        }

        return $user === null
            ? $this->decideByIdentifier($identifier, $guard, $purpose)
            : $this->decideByUser($user, $guard);
    }

    /**
     * 회원이 강제 대상인지 확인합니다 (비상 계정은 대상이 아니다).
     *
     * @param  User  $user  회원
     * @return bool 강제 대상이면 true
     */
    public function isEnforcedUser(User $user): bool
    {
        if ($this->emergency->isEmergencyUser($user)) {
            return false;
        }

        $policy = $this->policy->current();

        if ($policy['enforce_site']) {
            return true;
        }

        if ($this->enforcingIdpForDomain($this->domainOf((string) $user->email)) !== null) {
            return true;
        }

        return $this->userRuleIdp($user) !== false;
    }

    /**
     * 강제 대상 회원에게 안내할 IdP 를 고릅니다 (도메인 IdP → 활성 연결 IdP(리디렉션 IdP 먼저, 다음 LDAP) → 리디렉션 IdP 순).
     *
     * @param  User  $user  회원
     * @return IdentityProvider|null IdP
     */
    public function guidanceIdpFor(User $user): ?IdentityProvider
    {
        return $this->enforcingIdpForDomain($this->domainOf((string) $user->email))
            ?? $this->preferredLinkedIdp($this->repository->activeLinkedIdps((int) $user->getKey()))
            ?? $this->firstRedirectIdp();
    }

    /**
     * LDAP IdP 로 로그인할 때 로그인 폼의 아이디 칸에 넣는 값을 알려 줍니다.
     *
     * 디렉터리 검증자는 사내 아이디면 `accept_username` 이 켜진 LDAP IdP 로, 이메일이면 그 도메인을 소유한 LDAP IdP 로 보낸다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string|null `username`(사내 아이디), `email`(회사 이메일). 리디렉션 IdP 이거나 폼으로 들어갈 길이 없으면 null
     */
    public static function directoryLoginField(IdentityProvider $idp): ?string
    {
        if ($idp->protocol !== 'ldap') {
            return null;
        }

        if ((bool) $idp->configValue('accept_username', false)) {
            return 'username';
        }

        $domains = array_filter(is_array($idp->owned_domains) ? $idp->owned_domains : [], fn ($domain) => is_string($domain) && trim($domain) !== '');

        return $domains !== [] ? 'email' : null;
    }

    /**
     * 1차 — 식별자 기준 판정.
     *
     * @param  string  $identifier  로그인 칸 입력
     * @param  string  $guard  로그인 경로
     * @param  string  $purpose  판정 목적
     * @return PasswordAuthDecision|null 판정
     */
    private function decideByIdentifier(string $identifier, string $guard, string $purpose): ?PasswordAuthDecision
    {
        if ($this->emergency->isEmergencyIdentifier($identifier)) {
            return $this->policy->anyEnforcementOn() ? PasswordAuthDecision::allow(requireTwoFactor: true) : null;
        }

        $policy = $this->policy->current();

        if ($policy['enforce_site']) {
            $idp = $this->siteRedirectIdp($policy) ?? $this->firstDirectoryIdp();

            return $idp !== null
                ? $this->denyFor($idp, PasswordAuthDecision::BASIS_IDENTIFIER, $guard, self::REASON_SITE)
                : PasswordAuthDecision::deny(PasswordAuthDecision::BASIS_IDENTIFIER, reasonKey: self::REASON_SITE);
        }

        $idp = $this->enforcingIdpForDomain($this->domainOf($identifier));

        if ($idp === null) {
            return null;
        }

        if ($idp->protocol === 'ldap') {
            // 로그인이 여기 왔으면 디렉터리 검증자가 일치하지 않은 것(서버 확장 없음 등)이다. 비밀번호 찾기는 검증자를 거치지 않으므로
            // 그 이메일과 회사 비밀번호로 로그인하라고 안내한다.
            return PasswordAuthDecision::deny(
                PasswordAuthDecision::BASIS_IDENTIFIER,
                methodLabel: $this->labelOf($idp),
                reasonKey: in_array($purpose, self::RESET_PURPOSES, true) ? self::REASON_DIRECTORY_EMAIL : self::REASON_LDAP_UNAVAILABLE,
            );
        }

        return $this->denyTo($idp, PasswordAuthDecision::BASIS_IDENTIFIER, $guard);
    }

    /**
     * 2차 — 회원 기준 판정.
     *
     * @param  User  $user  비밀번호가 맞은 회원
     * @param  string  $guard  로그인 경로
     * @return PasswordAuthDecision|null 판정
     */
    private function decideByUser(User $user, string $guard): ?PasswordAuthDecision
    {
        if ($this->emergency->isEmergencyUser($user)) {
            return $this->policy->anyEnforcementOn() ? PasswordAuthDecision::allow(requireTwoFactor: true) : null;
        }

        $policy = $this->policy->current();

        if ($policy['enforce_site']) {
            $idp = $this->siteRedirectIdp($policy)
                ?? $this->preferredLinkedIdp($this->repository->activeLinkedIdps((int) $user->getKey()))
                ?? $this->firstDirectoryIdp();

            return $idp !== null
                ? $this->denyFor($idp, PasswordAuthDecision::BASIS_USER, $guard, self::REASON_SITE)
                : PasswordAuthDecision::deny(PasswordAuthDecision::BASIS_USER, reasonKey: self::REASON_SITE);
        }

        $domainIdp = $this->enforcingIdpForDomain($this->domainOf((string) $user->email));
        if ($domainIdp !== null && $domainIdp->protocol !== 'ldap') {
            return $this->denyTo($domainIdp, PasswordAuthDecision::BASIS_USER, $guard);
        }

        $ruleIdp = $this->userRuleIdp($user);

        if ($ruleIdp === false) {
            return null;
        }

        return $ruleIdp !== null
            ? $this->denyFor($ruleIdp, PasswordAuthDecision::BASIS_USER, $guard, self::REASON_ACCOUNT)
            : PasswordAuthDecision::deny(PasswordAuthDecision::BASIS_USER, reasonKey: self::REASON_ACCOUNT);
    }

    /**
     * 비밀번호를 만들거나 지정하는 목적의 판정 (D22, W36).
     *
     * @param  User  $user  대상 회원
     * @param  string  $guard  경로
     * @return PasswordAuthDecision|null 판정
     */
    private function decideSetPassword(User $user, string $guard): ?PasswordAuthDecision
    {
        if ($this->emergency->isEmergencyUser($user)) {
            return null;
        }

        if ($this->isEnforcedUser($user)) {
            $idp = $this->guidanceIdpFor($user);
            $fallback = $this->policy->current()['enforce_site'] ? self::REASON_SITE : self::REASON_ACCOUNT;

            return $idp !== null
                ? $this->denyFor($idp, PasswordAuthDecision::BASIS_USER, $guard, $fallback)
                : PasswordAuthDecision::deny(PasswordAuthDecision::BASIS_USER, reasonKey: $fallback);
        }

        $blocking = $this->repository->activeLinkedIdps((int) $user->getKey())
            ->first(fn (IdentityProvider $idp) => ! $idp->allow_local_password);

        if ($blocking === null) {
            return null;
        }

        return PasswordAuthDecision::deny(
            PasswordAuthDecision::BASIS_USER,
            methodLabel: $this->labelOf($blocking),
            redirectUrl: $blocking->protocol !== 'ldap' && $blocking->enabled ? $this->startPath($blocking, $guard) : null,
            reasonKey: self::REASON_SET_PASSWORD,
        );
    }

    /**
     * 회원 기준 강제 규칙(역할, 연결 회원 전원)에 걸리는지 봅니다.
     *
     * @param  User  $user  회원
     * @return IdentityProvider|false|null 걸리면 안내할 IdP(없으면 null), 안 걸리면 false
     */
    private function userRuleIdp(User $user): IdentityProvider|false|null
    {
        $policy = $this->policy->current();
        $linked = null;

        if ($policy['enforce_linked']) {
            $linked = $this->repository->activeLinkedIdps((int) $user->getKey());

            if ($linked->isNotEmpty()) {
                return $this->preferredLinkedIdp($linked);
            }
        }

        if ($policy['enforce_roles'] !== [] && $this->repository->userHasAnyRole($user, $policy['enforce_roles'])) {
            $linked ??= $this->repository->activeLinkedIdps((int) $user->getKey());

            return $this->preferredLinkedIdp($linked) ?? $this->firstRedirectIdp();
        }

        return false;
    }

    /**
     * 회원의 활성 연결 IdP 중 안내할 것 — 버튼으로 시작하는 리디렉션 IdP 먼저, 없으면 로그인 폼으로 들어가는 LDAP.
     *
     * @param  Collection<int, IdentityProvider>  $linked  활성 연결 IdP (정렬 순서)
     * @return IdentityProvider|null IdP (안내할 수 있는 연결이 없으면 null)
     */
    private function preferredLinkedIdp(Collection $linked): ?IdentityProvider
    {
        $enabled = $linked->filter(fn (IdentityProvider $idp) => $idp->enabled);

        return $enabled->first(fn (IdentityProvider $idp) => $idp->protocol !== 'ldap')
            ?? $enabled->first(fn (IdentityProvider $idp) => self::directoryLoginField($idp) !== null);
    }

    /**
     * 「비밀번호 로그인 막기」를 켠 활성 IdP 중 그 도메인을 가진 것.
     *
     * @param  string|null  $domain  이메일 도메인
     * @return IdentityProvider|null IdP
     */
    private function enforcingIdpForDomain(?string $domain): ?IdentityProvider
    {
        if ($domain === null) {
            return null;
        }

        return $this->enabledIdps()->first(fn (IdentityProvider $idp) => $idp->enforce_owned_domains && $idp->ownsDomain($domain));
    }

    /**
     * 사이트 전체 강제의 안내 IdP — 자동 이동 IdP, 없으면 버튼으로 시작할 수 있는 첫 IdP.
     *
     * @param  array<string, mixed>  $policy  정책
     * @return IdentityProvider|null IdP
     */
    private function siteRedirectIdp(array $policy): ?IdentityProvider
    {
        if (is_string($policy['auto_redirect_idp'] ?? null)) {
            $idp = $this->idps->findByKey($policy['auto_redirect_idp']);
            if ($idp !== null && $idp->enabled && $idp->protocol !== 'ldap') {
                return $idp;
            }
        }

        return $this->firstRedirectIdp();
    }

    /**
     * 리디렉션으로 시작하는(LDAP 이 아닌) 첫 활성 IdP.
     *
     * @return IdentityProvider|null IdP
     */
    private function firstRedirectIdp(): ?IdentityProvider
    {
        return $this->enabledIdps()->first(fn (IdentityProvider $idp) => $idp->protocol !== 'ldap' && ! $idp->isMock());
    }

    /**
     * 로그인 폼으로 들어갈 수 있는 첫 활성 LDAP IdP (사이트 전체 강제인데 버튼으로 시작할 IdP 가 없을 때의 안내).
     *
     * @return IdentityProvider|null IdP
     */
    private function firstDirectoryIdp(): ?IdentityProvider
    {
        return $this->enabledIdps()->first(fn (IdentityProvider $idp) => self::directoryLoginField($idp) !== null);
    }

    /**
     * 활성 IdP 목록 (IdP 상한 20 — 판정마다 읽어도 가볍다. 저장 직후 판정이 낡은 목록을 보지 않게 캐시하지 않는다).
     *
     * @return Collection<int, IdentityProvider> IdP
     */
    private function enabledIdps(): Collection
    {
        return $this->idps->enabled();
    }

    /**
     * 안내할 IdP 에 맞는 거절 판정 — 리디렉션 IdP 는 그 IdP 로 가는 경로, LDAP 은 경로 없이 로그인 폼 안내 사유.
     *
     * @param  IdentityProvider  $idp  안내할 IdP
     * @param  string  $basis  판정 기준
     * @param  string  $guard  경로
     * @param  string  $fallbackReason  LDAP 인데 폼으로 들어갈 길이 없을 때의 사유
     * @return PasswordAuthDecision 거절
     */
    private function denyFor(IdentityProvider $idp, string $basis, string $guard, string $fallbackReason): PasswordAuthDecision
    {
        if ($idp->protocol !== 'ldap') {
            return $this->denyTo($idp, $basis, $guard);
        }

        $reason = match (self::directoryLoginField($idp)) {
            'username' => self::REASON_DIRECTORY_USERNAME,
            'email' => self::REASON_DIRECTORY_EMAIL,
            default => $fallbackReason,
        };

        return PasswordAuthDecision::deny($basis, $this->labelOf($idp), reasonKey: $reason);
    }

    /**
     * IdP 로 안내하는 거절 판정.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $basis  판정 기준
     * @param  string  $guard  경로
     * @return PasswordAuthDecision 거절
     */
    private function denyTo(IdentityProvider $idp, string $basis, string $guard): PasswordAuthDecision
    {
        return PasswordAuthDecision::deny($basis, $this->labelOf($idp), $this->startPath($idp, $guard));
    }

    /**
     * IdP 로그인 시작 경로 (같은 출처).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $guard  경로
     * @return string 경로
     */
    public function startPath(IdentityProvider $idp, string $guard): string
    {
        return '/plugins/sirsoft-sso/login/'.$idp->key.'?'.http_build_query(['guard' => $guard === 'admin' ? 'admin' : 'user']);
    }

    /**
     * IdP 표시 이름 (로케일 맵).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<string, string> 이름
     */
    private function labelOf(IdentityProvider $idp): array
    {
        $labels = array_filter(is_array($idp->label) ? $idp->label : [], fn ($v) => is_string($v) && $v !== '');

        return $labels !== [] ? $labels : ['ko' => $idp->key, 'en' => $idp->key];
    }

    /**
     * 이메일 모양 문자열의 도메인 (소문자).
     *
     * @param  string  $identifier  입력
     * @return string|null 도메인 (이메일이 아니면 null)
     */
    private function domainOf(string $identifier): ?string
    {
        $at = strrpos($identifier, '@');

        if ($at === false || $at === strlen($identifier) - 1) {
            return null;
        }

        return mb_strtolower(trim(substr($identifier, $at + 1)));
    }
}
