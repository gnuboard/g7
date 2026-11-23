<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueSource;

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
        'issue_source',
        'auto_issue_key',
        'source_order_id',
        'status',
        'issued_at',
        'expired_at',
        'used_at',
        'expiry_notified_at',
        'order_id',
        'discount_amount',
    ];

    protected $casts = [
        'status' => CouponIssueRecordStatus::class,
        'issued_at' => 'datetime',
        'expired_at' => 'datetime',
        'used_at' => 'datetime',
        'expiry_notified_at' => 'datetime',
        'source_order_id' => 'integer',
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
     * 첫구매 쿠폰을 발급하게 한 주문 관계 (#952)
     *
     * 사용 주문(`order()`)과 다르다 — 이 주문이 전부 취소·반품완료되면 미사용 첫구매 쿠폰을 회수한다.
     *
     * @return BelongsTo 주문 모델과의 관계
     */
    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    /**
     * 발급 경로를 반환합니다. 경로 컬럼이 비어 있는 옛 발급 건은 코드 접두로 추정합니다. (#952)
     *
     * @return CouponIssueSource|null 발급 경로 (추정할 수 없으면 null)
     */
    public function resolveIssueSource(): ?CouponIssueSource
    {
        return CouponIssueSource::tryFrom((string) $this->issue_source)
            ?? CouponIssueSource::fromLegacyCode($this->coupon_code);
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
