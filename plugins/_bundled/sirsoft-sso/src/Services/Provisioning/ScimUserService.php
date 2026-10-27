<?php

namespace Plugins\Sirsoft\Sso\Services\Provisioning;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use App\Support\Auth\SystemOperation;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Exceptions\ScimException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoUserAttributeRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\AccountResolver;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;
use Plugins\Sirsoft\Sso\Services\RoleSyncService;

/**
 * SCIM 2.0 Users — IdP 가 G7 회원을 만들고, 고치고, 퇴사 처리한다.
 *
 * SCIM 자원 하나 = 그 IdP 의 회원 연결 1행(`sso_accounts`) — 자원 id 는 연결 id 다. 연결 키(subject)는 IdP 설정 `scim_match`
 * (`userName` 기본 / `externalId`)의 값이며, IdP 가 SAML/OIDC 로그인 때 보내는 식별자와 같아야 SCIM 으로 만든 회원과 로그인이 이어진다.
 *
 *  - 생성: 같은 연결이 있으면 409. 같은 이메일 회원이 있으면 그 IdP 가 소유 도메인 자동 연결을 켰고 도메인이 그 IdP 것이며 회원이 관리자가
 *    아닐 때만 연결하고, 아니면 409 uniqueness. 회원이 없으면 비밀번호 없는 회원을 만든다(본인인증 정책 생략, 약관 동의 기록 없음 —
 *    회사가 만드는 계정). 이메일은 그 IdP 소유 도메인일 때만 쓰고 아니면 받을 수 없는 주소로 만든다.
 *  - 이메일 변경: 소유 도메인일 때만, 다른 회원이 쓰면 건너뛴다(속성 동기화와 같은 규칙).
 *  - `active=false`, DELETE: 퇴사 처리(차단 + 토큰 회수). 회원을 지우지 않는다. `active=true`: 관리자가 끈 연결이 아니면 재활성.
 *
 * 모든 쓰기는 시스템 작업 문맥(행위자 없음)에서 돈다.
 *
 * @since 1.0.0
 */
class ScimUserService
{
    /** `userName` 을 보관하는 추가 속성 키 (대조 키가 externalId 일 때도 목록 필터가 찾을 수 있게) */
    public const USER_NAME_ATTRIBUTE = 'scim_user_name';

    /** @var array<int, string> 목록 필터로 받는 속성 */
    public const FILTER_ATTRIBUTES = ['username', 'externalid', 'emails.value'];

    /** @var array<int, string> 추가 속성으로 두는 SCIM 값 */
    private const ATTRIBUTE_FIELDS = ['title' => 'title', 'department' => 'department', 'employeeNumber' => 'employee_number'];

    /**
     * @param  ProvisioningAccountRepositoryInterface  $accounts  프로비저닝 연결 조회
     * @param  SsoAccountRepositoryInterface  $links  연결 저장소
     * @param  SsoUserAttributeRepositoryInterface  $attributes  추가 속성
     * @param  UserService  $users  코어 회원 서비스
     * @param  AuthService  $auth  코어 인증 서비스 (회원 생성)
     * @param  DeprovisionService  $deprovision  퇴사 처리
     * @param  AttributeSyncService  $attributeSync  이메일 동기화 규칙
     * @param  RoleSyncService  $roles  역할 매핑
     */
    public function __construct(
        private readonly ProvisioningAccountRepositoryInterface $accounts,
        private readonly SsoAccountRepositoryInterface $links,
        private readonly SsoUserAttributeRepositoryInterface $attributes,
        private readonly UserService $users,
        private readonly AuthService $auth,
        private readonly DeprovisionService $deprovision,
        private readonly AttributeSyncService $attributeSync,
        private readonly RoleSyncService $roles,
    ) {}

    /**
     * 목록을 반환합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string|null  $filter  SCIM 필터
     * @param  mixed  $startIndex  시작 위치
     * @param  mixed  $count  건수
     * @return array<string, mixed> 목록 응답
     */
    public function list(IdentityProvider $idp, ?string $filter, mixed $startIndex, mixed $count): array
    {
        $parsed = ScimFilter::parse($filter, self::FILTER_ATTRIBUTES);
        $page = ScimSchema::page($startIndex, $count);

        $column = match ($parsed['attribute'] ?? null) {
            'username' => self::matchKey($idp) === 'userName' ? 'subject' : 'attr:'.self::USER_NAME_ATTRIBUTE,
            'externalid' => self::matchKey($idp) === 'externalId' ? 'subject' : 'scim_external_id',
            'emails.value' => 'email',
            default => null,
        };

        $result = $this->accounts->pageInIdp(
            (int) $idp->getKey(),
            $column !== null ? ['column' => $column, 'value' => $parsed['value']] : null,
            $page['start'] - 1,
            $page['count'],
        );

        $resources = $result['items']->map(fn (SsoAccount $a) => $this->resource($idp, $a))->values()->all();

        return ScimSchema::listResponse($resources, $result['total'], $page['start']);
    }

