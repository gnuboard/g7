<?php

namespace Plugins\Sirsoft\Sso\Services\Ldap;

use App\Contracts\Extension\ExternalCredentialVerifierInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\Auth\ExternalCredentialMatch;
use App\Extension\Auth\ExternalCredentialResult;
use App\Extension\HookManager;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\Drivers\Ldap\LdapDriver;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\DTO\ResolveResult;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\LoginAttemptRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\AccountResolver;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;

/**
 * 코어 외부 자격 증명 검증자 — 로그인 칸의 아이디·비밀번호를 활성 LDAP/AD IdP 로 확인한다.
 *
 * 이 검증자 하나가 모든 LDAP IdP 를 대표한다. 코어 `AuthService::login()` 이 비밀번호 인증 정책보다 먼저 `match()` 를 묻고, 일치하면
 * 비밀번호는 G7 이 아니라 디렉터리로 간다. 인증되면 회원 결정(연결, 자동 생성, 동의 대기, 연결 확인 대기)은 리디렉션 프로토콜과 같은
 * `AccountResolver` 가 하고, 로그인은 코어 `loginVerifiedUser()` 가 마친다(잠금, 상태, 관리자 경로, 2단계 인증, 휴면).
 *
 * 일치 규칙: 이메일이면 그 도메인을 소유한 첫 LDAP IdP, 사내 아이디면 `accept_username` 이 켜진 첫 LDAP IdP(정렬 순서).
 *
 * @since 1.0.0
 */
class SsoCredentialVerifier implements ExternalCredentialVerifierInterface
{
    /** 검증자 식별자 */
    public const ID = 'sirsoft-sso';

    /** @var array<int, string> G7 로그인 실패 횟수에 세지 않는 사유 — 디렉터리 장애와 설정 문제 */
    private const NOT_COUNTED = ['ldap_unavailable', 'driver_unavailable', 'directory_unavailable', 'idp_disabled'];

    /** @var array<int, IdentityProvider>|null 이 요청의 활성 LDAP IdP */
    private ?array $ldapIdps = null;

    /**
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  SsoAccountRepositoryInterface  $accounts  연결 저장소
     * @param  LoginAttemptRepositoryInterface  $attempts  시도 이력
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  AccountResolver  $resolver  회원 결정
     * @param  LoginFlowService  $flow  인계 문맥
     * @param  LdapExtension  $extension  ext-ldap 유무
     */
    public function __construct(
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly SsoAccountRepositoryInterface $accounts,
        private readonly LoginAttemptRepositoryInterface $attempts,
        private readonly UserRepositoryInterface $users,
        private readonly AccountResolver $resolver,
        private readonly LoginFlowService $flow,
        private readonly LdapExtension $extension,
    ) {}

    /**
     * 검증자 식별자를 반환합니다.
     *
     * @return string sirsoft-sso
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * 쓸 수 있는지 — ext-ldap 이 있고 활성 LDAP IdP 가 하나 이상.
     *
     * @return bool 쓸 수 있으면 true
     */
    public function isAvailable(): bool
    {
        return $this->extension->loaded() && $this->ldapIdps() !== [];
    }

