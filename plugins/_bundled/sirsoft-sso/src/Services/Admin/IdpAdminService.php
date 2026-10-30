<?php

namespace Plugins\Sirsoft\Sso\Services\Admin;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Models\User;
use App\Support\SensitiveSettingMask;
use App\Support\SiteUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\Contracts\ProtocolDriverInterface;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminIdpRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminLogRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;

/**
 * 관리자 IdP 설정 — 목록, 상세, 추가, 수정, 삭제, 연결 테스트, SP 키, SCIM 토큰 (§6.15, W9, W14, W34, W35, W38).
 *
 * 비밀값(`secrets`)은 응답에서 가린다. 가린 값이 그대로 돌아오면 기존 값을 유지한다(코어 `SensitiveSettingMask` 규칙과 같다).
 * 활성화는 드라이버를 이 서버에서 쓸 수 있고(W9) 설정 검증이 통과할 때만 된다.
 *
 * @since 1.0.0
 */
class IdpAdminService
{
    use ResolvesActivityLogType;

    /** IdP 등록 상한 (W16) */
    public const MAX_IDPS = 20;

    /** @var array<int, string> 저장 API 가 받는 칸 (비밀값과 키, 프로토콜은 따로) */
    public const FIELDS = [
        'enabled', 'sort_order', 'label', 'button_visible', 'button_style', 'config', 'owned_domains', 'enforce_owned_domains',
        'provisioning_mode', 'link_methods', 'require_consent', 'identity_policy', 'attribute_map', 'role_mapping', 'role_sync_mode',
        'group_source', 'two_factor_mode', 'logout_mode', 'idp_initiated', 'allow_local_password', 'max_session_minutes', 'deprovision',
    ];

    /** 역할 매핑 최고관리자 검사기 (P3 — 없으면 이 서비스가 같은 규칙을 직접 본다) */
    private const ROLE_RULE_VALIDATOR = 'Plugins\\Sirsoft\\Sso\\Services\\RoleSyncRuleValidator';

    /**
     * @param  AdminIdpRepositoryInterface  $idps  IdP 저장소
     * @param  AdminLogRepositoryInterface  $logs  이력 저장소
     * @param  ProtocolRegistry  $registry  프로토콜 레지스트리
     * @param  PolicyService  $policy  정책 (W34 토큰 회수)
     * @param  LoginFlowService  $flow  브라우저 테스트 시작 티켓
     */
    public function __construct(
        private readonly AdminIdpRepositoryInterface $idps,
        private readonly AdminLogRepositoryInterface $logs,
        private readonly ProtocolRegistry $registry,
        private readonly PolicyService $policy,
        private readonly LoginFlowService $flow,
    ) {}

    /**
     * IdP 목록과 추가 가능한 프로토콜을 반환합니다.
     *
     * @return array{idps: array<int, array<string, mixed>>, protocols: array<int, array<string, mixed>>, limits: array{max: int, count: int}} 목록
     */
    public function list(): array
    {
        $idps = $this->idps->all();
        $counts = $this->idps->accountCounts($idps->pluck('id')->map(fn ($id) => (int) $id)->all());

        return [
            'idps' => $idps->map(fn (IdentityProvider $idp) => $this->summary($idp, $counts[(int) $idp->id] ?? ['active' => 0, 'total' => 0]))->values()->all(),
            'protocols' => $this->protocols(),
            'limits' => ['max' => self::MAX_IDPS, 'count' => $idps->count()],
        ];
    }

    /**
     * 등록된 프로토콜 목록 (이름, 이 서버에서 쓸 수 있는지, 기능).
     *
     * @return array<int, array{key: string, label: string, available: bool, capabilities: array<string, bool>}> 프로토콜
     */
    public function protocols(): array
    {
        $items = [];

        foreach ($this->registry->all() as $key => $driver) {
            $items[] = [
                'key' => (string) $key,
                'label' => $driver->label(),
                'available' => $driver->isAvailable(),
                'capabilities' => $driver->capabilities()->toArray(),
            ];
        }

        return $items;
    }