    /**
     * 자원 하나를 반환합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  자원 id
     * @return array<string, mixed> 자원
     *
     * @throws ScimException 없으면 404
     */
    public function get(IdentityProvider $idp, string $id): array
    {
        return $this->resource($idp, $this->find($idp, $id));
    }

    /**
     * 회원을 만들거나 같은 이메일 회원에 연결합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, mixed>  $payload  SCIM User 자원
     * @return array<string, mixed> 만든 자원
     *
     * @throws ScimException 대조 키 없음 400, 이미 있음·이메일 충돌·관리자 대상 409
     */
    public function create(IdentityProvider $idp, array $payload): array
    {
        $changes = ScimUserPayload::fromResource($payload);
        $subject = $this->subjectFrom($idp, $changes);
        if ($subject === null) {
            throw new ScimException(400, 'invalidValue', __('sirsoft-sso::scim.error.match_key_required', ['key' => self::matchKey($idp)]));
        }

        if ($this->accounts->findInIdpBy((int) $idp->getKey(), 'subject', $subject) !== null) {
            throw new ScimException(409, 'uniqueness', __('sirsoft-sso::scim.error.already_exists'));
        }

        $account = SystemOperation::run('external:sirsoft-sso:scim', function () use ($idp, $changes, $subject): SsoAccount {
            $email = isset($changes['email']) ? mb_strtolower((string) $changes['email']) : null;
            $existing = $email !== null ? $this->links->findUserByEmail($email) : null;
            if ($existing !== null && $existing->isWithdrawn()) {
                $existing = null;
            }

            $user = $existing !== null ? $this->linkableExisting($idp, $existing, $email) : $this->register($idp, $changes, $subject);

            return DB::transaction(fn () => $this->links->create([
                'idp_id' => (int) $idp->getKey(),
                'user_id' => (int) $user->getKey(),
                'subject' => $subject,
                'email' => $email !== null ? mb_substr($email, 0, 255) : null, // audit:allow resource-member-email-ungated 연결 행 저장값(응답 아님)
                'display_name' => isset($changes['name']) ? mb_substr((string) $changes['name'], 0, 255) : null,
                'linked_via' => 'scim',
                'scim_external_id' => isset($changes['externalId']) ? mb_substr((string) $changes['externalId'], 0, 191) : null,
                'active' => true,
                'linked_at' => now(),
            ]));
        });

        $user = $account->user;
        HookManager::doAction(AccountResolver::ACTION_AFTER_LINK, $account, $user, 'scim');

        unset($changes['email']);
        $this->apply($idp, $account, $changes);

        if ($idp->group_source === 'scim') {
            $this->roles->sync($user, $account, $idp, [], RoleSyncService::TRIGGER_CREATED);
        }

        return $this->resource($idp, $account->refresh());
    }

    /**
     * 자원을 통째로 바꿉니다 (PUT).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  자원 id
     * @param  array<string, mixed>  $payload  SCIM User 자원
     * @return array<string, mixed> 바뀐 자원
     */
    public function replace(IdentityProvider $idp, string $id, array $payload): array
    {
        $account = $this->find($idp, $id);
        $this->apply($idp, $account, ScimUserPayload::fromResource($payload));

        return $this->resource($idp, $account->refresh());
    }

    /**
     * 부분 수정합니다 (PATCH — add, replace, remove).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  자원 id
     * @param  array<int, mixed>  $operations  PatchOp Operations
     * @return array<string, mixed> 바뀐 자원
     */
    public function patch(IdentityProvider $idp, string $id, array $operations): array
    {
        $account = $this->find($idp, $id);
        $this->apply($idp, $account, ScimUserPayload::fromOperations($operations));

        return $this->resource($idp, $account->refresh());
    }

    /**
     * 퇴사 처리합니다 (DELETE — 회원은 지우지 않는다).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  자원 id
     * @return void
     */
    public function delete(IdentityProvider $idp, string $id): void
    {
        $this->deprovision->deprovision($this->find($idp, $id), 'scim');
    }

    /**
     * IdP 의 SCIM 대조 키를 읽습니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string `userName` 또는 `externalId`
     */
    public static function matchKey(IdentityProvider $idp): string
    {
        $value = is_array($idp->deprovision) ? ($idp->deprovision['scim_match'] ?? null) : null;

        return $value === 'externalId' ? 'externalId' : 'userName';
    }

