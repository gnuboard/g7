<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 임시 주문 모델 (주문서 작성 단계)
 */
class TempOrder extends Model
{
    protected $table = 'ecommerce_temp_orders';

    protected $fillable = [
        'cart_key',
        'user_id',
        'items',
        'calculation_input',
        'calculation_result',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'items' => 'array',
        'calculation_input' => 'array',
        'calculation_result' => 'array',
        'expires_at' => 'datetime',
    ];

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
     * 비회원 임시 주문 여부 확인
     *
     * @return bool 비회원 임시 주문 여부
     */
    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    /**
     * 만료 여부 확인
     *
     * @return bool 만료 여부
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * 최종 결제금액 반환
     *
     * @return int 최종 결제금액
     */
    public function getFinalAmount(): int
    {
        return $this->calculation_result['summary']['final_amount'] ?? 0;
    }

    /**
     * 아이템 개수 반환
     *
     * @return int 아이템 개수
     */
    public function getItemCount(): int
    {
        return count($this->items ?? []);
    }

    /**
     * 프로모션 정보 반환
     *
     * @return array 프로모션 정보 {item_coupons, order_coupon_issue_id, shipping_coupon_issue_id}
     */
    public function getPromotions(): array
    {
        return $this->calculation_input['promotions'] ?? [
            'item_coupons' => [],
            'order_coupon_issue_id' => null,
            'shipping_coupon_issue_id' => null,
        ];
    }

    /**
     * 상품별 쿠폰 배열 반환
     *
     * @return array 상품옵션ID => 쿠폰발급ID[] 배열
     */
    public function getItemCoupons(): array
    {
        return $this->getPromotions()['item_coupons'] ?? [];
    }

    /**
     * 주문 쿠폰 ID 반환
     *
     * @return int|null 주문 쿠폰 발급 ID
     */
    public function getOrderCouponIssueId(): ?int
    {
        return $this->getPromotions()['order_coupon_issue_id'] ?? null;
    }

    /**
     * 배송비 쿠폰 ID 반환
     *
     * @return int|null 배송비 쿠폰 발급 ID
     */
    public function getShippingCouponIssueId(): ?int
    {
        return $this->getPromotions()['shipping_coupon_issue_id'] ?? null;
    }

    /**
     * 사용 마일리지 반환
     *
     * @return int 사용 마일리지
     */
    public function getUsedPoints(): int
    {
        return $this->calculation_input['use_points'] ?? 0;
    }

    /**
     * 배송 주소 반환
     *
     * @return array|null 배송 주소 정보
     */
    public function getShippingAddress(): ?array
    {
        return $this->calculation_input['shipping_address'] ?? null;
    }
}