    /**
     * IdP 상세 (편집 화면).
     *
     * @param  int  $id  IdP ID
     * @param  User  $viewer  보는 관리자
     * @return array<string, mixed> 상세
     *
     * @throws SsoException 없으면 `idp_not_found` 404
     */
    public function show(int $id, User $viewer): array
    {
        $idp = $this->find($id);
        $driver = $this->driverOf($idp);
        $counts = $this->idps->accountCounts([(int) $idp->id])[(int) $idp->id] ?? ['active' => 0, 'total' => 0];

        return $this->summary($idp, $counts) + [
            'button_style' => $idp->button_style,
            'config' => is_array($idp->config) ? $idp->config : (object) [],
            'secrets' => $this->maskedSecrets($idp),
            'provisioning_mode' => $idp->provisioning_mode,
            'link_methods' => array_merge(IdentityProvider::DEFAULT_LINK_METHODS, is_array($idp->link_methods) ? $idp->link_methods : []),
            'require_consent' => (bool) $idp->require_consent,
            'identity_policy' => $idp->identity_policy,
            'attribute_map' => is_array($idp->attribute_map) ? array_values($idp->attribute_map) : [],
            'role_mapping' => is_array($idp->role_mapping) ? array_values($idp->role_mapping) : [],
            'role_sync_mode' => $idp->role_sync_mode,
            'group_source' => $idp->group_source,
            'two_factor_mode' => $idp->two_factor_mode,
            'logout_mode' => $idp->logout_mode,
            'idp_initiated' => (bool) $idp->idp_initiated,
            'allow_local_password' => (bool) $idp->allow_local_password,
            'max_session_minutes' => $idp->max_session_minutes,
            'deprovision' => is_array($idp->deprovision) ? $idp->deprovision : (object) [],
            'scim' => [
                'endpoint' => SiteUrl::absolute('/api/plugins/sirsoft-sso/scim/v2/'.$idp->key),
                'token_issued_at' => $idp->scim_token_issued_at?->toIso8601String(),
                'has_token' => $idp->scim_token_hash !== null,
            ],
            'periodic_checked_at' => $this->dateOf($idp->getAttribute('periodic_checked_at')),
            'metadata_refreshed_at' => $idp->metadata_refreshed_at?->toIso8601String(),
            'cert_expires_at' => $idp->cert_expires_at?->toIso8601String(),
            'schema' => $driver !== null ? $this->translatedSchema($driver) : [],
            'sp_info' => $driver !== null ? $this->translatedSpInfo($driver, $idp) : [],
            'capabilities' => $driver !== null ? $driver->capabilities()->toArray() : (object) [],
            'recent_attempts' => $this->logs->recentAttempts((int) $idp->id, 10),
            'abilities' => [
                'is_super' => $viewer->isSuperAdmin(),
                'role_rules_locked' => ! $viewer->isSuperAdmin() && $this->idps->hasPrivilegedLinkedUser((int) $idp->id),
            ],
        ];
    }

    /**
     * IdP 를 만듭니다.
     *
     * @param  array<string, mixed>  $data  검증된 값 (`key`, `protocol`, 칸들, `secrets`)
     * @param  User  $actor  관리자
     * @return array<string, mixed> 만든 IdP 상세
     *
     * @throws SsoException 상한, 키 중복, 프로토콜 없음, 활성화 불가
     */
    public function create(array $data, User $actor): array
    {
        if ($this->idps->count() >= self::MAX_IDPS) {
            throw new SsoException('idp_limit_reached', '', 422);
        }

        $key = (string) $data['key'];

        if (str_starts_with($key, IdentityProvider::MOCK_PREFIX)) {
            throw new SsoException('idp_key_reserved', $key, 422);
        }

        if ($this->idps->keyExists($key)) {
            throw new SsoException('idp_key_taken', $key, 422);
        }

        $driver = $this->registry->get((string) $data['protocol']);
        if ($driver === null) {
            throw new SsoException('driver_unavailable', (string) $data['protocol'], 422);
        }

        $attributes = $this->attributesFrom($data, null);
        $attributes['key'] = $key;
        $attributes['protocol'] = (string) $data['protocol'];
        $attributes['secrets'] = $this->mergeSecrets([], (array) ($data['secrets'] ?? []));
        $attributes['enabled'] = (bool) ($attributes['enabled'] ?? false);

        $this->assertActivatable($driver, $attributes);
        $this->assertRoleRules(null, $attributes, $actor);
        $this->assertDomainsUnowned((array) ($attributes['owned_domains'] ?? []), null);

        $idp = $this->idps->create($attributes);

        $this->revokeForEnforcedDomains([], $this->enforcedDomainsOf($idp));
        $this->audit('sso.idp_created', $idp, []);

        return $this->show((int) $idp->id, $actor);
    }