    /**
     * 자원 id 로 연결을 찾습니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  자원 id
     * @return SsoAccount 연결
     *
     * @throws ScimException 없으면 404
     */
    private function find(IdentityProvider $idp, string $id): SsoAccount
    {
        $account = ctype_digit($id) ? $this->accounts->findInIdp((int) $idp->getKey(), (int) $id) : null;
        if ($account === null || ! $account->user instanceof User) {
            throw new ScimException(404, null, __('sirsoft-sso::scim.error.not_found'));
        }

        return $account;
    }

    /**
     * 바꿀 값에서 연결 키를 고릅니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, mixed>  $changes  바꿀 값
     * @return string|null 연결 키
     */
    private function subjectFrom(IdentityProvider $idp, array $changes): ?string
    {
        $value = $changes[self::matchKey($idp)] ?? null;
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' && mb_strlen($value) <= 191 ? $value : null;
    }

    /**
     * 같은 이메일의 기존 회원에 연결해도 되는지 확인합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  User  $user  회원
     * @param  string  $email  이메일
     * @return User 회원
     *
     * @throws ScimException 연결할 수 없으면 409 uniqueness
     */
    private function linkableExisting(IdentityProvider $idp, User $user, string $email): User
    {
        $domain = substr($email, strrpos($email, '@') + 1);

        if (AccountResolver::isPrivileged($user)) {
            throw new ScimException(409, 'uniqueness', __('sirsoft-sso::scim.error.admin_target_manual_only'));
        }

        if (! $idp->linkMethodEnabled('domain_auto') || ! $idp->ownsDomain($domain)
            || $this->links->findByIdpUser((int) $idp->getKey(), (int) $user->getKey()) !== null) {
            throw new ScimException(409, 'uniqueness', __('sirsoft-sso::scim.error.email_in_use'));
        }

        return $user;
    }

    /**
     * 비밀번호 없는 회원을 만듭니다 (회사가 만드는 계정 — 본인인증 정책 생략, 약관 동의 기록 없음, 토큰 없음).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, mixed>  $changes  바꿀 값
     * @param  string  $subject  연결 키
     * @return User 만든 회원
     *
     * @throws ScimException 만들 이메일이 이미 쓰이면 409
     */
    private function register(IdentityProvider $idp, array $changes, string $subject): User
    {
        $identity = new ExternalIdentity(subject: $subject, email: isset($changes['email']) ? (string) $changes['email'] : null);
        $email = AccountResolver::registrationEmail($idp, $identity);
        if ($this->links->findUserByEmail($email) !== null) {
            throw new ScimException(409, 'uniqueness', __('sirsoft-sso::scim.error.email_in_use'));
        }

        $name = trim((string) ($changes['name'] ?? $changes['userName'] ?? $subject));

        return $this->auth->register([
            'name' => mb_substr($name !== '' ? $name : $subject, 0, 50),
            'email' => $email, // audit:allow resource-member-email-ungated 가입 저장 데이터(응답 아님)
            'language' => app()->getLocale(),
        ], [
            'issue_token' => false,
            'identity_policy' => 'skip',
            'identity_policy_reason' => 'external:sirsoft-sso:scim:'.$idp->key,
            'record_consents' => false,
        ])['user'];
    }

