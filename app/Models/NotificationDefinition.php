<?php

namespace App\Models;

use App\Models\Concerns\HasUserOverrides;
use App\Services\NotificationDefinitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationDefinition extends Model
{
    use HasFactory, HasUserOverrides;

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name', 'is_active'];

    /**
     * 모델 이벤트 등록 — 모든 변경 시 알림 정의 캐시 자동 삭제.
     */
    protected static function booted(): void
    {
        $invalidate = function () {
            try {
                app(NotificationDefinitionService::class)->invalidateAllCache();
            } catch (\Throwable) {
                // 테스트/마이그레이션 환경에서 서비스 미등록 시 무시
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /**
     * 활동 로그 추적 필드.
     *
     * @var array<string, array<string, string>>
     */
    public static array $activityLogFields = [
        'name' => ['label_key' => 'activity_log.fields.name', 'type' => 'text'],
        'channels' => ['label_key' => 'activity_log.fields.channels', 'type' => 'text'],
        'hooks' => ['label_key' => 'activity_log.fields.hooks', 'type' => 'text'],
        'is_active' => ['label_key' => 'activity_log.fields.is_active', 'type' => 'boolean'],
    ];

    /**
     * @var string
     */
    protected $table = 'notification_definitions';

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'variables' => '[]',
        'channels' => '["mail"]',
        'hooks' => '[]',
    ];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'hook_prefix',
        'extension_type',
        'extension_identifier',
        'name',
        'description',
        'variables',
        'channels',
        'hooks',
        'is_active',
        'is_default',
        'user_overrides',
    ];

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
            'variables' => 'array',
            'channels' => 'array',
            'hooks' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'user_overrides' => 'array',
        ];
    }

    /**
     * 알림 템플릿 관계.
     *
     * @return HasMany
     */
    public function templates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class, 'definition_id');
    }

    /**
     * 활성 알림 정의만 조회.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 특정 타입의 알림 정의 조회.
     *
     * @param Builder $query
     * @param string $type
     * @return Builder
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * 특정 확장의 알림 정의 조회.
     *
     * @param Builder $query
     * @param string $extensionType
     * @param string $extensionIdentifier
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
     * @param string|null $locale
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
     * @param string|null $locale
     * @return string
     */
    public function getLocalizedDescription(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $description = $this->description ?? [];

        return $description[$locale] ?? $description['ko'] ?? $description['en'] ?? '';
    }
}
