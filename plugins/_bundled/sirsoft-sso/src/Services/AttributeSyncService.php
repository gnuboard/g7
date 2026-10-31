<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\UserService;
use App\Support\Auth\SystemOperation;
use Plugins\Sirsoft\Sso\Drivers\Ldap\LdapDriver;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoUserAttributeRepositoryInterface;

/**
 * IdP 속성 → 회원 칸, 추가 속성 동기화와 칸 잠금.
 *
 * 규칙(`attribute_map`)은 `[{field, source, mode}]`:
 *  - `field`: 코어 칸(`name`, `nickname`, `mobile`, `phone`, `language`, `email`) 또는 `attr:{key}`(`sso_user_attributes`)
 *  - `source`: IdP 속성 이름 (특수값 `email`, `display_name` — 신원의 이메일, 표시 이름). LDAP 속성 이름은 디렉터리처럼 대소문자를
 *    가리지 않고, OIDC 클레임과 SAML 속성 이름은 IdP 가 보낸 그대로 가린다.
 *  - `mode`: `sync_lock`(매 로그인 덮어쓰기 + 회원 수정 잠금) / `sync`(매 로그인 덮어쓰기) / `first_only`(처음 만들 때만) / `ignore`
 *
 * 코어 칸은 `UserService::updateUser()` 를 직접 불러 쓴다 — 요청을 거치지 않으므로 코어 칸 잠금과 무관하다(의도). IdP 가 값을 주지
 * 않으면 지우지 않는다.
 *
 * 이메일: 새 주소의 도메인이 그 IdP 소유 도메인일 때만 받는다. 다른 회원이 쓰는 주소면 바꾸지 않고 건너뛴다 — 연결 행의 IdP 이메일은
 * 로그인 흐름이 이미 저장하므로 회원 상세가 「IdP 이메일과 다름」을 보이고, 활동 로그와 관리자 알림 훅을 한 번 낸다.
 *
 * @since 1.0.0
 */
class AttributeSyncService
{
    use ResolvesActivityLogType;

    /** 이메일 충돌 훅 (알림) */
    public const ACTION_EMAIL_CONFLICT = 'sirsoft-sso.account.email_sync_conflict';

    /** @var array<int, string> 동기화할 수 있는 코어 칸 (코어 칸 잠금 허용 목록과 같다) */
    public const CORE_FIELDS = ['name', 'nickname', 'mobile', 'phone', 'language', 'email'];

    /** @var array<int, string> 모드 */
    public const MODES = ['sync_lock', 'sync', 'first_only', 'ignore'];

    /** @var array<int, string> 신원의 고정 칸을 가리키는 출처 (IdP 속성 이름이 아니다) */
    public const SPECIAL_SOURCES = ['email', 'display_name'];

    /** 추가 속성 칸 접두 */
    public const ATTR_PREFIX = 'attr:';

