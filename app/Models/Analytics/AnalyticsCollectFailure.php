<?php

namespace App\Models\Analytics;

use App\Casts\IpBinary;
use App\Enums\Analytics\CollectFailureReason;
use Database\Factories\Analytics\AnalyticsCollectFailureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 적재 실패 로그 모델
 *
 * 수집은 사이트 동작을 막지 않으려고 예외를 전부 삼키고 응답을 그대로 돌려줍니다.
 * 그래서 실패가 아무 흔적도 남기지 않으면 운영자는 방문이 왜 비는지 알 길이
 * 없습니다. 이 표가 그 유일한 단서입니다.
 *
 * @property int $id
 * @property CollectFailureReason $reason
 * @property string $message
 * @property string|null $url
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $payload_excerpt
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class AnalyticsCollectFailure extends Model
{
    /** @use HasFactory<AnalyticsCollectFailureFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_collect_failures';

    /**
     * 타임스탬프 설정
     * created_at 만 쓴다 (실패 1건을 적고 끝난다)
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
        'reason',
        'message',
        'url',
        'ip',
        'user_agent',
        'payload_excerpt',
        'occurred_at',
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
            'reason' => CollectFailureReason::class,
            'ip' => IpBinary::class,
            'payload_excerpt' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
