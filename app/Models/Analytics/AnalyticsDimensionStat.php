<?php

namespace App\Models\Analytics;

use App\Enums\Analytics\VisitorType;
use Database\Factories\Analytics\AnalyticsDimensionStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 차원별 집계 모델
 *
 * 일 × 방문자 유형 × 차원(페이지·유입·브라우저·국가 등) × 라벨로 1행입니다.
 * 인기 페이지·유입 경로 같은 상위 목록은 전부 이 표 하나를 읽어 만듭니다.
 * 시간 단위를 두지 않는 이유는 행 수가 24배로 불어나기 때문입니다.
 *
 * @property int $id
 * @property Carbon $date
 * @property VisitorType $visitor_type
 * @property string $dimension
 * @property string $label_hash
 * @property string $label
 * @property int|null $action_name_id
 * @property int $visits
 * @property int $hits
 * @property int $entries
 * @property int $exits
 * @property int $bounces
 * @property int $total_time_seconds
 * @property array<string, mixed>|null $extra
 * @property Carbon $archived_at
 */
class AnalyticsDimensionStat extends Model
{
    /** @use HasFactory<AnalyticsDimensionStatFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_dimension_stats';

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
        'dimension',
        'label_hash',
        'label',
        'action_name_id',
        'visits',
        'hits',
        'entries',
        'exits',
        'bounces',
        'total_time_seconds',
        'extra',
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
            'action_name_id' => 'integer',
            'visits' => 'integer',
            'hits' => 'integer',
            'entries' => 'integer',
            'exits' => 'integer',
            'bounces' => 'integer',
            'total_time_seconds' => 'integer',
            'extra' => 'array',
        ];
    }

    /**
     * 이 라벨의 이름 사전 항목 (페이지 차원에서 원본 URL 을 되찾을 때 쓴다)
     *
     * @return BelongsTo<AnalyticsActionName, $this>
     */
    public function actionName(): BelongsTo
    {
        return $this->belongsTo(AnalyticsActionName::class, 'action_name_id');
    }
}