    /**
     * IdP 를 고칩니다.
     *
     * @param  int  $id  IdP ID
     * @param  array<string, mixed>  $data  검증된 값
     * @param  User  $actor  관리자
     * @return array<string, mixed> 고친 IdP 상세
     *
     * @throws SsoException 없음, 활성화 불가, 최고관리자 전용 변경
     */
    public function update(int $id, array $data, User $actor): array
    {
        $idp = $this->find($id);
        $driver = $this->driverOf($idp);

        if ($driver === null) {
            throw new SsoException('driver_unavailable', $idp->protocol, 422);
        }

        $attributes = $this->attributesFrom($data, $idp);
        $currentSecrets = $idp->readableSecrets();
        if (array_key_exists('secrets', $data)) {
            $secrets = $this->mergeSecrets($currentSecrets, (array) $data['secrets']);
            // 앱 키가 바뀌어 저장된 비밀값을 풀 수 없을 때 새로 입력한 값이 없으면 옛 암호문을 그대로 둔다 — 옛 앱 키를 되돌리면 다시
            // 읽힌다. 새 값을 입력하면 그 값으로 덮어 쓴다(풀 수 없는 옛 값은 합칠 수 없다).
            if ($secrets !== [] || ! $idp->secretsUnreadable()) {
                $attributes['secrets'] = $secrets;
            }
        }

        $merged = array_merge($idp->only(self::FIELDS), ['secrets' => $currentSecrets], $attributes);
        $this->assertActivatable($driver, $merged);
        $this->assertRoleRules($idp, $attributes, $actor);
        if (array_key_exists('owned_domains', $attributes)) {
            $this->assertDomainsUnowned((array) $attributes['owned_domains'], (int) $idp->id);
        }

        $domainsBefore = $this->enforcedDomainsOf($idp);
        $changed = array_values(array_filter(array_keys($attributes), fn ($field) => $this->changedField($idp, $field, $attributes[$field])));

        $idp = $this->idps->update($idp, $attributes);

        $this->revokeForEnforcedDomains($domainsBefore, $this->enforcedDomainsOf($idp));

        if ($changed !== []) {
            $this->audit('sso.idp_updated', $idp, ['changed' => $changed]);
        }

        return $this->show((int) $idp->id, $actor);
    }

    /**
     * IdP 를 지웁니다 — 연결이 남아 있으면 거절한다(연결을 먼저 해제하거나 IdP 를 끈다).
     *
     * @param  int  $id  IdP ID
     * @return void 반환값 없음
     *
     * @throws SsoException 없음 404, 연결 있음 409
     */
    public function delete(int $id): void
    {
        $idp = $this->find($id);
        $counts = $this->idps->accountCounts([(int) $idp->id])[(int) $idp->id] ?? ['total' => 0];

        if (($counts['total'] ?? 0) > 0) {
            throw new SsoException('idp_has_accounts', (string) $counts['total'], 409);
        }

        $this->audit('sso.idp_deleted', $idp, []);
        $this->idps->delete($idp);
    }

