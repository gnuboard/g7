<?php

namespace Plugins\Sirsoft\Sso\Services\Admin;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Extension\HookManager;
use App\Http\Resources\Traits\ExposesMemberContact;
use App\Models\User;
use App\Services\AuthService;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Listeners\CleanSsoAccountsOnUserLeave;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminIdpRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\AccountResolver;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;

/**
 * 관리자 「회원 연결」 — 목록, 단건 연결 추가, 사전 등록(비밀번호 없는 회원), 연결 해제 (D6 ③, D5, P7, W26, W42).
 *
 * 관리자, 최고관리자 계정을 대상으로 하는 연결은 행위자가 최고관리자일 때만 한다(W26 — 위임 관리자가 자기 회사 계정을 최고관리자에
 * 붙이는 권한 상승 차단). 연결 키는 (IdP, subject) 이고 이메일은 대상 회원을 고르는 데만 쓴다.
 *
 * @since 1.0.0
 */
class AccountAdminService
{
    use ExposesMemberContact;
    use ResolvesActivityLogType;

    /** @var array<int, string> 관리자 화면이 고르는 연결 방식 */
    public const ADMIN_VIA = ['admin', 'csv'];

    /**
     * @param  AdminAccountRepositoryInterface  $accounts  관리자 연결 저장소
     * @param  SsoAccountRepositoryInterface  $links  연결 저장소 (흐름과 공용)
     * @param  AdminIdpRepositoryInterface  $idps  IdP 저장소
     * @param  AuthService  $auth  코어 가입 (사전 등록)
     */
    public function __construct(
        private readonly AdminAccountRepositoryInterface $accounts,
        private readonly SsoAccountRepositoryInterface $links,
        private readonly AdminIdpRepositoryInterface $idps,
        private readonly AuthService $auth,
    ) {}

