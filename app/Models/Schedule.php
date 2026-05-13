<?php

namespace App\Models;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Models\Concerns\HasUserOverrides;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 스케줄 모델.
 *
 * 스케줄(크론) 작업을 관리하는 모델입니다.
 *
 * @since 7.0.0-beta.2 (HasUserOverrides 사전 설계)
 *
 * 현재는 시더 없이 사용자 수동 생성 중심입니다.
 * 사용자가 trackable 필드를 수정하면 trait 의 Eloquent updating 이벤트가
 * user_overrides 에 자동 기록합니다.
 *
 * 다음 버전에서 스케줄을 DB 로 관리할 때, 코어·확장 seeder 는
 * `GenericEntitySyncHelper` 를 호출하여 아래 패턴으로 동기화하면 됩니다
 * (사용자 수정 보존 + stale cleanup 자동 동작):
 *
 * ```php
 * $helper = app(\App\Extension\Helpers\GenericEntitySyncHelper::class);
 * $definedNames = [];
 * foreach ($defaultSchedules as $data) {
 *     $helper->sync(Schedule::class, ['name' => $data['name']], $data);
 *     $definedNames[] = $data['name'];
 * }
 * $helper->cleanupStale(Schedule::class,
 *     ['extension_type' => $extType, 'extension_identifier' => $extId],
 *     'name', $definedNames);
 * ```
 */
class Schedule extends Model
{
    use HasFactory, HasUserOverrides;

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = [
        'expression',
        'command',
        'timeout',
        'is_active',
    ];

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'name' => ['label_key' => 'activity_log.fields.name', 'type' => 'text'],
        'description' => ['label_key' => 'activity_log.fields.description', 'type' => 'text'],
        'command' => ['label_key' => 'activity_log.fields.command', 'type' => 'text'],
        'expression' => ['label_key' => 'activity_log.fields.expression', 'type' => 'text'],
        'without_overlapping' => ['label_key' => 'activity_log.fields.without_overlapping', 'type' => 'boolean'],
        'run_in_maintenance' => ['label_key' => 'activity_log.fields.run_in_maintenance', 'type' => 'boolean'],
        'timeout' => ['label_key' => 'activity_log.fields.timeout', 'type' => 'number'],
        'is_active' => ['label_key' => 'activity_log.fields.is_active', 'type' => 'boolean'],
    ];

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'schedules';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'type',
        'command',
        'expression',
        'frequency',
        'without_overlapping',
        'run_in_maintenance',
        'timeout',
        'is_active',
        'last_result',
        'last_run_at',
        'next_run_at',
        'extension_type',
        'extension_identifier',
        'created_by',
        'user_overrides',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ScheduleType::class,
            'frequency' => ScheduleFrequency::class,
            'last_result' => ScheduleResultStatus::class,
            'without_overlapping' => 'boolean',
            'run_in_maintenance' => 'boolean',
            'is_active' => 'boolean',
            'extension_type' => ExtensionOwnerType::class,
            'timeout' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'user_overrides' => 'array',
        ];
    }

    /**
     * 모델 부팅 시 이벤트 등록
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Schedule $schedule) {
            $schedule->calculateNextRunAt();
        });

        static::updating(function (Schedule $schedule) {
            if ($schedule->isDirty(['expression', 'is_active'])) {
                $schedule->calculateNextRunAt();
            }
        });
    }

    /**
     * 다음 실행 시간을 계산합니다.
     */
    public function calculateNextRunAt(): void
    {
        if (! $this->is_active) {
            $this->next_run_at = null;

            return;
        }

        try {
            $cron = new CronExpression($this->expression);
            $this->next_run_at = $cron->getNextRunDate();
        } catch (\Exception $e) {
            $this->next_run_at = null;
        }
    }

    /**
     * 등록자 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 실행 이력 관계
     */
    public function histories(): HasMany
    {
        return $this->hasMany(ScheduleHistory::class)->orderByDesc('started_at');
    }

    /**
     * 마지막 실행 이력을 반환합니다.
     */
    public function getLastHistoryAttribute(): ?ScheduleHistory
    {
        return $this->histories()->first();
    }

    /**
     * 마지막 실행 소요 시간을 포맷팅하여 반환합니다.
     */
    public function getLastDurationAttribute(): ?string
    {
        $lastHistory = $this->lastHistory;

        if (! $lastHistory || ! $lastHistory->duration) {
            return null;
        }

        $seconds = $lastHistory->duration;

        if ($seconds < 60) {
            return $seconds.__('schedule.duration.seconds');
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return $minutes.__('schedule.duration.minutes').' '.$remainingSeconds.__('schedule.duration.seconds');
    }

    /**
     * 작업 유형 라벨을 반환합니다.
     */
    public function getTypeLabelAttribute(): string
    {
        return $this->type->label();
    }

    /**
     * 실행 주기 라벨을 반환합니다.
     */
    public function getFrequencyLabelAttribute(): string
    {
        return $this->frequency->label();
    }

    /**
     * 마지막 실행 결과 라벨을 반환합니다.
     */
    public function getLastResultLabelAttribute(): string
    {
        return $this->last_result->label();
    }

    /**
     * 활성화된 스케줄만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 비활성화된 스케줄만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * 특정 타입으로 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  ScheduleType  $type  스케줄 타입
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeByType(Builder $query, ScheduleType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * 특정 주기로 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  ScheduleFrequency  $frequency  실행 주기
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeByFrequency(Builder $query, ScheduleFrequency $frequency): Builder
    {
        return $query->where('frequency', $frequency);
    }

    /**
     * 특정 확장으로 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string|null  $identifier  확장 식별자
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeByExtension(Builder $query, ExtensionOwnerType $type, ?string $identifier = null): Builder
    {
        $query->where('extension_type', $type);

        if ($identifier !== null) {
            $query->where('extension_identifier', $identifier);
        }

        return $query;
    }

    /**
     * 마지막 실행 결과로 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  ScheduleResultStatus  $result  실행 결과
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeByLastResult(Builder $query, ScheduleResultStatus $result): Builder
    {
        return $query->where('last_result', $result);
    }

    /**
     * 실행 대기 중인 스케줄만 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->active()
            ->where('next_run_at', '<=', now());
    }
}
