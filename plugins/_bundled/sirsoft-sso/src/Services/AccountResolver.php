<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use App\Support\Auth\SystemOperation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\DTO\ResolveResult;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;

/**
 * IdP 가 확인한 신원을 G7 회원으로 결정합니다.
 *
 * 연결 키는 (IdP, subject) 다. 이메일로 회원을 찾는 곳은 둘뿐이다 — 소유 도메인 자동 연결(IdP 가 그 도메인 주인이고 OIDC 면
 * `email_verified === true`)과 연결 확인 화면의 후보 찾기(회원이 비밀번호나 메일 인증번호로 직접 증명해야 연결된다).
 *
 * 처음 온 사람의 순서: 소유 도메인 자동 → 연결 확인 → 자동 생성(JIT) → 거절(`not_registered`). 관리자, 최고관리자 계정은 자동,
 * 연결 확인으로 붙이지 않는다(위임 관리자가 자기 subject 를 최고관리자에 붙이는 권한 상승 방지) — 최고관리자가 직접 연결한다.
 *
 * @since 1.0.0
 */
class AccountResolver
{
    /** 연결 직후 훅 */
    public const ACTION_AFTER_LINK = 'sirsoft-sso.account.after_link';

    /** 재활성 직후 훅 */
    public const ACTION_AFTER_REACTIVATE = 'sirsoft-sso.account.after_reactivate';

    /** 자동 생성 데이터 필터 */
    public const FILTER_JIT_DATA = 'sirsoft-sso.jit.filter_registration_data';

    /** 자동 생성 이메일의 대체 도메인 (받을 수 없는 주소 — `UndeliverableEmail` 이 받는다) */
    public const FALLBACK_EMAIL_DOMAIN = 'sso.invalid';

    /**
     * @param  SsoAccountRepositoryInterface  $accounts  연결 저장소
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  PendingStore  $pending  대기 저장소
     * @param  StateStore  $states  묶음 쿠키
     */
    public function __construct(
        private readonly SsoAccountRepositoryInterface $accounts,
        private readonly UserRepositoryInterface $users,
        private readonly PendingStore $pending,
        private readonly StateStore $states,
    ) {}

    /**
     * 신원을 회원으로 결정합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  검증된 신원
     * @param  LoginIntent  $intent  시작 정보
     * @param  Request  $request  콜백 요청 (대기 토큰 묶음)
     * @return ResolveResult 결과
     */
    public function resolve(IdentityProvider $idp, ExternalIdentity $identity, LoginIntent $intent, Request $request): ResolveResult
    {
        return match ($intent->intent) {
            LoginIntent::LINK => $this->resolveLink($idp, $identity, $intent),
            LoginIntent::REAUTH => $this->resolveReauth($idp, $identity, $intent),
            default => $this->resolveLogin($idp, $identity, $intent, $request),
        };
    }

    /**
     * 처음 로그인 약관 동의를 마친 신원을 자동 생성합니다 (동의 대기 완료 API).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @return ResolveResult JIT 결과 또는 거절
     */
    public function completeConsent(IdentityProvider $idp, ExternalIdentity $identity): ResolveResult
    {
        if ($this->accounts->findByIdpSubject((int) $idp->getKey(), $identity->subject) !== null) {
            return ResolveResult::rejected('already_linked');
        }

        return $this->register($idp, $identity, recordConsents: true);
    }

    /**
     * 연결 확인을 마친 기존 회원에게 신원을 연결합니다 (연결 확인 완료 API).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  User  $user  확인된 회원
     * @return ResolveResult VERIFY 결과 또는 거절
     */
    public function completeVerifiedLink(IdentityProvider $idp, ExternalIdentity $identity, User $user): ResolveResult
    {
        if (self::isPrivileged($user)) {
            return ResolveResult::rejected('admin_target_manual_only');
        }

        return $this->attach($idp, $identity, $user, 'verify', ResolveResult::VERIFY);
    }

