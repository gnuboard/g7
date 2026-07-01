<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 이커머스 일별 판매 집계 모델
 *
 * ecommerce_stats 테이블 — 하루 1행. 대시보드 스케쥴러가 upsert 하고
 * 대시보드 API(overview/sales-graph)가 읽는다. 원본(주문/주문상품) 풀스캔을
 * 피하기 위한 집계 캐시.
 *
 * @property int $id
 * @property Carbon $date
 * @property int $sales_quantity
 * @property string $sales_amount
 * @property array<string, int>|null $option_status_counts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EcommerceStat extends Model
{
    /**
     * 테이블명
     */
    protected $table = 'ecommerce_stats';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'date',
        'sales_quantity',
        'sales_amount',
        'option_status_counts',
    ];

    /**
     * 타입 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sales_quantity' => 'integer',
            'sales_amount' => 'decimal:2',
            'option_status_counts' => 'array',
        ];
    }
}
