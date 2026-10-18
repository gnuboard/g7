<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Helpers\TimezoneHelper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
     * 라벨 기간을 판정할 「오늘」(사이트 표준시 날짜)을 돌려줍니다.
     *
     * 기간은 시각 없는 날짜로 저장되고 모든 방문자에게 같은 라벨이 보여야 하므로, 보는 사람이 아니라 사이트
     * 표준시의 날짜로 판정한다(쿠폰 유효기간과 같은 기준). UTC 날짜로 판정하면 한국 시간 새벽 0~9시에는 오늘
     * 시작하는 라벨이 꺼져 있고 어제 끝난 라벨이 켜져 있었다(7.1.0 QE-O3).
     *
     * @return string Y-m-d (사이트 표준시)
     */
    public static function siteToday(): string
    {
        return CarbonImmutable::now(TimezoneHelper::getSiteTimezone() ?: 'UTC')->toDateString();
    }

    /**
     * 현재 활성 상태인지 확인
     *
     * @return bool 활성 상태 여부
     */
    public function isActive(): bool
    {
        $today = self::siteToday();

        // 시작일이 없거나 오늘 이전
        $startOk = $this->start_date === null || $this->start_date->toDateString() <= $today;

        // 종료일이 없거나 오늘 이후
        $endOk = $this->end_date === null || $this->end_date->toDateString() >= $today;

        return $startOk && $endOk;
    }

    /**
     * 현재 활성인 라벨만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 쿼리 빌더
     */
    public function scopeCurrentlyActive($query)
    {
        $today = self::siteToday();

        return $query->where(function ($q) use ($today) {
            $q->whereNull('start_date')
                ->orWhere('start_date', '<=', $today);
        })->where(function ($q) use ($today) {
            $q->whereNull('end_date')
                ->orWhere('end_date', '>=', $today);
        });
    }
}