    /**
     * 대기 값에 실어 다음 요청으로 넘길 refresh_token 을 드라이버에서 꺼냅니다.
     *
     * 대기(동의, 연결 확인)를 거치면 회원 결정이 다음 요청(완료 API)에서 일어난다 — 그 요청의 드라이버는 새 인스턴스라 콜백 때 받은 토큰이
     * 없다. 대기 값은 암호화해 저장하고(`PendingStore`), 완료 경로가 드라이버에 되돌린다(`LinkVerificationService`). 대기 정보 응답에는 싣지 않는다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string|null refresh_token (없거나 드라이버가 다루지 않으면 null)
     */
    private static function carriedRefreshToken(IdentityProvider $idp): ?string
    {
        $driver = $idp->driver();

        return method_exists($driver, 'pullRefreshToken') ? $driver->pullRefreshToken() : null;
    }

    /**
     * 관리자, 최고관리자 계정인지 확인합니다 (자동 연결과 연결 확인 대상에서 뺀다).
     *
     * @param  User  $user  회원
     * @return bool 관리자면 true
     */
    public static function isPrivileged(User $user): bool
    {
        return $user->is_super === true || $user->isAdmin();
    }

    /**
     * 자동 생성 이메일을 고릅니다 — IdP 소유 도메인 주소만 쓰고, 아니면 subject 해시의 받을 수 없는 주소.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @return string 이메일
     */
    public static function registrationEmail(IdentityProvider $idp, ExternalIdentity $identity): string
    {
        if ($identity->email !== null && $idp->ownsDomain($identity->emailDomain())) {
            return $identity->email;
        }

        return substr(hash('sha256', $idp->getKey().'|'.$identity->subject), 0, 12).'@'.self::FALLBACK_EMAIL_DOMAIN;
    }

    /**
     * 로그인 의도의 결정.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  LoginIntent  $intent  시작 정보
     * @param  Request  $request  요청
     * @return ResolveResult 결과
     */
    private function resolveLogin(IdentityProvider $idp, ExternalIdentity $identity, LoginIntent $intent, Request $request): ResolveResult
    {
        $account = $this->accounts->findByIdpSubject((int) $idp->getKey(), $identity->subject);
        if ($account !== null) {
            return $this->loginLinked($account);
        }

        $sameEmailUser = $identity->email !== null ? $this->accounts->findUserByEmail($identity->email) : null;
        if ($sameEmailUser !== null && $sameEmailUser->isWithdrawn()) {
            $sameEmailUser = null;
        }

        // 1) 소유 도메인 자동 연결 — IdP 가 그 도메인 주인일 때만, OIDC 는 IdP 가 이메일을 검증했다고 알려 줄 때만.
        if ($sameEmailUser !== null
            && $idp->linkMethodEnabled('domain_auto')
            && $idp->ownsDomain($identity->emailDomain())
            && ($idp->protocol !== 'oidc' || $identity->emailVerified === true)
            && ! self::isPrivileged($sameEmailUser)) {
            return $this->attach($idp, $identity, $sameEmailUser, 'domain', ResolveResult::DOMAIN);
        }

        // 2) 연결 확인 — 같은 이메일 회원이 있으면 그 회원이 직접 증명해야 붙는다.
        if ($sameEmailUser !== null && $idp->linkMethodEnabled('verify')) {
            if (self::isPrivileged($sameEmailUser)) {
                return ResolveResult::rejected('admin_target_manual_only');
            }

            $token = $this->pending->create(PendingStore::TYPE_LINK, [
                'idp_id' => (int) $idp->getKey(),
                'guard' => $intent->guard,
                'redirect' => $intent->redirect,
                'identity' => $identity->toArray(),
                'user_id' => (int) $sameEmailUser->getKey(),
                'failures' => 0,
                'refresh_token' => self::carriedRefreshToken($idp),
            ], $this->states->ensureBind($request));

            return new ResolveResult(ResolveResult::PENDING_LINK, pendingToken: $token);
        }

        // 3) 자동 생성.
        if ($idp->isJit()) {
            $email = self::registrationEmail($idp, $identity);
            if ($this->accounts->findUserByEmail($email) !== null) {
                return ResolveResult::rejected('email_in_use');
            }

            if ($idp->require_consent) {
                $token = $this->pending->create(PendingStore::TYPE_CONSENT, [
                    'idp_id' => (int) $idp->getKey(),
                    'guard' => $intent->guard,
                    'redirect' => $intent->redirect,
                    'identity' => $identity->toArray(),
                    'refresh_token' => self::carriedRefreshToken($idp),
                ], $this->states->ensureBind($request));

                return new ResolveResult(ResolveResult::PENDING_CONSENT, pendingToken: $token);
            }

            return $this->register($idp, $identity, recordConsents: false);
        }

        return ResolveResult::rejected('not_registered');
    }

