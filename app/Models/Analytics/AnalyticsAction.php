<?php

namespace App\Models\Analytics;

use App\Enums\Analytics\ActionType;
use Database\Factories\Analytics\AnalyticsActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 액션 원장 모델
 *
 * 방문 안에서 일어난 행동(페이지뷰·다운로드·외부링크·검색) 1건입니다.
 * visit_id 에 외래키를 두지 않고 삭제는 서비스가 액션 → 방문 순으로 명시 수행합니다.
 *
 * @property int $id
 * @property int $visit_id
 * @property string $visitor_key
 * @property ActionType $action_type
 * @property int $action_name_id
 * @property int|null $page_title_id
 * @property Carbon $occurred_at
 * @property int|null $time_spent_seconds
 * @property int|null $search_result_count
 * @property string|null $search_tab
 * @property array<string, mixed>|null $custom_dimensions
 * @property Carbon $created_at
 */
class AnalyticsAction extends Model
{
    /** @use HasFactory<AnalyticsActionFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_actions';

    /**
     * 타임스탬프 설정
     * created_at 만 쓴다 (체류 시간 확정을 빼면 값이 바뀌지 않는다)
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
        'visit_id',
        'visitor_key',
        'action_type',
        'action_name_id',
        'page_title_id',
        'occurred_at',
        'time_spent_seconds',
        'search_result_count',
        'search_tab',
        'custom_dimensions',
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
            'action_type' => ActionType::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'visit_id' => 'integer',
            'action_name_id' => 'integer',
            'page_title_id' => 'integer',
            'time_spent_seconds' => 'integer',
            'search_result_count' => 'integer',
            'custom_dimensions' => 'array',
        ];
    }

    /**
     * 이 액션이 속한 방문
     *
     * @return BelongsTo<AnalyticsVisit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(AnalyticsVisit::class, 'visit_id');
    }

    /**
     * 이 액션의 이름 사전 항목 (URL·파일명·검색어)
     *
     * @return BelongsTo<AnalyticsActionName, $this>
     */
    public function actionName(): BelongsTo
    {
        return $this->belongsTo(AnalyticsActionName::class, 'action_name_id');
    }

    /**
     * 이 액션의 페이지 제목 사전 항목
     *
     * @return BelongsTo<AnalyticsActionName, $this>
     */
    public function pageTitle(): BelongsTo
    {
        return $this->belongsTo(AnalyticsActionName::class, 'page_title_id');
    }
}