    /**
     * 연결 테스트 — 서버 쪽 점검 결과, 또는 「브라우저로 로그인 테스트」 시작 주소(W25).
     *
     * LDAP 은 `account` 가 오면 그 아이디와 비밀번호로 디렉터리 바인드를 시험한다(비밀번호는 저장하지 않는다).
     *
     * @param  int  $id  IdP ID
     * @param  bool  $browser  브라우저 테스트 시작 여부
     * @param  User  $actor  관리자
     * @param  Request  $request  요청 (묶음 쿠키)
     * @param  array{identifier: string, password: string}|null  $account  LDAP 「계정으로 시험」 입력
     * @return array<string, mixed> `{ok, checks}` 또는 `{start_url}`
     *
     * @throws SsoException 없음, 드라이버 없음
     */
    public function test(int $id, bool $browser, User $actor, Request $request, ?array $account = null): array
    {
        $idp = $this->find($id);
        $driver = $this->driverOf($idp);

        if ($driver === null || ! $driver->isAvailable()) {
            throw new SsoException('driver_unavailable', $idp->protocol, 422);
        }

        if ($browser) {
            if (! $driver->capabilities()->redirect) {
                throw new SsoException('browser_test_unsupported', $idp->protocol, 422);
            }

            return $this->flow->issueStartUrl($actor, $idp, 'test', $request, '/admin/plugins/sirsoft-sso/settings?idp='.$idp->id);
        }

        $result = ($account !== null && method_exists($driver, 'testAccount')
            ? $driver->testAccount($idp, $account['identifier'], $account['password'])
            : $driver->testConnection($idp))->toArray();
        $result['checks'] = array_map(fn (array $check) => $check + [
            'message' => $this->translate((string) ($check['message_key'] ?? '')),
        ], $result['checks']);

        return $result;
    }

    /**
     * SAML SP 서명 키와 인증서를 만듭니다 — 이전 키는 30일 함께 둔다.
     *
     * @param  int  $id  IdP ID
     * @return array{certificate: string, expires_at: string|null} 새 인증서 (개인키는 응답에 싣지 않는다)
     *
     * @throws SsoException SAML 이 아니거나 키를 만들지 못하면
     */
    public function generateSpKeys(int $id): array
    {
        $idp = $this->find($id);

        if ($idp->protocol !== 'saml') {
            throw new SsoException('sp_keys_saml_only', $idp->protocol, 422);
        }

        // 키 생성은 SAML 드라이버가 맡는다(자체 서명 인증서 형식이 드라이버의 메타데이터와 맞아야 한다).
        $driver = $this->driverOf($idp);
        if ($driver === null || ! method_exists($driver, 'generateSpKeys')) {
            throw new SsoException('driver_unavailable', $idp->protocol, 422);
        }

        $keys = $driver->generateSpKeys($idp);

        // 앱 키가 바뀌어 옛 키를 풀 수 없으면 이전 키 없이 새 키만 둔다.
        $secrets = $idp->readableSecrets();
        if (! empty($secrets['sp_certificate'])) {
            $secrets['sp_previous_certificate'] = $secrets['sp_certificate'];
            $secrets['sp_previous_private_key'] = $secrets['sp_private_key'] ?? null;
            $secrets['sp_previous_until'] = now()->addDays(30)->toIso8601String();
        }
        $secrets['sp_certificate'] = (string) $keys['certificate'];
        $secrets['sp_private_key'] = (string) $keys['private_key'];

        $this->idps->update($idp, ['secrets' => $secrets]);
        $this->audit('sso.sp_keys_generated', $idp, []);

        return ['certificate' => (string) $keys['certificate'], 'expires_at' => isset($keys['expires_at']) ? (string) $keys['expires_at'] : null];
    }

    /**
     * SCIM Bearer 토큰을 새로 발급합니다 — 응답에 한 번만 보이고 해시만 저장한다. 이전 토큰은 즉시 무효.
     *
     * @param  int  $id  IdP ID
     * @return array{token: string, issued_at: string, endpoint: string} 새 토큰
     *
     * @throws SsoException 없으면 404
     */
    public function issueScimToken(int $id): array
    {
        $idp = $this->find($id);
        $token = Str::random(64);
        $issuedAt = now();

        $this->idps->update($idp, ['scim_token_hash' => hash('sha256', $token), 'scim_token_issued_at' => $issuedAt]);
        $this->audit('sso.scim_token_issued', $idp, []);

        return [
            'token' => $token,
            'issued_at' => $issuedAt->toIso8601String(),
            'endpoint' => SiteUrl::absolute('/api/plugins/sirsoft-sso/scim/v2/'.$idp->key),
        ];
    }

