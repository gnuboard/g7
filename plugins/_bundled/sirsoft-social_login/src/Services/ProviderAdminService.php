<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Extension\HookManager;
use App\Support\SensitiveSettingMask;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\SocialLogin\Contracts\ProviderDriverInterface;
use Plugins\Sirsoft\SocialLogin\Drivers\AbstractOAuth2Driver;
use Plugins\Sirsoft\SocialLogin\Drivers\OidcDriver;
use Plugins\Sirsoft\SocialLogin\Enums\EmailMissingPolicy;
use Plugins\Sirsoft\SocialLogin\Enums\EmailTrust;
use Plugins\Sirsoft\SocialLogin\Enums\ProviderDriver;
use Plugins\Sirsoft\SocialLogin\Enums\ProviderType;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;

/**
 * 관리자 제공자 설정 — 목록·수정·일반 OIDC 제공자 추가·삭제·정렬·discovery 조회·영향 조회.
 *
 * 비밀값(`client_secret`·`admin_key`)은 응답에 값을 싣지 않는다. 저장돼 있으면 코어 설정 화면과 같은 마스크
 * (`SensitiveSettingMask::MASK`), 없으면 null 이다. 저장 요청에서 마스크를 그대로 돌려보내거나 키를 빼면 유지,
 * 빈 문자열(null)이면 지운다. 마스크 판정은 암호문 존재만 보고 복호화하지 않는다 — 앱 키가 바뀌어 복호화가 안 되는
 * 사이트에서도 목록이 열려야 진단과 재입력이 가능하다.
 *
 * 다른 확장이 등록한 제공자(테이블 행이 없는 것)는 읽기 전용으로만 보인다. 등록 필터 계약(드라이버 객체 인자)은
 * 바꾸지 않는다.
 *
 * @since 1.0.0
 */
class ProviderAdminService
{
    /** 제공자 추가 직후 훅 (인자: array $summary) */
    public const ACTION_AFTER_CREATE = 'sirsoft-social_login.provider.after_create';

    /** 제공자 수정 직후 훅 (인자: array $summary, array $changed) */
    public const ACTION_AFTER_UPDATE = 'sirsoft-social_login.provider.after_update';

    /** 제공자 삭제 직후 훅 (인자: array $summary) */
    public const ACTION_AFTER_DELETE = 'sirsoft-social_login.provider.after_delete';

    /** 비밀값 컬럼 */
    public const SECRET_FIELDS = ['client_secret', 'admin_key'];

