<?php

namespace App\Models;

use App\Enums\QueueJobLogStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 큐 작업 처리 기록
 *
 * 워커가 처리를 마친 작업 1건(성공·실패)을 남긴다. 처리 기록 리스너(RecordQueueJobLog)만 쓰고,
 * 정리 명령(queue:prune-records)이 보관 기간이 지난 행을 지운다. 생성 시각만 있고 수정되지 않는다.
 *
 * @property int $id
 * @property string|null $uuid
 * @property string $connection
 * @property string $queue
 * @property string $job_class
 * @property string $display_name
 * @property QueueJobLogStatus $status
 * @property int $attempts
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property string|null $exception_class
 * @property string|null $exception_message
 * @property int|null $schedule_id
 * @property Carbon|null $created_at
 */
class QueueJobLog extends Model
{
    /** 예외 메시지 저장 상한(자) */
    public const EXCEPTION_MESSAGE_LIMIT = 2000;

    /** updated_at 컬럼 없음 — 기록은 수정되지 않는다 */
    public const UPDATED_AT = null;

    /**
     * 대량 할당 허용 컬럼
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'connection',
        'queue',
        'job_class',
        'display_name',
        'status',
        'attempts',
        'started_at',
        'finished_at',
        'duration_ms',
        'exception_class',
        'exception_message',
        'schedule_id',
        'created_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 캐스팅 정의
     */
    protected function casts(): array
    {
        return [
            'status' => QueueJobLogStatus::class,
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'schedule_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * 이 작업을 넣은 예약 작업
     *
     * @return BelongsTo<Schedule, $this> 예약 작업 관계
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