    /**
     * IdP 를 찾습니다.
     *
     * @param  int  $id  IdP ID
     * @return IdentityProvider IdP
     *
     * @throws SsoException 없으면 `idp_not_found` 404
     */
    public function find(int $id): IdentityProvider
    {
        return $this->idps->findById($id) ?? throw new SsoException('idp_not_found', (string) $id, 404);
    }

    /**
     * 목록 1행.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array{active: int, total: int}  $counts  연결 수
     * @return array<string, mixed> 행
     */
    private function summary(IdentityProvider $idp, array $counts): array
    {
        $driver = $this->driverOf($idp);

        return [
            'id' => (int) $idp->id,
            'key' => $idp->key,
            'protocol' => $idp->protocol,
            'protocol_label' => $driver?->label() ?? $idp->protocol,
            'label' => is_array($idp->label) ? $idp->label : [],
            'display_label' => $idp->localizedLabel(),
            'enabled' => (bool) $idp->enabled,
            'available' => $driver !== null && $driver->isAvailable(),
            'is_mock' => $idp->isMock(),
            'sort_order' => (int) $idp->sort_order,
            'button_visible' => (bool) $idp->button_visible,
            'owned_domains' => array_values((array) $idp->owned_domains),
            'enforce_owned_domains' => (bool) $idp->enforce_owned_domains,
            'last_success_at' => $idp->last_success_at?->toIso8601String(),
            'last_error_at' => $idp->last_error_at?->toIso8601String(),
            'last_error_code' => $idp->last_error_code,
            'accounts_active' => $counts['active'],
            'accounts_total' => $counts['total'],
        ];
    }

    /**
     * 요청 값에서 저장할 칸을 고릅니다 (소유 도메인은 소문자 정규화).
     *
     * @param  array<string, mixed>  $data  요청 값
     * @param  IdentityProvider|null  $idp  수정 대상 (생성이면 null)
     * @return array<string, mixed> 저장할 칸
     */
    private function attributesFrom(array $data, ?IdentityProvider $idp): array
    {
        $attributes = array_intersect_key($data, array_flip(self::FIELDS));

        if (array_key_exists('owned_domains', $attributes)) {
            $attributes['owned_domains'] = array_values(array_unique(array_filter(array_map(
                static fn ($domain) => mb_strtolower(trim((string) $domain)),
                (array) $attributes['owned_domains'],
            ), static fn ($domain) => $domain !== '')));
        }

        if (array_key_exists('config', $attributes)) {
            $base = $idp !== null && is_array($idp->config) ? $idp->config : [];
            $attributes['config'] = array_merge($base, (array) $attributes['config']);
        }

        if (array_key_exists('deprovision', $attributes)) {
            $base = $idp !== null && is_array($idp->deprovision) ? $idp->deprovision : [];
            $attributes['deprovision'] = array_merge($base, (array) $attributes['deprovision']);
        }

        return $attributes;
    }

    /**
     * 활성화 조건을 확인합니다 — 드라이버를 쓸 수 있어야 하고(W9) 설정 검증이 통과해야 한다. 꺼 둔 채 저장은 언제나 된다.
     *
     * @param  ProtocolDriverInterface  $driver  드라이버
     * @param  array<string, mixed>  $attributes  저장될 값 (config, secrets, enabled)
     * @return void 반환값 없음
     *
     * @throws SsoException 활성화 불가 (`driver_unavailable`, `config_invalid` — 칸별 오류는 detail 에 JSON)
     */
    private function assertActivatable(ProtocolDriverInterface $driver, array $attributes): void
    {
        if (empty($attributes['enabled'])) {
            return;
        }

        if (! $driver->isAvailable()) {
            throw new SsoException('driver_unavailable', $driver->key(), 422);
        }

        $errors = $driver->validateConfig((array) ($attributes['config'] ?? []), (array) ($attributes['secrets'] ?? []));

        if ($errors !== []) {
            throw new SsoException('config_invalid', (string) json_encode(array_map(fn ($key) => $this->translate((string) $key), $errors), JSON_UNESCAPED_UNICODE), 422);
        }
    }

