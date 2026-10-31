<?php

namespace App\Models\Analytics;

use Database\Factories\Analytics\AnalyticsConversionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 전환 원장 모델
 *
 * 확장이 등록한 목표(주문 완료·가입 등)가 달성될 때마다 1행입니다. 확장을
 * 데이터까지 지우며 제거하면 extension_identifier 로 이 표의 행을 찾아 함께 지웁니다.
 *
 * @property int $id
 * @property int $visit_id
 * @property string $visitor_key
 * @property string $goal_key
 * @property string|null $extension_type
 * @property string|null $extension_identifier
 * @property string|null $label
 * @property string|null $revenue
 * @property string|null $currency
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $custom_dimensions
 * @property Carbon $created_at
 */
class AnalyticsConversion extends Model
{
    /** @use HasFactory<AnalyticsConversionFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_conversions';

    /**
     * 타임스탬프 설정
     * created_at 만 쓴다 (한 번 적으면 바뀌지 않는다)
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
        'goal_key',
        'extension_type',
        'extension_identifier',
        'label',
        'revenue',
        'currency',
        'occurred_at',
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
            'visit_id' => 'integer',
            'revenue' => 'decimal:2',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'custom_dimensions' => 'array',
        ];
    }

    /**
     * 이 전환이 일어난 방문
     *
     * @return BelongsTo<AnalyticsVisit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(AnalyticsVisit::class, 'visit_id');
    }
}
