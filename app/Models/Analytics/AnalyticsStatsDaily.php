<?php

namespace App\Models\Analytics;

use App\Enums\Analytics\VisitorType;
use Database\Factories\Analytics\AnalyticsStatsDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 일 단위 집계 모델
 *
 * 일 × 방문자 유형으로 1행이며 주·월·년 숫자는 모두 이 표를 더해서 만듭니다.
 * 원장은 보존기간이 지나면 지워지지만 이 표는 영구 보존이라 「전체 방문자」를
 * 몇 년이 지나도 말할 수 있습니다.
 *
 * @property int $id
 * @property Carbon $date
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
 * @property bool $is_final
 * @property Carbon $archived_at
 */
class AnalyticsStatsDaily extends Model
{
    /** @use HasFactory<AnalyticsStatsDailyFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_stats_daily';

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
        'date',
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
        'is_final',
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
            'date' => 'date',
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
            'is_final' => 'boolean',
        ];
    }
}