    /**
     * 한 도메인은 IdP 하나만 소유한다(§7 G-3) — 다른 IdP 가 이미 소유한 도메인을 넣으면 저장을 거절한다. 소유 도메인 자동 연결, 도메인
     * 강제, 이메일 먼저 입력의 판정이 어느 IdP 로 갈지 갈리지 않게 한다.
     *
     * @param  array<int, string>  $domains  저장할 소유 도메인(정규화된 소문자)
     * @param  int|null  $exceptId  수정 중인 IdP (생성이면 null)
     * @return void 반환값 없음
     *
     * @throws SsoException 다른 IdP 가 소유한 도메인이 있으면 `domain_owned_by_other_idp` 422 (메시지는 겹친 도메인 JSON)
     */
    private function assertDomainsUnowned(array $domains, ?int $exceptId): void
    {
        if ($domains === []) {
            return;
        }

        $taken = [];
        foreach ($this->idps->all() as $other) {
            if ($exceptId !== null && (int) $other->id === $exceptId) {
                continue;
            }
            $owned = array_map(static fn ($d) => mb_strtolower((string) $d), (array) $other->owned_domains);
            foreach (array_intersect($domains, $owned) as $domain) {
                $taken[$domain] = $other->key;
            }
        }

        if ($taken !== []) {
            throw new SsoException('domain_owned_by_other_idp', (string) json_encode(array_keys($taken)), 422);
        }
    }

    /**
     * 역할 매핑, 역할 동기화 방식, 그룹 출처 변경의 최고관리자 검사(W14, W35).
     *
     * 프로비저닝 영역의 검사기가 있으면 그것을 따르고, 없으면 「최고관리자가 아니면 관리자 역할 대상 규칙을 바꿀 수 없다」만 본다.
     *
     * @param  IdentityProvider|null  $before  수정 전 IdP (생성이면 null)
     * @param  array<string, mixed>  $changes  바꿀 값
     * @param  User  $actor  관리자
     * @return void 반환값 없음
     *
     * @throws SsoException 최고관리자 전용 변경이면 `super_only` 403
     */
    private function assertRoleRules(?IdentityProvider $before, array $changes, User $actor): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if (class_exists(self::ROLE_RULE_VALIDATOR)) {
            try {
                app(self::ROLE_RULE_VALIDATOR)->assertAllowed($before, $changes, $actor);
            } catch (AuthorizationException $e) {
                throw new SsoException('super_only', 'role_rules', 403, $e);
            }

            return;
        }

        foreach ((array) ($changes['role_mapping'] ?? []) as $rule) {
            if (is_array($rule) && in_array((string) ($rule['role'] ?? ''), ['admin'], true)) {
                throw new SsoException('super_only', 'role_mapping', 403);
            }
        }

