<?php

namespace App\Models;

use App\Models\Concerns\HasUserOverrides;
use App\Notifications\Identity\IdentityNotificationType;
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
     * 다국어 JSON 컬럼 — sub-key dot-path 단위 user_overrides 보존.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name'];

    /**
     * 모델 이벤트 등록 — 모든 변경 시 알림 정의 캐시 자동 삭제.
     *
     * @return void 반환값 없음
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
        'category',
        'name',
        'description',
        'variables',
        'channels',
        'hooks',
        'relations',
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
            'relations' => 'array',
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
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 특정 타입의 알림 정의 조회.
     *
     * @param  Builder  $query
     * @param  string  $type
     * @return Builder
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * 특정 확장의 알림 정의 조회.
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

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? '';
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

        return $description[$locale] ?? $description[config('app.fallback_locale', 'ko')] ?? '';
    }

    /**
     * 수신자 관계 키(`post_author` 등)의 표시 이름을 찾습니다.
     *
     * 관계는 그 알림을 만든 확장이 선언하므로 이름도 그 확장의 언어 파일에서 찾는다 — 확장은
     * `{식별자}::messages.notification_relations.{관계}`, 코어는 `notification.relations.{관계}`.
     * 이름이 없으면 키를 그대로 돌려준다.
     *
     * @param  string  $relation  관계 키
     * @return string 표시 이름(없으면 키)
     */
    public function relationLabel(string $relation): string
    {
        $identifier = (string) ($this->extension_identifier ?? '');
        $key = ($identifier === '' || $identifier === 'core')
            ? 'notification.relations.'.$relation
            : $identifier.'::messages.notification_relations.'.$relation;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $relation;
    }

    /**
     * 템플릿 변수(`{name}` 등)의 설명을 지금 언어로 찾습니다.
     *
     * 변수 설명은 선언(config/core.php·확장 getNotificationDefinitions())에 한국어 문자열로만 있어,
     * 그대로 내보내면 영어·일본어 화면의 편집 창에도 한국어가 섞였다. 운영자가 고칠 수 없는 시스템
     * 문구라 언어 파일에서 찾는다 — 본인인증 알림(`identity.*`)은 코어 공통 변수라
     * `notification.variables.identity.{키}`, 그 밖은 코어 `notification.variables.*` /
     * 확장 `{식별자}::messages.notification_variables.*` 에서 `by_type.{type}.{키}`(그 알림에서만 뜻이
     * 다른 변수) → `common.{키}` 순. 어디에도 없으면 선언 문자열을 그대로 돌려준다.
     *
     * @param  string  $key  변수 키
     * @param  mixed  $fallback  언어 파일에 없을 때 돌려줄 선언 설명
     * @return mixed 번역된 설명 문자열, 없으면 $fallback
     */
    public function variableDescription(string $key, mixed $fallback): mixed
    {
        if ($key === '' || str_contains($key, '.')) {
            return $fallback;
        }

        $type = (string) ($this->type ?? '');
        $identifier = (string) ($this->extension_identifier ?? '');
        $candidates = [];

        if (str_starts_with($type, IdentityNotificationType::PREFIX)) {
            $candidates[] = 'notification.variables.identity.'.$key;
        } else {
            $prefix = ($identifier === '' || $identifier === 'core')
                ? 'notification.variables.'
                : $identifier.'::messages.notification_variables.';
            if ($type !== '' && ! str_contains($type, '.')) {
                $candidates[] = $prefix.'by_type.'.$type.'.'.$key;
            }
            $candidates[] = $prefix.'common.'.$key;
        }

        foreach ($candidates as $candidate) {
            $text = __($candidate);
            if (is_string($text) && $text !== $candidate) {
                return $text;
            }
        }

        return $fallback;
    }

    /**
     * 변수 목록의 설명을 지금 언어로 바꿔 돌려줍니다(키·순서·다른 칸은 그대로).
     *
     * @param  array<int, mixed>|null  $variables  변수 목록(생략하면 이 정의의 variables)
     * @return array<int, mixed> 설명을 번역한 변수 목록
     */
    public function localizedVariables(?array $variables = null): array
    {
        $variables ??= (array) ($this->variables ?? []);

        return array_map(function ($variable) {
            if (! is_array($variable) || ! is_string($variable['key'] ?? null)) {
                return $variable;
            }
            $variable['description'] = $this->variableDescription($variable['key'], $variable['description'] ?? null);

            return $variable;
        }, $variables);
    }
}
