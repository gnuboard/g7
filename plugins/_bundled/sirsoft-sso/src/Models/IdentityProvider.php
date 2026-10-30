<?php

namespace Plugins\Sirsoft\Sso\Models;

use App\Support\LocalizedValue;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Sso\Concerns\SavesOverUnreadableEncryptedValues;
use Plugins\Sirsoft\Sso\Contracts\ProtocolDriverInterface;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;

/**
 * 회사 IdP 1행.
 *
 * 비밀값(`secrets`)은 encrypted:array 로 저장하고 직렬화에서 숨긴다 — API 응답과 훅 인자에 실리지 않게.
 *
 * @property int $id
 * @property string $key
 * @property string $protocol
 * @property bool $enabled
 * @property int $sort_order
 * @property array<string, string> $label
 * @property bool $button_visible
 * @property array<string, string>|null $button_style
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $secrets
 * @property array<int, string>|null $owned_domains
 * @property bool $enforce_owned_domains
 * @property string $provisioning_mode
 * @property array<string, bool>|null $link_methods
 * @property bool $require_consent
 * @property string $identity_policy
 * @property array<string, mixed>|null $attribute_map
 * @property array<int, array<string, mixed>>|null $role_mapping
 * @property string $role_sync_mode
 * @property string $group_source
 * @property string $two_factor_mode
 * @property string $logout_mode
 * @property bool $idp_initiated
 * @property bool $allow_local_password
 * @property int|null $max_session_minutes
 * @property array<string, mixed>|null $deprovision
 * @property string|null $scim_token_hash
 * @property Carbon|null $scim_token_issued_at
 * @property Carbon|null $metadata_refreshed_at
 * @property Carbon|null $cert_expires_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_error_at
 * @property Carbon|null $periodic_checked_at
 * @property string|null $last_error_code
 *
 * @since 1.0.0
 */
class IdentityProvider extends Model
{
    use SavesOverUnreadableEncryptedValues;

    /** 처음 온 사람 — 자동 생성 */
    public const PROVISIONING_JIT = 'jit';

    /** 처음 온 사람 — 사전 등록 회원만 */
    public const PROVISIONING_PREREGISTERED = 'preregistered';

