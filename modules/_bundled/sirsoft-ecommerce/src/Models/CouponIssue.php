<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;

/**
 * 쿠폰 발급 내역 모델
 */
class CouponIssue extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_promotion_coupon_issues';

    protected $fillable = [
        'coupon_id',
        'user_id',
        'coupon_code',
        'status',
        'issued_at',
        'expired_at',
        'used_at',
        'order_id',
        'discount_amount',
    ];

    protected $casts = [
        'status' => CouponIssueRecordStatus::class,
        'issued_at' => 'datetime',
        'expired_at' => 'datetime',
        'used_at' => 'datetime',
        'discount_amount' => 'decimal:2',
    ];

    // ==================== Relations ====================

    /**
     * 쿠폰 관계
     *
     * @return BelongsTo 쿠폰 모델과의 관계
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    /**
     * 회원 관계
     *
     * @return BelongsTo 회원 모델과의 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 주문 관계
     *
     * @return BelongsTo 주문 모델과의 관계
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    // ==================== Accessors ====================

    /**
     * 만료 여부 확인
     *
     * @return bool 만료 여부
     */
    public function isExpired(): bool
    {
        if ($this->status === CouponIssueRecordStatus::EXPIRED) {
            return true;
        }

        if ($this->expired_at && now()->gt($this->expired_at)) {
            return true;
        }

        return false;
    }

    /**
     * 사용 가능 여부 확인
     *
     * @return bool 사용 가능 여부
     */
    public function isUsable(): bool
    {
        if ($this->status !== CouponIssueRecordStatus::AVAILABLE) {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        return true;
    }

    // ==================== Scopes ====================

    /**
     * 사용 가능 상태 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', CouponIssueRecordStatus::AVAILABLE);
    }

    /**
     * 사용 완료 상태 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeUsed(Builder $query): Builder
    {
        return $query->where('status', CouponIssueRecordStatus::USED);
    }

    /**
     * 만료 상태 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', CouponIssueRecordStatus::EXPIRED);
    }

    /**
     * 취소 상태 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', CouponIssueRecordStatus::CANCELLED);
    }

    /**
     * 특정 회원의 발급 내역 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  int  $userId  회원 ID
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * 특정 쿠폰의 발급 내역 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  int  $couponId  쿠폰 ID
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeForCoupon(Builder $query, int $couponId): Builder
    {
        return $query->where('coupon_id', $couponId);
    }
}