    /**
     * 이미 연결된 신원의 로그인 — 퇴사 처리된 연결은 출처에 따라 다시 켜거나 거절한다.
     *
     * 주기 점검, 다음 로그인 차단이 끈 연결은 IdP 가 방금 인증해 준 것이 재직 증거라 다시 켠다. SCIM, 관리자가 끈 연결은 그쪽이
     * 진실이라 로그인으로 풀지 않는다.
     *
     * @param  SsoAccount  $account  연결
     * @return ResolveResult 결과
     */
    private function loginLinked(SsoAccount $account): ResolveResult
    {
        $user = $this->users->findById((int) $account->user_id);
        if (! $user instanceof User || $user->isWithdrawn()) {
            return ResolveResult::rejected('not_registered');
        }

        if (! $account->active) {
            if (! in_array($account->deprovision_reason, SsoAccount::REACTIVATABLE_ON_LOGIN, true)) {
                return ResolveResult::rejected('deprovisioned');
            }

            $this->reactivate($account, $user);
        }

        return new ResolveResult(ResolveResult::LOGIN, $user, $account);
    }

    /**
     * 연결을 다시 켜고, 이 연결 때문에 차단한 회원이면 차단을 풉니다.
     *
     * 해제는 회원의 차단 시각이 이 연결이 차단한 시각과 같을 때만 한다 — 그 뒤 누가 다른 이유로 다시 차단했으면 같지 않다.
     *
     * @param  SsoAccount  $account  연결
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    private function reactivate(SsoAccount $account, User $user): void
    {
        $blockedByThis = $account->blocked_user_at !== null
            && $user->status === UserStatus::Blocked->value
            && $user->blocked_at !== null
            && $user->blocked_at->equalTo($account->blocked_user_at);

        $this->accounts->update($account, [
            'active' => true,
            'deprovisioned_at' => null,
            'deprovision_reason' => null,
            'blocked_user_at' => null,
        ]);

        if ($blockedByThis && method_exists(UserService::class, 'unblockUser')) {
            SystemOperation::run('external:sirsoft-sso:reactivate', fn () => app(UserService::class)->unblockUser($user, [
                'reason_key' => 'sirsoft-sso::messages.reactivated',
                'source' => 'sirsoft-sso:login',
            ]));
        }

        HookManager::doAction(self::ACTION_AFTER_REACTIVATE, $account, $user, 'login');
    }

    /**
     * 마이페이지 연결 의도의 결정 — 다른 회원에게 연결된 신원이면 거절.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  LoginIntent  $intent  시작 정보 (티켓의 회원)
     * @return ResolveResult 결과
     */
    private function resolveLink(IdentityProvider $idp, ExternalIdentity $identity, LoginIntent $intent): ResolveResult
    {
        $user = $intent->linkUserId !== null ? $this->users->findById($intent->linkUserId) : null;
        if (! $user instanceof User || $user->isWithdrawn()) {
            return ResolveResult::rejected('ticket_invalid');
        }

        $existing = $this->accounts->findByIdpSubject((int) $idp->getKey(), $identity->subject);
        if ($existing !== null) {
            return (int) $existing->user_id === (int) $user->getKey()
                ? new ResolveResult(ResolveResult::LINKED, $user, $existing)
                : ResolveResult::rejected('already_linked');
        }

        if ($this->accounts->findByIdpUser((int) $idp->getKey(), (int) $user->getKey()) !== null) {
            return ResolveResult::rejected('already_linked');
        }

        return $this->attach($idp, $identity, $user, 'verify', ResolveResult::LINKED);
    }

    /**
     * 재확인 의도의 결정 — 티켓의 회원이 이 신원에 연결돼 있고 연결이 활성일 때만 성공.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  LoginIntent  $intent  시작 정보
     * @return ResolveResult 결과
     */
    private function resolveReauth(IdentityProvider $idp, ExternalIdentity $identity, LoginIntent $intent): ResolveResult
    {
        $account = $this->accounts->findByIdpSubject((int) $idp->getKey(), $identity->subject);
        if ($account === null || ! $account->active || $intent->linkUserId === null || (int) $account->user_id !== $intent->linkUserId) {
            return ResolveResult::rejected('ticket_invalid');
        }

        $user = $this->users->findById((int) $account->user_id);

        return $user instanceof User
            ? new ResolveResult(ResolveResult::REAUTH, $user, $account)
            : ResolveResult::rejected('ticket_invalid');
    }

