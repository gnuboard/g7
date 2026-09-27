<?php

namespace App\Models\Analytics;

use App\Casts\IpBinary;
use Database\Factories\Analytics\AnalyticsBotHitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 봇 요청 모델
 *
 * 봇은 사람 방문과 섞으면 통계가 망가지므로 원장을 따로 둡니다. 어떤 봇이 언제
 * 무엇을 가져갔는지와 그때 SEO 캐시가 쓰였는지를 함께 남깁니다.
 *
 * @property int $id
 * @property string $bot_name
 * @property string $user_agent
 * @property string $url
 * @property string $url_hash
 * @property string|null $ip
 * @property string $cache_status
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class AnalyticsBotHit extends Model
{
    /** @use HasFactory<AnalyticsBotHitFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_bot_hits';

    /**
     * 타임스탬프 설정
     * created_at 만 쓴다 (요청 1건을 그대로 적고 끝난다)
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
        'bot_name',
        'user_agent',
        'url',
        'url_hash',
        'ip',
        'cache_status',
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
            'ip' => IpBinary::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
