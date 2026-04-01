<?php

namespace App\Models;

use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 스케줄 실행 이력 모델
 *
 * 스케줄 실행 이력을 관리하는 모델입니다.
 */
class ScheduleHistory extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'schedule_histories';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'schedule_id',
        'started_at',
        'ended_at',
        'duration',
        'status',
        'exit_code',
        'memory_usage',
        'output',
        'error_output',
        'trigger_type',
        'triggered_by',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScheduleResultStatus::class,
            'trigger_type' => ScheduleTriggerType::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration' => 'integer',
            'exit_code' => 'integer',
            'memory_usage' => 'integer',
        ];
    }

    /**
     * 스케줄 관계
     *
     * @return BelongsTo
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * 수동 실행자 관계
     *
     * @return BelongsTo
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * 상태 라벨을 반환합니다.
     *
     * @return string
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    /**
     * 트리거 유형 라벨을 반환합니다.
     *
     * @return string
     */
    public function getTriggerTypeLabelAttribute(): string
    {
        return $this->trigger_type->label();
    }

    /**
     * 소요 시간을 포맷팅하여 반환합니다.
     *
     * @return string|null
     */
    public function getDurationFormattedAttribute(): ?string
    {
        if (!$this->duration) {
            return null;
        }

        $seconds = $this->duration;

        if ($seconds < 60) {
            return $seconds . __('schedule.duration.seconds');
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return $minutes . __('schedule.duration.minutes') . ' ' . $remainingSeconds . __('schedule.duration.seconds');
    }

    /**
     * 메모리 사용량을 포맷팅하여 반환합니다.
     *
     * @return string|null
     */
    public function getMemoryUsageFormattedAttribute(): ?string
    {
        if (!$this->memory_usage) {
            return null;
        }

        $bytes = $this->memory_usage;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * 성공적으로 완료되었는지 확인합니다.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status === ScheduleResultStatus::Success;
    }

    /**
     * 실패했는지 확인합니다.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === ScheduleResultStatus::Failed;
    }

    /**
     * 실행 중인지 확인합니다.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->status === ScheduleResultStatus::Running;
    }

    /**
     * 수동 실행인지 확인합니다.
     *
     * @return bool
     */
    public function isManualTrigger(): bool
    {
        return $this->trigger_type === ScheduleTriggerType::Manual;
    }

    /**
     * 성공한 이력만 필터링하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', ScheduleResultStatus::Success);
    }

    /**
     * 실패한 이력만 필터링하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ScheduleResultStatus::Failed);
    }

    /**
     * 실행 중인 이력만 필터링하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', ScheduleResultStatus::Running);
    }

    /**
     * 수동 실행 이력만 필터링하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where('trigger_type', ScheduleTriggerType::Manual);
    }

    /**
     * 예약 실행 이력만 필터링하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('trigger_type', ScheduleTriggerType::Scheduled);
    }

    /**
     * 최신순으로 정렬하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder 정렬된 쿼리 빌더
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderByDesc('started_at');
    }
}
