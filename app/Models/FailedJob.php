<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 실패 큐 작업 (#17)
 *
 * Laravel 기본 `failed_jobs` 테이블의 조회 전용 매핑. 행 추가는 프레임워크 failer(queue.failer)가 하고,
 * 재시도·삭제도 failer 를 거친다(JobQueueService) — 이 모델로 직접 쓰지 않는다.
 * 외부 노출 식별자는 uuid 다.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property Carbon $failed_at
 */
class FailedJob extends Model
{
    /** @var string 테이블명 */
    protected $table = 'failed_jobs';

    /** @var bool created_at/updated_at 없음 */
    public $timestamps = false;

    /**
     * 대량 할당 허용 컬럼
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'connection',
        'queue',
        'payload',
        'exception',
        'failed_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 캐스팅 정의
     */
    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }

    /**
     * 라우트 모델 바인딩 키
     *
     * @return string uuid
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
