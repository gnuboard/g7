<?php

namespace Plugins\Sirsoft\SocialLogin\Models;

use App\Support\LocalizedValue;
use Illuminate\Database\Eloquent\Model;
use Plugins\Sirsoft\SocialLogin\Concerns\SavesOverUnreadableEncryptedValues;
use Plugins\Sirsoft\SocialLogin\Enums\ProviderType;

/**
 * 소셜 로그인 제공자 설정 1행.
 *
 * 비밀값(`client_secret`·`admin_key`)은 encrypted 캐스트로만 다룬다. 직렬화(`toArray`)에도 싣지 않는다 —
 * 응답에 내보낼 때는 컨트롤러가 마스크를 따로 만든다.
 *
 * @property int $id
 * @property string $key
 * @property string $driver
 * @property string $type
 * @property bool $enabled
 * @property int $sort_order
 * @property array<string, string> $label
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $admin_key
 * @property array<int, string>|null $scopes
 * @property string|null $discovery_url
 * @property string|null $authorize_url
 * @property string|null $token_url
 * @property string|null $userinfo_url
 * @property string|null $jwks_url
 * @property string|null $issuer
 * @property string|null $graph_api_version
 * @property array<string, string>|null $button_style
 * @property bool $signup_allowed
 * @property string $email_missing_policy
 * @property string $email_trust
 * @property array<string, mixed>|null $extra
 *
 * @since 1.0.0
 */
class SocialLoginProvider extends Model
{
    use SavesOverUnreadableEncryptedValues;

    /** @var string 테이블명 */
    protected $table = 'social_login_providers';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = [
        'key', 'driver', 'type', 'enabled', 'sort_order', 'label',
        'client_id', 'client_secret', 'admin_key', 'scopes',
        'discovery_url', 'authorize_url', 'token_url', 'userinfo_url', 'jwks_url', 'issuer',
        'graph_api_version', 'button_style', 'signup_allowed', 'email_missing_policy', 'email_trust', 'extra',
        'last_success_at', 'last_error_at', 'last_error_code',
    ];

    /** @var array<int, string> 직렬화에서 숨길 비밀 컬럼 */
    protected $hidden = ['client_secret', 'admin_key'];

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
            'client_secret' => 'encrypted',
            'admin_key' => 'encrypted',
            'scopes' => 'array',
            'button_style' => 'array',
            'signup_allowed' => 'boolean',
            'extra' => 'array',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /**
     * 내장 제공자인지 확인합니다.
     *
     * @return bool 내장이면 true
     */
    public function isBuiltin(): bool
    {
        return $this->type === ProviderType::Builtin->value;
    }

    /**
     * 표시 이름을 로케일에 맞춰 반환합니다.
     *
     * @param  string|null  $locale  로케일 (null 이면 현재 앱 로케일)
     * @return string 표시 이름 (코어 다국어 값 기준 — 받는 로케일 → 사이트 기본 → en, 모두 없으면 키)
     */
    public function localizedLabel(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $label = $this->label ?? [];

        return LocalizedValue::pick($label, $locale, false) ?? (string) $this->key;
    }
}
