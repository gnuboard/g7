<?php

namespace App\Models;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleHistoryMode;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Concerns\HasUserOverrides;
use App\Support\LocalizedValue;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 스케줄 모델.
 *
 * 스케줄(크론) 작업 한 건을 나타냅니다. 7.2.0 부터 모든 예약은 이 테이블의 행을 거쳐 실행된다.
 *
 * 소유 구분(`extension_type`):
 * - core   — `config/core.php` 의 `schedules` 카탈로그를 `CoreScheduleSeeder` 가 동기화한 행
 * - module/plugin — 확장 `getSchedules()` 선언을 `ExtensionScheduleSyncHelper` 가 동기화한 행
 * - NULL   — 운영자가 관리 화면에서 직접 등록한 행
 *
 * 소유 행은 (extension_type, extension_identifier, name) 이 유일하며(`uq_schedules_owner_name`),
 * 운영자가 바꾼 trackable 필드는 `HasUserOverrides` 가 `user_overrides` 에 기록해 재동기화 때 보존한다.
 * `command`·`type`·`name`·`enabled_condition`·`timezone` 은 소유 행에서 운영자가 바꿀 수 없다(FormRequest `prohibited`).
 *
 * @since 7.0.0-beta.2 (HasUserOverrides 사전 설계)
 * @since 7.2.0 DB 스케줄 등록부 — 실행 방식·이력 방식·실행 조건·다국어 명칭·잠금 분 단위
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
        'frequency',
        'timeout',
        'is_active',
        'without_overlapping',
        'on_one_server',
        'dispatch_mode',
        'queue_connection',
        'queue_name',
        'history_mode',
        'notify_on_failure',
        'run_in_maintenance',
        'label',
        'description',
    ];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위 user_overrides 보존 (Menu.php 선례).
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['label', 'description'];

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'name' => ['label_key' => 'activity_log.fields.name', 'type' => 'text'],
        'description' => ['label_key' => 'activity_log.fields.description', 'type' => 'text'],
        'command' => ['label_key' => 'activity_log.fields.command', 'type' => 'text'],
        'expression' => ['label_key' => 'activity_log.fields.expression', 'type' => 'text'],
        'without_overlapping' => ['label_key' => 'activity_log.fields.without_overlapping', 'type' => 'number'],
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
        'label',
        'description',
        'type',
        'command',
        'command_class',
        'expression',
        'timezone',
        'frequency',
        'without_overlapping',
        'on_one_server',
        'dispatch_mode',
        'queue_connection',
        'queue_name',
        'history_mode',
        'notify_on_failure',
        'enabled_condition',
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
            'dispatch_mode' => ScheduleDispatchMode::class,
            'history_mode' => ScheduleHistoryMode::class,
            'label' => 'array',
            'description' => 'array',
            'enabled_condition' => 'array',
            'without_overlapping' => 'integer',
            'on_one_server' => 'boolean',
            'notify_on_failure' => 'boolean',
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
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Schedule $schedule) {
            $schedule->calculateNextRunAt();
        });

        static::updating(function (Schedule $schedule) {
            if ($schedule->isDirty(['expression', 'is_active', 'timezone'])) {
                $schedule->calculateNextRunAt();
            }
        });
    }

    /**
     * 다음 실행 시간을 계산합니다.
     *
     * @return void
     */
    public function calculateNextRunAt(): void
    {
        if (! $this->is_active) {
            $this->next_run_at = null;

            return;
        }

        try {
            // cron 식은 행의 시간대(없으면 사이트 설정 시간대 app.schedule_timezone)로 해석한다 —
            // 등록기와 같은 기준이어야 같은 화면에서 "새벽 4시" 의 근거가 어긋나지 않는다.
            // 계산 결과는 저장 타임존(app.timezone = UTC)으로 되돌려 저장한다.
            $storeTimezone = (string) config('app.timezone', 'UTC');
            $scheduleTimezone = $this->timezone ?: (string) config('app.schedule_timezone', $storeTimezone);

            $cron = new CronExpression($this->expression);
            $nextRun = $cron->getNextRunDate('now', 0, false, $scheduleTimezone);

            $this->next_run_at = Carbon::instance($nextRun)->setTimezone($storeTimezone);
        } catch (\Exception $e) {
            $this->next_run_at = null;
        }
    }

    /**
     * 로케일에 맞는 표시 명칭을 반환합니다.
     *
     * 우선순위: label[로케일] → label[폴백 로케일] → name.
     *
     * @param  string|null  $locale  로케일 (null 이면 현재 로케일)
     * @return string 표시 명칭
     */
    public function localizedLabel(?string $locale = null): string
    {
        return $this->resolveLocalized($this->label, $locale) ?? (string) $this->name;
    }

    /**
     * 로케일에 맞는 설명을 반환합니다.
     *
     * 우선순위: description[로케일] → description[폴백 로케일] → null.
     *
     * @param  string|null  $locale  로케일 (null 이면 현재 로케일)
     * @return string|null 설명, 없으면 null
     */
    public function localizedDescription(?string $locale = null): ?string
    {
        return $this->resolveLocalized($this->description, $locale);
    }

    /**
     * 코어·모듈·플러그인이 소유한 행인지 확인합니다.
     *
     * @return bool 소유 행이면 true, 운영자 직접 등록 행이면 false
     */
    public function isOwned(): bool
    {
        return $this->extension_type instanceof ExtensionOwnerType;
    }

    /**
     * 소유 확장이 활성 상태인지 확인합니다.
     *
     * 코어와 직접 등록 행은 항상 true. 모듈·플러그인 행은 활성 확장 목록(상태 캐시)에 있을 때만 true.
     *
     * @return bool 실행 가능한 소유 상태 여부
     */
    public function ownerIsActive(): bool
    {
        return match ($this->extension_type) {
            ExtensionOwnerType::Module => in_array(
                (string) $this->extension_identifier,
                ModuleManager::getActiveModuleIdentifiers(),
                true,
            ),
            ExtensionOwnerType::Plugin => in_array(
                (string) $this->extension_identifier,
                PluginManager::getActivePluginIdentifiers(),
                true,
            ),
            default => true,
        };
    }

    /**
     * 다국어 배열에서 로케일 값을 꺼냅니다.
     *
     * @param  mixed  $values  {로케일: 문자열} 배열
     * @param  string|null  $locale  로케일 (null 이면 현재 로케일)
     * @return string|null 해석된 값, 없으면 null
     */
    private function resolveLocalized(mixed $values, ?string $locale): ?string
    {
        if (! is_array($values) || $values === []) {
            return null;
        }

        // 다국어 값 해석은 저장소 단일 기준(받는 로케일 → 사이트 기본 → en)으로 — 어느 로케일에도 없으면 null
        return LocalizedValue::pick($values, $locale ?? app()->getLocale(), false);
    }

    /**
     * 등록자 관계
     *
     * @return BelongsTo 등록자(User) 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 실행 이력 관계
     *
     * @return HasMany 실행 이력 관계 (최신순)
     */
    public function histories(): HasMany
    {
        return $this->hasMany(ScheduleHistory::class)->orderByDesc('started_at');
    }

    /**
     * 마지막 실행 이력을 반환합니다.
     *
     * @return ScheduleHistory|null 마지막 실행 이력
     */
    public function getLastHistoryAttribute(): ?ScheduleHistory
    {
        return $this->histories()->first();
    }

    /**
     * 마지막 실행 소요 시간을 포맷팅하여 반환합니다.
     *
     * @return string|null 포맷된 소요 시간
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
     *
     * @return string 작업 유형 라벨
     */
    public function getTypeLabelAttribute(): string
    {
        return $this->type->label();
    }

    /**
     * 실행 주기 라벨을 반환합니다.
     *
     * @return string 실행 주기 라벨
     */
    public function getFrequencyLabelAttribute(): string
    {
        return $this->frequency->label();
    }

    /**
     * 마지막 실행 결과 라벨을 반환합니다.
     *
     * @return string 마지막 실행 결과 라벨
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
     * 소유자로 필터링하는 스코프.
     *
     * 소유 타입이 null 이면 운영자 직접 등록 행(extension_type IS NULL)만 고른다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  ExtensionOwnerType|null  $type  소유 타입 (null = 직접 등록)
     * @param  string|null  $identifier  소유 확장 식별자 (null 이면 타입 전체)
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeOwnedBy(Builder $query, ?ExtensionOwnerType $type, ?string $identifier = null): Builder
    {
        if ($type === null) {
            return $query->whereNull('extension_type');
        }

        $query->where('extension_type', $type->value);

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
