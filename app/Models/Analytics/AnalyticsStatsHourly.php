<?php

namespace App\Models\Analytics;

use App\Enums\Analytics\VisitorType;
use Database\Factories\Analytics\AnalyticsStatsHourlyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 시간 단위 집계 모델
 *
 * 시간 × 방문자 유형으로 1행입니다. hour_start 는 UTC 가 아니라 사이트 표준시의
 * 시각을 그대로 담습니다 — 시간대별 이용 패턴을 시(hour)로 묶을 때 사이트 기준과
 * 어긋나지 않게 하기 위함입니다.
 *
 * @property int $id
 * @property Carbon $hour_start
 * @property VisitorType $visitor_type
 * @property int $visits
 * @property int $visitors
 * @property int $pageviews
 * @property int $actions
 * @property int $bounces
 * @property int $server_only_visits
 * @property int|null $new_visitors
 * @property int|null $returning_visitors
 * @property int $total_time_seconds
 * @property Carbon $archived_at
 */
class AnalyticsStatsHourly extends Model
{
    /** @use HasFactory<AnalyticsStatsHourlyFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_stats_hourly';

    /**
     * 타임스탬프 설정
     * 집계 시각은 archived_at 이 담는다
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
        'hour_start',
        'visitor_type',
        'visits',
        'visitors',
        'pageviews',
        'actions',
        'bounces',
        'server_only_visits',
        'new_visitors',
        'returning_visitors',
        'total_time_seconds',
        'archived_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 캐스팅 정의
     */
    protected function casts(): array
    {
        return [
            'visitor_type' => VisitorType::class,
            'hour_start' => 'datetime',
            'archived_at' => 'datetime',
            'visits' => 'integer',
            'visitors' => 'integer',
            'pageviews' => 'integer',
            'actions' => 'integer',
            'bounces' => 'integer',
            'server_only_visits' => 'integer',
            'new_visitors' => 'integer',
            'returning_visitors' => 'integer',
            'total_time_seconds' => 'integer',
        ];
    }
}