    /**
     * 사내 아이디(이메일이 아닌 값)를 받는지 — `accept_username` 이 켜진 활성 LDAP IdP 가 있으면.
     *
     * @param  string  $guard  로그인 경로
     * @return bool 받으면 true
     */
    public function acceptsUsernames(string $guard): bool
    {
        foreach ($this->ldapIdps() as $idp) {
            if ((bool) $idp->configValue('accept_username', false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 식별자를 맡을 LDAP IdP 를 고르고, 이미 연결된 회원이면 그 회원을 함께 돌려줍니다(코어가 바인드 전에 잠금을 본다).
     *
     * 연결된 회원은 디렉터리의 고유 식별자로 찾는다 — 이메일, 사내 아이디, UPN 어느 것으로 들어와도 같은 회원이 된다. 디렉터리를 읽지
     * 못하면 회원 없이 일치만 돌려준다(검증 단계가 `ldap_unavailable` 로 끝난다).
     *
     * @param  string  $identifier  로그인 칸 입력
     * @param  string  $guard  로그인 경로
     * @return ExternalCredentialMatch|null 일치, 맡을 IdP 가 없으면 null
     */
    public function match(string $identifier, string $guard): ?ExternalCredentialMatch
    {
        $identifier = trim($identifier);
        $idp = $this->pickIdp($identifier);
        if ($idp === null) {
            return null;
        }

        $user = null;
        try {
            $entry = $this->driver($idp)->lookup($idp, $identifier);
            $account = $entry !== null ? $this->accounts->findByIdpSubject((int) $idp->getKey(), $entry->subject) : null;
            $user = $account !== null ? $this->users->findById((int) $account->user_id) : null;
        } catch (SsoException) {
            $user = null;
        }

        return new ExternalCredentialMatch($user, $idp->key, $identifier);
    }

    /**
     * 디렉터리로 비밀번호를 확인하고 회원을 정합니다.
     *
     * @param  ExternalCredentialMatch  $match  일치 결과
     * @param  string  $password  입력 비밀번호
     * @param  string  $guard  로그인 경로
     * @return ExternalCredentialResult 인증, 거절, 화면 단계 필요
     */
    public function verify(ExternalCredentialMatch $match, string $password, string $guard): ExternalCredentialResult
    {
        $request = request();
        $guard = in_array($guard, LoginIntent::GUARDS, true) ? $guard : 'user';
        $idp = $this->idps->findByKey($match->handle);
        if ($idp === null || ! $idp->enabled || $idp->protocol !== LdapDriver::KEY) {
            return $this->reject(null, $guard, 'idp_disabled', $match, $request);
        }

        try {
            $identity = $this->driver($idp)->authenticate($idp, $match->display, $password);
        } catch (SsoException $e) {
            return $this->reject($idp, $guard, $e->errorCode, $match, $request);
        }

        $filtered = HookManager::applyFilters(LoginFlowService::FILTER_IDENTITY, $identity, $idp);
        if ($filtered instanceof ExternalIdentity && $filtered->subject !== '') {
            $identity = $filtered;
        }
        $this->idps->recordOutcome($idp, null);

        $intent = new LoginIntent(LoginIntent::LOGIN, $guard, '/', (int) $idp->getKey(), $idp->key, '', '', '', '', createdAt: time());

        try {
            $result = $this->resolver->resolve($idp, $identity, $intent, $request);
        } catch (SsoException $e) {
            return $this->reject($idp, $guard, $e->errorCode, $match, $request, SsoLoginAttempt::OUTCOME_DENIED);
        }

        $screen = $guard === 'admin' ? '/admin/login' : '/login';

        switch ($result->outcome) {
            case ResolveResult::PENDING_CONSENT:
                $this->record($idp, $guard, SsoLoginAttempt::OUTCOME_PENDING, null, null, $request);

                return ExternalCredentialResult::stepRequired($screen.'?'.http_build_query(['sso_pending' => (string) $result->pendingToken]), 'auth.external_step_required');

            case ResolveResult::PENDING_LINK:
                $this->record($idp, $guard, SsoLoginAttempt::OUTCOME_LINK_PENDING, null, null, $request);

                return ExternalCredentialResult::stepRequired($screen.'?'.http_build_query(['sso_link' => (string) $result->pendingToken]), 'auth.external_step_required');
        }

        if (! $result->logsIn() || $result->user === null || $result->account === null) {
            return $this->reject($idp, $guard, (string) ($result->errorCode ?? 'not_registered'), $match, $request, SsoLoginAttempt::OUTCOME_DENIED);
        }

        $user = $result->user;
        $account = $this->accounts->update($result->account, [
            'last_login_at' => now(),
            'email' => $identity->email !== null ? mb_substr($identity->email, 0, 255) : $result->account->email, // audit:allow resource-member-email-ungated 연결 행 저장값(응답 아님)
            'display_name' => $identity->displayName ?? $result->account->display_name,
        ]);

        HookManager::doAction(LoginFlowService::ACTION_AFTER_RESOLVE, $user, $account, $identity, $result->outcome);
        $context = $this->flow->handoffContext($user, $account, $identity, $idp);
        HookManager::doAction(LoginFlowService::ACTION_AFTER_SSO_LOGIN, $user, $account);

        $this->record($idp, $guard, SsoLoginAttempt::OUTCOME_HANDOFF, (int) $user->getKey(), null, $request);

        return ExternalCredentialResult::authenticated($user, $context);
    }

    /**
     * 식별자를 맡을 LDAP IdP.
     *
     * @param  string  $identifier  입력
     * @return IdentityProvider|null IdP
     */
    private function pickIdp(string $identifier): ?IdentityProvider
    {
        if ($identifier === '' || mb_strlen($identifier) > 255) {
            return null;
        }

        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $domain = $isEmail ? mb_strtolower(substr($identifier, strrpos($identifier, '@') + 1)) : null;

        foreach ($this->ldapIdps() as $idp) {
            if ($isEmail ? $idp->ownsDomain($domain) : (bool) $idp->configValue('accept_username', false)) {
                return $idp;
            }
        }

        return null;
    }

    /**
     * 이 요청의 활성 LDAP IdP (정렬 순서).
     *
     * @return array<int, IdentityProvider> 목록
     */
    private function ldapIdps(): array
    {
        if ($this->ldapIdps === null) {
            try {
                $this->ldapIdps = $this->idps->enabled()->filter(fn (IdentityProvider $idp) => $idp->protocol === LdapDriver::KEY)->values()->all();
            } catch (\Throwable $e) {
                report($e);
                $this->ldapIdps = [];
            }
        }

        return $this->ldapIdps;
    }

    /**
     * IdP 의 LDAP 드라이버.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return LdapDriver 드라이버
     *
     * @throws SsoException 드라이버를 쓸 수 없으면 `driver_unavailable`
     */
    private function driver(IdentityProvider $idp): LdapDriver
    {
        $driver = $idp->driver();
        if (! $driver instanceof LdapDriver || ! $driver->isAvailable()) {
            throw new SsoException('driver_unavailable');
        }

        return $driver;
    }

    /**
     * 거절을 기록하고 코어 거절 결과를 만듭니다.
     *
     * 실패 횟수는 회원이 확정된 경우(코어가 판단 — 일치 결과에 회원이 있을 때)에만 코어가 센다. 디렉터리 장애, 설정 문제는 세지 않는다.
     *
     * @param  IdentityProvider|null  $idp  IdP
     * @param  string  $guard  로그인 경로
     * @param  string  $code  사유 코드
     * @param  ExternalCredentialMatch  $match  일치 결과
     * @param  Request  $request  요청
     * @param  string  $outcome  기록할 결과
     * @return ExternalCredentialResult 거절
     */
    private function reject(?IdentityProvider $idp, string $guard, string $code, ExternalCredentialMatch $match, Request $request, string $outcome = SsoLoginAttempt::OUTCOME_FAILED): ExternalCredentialResult
    {
        $this->record($idp, $guard, $outcome, $match->user !== null ? (int) $match->user->getKey() : null, $code, $request);

        $counted = ! in_array($code, self::NOT_COUNTED, true);
        if ($idp !== null && ! $counted) {
            $this->idps->recordOutcome($idp, $code);
        }

        return ExternalCredentialResult::rejected($code, $counted && $code === 'ldap_invalid_credentials');
    }

    /**
     * 로그인 시도 1행을 남깁니다.
     *
     * @param  IdentityProvider|null  $idp  IdP
     * @param  string  $guard  로그인 경로
     * @param  string  $outcome  결과
     * @param  int|null  $userId  회원
     * @param  string|null  $reason  사유 코드
     * @param  Request  $request  요청
     * @return void 반환값 없음
     */
    private function record(?IdentityProvider $idp, string $guard, string $outcome, ?int $userId, ?string $reason, Request $request): void
    {
        $this->attempts->record([
            'idp_id' => $idp !== null ? (int) $idp->getKey() : null,
            'user_id' => $userId,
            'intent' => LoginIntent::LOGIN,
            'guard' => $guard,
            'outcome' => $outcome,
            'reason_code' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }
}