    /**
     * 처음 온 사람을 비밀번호 없는 회원으로 만들고 연결합니다.
     *
     * 생성은 코어 `AuthService::register()` 로만 한다(토큰 없음 — 로그인은 인계로). 본인인증 가입 정책은 IdP 설정이 「적용 안 함」이면
     * 사유를 남기고 건너뛴다. 생성과 연결은 시스템 작업 문맥 안에서 돌린다(운영자가 건 사람용 본인인증 정책이 대상 회원으로 판정돼
     * 멈추지 않게).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  bool  $recordConsents  동의 화면을 거쳤는지 (거짓이면 동의 기록을 남기지 않는다)
     * @return ResolveResult JIT 결과 또는 거절
     */
    private function register(IdentityProvider $idp, ExternalIdentity $identity, bool $recordConsents): ResolveResult
    {
        $email = self::registrationEmail($idp, $identity);
        if ($this->accounts->findUserByEmail($email) !== null) {
            return ResolveResult::rejected('email_in_use');
        }

        $data = HookManager::applyFilters(self::FILTER_JIT_DATA, [
            'name' => $this->registrationName($idp, $identity),
            'email' => $email, // audit:allow resource-member-email-ungated 가입 저장 데이터(응답 아님)
            'language' => app()->getLocale(),
        ], $identity, $idp);
        $data = is_array($data) ? $data : [];
        $data['email'] = $email;

        $options = [
            'issue_token' => false,
            'identity_policy_reason' => 'external:sirsoft-sso:'.$idp->key,
            'record_consents' => $recordConsents,
        ];
        if ($idp->identity_policy === 'skip') {
            $options['identity_policy'] = 'skip';
        }

        $user = SystemOperation::run('external:sirsoft-sso:jit', function () use ($data, $options): User {
            unset($data['password'], $data['password_confirmation'], $data['is_super']);

            return app(AuthService::class)->register($data, $options)['user'];
        });

        return $this->attach($idp, $identity, $user, 'jit', ResolveResult::JIT);
    }

    /**
     * 회원과 신원의 연결을 만들고 훅을 발행합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  User  $user  회원
     * @param  string  $via  연결 방식
     * @param  string  $outcome  결과 종류
     * @return ResolveResult 결과
     */
    private function attach(IdentityProvider $idp, ExternalIdentity $identity, User $user, string $via, string $outcome): ResolveResult
    {
        if ($this->accounts->findByIdpUser((int) $idp->getKey(), (int) $user->getKey()) !== null) {
            return ResolveResult::rejected('already_linked');
        }

        $account = DB::transaction(fn () => $this->accounts->create([
            'idp_id' => (int) $idp->getKey(),
            'user_id' => (int) $user->getKey(),
            'subject' => $identity->subject,
            'subject_format' => $identity->subjectFormat !== null ? mb_substr($identity->subjectFormat, 0, 100) : null,
            'email' => $identity->email !== null ? mb_substr($identity->email, 0, 255) : null, // audit:allow resource-member-email-ungated 연결 행 저장값(응답 아님)
            'display_name' => $identity->displayName,
            'linked_via' => $via,
            'active' => true,
            'linked_at' => now(),
        ]));

        HookManager::doAction(self::ACTION_AFTER_LINK, $account, $user, $via);

        return new ResolveResult($outcome, $user, $account);
    }

    /**
     * 자동 생성 회원 이름 — IdP 표시 이름, 없으면 이메일 앞부분, 그것도 없으면 「{IdP 이름} 회원」.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @return string 이름
     */
    private function registrationName(IdentityProvider $idp, ExternalIdentity $identity): string
    {
        if ($identity->displayName !== null && trim($identity->displayName) !== '') {
            return mb_substr(trim($identity->displayName), 0, 50);
        }

        if ($identity->email !== null && str_contains($identity->email, '@')) {
            return mb_substr(strstr($identity->email, '@', true) ?: $identity->email, 0, 50);
        }

        return mb_substr((string) __('sirsoft-sso::messages.jit.default_name', ['idp' => $idp->localizedLabel()]), 0, 50);
    }
}