    /** 추가 속성 키 형식 */
    public const ATTR_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,49}$/';

    /** 같은 충돌 알림을 다시 내지 않는 기간(초) */
    private const CONFLICT_NOTICE_TTL = 60 * 60 * 24 * 30;

    /**
     * @param  SsoUserAttributeRepositoryInterface  $attributes  추가 속성 저장소
     * @param  ProvisioningAccountRepositoryInterface  $accounts  연결 조회
     * @param  SsoAccountRepositoryInterface  $links  연결 저장소 (이메일 충돌 확인 — 대소문자 무시 회원 조회)
     * @param  UserService  $users  코어 회원 서비스
     * @param  CacheInterface  $cache  플러그인 캐시 (충돌 알림 1회)
     */
    public function __construct(
        private readonly SsoUserAttributeRepositoryInterface $attributes,
        private readonly ProvisioningAccountRepositoryInterface $accounts,
        private readonly SsoAccountRepositoryInterface $links,
        private readonly UserService $users,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 신원의 속성을 회원에게 반영합니다.
     *
     * @param  User  $user  회원
     * @param  SsoAccount  $account  연결
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  bool  $created  이번에 처음 만든 회원인지 (`first_only` 규칙)
     * @return array{fields: array<int, string>, attributes: array<int, string>, email: string|null} 바꾼 코어 칸, 추가 속성 키, 이메일 결과(synced, conflict, skipped)
     */
    public function sync(User $user, SsoAccount $account, IdentityProvider $idp, ExternalIdentity $identity, bool $created): array
    {
        $result = ['fields' => [], 'attributes' => [], 'email' => null];
        $coreUpdates = [];
        $caseInsensitive = self::caseInsensitiveSources($idp);

        foreach (self::rules($idp) as $rule) {
            if ($rule['mode'] === 'ignore' || ($rule['mode'] === 'first_only' && ! $created)) {
                continue;
            }

            $value = self::sourceValue($identity, $rule['source'], $caseInsensitive);
            if ($value === null) {
                continue;
            }

            if (str_starts_with($rule['field'], self::ATTR_PREFIX)) {
                $key = substr($rule['field'], strlen(self::ATTR_PREFIX));
                $existing = $this->attributes->find((int) $user->getKey(), (int) $idp->getKey(), $key);
                if ($existing === null || $existing->value !== $value) {
                    $this->attributes->put((int) $user->getKey(), (int) $idp->getKey(), $key, $value);
                    $result['attributes'][] = $key;
                }

                continue;
            }

            if ($rule['field'] === 'email') {
                $result['email'] = $this->syncEmail($user, $account, $idp, $value);

                continue;
            }

            $value = $this->fitCoreValue($rule['field'], $value);
            if ($value !== null && (string) ($user->{$rule['field']} ?? '') !== $value) {
                $coreUpdates[$rule['field']] = $value;
            }
        }

        if ($coreUpdates !== []) {
            SystemOperation::run('external:sirsoft-sso:attribute-sync', fn () => $this->users->updateUser($user, $coreUpdates));
            $user->refresh();
            $result['fields'] = array_keys($coreUpdates);
        }

        if ($result['fields'] !== [] || $result['attributes'] !== []) {
            $this->logActivity('sso.attributes_synced', [
                'loggable' => $user,
                'description_key' => 'sirsoft-sso::activity_log.description.sso.attributes_synced',
                'description_params' => ['user_name' => (string) $user->name, 'idp' => $idp->localizedLabel()],
                'properties' => [
                    'user_id' => (int) $user->getKey(),
                    'idp_id' => (int) $idp->getKey(),
                    'fields' => $result['fields'],
                    'attributes' => $result['attributes'],
                ],
            ]);
        }

        return $result;
    }

    /**
     * 회원의 잠긴 코어 칸 — 활성 연결 IdP 들의 `sync_lock` 코어 칸 합집합 (필터 `core.user.locked_fields`).
     *
     * @param  User  $user  회원
     * @return array<int, string> 잠긴 칸 이름
     */
    public function lockedFields(User $user): array
    {
        $locked = [];

        foreach ($this->accounts->activeForUser((int) $user->getKey()) as $account) {
            $idp = $account->idp;
            if (! $idp instanceof IdentityProvider) {
                continue;
            }

            foreach (self::rules($idp) as $rule) {
                if ($rule['mode'] === 'sync_lock' && in_array($rule['field'], self::CORE_FIELDS, true)) {
                    $locked[] = $rule['field'];
                }
            }
        }

        return array_values(array_unique($locked));
    }

    /**
     * IdP 의 속성 규칙을 정규화합니다 (모르는 칸, 모드, 빈 출처는 버린다).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, array{field: string, source: string, mode: string}> 규칙
     */
    public static function rules(IdentityProvider $idp): array
    {
        $rules = [];
        foreach (is_array($idp->attribute_map) ? $idp->attribute_map : [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $field = trim((string) ($rule['field'] ?? ''));
            $source = trim((string) ($rule['source'] ?? ''));
            $mode = (string) ($rule['mode'] ?? '');

            $validField = in_array($field, self::CORE_FIELDS, true)
                || (str_starts_with($field, self::ATTR_PREFIX) && preg_match(self::ATTR_KEY_PATTERN, substr($field, strlen(self::ATTR_PREFIX))) === 1);

            if ($validField && $source !== '' && in_array($mode, self::MODES, true)) {
                $rules[] = ['field' => $field, 'source' => $source, 'mode' => $mode];
            }
        }

        return $rules;
    }

    /**
     * 규칙이 IdP 에게 받아야 하는 속성 이름 — 가져오지 않음 규칙과 특수 출처(`email`, `display_name`)는 뺀다(드라이버의 요청 목록용).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, string> 출처 이름 (규칙에 적은 그대로)
     */
    public static function requestedSources(IdentityProvider $idp): array
    {
        $sources = [];
        foreach (self::rules($idp) as $rule) {
            if ($rule['mode'] !== 'ignore' && ! in_array($rule['source'], self::SPECIAL_SOURCES, true)) {
                $sources[] = $rule['source'];
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * 이 IdP 의 출처 이름을 대소문자 없이 찾는지 — LDAP 속성 이름은 대소문자를 가리지 않는다(RFC 4512). OIDC 클레임과 SAML 속성
     * 이름은 가린다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return bool 대소문자를 가리지 않으면 true
     */
    public static function caseInsensitiveSources(IdentityProvider $idp): bool
    {
        return $idp->protocol === LdapDriver::KEY;
    }

    /**
     * 신원에서 출처 값을 꺼냅니다 (배열 값은 쉼표로 잇는다, 빈 값은 null).
     *
     * @param  ExternalIdentity  $identity  신원
     * @param  string  $source  출처 이름
     * @param  bool  $caseInsensitive  같은 이름이 없을 때 대소문자 없이 다시 찾을지 (LDAP)
     * @return string|null 값
     */
    public static function sourceValue(ExternalIdentity $identity, string $source, bool $caseInsensitive = false): ?string
    {
        $raw = match ($source) {
            'email' => $identity->email, // audit:allow resource-member-email-ungated 동기화 출처 값 고르기(응답·캐시 아님)
            'display_name', 'displayName' => $identity->displayName ?? self::attributeValue($identity->attributes, $source, $caseInsensitive),
            default => self::attributeValue($identity->attributes, $source, $caseInsensitive),
        };

        if (is_array($raw)) {
            $raw = implode(', ', array_filter(array_map(static fn ($v) => is_scalar($v) ? trim((string) $v) : '', $raw), static fn ($v) => $v !== ''));
        }

        if (! is_scalar($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }

    /**
     * 속성 표에서 이름으로 값을 찾습니다 — 같은 이름이 먼저, 없고 대소문자를 가리지 않으면 이름을 소문자로 맞춰 다시 찾는다.
     *
     * @param  array<string, mixed>  $attributes  신원의 속성
     * @param  string  $source  출처 이름
     * @param  bool  $caseInsensitive  대소문자 없이 다시 찾을지
     * @return mixed 값, 없으면 null
     */
    private static function attributeValue(array $attributes, string $source, bool $caseInsensitive): mixed
    {
        if (array_key_exists($source, $attributes) || ! $caseInsensitive) {
            return $attributes[$source] ?? null;
        }

        $wanted = mb_strtolower($source);
        foreach ($attributes as $name => $value) {
            if (mb_strtolower((string) $name) === $wanted) {
                return $value;
            }
        }

        return null;
    }

    /**
     * 코어 칸에 맞게 값을 다듬습니다 (지원하지 않는 언어는 버린다, 길이 제한).
     *
     * @param  string  $field  코어 칸
     * @param  string  $value  값
     * @return string|null 다듬은 값
     */
    private function fitCoreValue(string $field, string $value): ?string
    {
        if ($field === 'language') {
            $value = mb_strtolower($value);
            $value = strtok(str_replace('_', '-', $value), '-') ?: $value;

            return in_array($value, (array) config('app.supported_locales', []), true) ? $value : null;
        }

        return mb_substr($value, 0, $field === 'name' ? 50 : 255);
    }

    /**
     * 이메일을 동기화합니다 — 소유 도메인일 때만, 다른 회원이 쓰면 건너뛰고 알린다 (로그인 속성 규칙과 SCIM 공용).
     *
     * @param  User  $user  회원
     * @param  SsoAccount  $account  연결
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $email  IdP 이메일
     * @return string 결과 (synced, unchanged, conflict, skipped)
     */
    public function syncEmail(User $user, SsoAccount $account, IdentityProvider $idp, string $email): string
    {
        $email = mb_strtolower($email);
        $domain = str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : null;

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! $idp->ownsDomain($domain)) {
            return 'skipped';
        }

        $current = (string) $user->email; // audit:allow resource-member-email-ungated 동기화 비교값(응답 아님)
        if (mb_strtolower($current) === $email) {
            return 'unchanged';
        }

        $owner = $this->links->findUserByEmail($email);
        if ($owner !== null && (int) $owner->getKey() !== (int) $user->getKey()) {
            $this->noticeConflict($user, $account, $idp, $email);

            return 'conflict';
        }

        SystemOperation::run('external:sirsoft-sso:email-sync', fn () => $this->users->updateUser($user, ['email' => $email]));
        $user->refresh();

        $this->logActivity('sso.email_synced', [
            'loggable' => $user,
            'description_key' => 'sirsoft-sso::activity_log.description.sso.email_synced',
            'description_params' => ['user_name' => (string) $user->name, 'idp' => $idp->localizedLabel(), 'from' => $current, 'to' => $email], // audit:allow resource-member-email-ungated reason: 활동 로그 기록(관리자 활동 로그 화면 — 응답·캐시 아님), 이메일 변경 감사 근거
            'properties' => ['user_id' => (int) $user->getKey(), 'idp_id' => (int) $idp->getKey(), 'from' => $current, 'to' => $email],
        ]);

        return 'synced';
    }

    /**
     * 이메일 충돌을 활동 로그와 알림 훅으로 한 번 알립니다 (같은 회원, 같은 주소는 30일 동안 다시 알리지 않는다).
     *
     * @param  User  $user  회원
     * @param  SsoAccount  $account  연결
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $email  IdP 이메일
     * @return void 반환값 없음
     */
    private function noticeConflict(User $user, SsoAccount $account, IdentityProvider $idp, string $email): void
    {
        $key = 'email_conflict:'.$user->getKey().':'.hash('sha256', $idp->getKey().'|'.$email);
        if ($this->cache->has($key)) {
            return;
        }
        $this->cache->put($key, 1, self::CONFLICT_NOTICE_TTL);

        $this->logActivity('sso.email_sync_conflict', [
            'loggable' => $user,
            'description_key' => 'sirsoft-sso::activity_log.description.sso.email_sync_conflict',
            'description_params' => ['user_name' => (string) $user->name, 'idp' => $idp->localizedLabel()],
            'properties' => ['user_id' => (int) $user->getKey(), 'idp_id' => (int) $idp->getKey()],
        ]);

        HookManager::doAction(self::ACTION_EMAIL_CONFLICT, $user, $account, $email);
    }
}