    /** 키 형식 */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,49}$/';

    /** 모의 IdP 예약 접두 */
    public const MOCK_PREFIX = 'mock_';

    /** @var array<string, bool> 연결 방식 기본값 — 소유 도메인 자동은 기본 꺼짐 */
    public const DEFAULT_LINK_METHODS = ['domain_auto' => false, 'verify' => true, 'admin' => true];

    /** @var string 테이블명 */
    protected $table = 'sso_identity_providers';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = [
        'key', 'protocol', 'enabled', 'sort_order', 'label', 'button_visible', 'button_style', 'config', 'secrets',
        'owned_domains', 'enforce_owned_domains', 'provisioning_mode', 'link_methods', 'require_consent', 'identity_policy',
        'attribute_map', 'role_mapping', 'role_sync_mode', 'group_source', 'two_factor_mode', 'logout_mode', 'idp_initiated',
        'allow_local_password', 'max_session_minutes', 'deprovision', 'scim_token_hash', 'scim_token_issued_at',
        'metadata_refreshed_at', 'cert_expires_at', 'last_success_at', 'last_error_at', 'last_error_code',
    ];

    /** @var array<int, string> 직렬화에서 숨길 비밀 컬럼 */
    protected $hidden = ['secrets', 'scim_token_hash'];

    /** @var array<string, mixed> 기본값 (DB 기본값과 같게 — 저장 전 읽기용) */
    protected $attributes = [
        'enabled' => false,
        'sort_order' => 0,
        'button_visible' => true,
        'enforce_owned_domains' => false,
        'provisioning_mode' => self::PROVISIONING_PREREGISTERED,
        'require_consent' => true,
        'identity_policy' => 'skip',
        'role_sync_mode' => 'managed_only',
        'group_source' => 'assertion',
        'two_factor_mode' => 'skip_if_mfa',
        'logout_mode' => 'local',
        'idp_initiated' => false,
        'allow_local_password' => false,
    ];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort_order' => 'integer',
            'label' => 'array',
            'button_visible' => 'boolean',
            'button_style' => 'array',
            'config' => 'array',
            'secrets' => 'encrypted:array',
            'owned_domains' => 'array',
            'enforce_owned_domains' => 'boolean',
            'link_methods' => 'array',
            'require_consent' => 'boolean',
            'attribute_map' => 'array',
            'role_mapping' => 'array',
            'idp_initiated' => 'boolean',
            'allow_local_password' => 'boolean',
            'max_session_minutes' => 'integer',
            'deprovision' => 'array',
            'scim_token_issued_at' => 'datetime',
            'metadata_refreshed_at' => 'datetime',
            'cert_expires_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            // 프로비저닝 마이그레이션(000026)이 더한 칸 — 쓰는 쪽은 프로비저닝 저장소.
            'periodic_checked_at' => 'datetime',
        ];
    }

    /**
     * 이 IdP 의 회원 연결.
     *
     * @return HasMany<SsoAccount, $this> 연결 관계
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(SsoAccount::class, 'idp_id');
    }

    /**
     * 이 IdP 의 프로토콜 드라이버를 반환합니다.
     *
     * @return ProtocolDriverInterface 드라이버
     *
     * @throws SsoException 등록되지 않은 프로토콜이면 `driver_unavailable`
     */
    public function driver(): ProtocolDriverInterface
    {
        $driver = app(ProtocolRegistry::class)->get($this->protocol);
        if ($driver === null) {
            throw new SsoException('driver_unavailable', 'protocol='.$this->protocol);
        }

        return $driver;
    }

    /**
     * 로케일에 맞는 표시 이름을 반환합니다 (코어 다국어 값 단일 기준 — 받는 로케일 → 사이트 기본 → en → 첫 값, 없으면 키).
     *
     * @param  string|null  $locale  로케일 (null 이면 현재)
     * @return string 표시 이름
     */
    public function localizedLabel(?string $locale = null): string
    {
        $labels = is_array($this->label) ? $this->label : [];

        $value = LocalizedValue::pick($labels, $locale ?? app()->getLocale());

        return is_string($value) && $value !== '' ? $value : $this->key;
    }

    /**
     * 프로토콜 설정 값을 읽습니다.
     *
     * @param  string  $key  설정 키
     * @param  mixed  $default  기본값
     * @return mixed 값
     */
    public function configValue(string $key, mixed $default = null): mixed
    {
        $config = is_array($this->config) ? $this->config : [];

        return array_key_exists($key, $config) && $config[$key] !== null && $config[$key] !== '' ? $config[$key] : $default;
    }

    /**
     * 비밀값을 읽습니다.
     *
     * @param  string  $key  비밀 키
     * @return string|null 값, 없으면 null
     */
    public function secret(string $key): ?string
    {
        $secrets = is_array($this->secrets) ? $this->secrets : [];
        $value = $secrets[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * 관리자 화면이 다룰 비밀값을 읽습니다 — 앱 키가 바뀌어 풀 수 없으면 빈 배열로 둡니다.
     *
     * 예외를 그대로 올리면 편집 화면과 저장이 500 이 되어, 비밀값을 다시 입력해 복구할 길이 막힌다.
     *
     * @return array<string, mixed> 비밀값 (풀 수 없으면 빈 배열)
     */
    public function readableSecrets(): array
    {
        try {
            $secrets = $this->secrets;
        } catch (DecryptException) {
            Log::warning('[sirsoft-sso] IdP 비밀값을 풀 수 없습니다 — 앱 키가 바뀌었으면 비밀값을 다시 입력하세요.', ['idp' => $this->key]);

            return [];
        }

        return is_array($secrets) ? $secrets : [];
    }

    /**
     * 저장된 비밀값을 지금 앱 키로 풀 수 없는지 확인합니다.
     *
     * @return bool 풀 수 없으면 true
     */
    public function secretsUnreadable(): bool
    {
        try {
            $this->secrets;
        } catch (DecryptException) {
            return true;
        }

        return false;
    }

    /**
     * 이 IdP 가 이메일 도메인을 소유하는지 확인합니다.
     *
     * @param  string|null  $domain  도메인 (대소문자 무시)
     * @return bool 소유하면 true
     */
    public function ownsDomain(?string $domain): bool
    {
        if ($domain === null || $domain === '') {
            return false;
        }

        $owned = array_map(static fn ($d) => mb_strtolower(trim((string) $d)), is_array($this->owned_domains) ? $this->owned_domains : []);

        return in_array(mb_strtolower($domain), $owned, true);
    }

    /**
     * 기존 회원 연결 방식이 켜져 있는지 확인합니다.
     *
     * @param  string  $method  domain_auto, verify, admin
     * @return bool 켜져 있으면 true
     */
    public function linkMethodEnabled(string $method): bool
    {
        $methods = array_merge(self::DEFAULT_LINK_METHODS, is_array($this->link_methods) ? $this->link_methods : []);

        return (bool) ($methods[$method] ?? false);
    }

    /**
     * 처음 온 사람을 자동 생성(JIT)하는지 확인합니다.
     *
     * @return bool 자동 생성이면 true
     */
    public function isJit(): bool
    {
        return $this->provisioning_mode === self::PROVISIONING_JIT;
    }

    /**
     * 모의 IdP 인지 확인합니다 (키 접두 `mock_`).
     *
     * @return bool 모의 IdP 면 true
     */
    public function isMock(): bool
    {
        return str_starts_with($this->key, self::MOCK_PREFIX);
    }
}