    /**
     * 연결 목록.
     *
     * @param  array<string, mixed>  $filters  조건
     * @return array{idps: array<int, array<string, mixed>>, data: array<int, array<string, mixed>>, pagination: array<string, int>} 목록 (필터와 연결 추가 창이 쓰는 IdP 목록 포함)
     */
    public function list(array $filters): array
    {
        $page = $this->accounts->paginate($filters);

        return [
            'idps' => $this->idps->all()->map(fn (IdentityProvider $idp) => ['id' => (int) $idp->id, 'key' => $idp->key, 'label' => $idp->localizedLabel(), 'protocol' => $idp->protocol])->values()->all(),
            'data' => collect($page->items())->map(fn (SsoAccount $account) => $this->row($account))->values()->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * 연결을 추가합니다 — 기존 회원(`user_id`)에 붙이거나, 회원이 없으면 비밀번호 없이 사전 등록(`new_user{name,email}`)한 뒤 붙인다.
     *
     * @param  array{idp_id: int, subject: string, user_id?: int|null, new_user?: array{name?: string, email?: string}|null}  $data  검증된 값
     * @param  User  $actor  관리자
     * @param  string  $via  연결 방식 (admin, csv)
     * @return array<string, mixed> 만든 연결 행
     *
     * @throws SsoException 거절 (`idp_not_found`, `already_linked`, `email_in_use`, `admin_target_manual_only`)
     */
    public function link(array $data, User $actor, string $via = 'admin'): array
    {
        $idp = $this->idps->findById((int) $data['idp_id']) ?? throw new SsoException('idp_not_found', '', 404);
        $subject = trim((string) $data['subject']);

        if ($this->links->findByIdpSubject((int) $idp->id, $subject) !== null) {
            throw new SsoException('already_linked', '', 409);
        }

        $user = ! empty($data['user_id']) ? $this->accounts->findUser((int) $data['user_id']) : null;

        if (! empty($data['user_id']) && $user === null) {
            throw new SsoException('user_not_found', '', 404);
        }

        if ($user !== null) {
            $this->assertLinkable($idp, $user, $actor);
        } else {
            $user = $this->preregister($idp, (array) ($data['new_user'] ?? []), $via);
        }

        return $this->row($this->attach($idp, $user, $subject, $via));
    }

    /**
     * 연결을 해제합니다 — 비밀번호가 없고 이 연결이 유일한 수단이면 `confirm_last_method` 가 있어야 한다.
     *
     * @param  int  $id  연결 ID
     * @param  bool  $confirmLastMethod  마지막 로그인 수단 해제 확인
     * @return void 반환값 없음
     *
     * @throws SsoException 없음 404, 마지막 수단 409(`last_method`)
     */
    public function unlink(int $id, bool $confirmLastMethod): void
    {
        $account = $this->accounts->findById($id) ?? throw new SsoException('account_not_found', '', 404);
        $user = $account->user;

        if ($user instanceof User && ! $confirmLastMethod && $this->isLastMethod($user, $account)) {
            throw new SsoException('last_method', '', 409);
        }

        $this->detach($account, $user, 'admin');
    }

    /**
     * 기존 회원에게 연결을 붙여도 되는지 확인합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  User  $user  대상 회원
     * @param  User|null  $actor  관리자 (CSV 는 null — 관리자 대상은 언제나 건너뛴다)
     * @return void 반환값 없음
     *
     * @throws SsoException 관리자 대상(`admin_target_manual_only` 403), 이미 그 IdP 와 연결(`already_linked` 409)
     */
    public function assertLinkable(IdentityProvider $idp, User $user, ?User $actor): void
    {
        if (AccountResolver::isPrivileged($user) && ($actor === null || ! $actor->isSuperAdmin())) {
            throw new SsoException('admin_target_manual_only', '', 403);
        }

        if ($this->links->findByIdpUser((int) $idp->id, (int) $user->getKey()) !== null) {
            throw new SsoException('already_linked', '', 409);
        }
    }

    /**
     * 비밀번호 없는 회원을 만듭니다 (사전 등록 — 회사가 만드는 계정이라 가입 끄기·본인인증 가입 정책·약관 동의 기록과 무관).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array{name?: string, email?: string}  $newUser  이름, 이메일
     * @param  string  $via  연결 방식
     * @return User 만든 회원
     *
     * @throws SsoException 이메일 없음·중복
     */
    public function preregister(IdentityProvider $idp, array $newUser, string $via): User
    {
        $email = mb_strtolower(trim((string) ($newUser['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SsoException('email_required', '', 422);
        }

        if ($this->accounts->findUserByEmail($email) !== null) {
            throw new SsoException('email_in_use', '', 409);
        }

        $name = trim((string) ($newUser['name'] ?? ''));
        $result = $this->auth->register([
            'name' => $name !== '' ? mb_substr($name, 0, 50) : mb_substr(strstr($email, '@', true) ?: $email, 0, 50),
            'email' => $email, // audit:allow resource-member-email-ungated reason: 가입 서비스에 넘기는 입력값이다(응답·캐시가 아님)
        ], [
            'issue_token' => false,
            'identity_policy' => 'skip',
            'identity_policy_reason' => 'external:sirsoft-sso:'.$idp->key,
            'record_consents' => false,
        ]);

        /** @var User $user */
        $user = $result['user'];

        $this->logActivity('sso.preregistered', [
            'loggable' => $user,
            'description_key' => 'sirsoft-sso::activity_log.description.sso.preregistered',
            'description_params' => ['user_name' => (string) $user->name, 'idp' => $idp->localizedLabel()],
            'properties' => ['idp_key' => $idp->key, 'via' => $via, 'target_user_id' => (int) $user->getKey()],
        ]);

        return $user;
    }

    /**
     * 연결 행을 만들고 연결 훅을 냅니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  User  $user  회원
     * @param  string  $subject  IdP 고유 식별자
     * @param  string  $via  연결 방식
     * @return SsoAccount 연결
     */
    public function attach(IdentityProvider $idp, User $user, string $subject, string $via): SsoAccount
    {
        $account = $this->links->create([
            'idp_id' => (int) $idp->id,
            'user_id' => (int) $user->getKey(),
            'subject' => $subject,
            'linked_via' => in_array($via, self::ADMIN_VIA, true) ? $via : 'admin',
            'active' => true,
            'linked_at' => now(),
        ]);

        HookManager::doAction(AccountResolver::ACTION_AFTER_LINK, $account, $user, $account->linked_via);

        return $account->setRelation('idp', $idp)->setRelation('user', $user);
    }

    /**
     * 연결을 지우고 해제 훅을 냅니다.
     *
     * @param  SsoAccount  $account  연결
     * @param  User|null  $user  회원
     * @param  string  $reason  사유 (admin, user)
     * @return void 반환값 없음
     */
    public function detach(SsoAccount $account, ?User $user, string $reason): void
    {
        if ($user instanceof User) {
            HookManager::doAction(CleanSsoAccountsOnUserLeave::ACTION_BEFORE_UNLINK, $account, $user, $reason);
        }

        $this->accounts->delete($account);

        if ($user instanceof User) {
            HookManager::doAction(CleanSsoAccountsOnUserLeave::ACTION_AFTER_UNLINK, $account, $user, $reason);
        }
    }

    /**
     * 이 연결이 회원의 마지막 로그인 수단인지 확인합니다 (비밀번호 없음 + 연결 1개).
     *
     * @param  User  $user  회원
     * @param  SsoAccount  $account  해제할 연결
     * @return bool 마지막 수단이면 true
     */
    public function isLastMethod(User $user, SsoAccount $account): bool
    {
        if ($user->hasPassword()) {
            return false;
        }

        return $this->links->forUser((int) $user->getKey())->reject(fn (SsoAccount $a) => (int) $a->id === (int) $account->id)->isEmpty();
    }

    /**
     * 목록 1행 — subject 는 가린다.
     *
     * @param  SsoAccount  $account  연결 (회원, IdP 함께)
     * @return array<string, mixed> 행
     */
    private function row(SsoAccount $account): array
    {
        $user = $account->user;
        $idp = $account->idp;

        return [
            'id' => (int) $account->id,
            'user' => $user instanceof User ? [
                'id' => (int) $user->getKey(),
                'uuid' => (string) $user->uuid,
                'name' => (string) $user->name,
                'email' => $this->canExposeMemberEmail(null, (int) $user->getKey()) ? (string) $user->email : null,
                'has_password' => $user->hasPassword(),
                'is_privileged' => AccountResolver::isPrivileged($user),
            ] : null,
            'idp' => $idp instanceof IdentityProvider ? ['id' => (int) $idp->id, 'key' => $idp->key, 'label' => $idp->localizedLabel(), 'protocol' => $idp->protocol] : null,
            'subject_masked' => LoginFlowService::mask((string) $account->subject),
            'linked_via' => $account->linked_via,
            'active' => (bool) $account->active,
            'deprovisioned_at' => $account->deprovisioned_at?->toIso8601String(),
            'deprovision_reason' => $account->deprovision_reason,
            'linked_at' => $account->linked_at?->toIso8601String(),
            'last_login_at' => $account->last_login_at?->toIso8601String(),
        ];
    }
}
