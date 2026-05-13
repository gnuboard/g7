<?php

namespace App\Models;

use App\Enums\IdentityMessageScopeType;
use App\Models\Concerns\HasUserOverrides;
use App\Services\IdentityMessageDefinitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 본인인증 메시지 정의 모델.
 *
 * 알림 시스템(NotificationDefinition)과 분리된 IDV 전용 메시지 템플릿 시스템의 정의 레코드.
 * (provider_id, scope_type, scope_value) 매트릭스로 식별.
 */
class IdentityMessageDefinition extends Model
{
    use HasFactory, HasUserOverrides;

    /** @deprecated 7.0.0-beta.5 — IdentityMessageScopeType enum 사용 */
    public const SCOPE_PROVIDER_DEFAULT = 'provider_default';

    /** @deprecated 7.0.0-beta.5 — IdentityMessageScopeType enum 사용 */
    public const SCOPE_PURPOSE = 'purpose';

    /** @deprecated 7.0.0-beta.5 — IdentityMessageScopeType enum 사용 */
    public const SCOPE_POLICY = 'policy';

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name', 'is_active'];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위 user_overrides 보존.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name'];

    /**
     * 활동 로그 추적 필드.
     *
     * @var array<string, array<string, string>>
     */
    public static array $activityLogFields = [
        'name' => ['label_key' => 'activity_log.fields.name', 'type' => 'text'],
        'channels' => ['label_key' => 'activity_log.fields.channels', 'type' => 'text'],
        'is_active' => ['label_key' => 'activity_log.fields.is_active', 'type' => 'boolean'],
    ];

    /**
     * @var string
     */
    protected $table = 'identity_message_definitions';

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'channels' => '["mail"]',
        'variables' => '[]',
        'scope_value' => '',
    ];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'provider_id',
        'scope_type',
        'scope_value',
        'name',
        'description',
        'channels',
        'variables',
        'extension_type',
        'extension_identifier',
        'is_active',
        'is_default',
        'user_overrides',
    ];

    /**
     * 모델 이벤트 등록 — 모든 변경 시 정의 캐시 자동 삭제.
     */
    protected static function booted(): void
    {
        $invalidate = function () {
            try {
                app(IdentityMessageDefinitionService::class)->invalidateAllCache();
            } catch (\Throwable) {
                // 테스트/마이그레이션 환경에서 서비스 미등록 시 무시
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /**
     * 캐스팅 정의.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'channels' => 'array',
            'variables' => 'array',
            'scope_type' => IdentityMessageScopeType::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'user_overrides' => 'array',
        ];
    }

    /**
     * 메시지 템플릿 관계.
     *
     * @return HasMany
     */
    public function templates(): HasMany
    {
        return $this->hasMany(IdentityMessageTemplate::class, 'definition_id');
    }

    /**
     * 활성 정의만 조회.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 특정 프로바이더 정의 조회.
     *
     * @param  Builder  $query
     * @param  string  $providerId
     * @return Builder
     */
    public function scopeByProvider(Builder $query, string $providerId): Builder
    {
        return $query->where('provider_id', $providerId);
    }

    /**
     * 특정 scope 정의 조회.
     *
     * @param  Builder  $query
     * @param  string  $scopeType
     * @param  string|null  $scopeValue
     * @return Builder
     */
    public function scopeByScope(Builder $query, string $scopeType, ?string $scopeValue = null): Builder
    {
        return $query->where('scope_type', $scopeType)
            ->where('scope_value', $scopeValue ?? '');
    }

    /**
     * 특정 확장 정의 조회.
     *
     * @param  Builder  $query
     * @param  string  $extensionType
     * @param  string  $extensionIdentifier
     * @return Builder
     */
    public function scopeByExtension(Builder $query, string $extensionType, string $extensionIdentifier): Builder
    {
        return $query->where('extension_type', $extensionType)
            ->where('extension_identifier', $extensionIdentifier);
    }

    /**
     * 현재 로케일의 이름 반환.
     *
     * @param  string|null  $locale
     * @return string
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name ?? [];

        return $name[$locale] ?? $name['ko'] ?? $name['en'] ?? '';
    }

    /**
     * 현재 로케일의 설명 반환.
     *
     * @param  string|null  $locale
     * @return string
     */
    public function getLocalizedDescription(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $description = $this->description ?? [];

        return $description[$locale] ?? $description['ko'] ?? $description['en'] ?? '';
    }
}
