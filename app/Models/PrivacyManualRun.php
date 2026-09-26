<?php

namespace App\Models;

use App\Enums\PrivacyManualRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 개인정보 조치 관리자 직권 실행 기록 모델
 *
 * 「지금 실행」 한 번이 한 행이다. 큐 작업이 상태·결과를 채운다.
 */
class PrivacyManualRun extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'privacy_manual_runs';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'target',
        'status',
        'skip_waiting',
        'requested_by',
        'processed_count',
        'remaining_count',
        'mode',
        'error',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PrivacyManualRunStatus::class,
            'skip_waiting' => 'boolean',
            'requested_by' => 'integer',
            'processed_count' => 'integer',
            'remaining_count' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * 접수한 관리자 관계 (삭제된 회원이면 null)
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
