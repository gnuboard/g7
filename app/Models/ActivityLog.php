<?php

namespace App\Models;

use App\Enums\ActivityLogType;
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
     * @return array<string, string>
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
     * @return MorphTo
     */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 사용자 관계
     *
     * @return BelongsTo
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
     * @return string
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

        return __($this->description_key, $params);
    }

    /**
     * 로그 유형 라벨을 반환합니다.
     *
     * @return string
     */
    public function getLogTypeLabelAttribute(): string
    {
        return $this->log_type->label();
    }

    /**
     * 액션 라벨을 반환합니다.
     *
     * 3단계 조회: 전체 키 → 마지막 세그먼트 → raw 문자열 fallback
     *
     * @return string
     */
    public function getActionLabelAttribute(): string
    {
        // 1단계: 전체 액션 키 (예: activity_log.action.user.create)
        $fullKey = "activity_log.action.{$this->action}";
        $translated = __($fullKey);
        if ($translated !== $fullKey) {
            return $translated;
        }

        // 2단계: 마지막 세그먼트만 (예: activity_log.action.create)
        $lastSegment = last(explode('.', $this->action));
        $segmentKey = "activity_log.action.{$lastSegment}";
        $translated = __($segmentKey);
        if ($translated !== $segmentKey) {
            return $translated;
        }

        // 3단계: raw action 문자열 fallback
        return $this->action;
    }

    /**
     * 행위자 이름을 반환합니다.
     *
     * @return string
     */
    public function getActorNameAttribute(): string
    {
        return $this->user?->name ?? __('common.system');
    }

    /**
     * 대상 모델 타입의 짧은 표시명을 반환합니다.
     *
     * FQCN에서 클래스명만 추출합니다.
     * 예: 'Modules\Sirsoft\Ecommerce\Models\Product' → 'Product'
     *
     * @return string|null
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
     * @param Builder $query 쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeAdmin(Builder $query): Builder
    {
        return $query->where('log_type', ActivityLogType::Admin);
    }

    /**
     * 사용자 활동 로그만 필터링하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeUserLog(Builder $query): Builder
    {
        return $query->where('log_type', ActivityLogType::User);
    }

    /**
     * 시스템 활동 로그만 필터링하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('log_type', ActivityLogType::System);
    }

    /**
     * 특정 모델에 대한 로그만 필터링하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @param Model $model 대상 모델
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
     * @param Builder $query 쿼리 빌더
     * @param string $action 액션 유형
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * 특정 사용자의 로그만 필터링하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @param int $userId 사용자 ID
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * 최신순으로 정렬하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder 정렬된 쿼리 빌더
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