    /**
     * 바꿀 값을 연결과 회원에 반영합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoAccount  $account  연결
     * @param  array<string, mixed>  $changes  바꿀 값 (ScimUserPayload 형식)
     * @return void
     *
     * @throws ScimException 연결 키를 다른 연결이 쓰고 있으면 409
     */
    private function apply(IdentityProvider $idp, SsoAccount $account, array $changes): void
    {
        /** @var User $user */
        $user = $account->user;
        $userId = (int) $user->getKey();
        $idpId = (int) $idp->getKey();

        SystemOperation::run('external:sirsoft-sso:scim', function () use ($idp, $account, $user, $userId, $idpId, $changes): void {
            $accountUpdates = [];

            $subject = $this->subjectFrom($idp, $changes);
            if ($subject !== null && $subject !== $account->subject) {
                if ($this->accounts->findInIdpBy($idpId, 'subject', $subject) !== null) {
                    throw new ScimException(409, 'uniqueness', __('sirsoft-sso::scim.error.already_exists'));
                }
                $accountUpdates['subject'] = $subject;
            }

            if (array_key_exists('externalId', $changes)) {
                $accountUpdates['scim_external_id'] = $changes['externalId'] !== null ? mb_substr((string) $changes['externalId'], 0, 191) : null;
            }

            if (array_key_exists('userName', $changes) && $changes['userName'] !== null) {
                $this->attributes->put($userId, $idpId, self::USER_NAME_ATTRIBUTE, mb_substr((string) $changes['userName'], 0, 191));
            }

            $core = [];
            if (isset($changes['name']) && trim((string) $changes['name']) !== '') {
                $core['name'] = mb_substr(trim((string) $changes['name']), 0, 50);
                $accountUpdates['display_name'] = mb_substr(trim((string) $changes['name']), 0, 255);
            }
            if (array_key_exists('mobile', $changes)) {
                $core['mobile'] = $changes['mobile'] !== null ? mb_substr((string) $changes['mobile'], 0, 30) : null;
            }
            if (isset($changes['language'])) {
                $language = strtok(str_replace('_', '-', mb_strtolower((string) $changes['language'])), '-');
                if (in_array($language, (array) config('app.supported_locales', []), true)) {
                    $core['language'] = $language;
                }
            }
            $core = array_filter($core, static fn ($v, $k) => (string) ($user->{$k} ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);
            if ($core !== []) {
                $this->users->updateUser($user, $core);
                $user->refresh();
            }

            if (isset($changes['email'])) {
                $accountUpdates['email'] = mb_substr(mb_strtolower((string) $changes['email']), 0, 255); // audit:allow resource-member-email-ungated 연결 행 저장값(응답 아님)
                $this->attributeSync->syncEmail($user, $account, $idp, (string) $changes['email']);
            }

            foreach (self::ATTRIBUTE_FIELDS as $field => $key) {
                if (array_key_exists($field, $changes)) {
                    $this->attributes->put($userId, $idpId, $key, $changes[$field] !== null ? mb_substr((string) $changes[$field], 0, 1000) : null);
                }
            }

            if ($accountUpdates !== []) {
                $this->accounts->update($account, $accountUpdates);
            }
        });

        if (array_key_exists('active', $changes) && is_bool($changes['active'])) {
            if ($changes['active'] === false) {
                $this->deprovision->deprovision($account->refresh(), 'scim');
            } elseif (! $account->active && in_array($account->deprovision_reason, SsoAccount::REACTIVATABLE_BY_SCIM, true)) {
                $this->deprovision->reactivate($account, 'scim');
            }
        }
    }

    /**
     * 연결을 SCIM User 자원으로 바꿉니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoAccount  $account  연결
     * @return array<string, mixed> 자원
     */
    private function resource(IdentityProvider $idp, SsoAccount $account): array
    {
        /** @var User $user */
        $user = $account->user;
        $attrs = [];
        foreach ($this->attributes->forUser((int) $user->getKey()) as $attribute) {
            if ((int) $attribute->idp_id === (int) $idp->getKey()) {
                $attrs[$attribute->key] = $attribute->value;
            }
        }

        $userName = self::matchKey($idp) === 'userName' ? $account->subject : ($attrs[self::USER_NAME_ATTRIBUTE] ?? $account->subject);
        $externalId = self::matchKey($idp) === 'externalId' ? $account->subject : $account->scim_external_id;
        $email = (string) $user->email; // audit:allow resource-member-email-ungated IdP 가 만든 회원의 SCIM 응답(토큰 인증된 그 IdP 에만)

        $resource = [
            'schemas' => [ScimSchema::USER],
            'id' => (string) $account->getKey(),
            'userName' => $userName,
            'name' => ['formatted' => (string) $user->name],
            'displayName' => $account->display_name ?? (string) $user->name,
            'active' => (bool) $account->active,
            'emails' => str_ends_with($email, '@'.AccountResolver::FALLBACK_EMAIL_DOMAIN) ? [] : [['value' => $email, 'type' => 'work', 'primary' => true]],
            'meta' => [
                'resourceType' => 'User',
                'created' => $account->created_at?->toIso8601String(),
                'lastModified' => $account->updated_at?->toIso8601String(),
                'location' => ScimSchema::baseUrl($idp).'/Users/'.$account->getKey(),
            ],
        ];

        if ($externalId !== null && $externalId !== '') {
            $resource['externalId'] = $externalId;
        }
        if (is_string($user->mobile) && $user->mobile !== '') {
            $resource['phoneNumbers'] = [['value' => $user->mobile, 'type' => 'mobile']];
        }
        if (($attrs['title'] ?? null) !== null) {
            $resource['title'] = $attrs['title'];
        }

        $enterprise = array_filter([
            'department' => $attrs['department'] ?? null,
            'employeeNumber' => $attrs['employee_number'] ?? null,
        ], static fn ($v) => $v !== null);
        if ($enterprise !== []) {
            $resource['schemas'][] = ScimSchema::ENTERPRISE_USER;
            $resource[ScimSchema::ENTERPRISE_USER] = $enterprise;
        }

        return $resource;
    }
}
