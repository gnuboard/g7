<?php

namespace App\Models;

use App\Enums\ActivityLogType;
use App\Extension\ExtensionManager;
use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 활동 로그 모델
 *
 * 시스템 전체의 활동 이력을 관리하는 모델입니다.
 * Polymorphic 관계를 통해 어떤 모델의 활동이든 기록할 수 있습니다.
 * description_key + description_params 기반 실시간 다국어 번역을 지원합니다.
 */
class ActivityLog extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'activity_logs';

    /**
     * 타임스탬프 설정
     * created_at만 사용 (updated_at 없음)
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'log_type',
        'loggable_type',
        'loggable_id',
        'user_id',
        'action',
        'description_key',
        'description_params',
        'properties',
        'changes',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 속성별 캐스트 타입
     */
    protected function casts(): array
    {
        return [
            'log_type' => ActivityLogType::class,
            'description_params' => 'array',
            'properties' => 'array',
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * 모델 생성 시 created_at 자동 설정
     *
     * @return void 반환값 없음
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ActivityLog $model) {
            if (! $model->created_at) {
                $model->created_at = now();
            }
        });
    }

    /**
     * 대상 모델 관계 (Polymorphic)
     *
     * @return MorphTo 활동 대상 모델 다형 관계
     */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 사용자 관계
     *
     * @return BelongsTo 행위자 사용자 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 다국어 번역된 설명을 반환합니다.
     *
     * description_key와 description_params를 사용하여 실시간 번역합니다.
     * 'core.activity_log.filter_description_params' 필터 훅을 통해
     * 모듈이 properties의 ID를 엔티티 이름으로 변환할 수 있습니다.
     *
     * @return string 현재 로케일로 번역한 설명 (description_key 가 없으면 빈 문자열)
     */
    public function getLocalizedDescriptionAttribute(): string
    {
        if ($this->description_key === null) {
            return '';
        }

        $params = $this->description_params ?? [];

        $params = HookManager::applyFilters(
            'core.activity_log.filter_description_params',
            $params,
            $this->description_key,
            $this->properties ?? []
        );

        // 다국어 배열 값을 현재 로케일 문자열로 변환 (__() 함수는 문자열만 치환 가능)
        $params = array_map(function ($value) {
            if (is_array($value)) {
                $locale = app()->getLocale();
                $fallback = config('app.fallback_locale', 'en');

                return $value[$locale] ?? $value[$fallback] ?? (string) reset($value);
            }

            return $value;
        }, $params);

        // 문구가 `단수|복수` 로 바뀌기 전에 저장된 행은 수를 `count` 없이 다른 이름(`attempts`·`days` 등)으로만
        // 담았다 — 그대로 두면 두 문장이 이어져 나오므로, 처음 나오는 수 치환값으로 고른다.
        if (! isset($params['count']) && str_contains((string) __($this->description_key), '|')) {
            foreach ($params as $value) {
                if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                    $params['count'] = $value;
                    break;
                }
            }
        }

        // 저장된 `count` 가 있으면 `단수|복수` 문구를 그 수로 고른다 (예: 「1 item」/「3 items」).
        return trans_count($this->description_key, $params);
    }

    /**
     * 로그 유형 라벨을 반환합니다.
     *
     * @return string 현재 로케일의 로그 유형 라벨
     */
    public function getLogTypeLabelAttribute(): string
    {
        return $this->log_type->label();
    }

    /**
     * 액션 라벨을 반환합니다.
     *
     * 5단계 조회: 모듈 lang 전체 키 → 모듈 lang 마지막 세그먼트
     *           → 코어 lang 전체 키 → 코어 lang 마지막 세그먼트 → raw 문자열 fallback
     *
     * 모듈/플러그인 origin 라벨은 자체 lang 파일에서 우선 조회되어 G7 의 영역 분리
     * 설계 의도와 일치합니다. 모듈 lang 미정의 시 코어 lang fallback (하위 호환).
     *
     * Origin 식별 우선순위:
     *   1) loggable_type FQCN (가장 정확)
     *   2) properties.extension_origin (loggable 없는 케이스 — 호출 시점 trait 자동 주입)
     *
     * @return string 현재 로케일의 액션 라벨
     */
    public function getActionLabelAttribute(): string
    {
        $namespace = $this->resolveOriginNamespace();
        $lastSegment = last(explode('.', $this->action));

        if ($namespace !== null) {
            // 1단계: 모듈 lang 의 전체 액션 키
            $moduleFullKey = "{$namespace}::activity_log.action.{$this->action}";
            $translated = __($moduleFullKey);
            if ($translated !== $moduleFullKey) {
                return $translated;
            }

            // 2단계: 모듈 lang 의 마지막 세그먼트
            $moduleSegmentKey = "{$namespace}::activity_log.action.{$lastSegment}";
            $translated = __($moduleSegmentKey);
            if ($translated !== $moduleSegmentKey) {
                return $translated;
            }
        }

        // 3단계: 코어 lang 의 전체 액션 키
        $fullKey = "activity_log.action.{$this->action}";
        $translated = __($fullKey);
        if ($translated !== $fullKey) {
            return $translated;
        }

        // 4단계: 코어 lang 의 마지막 세그먼트
        $segmentKey = "activity_log.action.{$lastSegment}";
        $translated = __($segmentKey);
        if ($translated !== $segmentKey) {
            return $translated;
        }

        // 5단계: raw action 문자열 fallback
        return $this->action;
    }

    /**
     * 본 활동 로그의 origin 모듈/플러그인 lang 네임스페이스를 추론합니다.
     *
     * @return string|null 모듈/플러그인 식별자 (네임스페이스), 코어 origin 또는 미해석 시 null
     */
    protected function resolveOriginNamespace(): ?string
    {
        if ($this->loggable_type !== null) {
            $resolved = ExtensionManager::resolveExtensionByFqcn($this->loggable_type);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $origin = $this->properties['extension_origin'] ?? null;
        if (is_string($origin) && $origin !== '') {
            return $origin;
        }

        // 출처 자동 주입(extension_origin) 이전에 쌓인 행은 properties 가 비어 있다 — 설명 키가
        // 확장 lang 네임스페이스(`sirsoft-ecommerce::...`)면 그것이 출처다(#138 실측 ⑧).
        $descriptionKey = (string) ($this->description_key ?? '');
        if (str_contains($descriptionKey, '::')) {
            return strstr($descriptionKey, '::', true) ?: null;
        }

        return null;
    }

    /**
     * 행위자 이름을 반환합니다.
     *
     * 회원이 없는 로그는 "시스템" 이다. 그중 CLI(Artisan 커맨드·스케줄러)에서 남은 로그는
     * properties.via 로 구분해 "시스템(CLI)" 로 표기한다. 'console' 은 via 를 먼저 도입한
     * 커맨드(security:ip-restriction)의 표기라 같은 뜻으로 받는다.
     *
     * @return string 회원 이름, 또는 시스템 / 시스템(CLI) 라벨
     */
    public function getActorNameAttribute(): string
    {
        if ($this->user) {
            return (string) $this->user->name;
        }

        return $this->isCliOrigin() ? __('common.system_cli') : __('common.system');
    }

    /**
     * CLI 진입점에서 남은 로그인지 판정합니다.
     *
     * @return bool properties.via 가 cli/console 이면 true
     */
    public function isCliOrigin(): bool
    {
        $via = is_array($this->properties) ? ($this->properties['via'] ?? null) : null;

        return in_array($via, ['cli', 'console'], true);
    }

    /**
     * 대상 모델 타입의 짧은 표시명을 반환합니다.
     *
     * FQCN에서 클래스명만 추출합니다.
     * 예: 'Modules\Sirsoft\Ecommerce\Models\Product' → 'Product'
     *
     * @return string|null 클래스 짧은 이름 (대상 타입이 없으면 null)
     */
    public function getLoggableTypeDisplayAttribute(): ?string
    {
        if ($this->loggable_type === null) {
            return null;
        }

        $parts = explode('\\', $this->loggable_type);

        return end($parts);
    }

    /**
     * 관리자 활동 로그만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeAdmin(Builder $query): Builder
    {
        return $query->where('log_type', ActivityLogType::Admin);
    }

    /**
     * 사용자 활동 로그만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeUserLog(Builder $query): Builder
    {
        return $query->where('log_type', ActivityLogType::User);
    }

    /**
     * 시스템 활동 로그만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('log_type', ActivityLogType::System);
    }

    /**
     * 특정 모델에 대한 로그만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  Model  $model  대상 모델
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeForModel(Builder $query, Model $model): Builder
    {
        return $query
            ->where('loggable_type', $model->getMorphClass())
            ->where('loggable_id', $model->getKey());
    }

    /**
     * 특정 액션에 대한 로그만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $action  액션 유형
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * 특정 사용자의 로그만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  int  $userId  사용자 ID
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * 최신순으로 정렬하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 정렬된 쿼리 빌더
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