    /** 키 규칙 — 커스텀 제공자 */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,49}$/';

    /** 모의 제공자 예약 접두 */
    public const RESERVED_PREFIX = 'mock_';

    /** 웹훅을 받는 내장 제공자 => 웹훅 종류 => 웹 라우트 이름 (`src/routes/web.php` 의 webhooks 묶음) */
    public const WEBHOOK_ROUTES = [
        'kakao' => ['unlink' => 'web.plugins.sirsoft-social_login.webhooks.kakao'],
        'naver' => ['unlink' => 'web.plugins.sirsoft-social_login.webhooks.naver'],
        'facebook' => [
            'deauthorize' => 'web.plugins.sirsoft-social_login.webhooks.facebook.deauthorize',
            'data_deletion' => 'web.plugins.sirsoft-social_login.webhooks.facebook.data-deletion',
        ],
    ];

    /** 드라이버별 콜백 주소 등록 주의사항 키 (`resources/lang` 의 admin.providers.notes.*) */
    private const NOTES = [
        'kakao' => ['client_secret_default_on', 'biz_app_email', 'reconsent_needs_email_item', 'redirect_koe006', 'webhook_https_443'],
        'naver' => ['review_no_test_account', 'redirect_exact'],
        'google' => ['redirect_exact_match', 'unverified_app'],
        'facebook' => ['strict_mode', 'email_app_review', 'data_deletion_callback'],
        'oidc' => ['redirect_exact', 'discovery_https'],
    ];

    /** 수정 가능한 일반 컬럼 */
    private const EDITABLE = [
        'enabled', 'label', 'client_id', 'scopes', 'signup_allowed', 'email_missing_policy', 'email_trust',
        'discovery_url', 'authorize_url', 'token_url', 'userinfo_url', 'jwks_url', 'issuer', 'graph_api_version', 'button_style',
    ];

    /** 커스텀(일반 OIDC) 제공자만 바꿀 수 있는 컬럼 */
    private const CUSTOM_ONLY = ['discovery_url', 'authorize_url', 'token_url', 'userinfo_url', 'jwks_url', 'issuer', 'button_style'];

    /** `extra` 에서 운영자가 드라이버와 무관하게 바꿀 수 있는 켬·끔 키 — 모의 표지·주소 덮어쓰기는 받지 않는다. 카카오 앱 ID(`app_id`)는 카카오에만 받으므로 아래에서 따로 다룬다 */
    private const EDITABLE_EXTRA = ['webhook_ip_allowlist_enabled'];

    /**
     * @param  ProviderRepositoryInterface  $providers  제공자 저장소
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  ProviderRegistry  $registry  제공자 레지스트리
     */
    public function __construct(
        private readonly ProviderRepositoryInterface $providers,
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly ProviderRegistry $registry,
    ) {}

    /**
     * 설정 화면용 제공자 목록을 돌려줍니다.
     *
     * @return array<string, mixed> `providers`(내장·커스텀·확장 등록) · `limits`
     */
    public function list(): array
    {
        $rows = $this->providers->all();
        $items = $rows->map(fn (SocialLoginProvider $row) => $this->present($row))->values()->all();

        $tableKeys = $rows->pluck('key')->all();
        foreach ($this->registry->all() as $key => $driver) {
            if (! in_array($key, $tableKeys, true)) {
                $items[] = $this->presentExtension((string) $key, $driver);
            }
        }

        return [
            'providers' => $items,
            'limits' => [
                'custom_count' => $this->providers->countCustom(),
                'custom_limit' => $this->customLimit(),
            ],
        ];
    }

    /**
     * 제공자 1건을 응답 모양으로 돌려줍니다.
     *
     * @param  string  $key  제공자 키
     * @return array<string, mixed> 제공자
     *
     * @throws SocialFlowException 테이블에 없는 제공자(`provider_unknown`, 404)
     */
    public function show(string $key): array
    {
        return $this->present($this->findOrFail($key));
    }

    /**
     * 제공자 설정을 바꿉니다.
     *
     * 키·드라이버·종류는 바꾸지 않는다. 내장 제공자는 주소 칸·버튼 색을 받지 않는다(드라이버가 정한다).
     * 켜려면 클라이언트 ID 와 필수 비밀값이 있어야 한다.
     *
     * @param  string  $key  제공자 키
     * @param  array<string, mixed>  $input  검증된 입력
     * @return array<string, mixed> 바뀐 제공자
     *
     * @throws SocialFlowException 테이블에 없는 제공자(404), 자격증명 없이 켜기(`credentials_required`, 422)
     */
    public function update(string $key, array $input): array
    {
        $row = $this->findOrFail($key);
        $values = $this->editableValues($row->type, $row->driver, $row->label ?? [], $input);
        $values += $this->secretValues($row->driver, $input);
        $values += $this->extraValues($row, $input);

        $this->assertEnableable($row, $values);

        $changed = $this->providers->update($row, $values);
        $this->registry->flush();

        if ($changed !== []) {
            HookManager::doAction(self::ACTION_AFTER_UPDATE, $this->summary($row), $this->publicFieldNames($changed));
        }

        return $this->present($row);
    }

    /**
     * 일반 OIDC 제공자를 추가합니다 (꺼진 상태로).
     *
     * @param  array<string, mixed>  $input  검증된 입력 (key·label·client_id·client_secret·discovery_url 또는 주소 4종)
     * @return array<string, mixed> 만든 제공자
     *
     * @throws SocialFlowException 예약·중복 키(`key_taken`, 422), 한도 초과(`custom_limit_reached`, 422)
     */
    public function create(array $input): array
    {
        $key = (string) $input['key'];

        if ($this->isReservedKey($key) || $this->providers->findByKey($key) !== null || $this->registry->get($key) !== null) {
            throw new SocialFlowException('key_taken', 422);
        }

        // 한도 확인과 추가만 트랜잭션에 묶는다 — 훅은 커밋 뒤에 발화한다(구독자가 롤백된 추가를 기록하지 않게).
        $row = DB::transaction(function () use ($key, $input) {
            if ($this->providers->countCustom() >= $this->customLimit()) {
                throw new SocialFlowException('custom_limit_reached', 422);
            }

            $base = [
                'key' => $key,
                'driver' => ProviderDriver::Oidc->value,
                'type' => ProviderType::Custom->value,
                'enabled' => false,
                'sort_order' => $this->providers->maxSortOrder() + 1,
                'signup_allowed' => true,
                'email_missing_policy' => EmailMissingPolicy::Ask->value,
                'email_trust' => EmailTrust::Flag->value,
            ];
            $values = $this->editableValues($base['type'], $base['driver'], [], ['enabled' => false] + $input)
                + $this->secretValues($base['driver'], $input);

            return $this->providers->create($values + $base);
        });

        $this->registry->flush();
        HookManager::doAction(self::ACTION_AFTER_CREATE, $this->summary($row));

        return $this->present($row);
    }

    /**
     * 일반 OIDC 제공자를 지웁니다.
     *
     * 내장 제공자는 지우지 않는다(끄면 된다). 연결된 회원이 있으면 지우지 않는다 — 연결 행이 가리킬 제공자가 사라지면
     * 그 회원들은 이 사이트에서 연결을 볼 수도, 끊을 수도 없게 된다. 먼저 끄고 회원이 다른 수단을 갖게 한 뒤 지운다.
     *
     * @param  string  $key  제공자 키
     * @return void 반환값 없음
     *
     * @throws SocialFlowException 내장(`builtin_not_deletable`, 422), 연결 남음(`provider_has_links`, 409)
     */
    public function delete(string $key): void
    {
        $row = $this->findOrFail($key);

        if ($row->isBuiltin()) {
            throw new SocialFlowException('builtin_not_deletable', 422);
        }

        if ($this->linkedCount($key) > 0) {
            throw new SocialFlowException('provider_has_links', 409);
        }

        $summary = $this->summary($row);
        $this->providers->delete($row);
        $this->registry->flush();

        HookManager::doAction(self::ACTION_AFTER_DELETE, $summary);
    }

    /**
     * 표시 순서를 바꿉니다.
     *
     * 목록에 없는 테이블 제공자는 받은 순서 뒤에 원래 순서대로 붙는다. 테이블에 없는 키(확장 등록)는 무시한다.
     *
     * @param  array<int, string>  $keys  새 순서의 제공자 키
     * @return array<int, string> 저장된 순서
     */
    public function reorder(array $keys): array
    {
        $rows = $this->providers->all()->keyBy('key');
        $ordered = [];

        foreach ($keys as $key) {
            if ($rows->has($key) && ! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        foreach ($rows->keys() as $key) {
            if (! in_array($key, $ordered, true)) {
                $ordered[] = (string) $key;
            }
        }

        DB::transaction(function () use ($ordered, $rows) {
            foreach ($ordered as $index => $key) {
                $this->providers->update($rows->get($key), ['sort_order' => $index + 1]);
            }
        });

        $this->registry->flush();

        return $ordered;
    }

    /**
     * discovery 문서를 읽어 주소를 채울 값을 돌려줍니다 (저장하지 않음).
     *
     * @param  string  $url  discovery 주소 (https)
     * @return array<string, string|null> issuer·authorize_url·token_url·userinfo_url·jwks_url
     *
     * @throws SocialFlowException 받지 못했거나 필수 키가 없을 때(`discovery_failed`·`discovery_incomplete`, 422)
     */
    public function discover(string $url): array
    {
        try {
            // 캐시 없이 새로 읽는다 — 운영자가 방금 고친 문서를 확인하는 용도다.
            $doc = (new OidcDriver)->withConfig(['key' => 'discovery', 'discovery_url' => $url])->discovery($url);
        } catch (ProviderException $e) {
            // 코드는 그대로 두고 문구만 관리자용으로 — 회원 흐름의 같은 코드는 공통 문구다(회원 흐름 API 계약).
            throw new SocialFlowException($e->errorCode, 422, 'exceptions.admin.'.$e->errorCode);
        }

        return [
            'issuer' => (string) $doc['issuer'],
            'authorize_url' => (string) $doc['authorization_endpoint'],
            'token_url' => (string) $doc['token_endpoint'],
            'userinfo_url' => isset($doc['userinfo_endpoint']) && is_string($doc['userinfo_endpoint']) ? $doc['userinfo_endpoint'] : null,
            'jwks_url' => (string) $doc['jwks_uri'],
        ];
    }

    /**
     * 제공자를 끄거나 자격증명을 바꾸기 전에 볼 영향 수치를 돌려줍니다.
     *
     * @param  string  $key  제공자 키
     * @return array<string, int> social_only_users(이 제공자로만 로그인하는 회원) · linked_users(연결 회원)
     *
     * @throws SocialFlowException 등록되지 않은 제공자(404)
     */
    public function impact(string $key): array
    {
        if ($this->providers->findByKey($key) === null && $this->registry->get($key) === null) {
            throw new SocialFlowException('provider_unknown', 404);
        }

        return [
            'social_only_users' => $this->accounts->socialOnlyUserCountForProvider($key),
            'linked_users' => $this->linkedCount($key),
        ];
    }

    /**
     * 테이블 행을 찾습니다.
     *
     * @param  string  $key  제공자 키
     * @return SocialLoginProvider 행
     *
     * @throws SocialFlowException 없을 때(`provider_unknown`, 404 — 확장 등록 제공자도 여기서 막힌다)
     */
    public function findOrFail(string $key): SocialLoginProvider
    {
        $row = $this->providers->findByKey($key);
        if ($row === null) {
            throw new SocialFlowException('provider_unknown', 404);
        }

        return $row;
    }

    /**
     * 제공자 행을 응답 모양으로 만듭니다.
     *
     * @param  SocialLoginProvider  $row  행
     * @return array<string, mixed> 제공자
     */
    public function present(SocialLoginProvider $row): array
    {
        $driver = $this->registry->make($row);

        return [
            'key' => $row->key,
            'driver' => $row->driver,
            'type' => $row->type,
            'read_only' => false,
            'enabled' => (bool) $row->enabled,
            'sort_order' => (int) $row->sort_order,
            'label' => $row->label ?? [],
            'display_label' => $row->localizedLabel(),
            'brand' => ProviderSettings::brandForDriver($row->driver),
            'client_id' => $row->client_id,
            'client_secret' => $this->mask($row, 'client_secret'),
            'admin_key' => $this->mask($row, 'admin_key'),
            'credentials_unreadable' => $this->credentialsUnreadable($row),
            'scopes' => $row->scopes ?? [],
            'discovery_url' => $row->discovery_url,
            'authorize_url' => $row->authorize_url,
            'token_url' => $row->token_url,
            'userinfo_url' => $row->userinfo_url,
            'jwks_url' => $row->jwks_url,
            'issuer' => $row->issuer,
            'graph_api_version' => $row->graph_api_version,
            'button_style' => $row->button_style,
            'signup_allowed' => (bool) $row->signup_allowed,
            'email_missing_policy' => $row->email_missing_policy,
            'email_trust' => $row->email_trust,
            'webhook_ip_allowlist_enabled' => (bool) ($row->extra['webhook_ip_allowlist_enabled'] ?? true),
            'webhook_app_id' => isset($row->extra['app_id']) ? (string) $row->extra['app_id'] : null,
            'credential_schema' => $driver?->credentialSchema() ?? [],
            'supports' => $driver?->supports() ?? [],
            'notes' => $this->notes($row->driver),
            'redirect_uri' => OAuthFlowService::callbackUrl($row->key),
            'webhook_urls' => $this->webhookUrls($row->key, $row->isBuiltin()),
            'last_success_at' => $row->last_success_at?->toIso8601String(),
            'last_error_at' => $row->last_error_at?->toIso8601String(),
            'last_error_code' => $row->last_error_code,
        ];
    }

    /**
     * 확장이 등록한 제공자를 읽기 전용 행으로 만듭니다.
     *
     * @param  string  $key  제공자 키
     * @param  ProviderDriverInterface  $driver  드라이버
     * @return array<string, mixed> 제공자
     */
    private function presentExtension(string $key, ProviderDriverInterface $driver): array
    {
        $name = $driver instanceof AbstractOAuth2Driver ? $driver->driverName() : 'custom';

        return [
            'key' => $key,
            'driver' => $name,
            'type' => 'extension',
            'read_only' => true,
            'enabled' => true,
            'sort_order' => null,
            'label' => [],
            'display_label' => $driver->label(),
            'brand' => ProviderSettings::brandForDriver($name),
            'redirect_uri' => OAuthFlowService::callbackUrl($key),
            'webhook_urls' => [],
            'notes' => [],
        ];
    }

    /**
     * 입력에서 바꿀 일반 컬럼을 고릅니다.
     *
     * @param  string  $type  제공자 종류 (builtin·custom)
     * @param  string  $driver  드라이버 이름
     * @param  array<string, string>  $currentLabel  지금 표시 이름 (부분 입력과 합친다)
     * @param  array<string, mixed>  $input  입력
     * @return array<string, mixed> 컬럼 값
     */
    private function editableValues(string $type, string $driver, array $currentLabel, array $input): array
    {
        $values = [];
        $custom = $type === ProviderType::Custom->value;

        foreach (self::EDITABLE as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            if (in_array($field, self::CUSTOM_ONLY, true) && ! $custom) {
                continue;
            }

            if ($field === 'graph_api_version' && $driver !== ProviderDriver::Facebook->value) {
                continue;
            }

            $value = $input[$field];
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            if ($field === 'label' && is_array($value)) {
                $value = array_filter(array_merge($currentLabel, $value), fn ($v) => is_string($v) && $v !== '');
            }

            if ($field === 'scopes' && is_array($value)) {
                $value = array_values(array_unique(array_filter(array_map('trim', $value), fn ($v) => $v !== '')));
            }

            $values[$field] = $value;
        }

        return $values;
    }

    /**
     * 비밀값 입력을 해석합니다 — 키 없음·마스크는 유지, 빈 문자열(요청에서 null 로 바뀐 값 포함)은 삭제, 그 밖은 새 값.
     *
     * 전역 미들웨어가 빈 문자열을 null 로 바꾸므로 null 도 삭제로 읽는다(코어 설정 화면 비밀값과 같은 규칙).
     *
     * @param  string  $driver  드라이버 이름
     * @param  array<string, mixed>  $input  입력
     * @return array<string, string|null> 컬럼 값
     */
    private function secretValues(string $driver, array $input): array
    {
        $values = [];

        foreach (self::SECRET_FIELDS as $field) {
            if (! array_key_exists($field, $input) || $input[$field] === SensitiveSettingMask::MASK) {
                continue;
            }

            if ($field === 'admin_key' && $driver !== ProviderDriver::Kakao->value) {
                continue;
            }

            $value = trim((string) $input[$field]);
            $values[$field] = $value === '' ? null : $value;
        }

        return $values;
    }

    /**
     * `extra` 의 운영자 편집 키만 합칩니다.
     *
     * @param  SocialLoginProvider  $row  행
     * @param  array<string, mixed>  $input  입력
     * @return array<string, mixed> `extra` 컬럼 값 (바뀌지 않으면 빈 배열)
     */
    private function extraValues(SocialLoginProvider $row, array $input): array
    {
        $extra = $row->extra ?? [];
        $touched = false;

        foreach (self::EDITABLE_EXTRA as $field) {
            if (array_key_exists($field, $input)) {
                $extra[$field] = (bool) $input[$field];
                $touched = true;
            }
        }

        // 카카오 웹훅 앱 ID — 넣으면 다른 앱의 알림을 거른다(`KakaoDriver::verifyWebhook`), 비우면 대조하지 않는다.
        if ($row->driver === ProviderDriver::Kakao->value && array_key_exists('webhook_app_id', $input)) {
            $appId = trim((string) $input['webhook_app_id']);
            if ($appId === '') {
                unset($extra['app_id']);
            } else {
                $extra['app_id'] = $appId;
            }
            $touched = true;
        }

        return $touched ? ['extra' => $extra] : [];
    }

    /**
     * 켜기 전에 자격증명이 있는지 확인합니다 — 저장된 값에 이번에 바꿀 값을 겹쳐 본다(모델은 건드리지 않는다).
     *
     * 비밀값은 암호문 존재만 본다(복호화하지 않는다 — 앱 키가 바뀐 사이트에서도 판정이 멈추지 않게).
     *
     * @param  SocialLoginProvider  $row  저장된 행
     * @param  array<string, mixed>  $values  이번에 바꿀 컬럼 값
     * @return void 반환값 없음
     *
     * @throws SocialFlowException `credentials_required`(422)
     */
    private function assertEnableable(SocialLoginProvider $row, array $values): void
    {
        $value = fn (string $field) => array_key_exists($field, $values) ? $values[$field] : $row->getAttribute($field);

        if (! $value('enabled')) {
            return;
        }

        $secretSet = array_key_exists('client_secret', $values)
            ? $values['client_secret'] !== null
            : $row->getRawOriginal('client_secret') !== null;
        $missing = blank($value('client_id')) || ! $secretSet;
        if ($row->type === ProviderType::Custom->value) {
            $missing = $missing || (blank($value('discovery_url'))
                && (blank($value('authorize_url')) || blank($value('token_url')) || blank($value('jwks_url')) || blank($value('issuer'))));
        }

        if ($missing) {
            throw new SocialFlowException('credentials_required', 422);
        }
    }

    /**
     * 비밀값 표시 값을 만듭니다.
     *
     * @param  SocialLoginProvider  $row  행
     * @param  string  $field  컬럼
     * @return string|null 마스크(설정됨) 또는 null(미설정)
     */
    private function mask(SocialLoginProvider $row, string $field): ?string
    {
        return $row->getRawOriginal($field) !== null ? SensitiveSettingMask::MASK : null;
    }

    /**
     * 저장된 비밀값을 지금 앱 키로 풀 수 없는지 확인합니다 (앱 키 교체 진단).
     *
     * @param  SocialLoginProvider  $row  행
     * @return bool 풀 수 없으면 true
     */
    public function credentialsUnreadable(SocialLoginProvider $row): bool
    {
        foreach (self::SECRET_FIELDS as $field) {
            $raw = $row->getRawOriginal($field);
            if ($raw === null) {
                continue;
            }

            try {
                decrypt($raw, false);
            } catch (\Throwable) {
                return true;
            }
        }

        return false;
    }

    /**
     * 웹훅 주소를 돌려줍니다 (내장 제공자 중 웹훅을 받는 것만).
     *
     * @param  string  $key  제공자 키
     * @param  bool  $builtin  내장 여부
     * @return array<string, string> 이름 => 절대 주소
     */
    private function webhookUrls(string $key, bool $builtin): array
    {
        if (! $builtin) {
            return [];
        }

        // 제공자 콘솔에 등록하는 값이라 콜백 주소와 같이 요청 Host 가 아니라 사이트 주소(app.url) 기준으로 만든다.
        return array_map(fn (string $name) => SiteUrl::absolute(route($name, [], false)), self::WEBHOOK_ROUTES[$key] ?? []);
    }

    /**
     * 주의사항 다국어 키를 돌려줍니다 (화면이 `$t:` 로 그린다).
     *
     * @param  string  $driver  드라이버 이름
     * @return array<int, string> `sirsoft-social_login.admin.providers.notes.{driver}.{note}` 키
     */
    private function notes(string $driver): array
    {
        return array_map(
            fn (string $note) => 'sirsoft-social_login.admin.providers.notes.'.$driver.'.'.$note,
            self::NOTES[$driver] ?? [],
        );
    }

    /**
     * 훅·활동 로그에 넘길 요약 (비밀값 없음).
     *
     * @param  SocialLoginProvider  $row  행
     * @return array<string, mixed> key·driver·type·enabled·label
     */
    private function summary(SocialLoginProvider $row): array
    {
        return [
            'key' => $row->key,
            'driver' => $row->driver,
            'type' => $row->type,
            'enabled' => (bool) $row->enabled,
            'label' => $row->localizedLabel(),
        ];
    }

    /**
     * 바뀐 컬럼 이름에서 비밀값을 이름만 남겨 돌려줍니다 (값은 싣지 않는다).
     *
     * @param  array<int, string>  $changed  바뀐 컬럼
     * @return array<int, string> 컬럼 이름
     */
    private function publicFieldNames(array $changed): array
    {
        return array_values(array_diff($changed, ['updated_at']));
    }

    /**
     * 커스텀 키로 쓸 수 없는 키인지 확인합니다.
     *
     * @param  string  $key  키
     * @return bool 예약·내장·규칙 위반이면 true
     */
    private function isReservedKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) !== 1
            || str_starts_with($key, self::RESERVED_PREFIX)
            || in_array($key, ProviderRegistry::BUILTIN_KEYS, true)
            || in_array($key, ProviderDriver::values(), true);
    }

    /**
     * 커스텀 제공자 한도.
     *
     * @return int 한도
     */
    private function customLimit(): int
    {
        return (int) plugin_setting('sirsoft-social_login', 'custom_provider_limit', ProviderRegistry::DEFAULT_CUSTOM_LIMIT);
    }

    /**
     * 이 제공자에 연결된 회원 수.
     *
     * @param  string  $key  제공자 키
     * @return int 회원 수
     */
    private function linkedCount(string $key): int
    {
        return $this->accounts->countForProvider($key);
    }
}