        if ($before !== null && $this->idps->hasPrivilegedLinkedUser((int) $before->id)) {
            foreach (['role_sync_mode', 'group_source'] as $field) {
                if (array_key_exists($field, $changes) && $changes[$field] !== $before->{$field}) {
                    throw new SsoException('super_only', $field, 403);
                }
            }
        }
    }

    /**
     * 비밀값을 합칩니다 — 가린 값이나 빈 값은 기존 값을 유지, null 은 지움.
     *
     * @param  array<string, mixed>  $current  기존 비밀값
     * @param  array<string, mixed>  $incoming  요청 비밀값
     * @return array<string, mixed> 합친 비밀값
     */
    private function mergeSecrets(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if ($value === null) {
                unset($current[$key]);
            } elseif (is_string($value) && $value !== '' && $value !== SensitiveSettingMask::MASK) {
                $current[$key] = $value;
            }
        }

        return $current;
    }

    /**
     * 비밀값을 가립니다 (값이 있으면 마스크, 없으면 null). 이전 SP 키는 보이지 않는다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<string, string|null>|object 가린 비밀값
     */
    private function maskedSecrets(IdentityProvider $idp): array|object
    {
        $masked = [];

        foreach ($idp->readableSecrets() as $key => $value) {
            if (is_string($key) && ! str_starts_with($key, 'sp_previous_')) {
                $masked[$key] = $value !== null && $value !== '' ? SensitiveSettingMask::MASK : null;
            }
        }

        return $masked === [] ? (object) [] : $masked;
    }

    /**
     * 칸 정의의 문구 키를 현재 로케일 문구로 바꿉니다.
     *
     * @param  ProtocolDriverInterface  $driver  드라이버
     * @return array<int, array<string, mixed>> 칸 정의
     */
    private function translatedSchema(ProtocolDriverInterface $driver): array
    {
        return array_map(fn (array $field) => $field + [
            'label' => $this->translate((string) ($field['label_key'] ?? '')) ?: (string) ($field['key'] ?? ''),
            'hint' => $this->translate((string) ($field['hint_key'] ?? '')),
        ], array_values($driver->configSchema()));
    }

    /**
     * 「운영자가 IdP 콘솔에 넣을 값」 표를 현재 로케일로 바꿉니다.
     *
     * @param  ProtocolDriverInterface  $driver  드라이버
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, array<string, mixed>> 행
     */
    private function translatedSpInfo(ProtocolDriverInterface $driver, IdentityProvider $idp): array
    {
        try {
            $rows = $driver->serviceProviderInfo($idp);
        } catch (\Throwable) {
            return [];
        }

        return array_map(fn (array $row) => $row + [
            'label' => $this->translate((string) ($row['label_key'] ?? '')) ?: (string) ($row['key'] ?? ''),
        ], array_values($rows));
    }

    /**
     * 문구 키를 번역합니다 (키가 없으면 빈 문자열).
     *
     * @param  string  $key  문구 키
     * @return string 문구
     */
    private function translate(string $key): string
    {
        if ($key === '') {
            return '';
        }

        $text = __($key);

        return is_string($text) && $text !== $key ? $text : '';
    }

    /**
     * IdP 의 드라이버 (등록되지 않았으면 null).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return ProtocolDriverInterface|null 드라이버
     */
    private function driverOf(IdentityProvider $idp): ?ProtocolDriverInterface
    {
        return $this->registry->get($idp->protocol);
    }

    /**
     * 이 IdP 가 강제하는 도메인 (활성 + 「비밀번호 로그인 막기」일 때).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, string> 도메인
     */
    private function enforcedDomainsOf(IdentityProvider $idp): array
    {
        if (! $idp->enabled || ! $idp->enforce_owned_domains) {
            return [];
        }

        return array_values(array_map(static fn ($d) => mb_strtolower((string) $d), (array) $idp->owned_domains));
    }

    /**
     * IdP 저장으로 새로 강제된 도메인 회원의 비밀번호 토큰을 회수합니다(W34).
     *
     * @param  array<int, string>  $before  저장 전 이 IdP 강제 도메인
     * @param  array<int, string>  $after  저장 뒤 이 IdP 강제 도메인
     * @return void 반환값 없음
     */
    private function revokeForEnforcedDomains(array $before, array $after): void
    {
        if (array_diff($after, $before) !== []) {
            $this->policy->revokeForDomainChange($before, $after);
        }
    }

    /**
     * 칸 값이 바뀌는지 확인합니다.
     *
     * @param  IdentityProvider  $idp  수정 전 IdP
     * @param  string  $field  칸
     * @param  mixed  $value  새 값
     * @return bool 바뀌면 true
     */
    private function changedField(IdentityProvider $idp, string $field, mixed $value): bool
    {
        return $field === 'secrets' ? true : json_encode($idp->{$field}) !== json_encode($value);
    }

    /**
     * 날짜 값을 ISO 문자열로 (없거나 해석 불가면 null).
     *
     * @param  mixed  $value  값
     * @return string|null 날짜
     */
    private function dateOf(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * IdP 관리 행위를 활동 로그로 남깁니다 (비밀값과 칸 값은 남기지 않는다 — 바뀐 칸 이름만).
     *
     * @param  string  $action  활동
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, mixed>  $properties  추가 속성
     * @return void 반환값 없음
     */
    private function audit(string $action, IdentityProvider $idp, array $properties): void
    {
        $this->logActivity($action, [
            'description_key' => 'sirsoft-sso::activity_log.description.'.$action,
            'description_params' => ['idp' => $idp->localizedLabel()],
            'properties' => ['idp_key' => $idp->key, 'protocol' => $idp->protocol] + $properties,
        ]);
    }
}
