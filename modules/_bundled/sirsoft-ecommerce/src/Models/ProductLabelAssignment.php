<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 상품-라벨 연결 모델
 */
class ProductLabelAssignment extends Model
{
    protected $table = 'ecommerce_product_label_assignments';

    protected $fillable = [
        'product_id',
        'label_id',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * 상품 관계
     *
     * @return BelongsTo 상품 모델과의 관계
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * 라벨 관계
     *
     * @return BelongsTo 라벨 모델과의 관계
     */
    public function label(): BelongsTo
    {
        return $this->belongsTo(ProductLabel::class, 'label_id');
    }

    /**
     * 현재 활성 상태인지 확인
     *
     * @return bool 활성 상태 여부
     */
    public function isActive(): bool
    {
        $today = now()->toDateString();

        // 시작일이 없거나 오늘 이전
        $startOk = $this->start_date === null || $this->start_date->toDateString() <= $today;

        // 종료일이 없거나 오늘 이후
        $endOk = $this->end_date === null || $this->end_date->toDateString() >= $today;

        return $startOk && $endOk;
    }

    /**
     * 현재 활성인 라벨만 조회하는 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query 쿼리 빌더
     * @return \Illuminate\Database\Eloquent\Builder 쿼리 빌더
     */
    public function scopeCurrentlyActive($query)
    {
        $today = now()->toDateString();

        return $query->where(function ($q) use ($today) {
            $q->whereNull('start_date')
                ->orWhere('start_date', '<=', $today);
        })->where(function ($q) use ($today) {
            $q->whereNull('end_date')
                ->orWhere('end_date', '>=', $today);
        });
    }
}
