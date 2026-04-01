<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 쿠폰 적용 상세 DTO
 */
class CouponApplication
{
    /**
     * @param  int  $couponId  쿠폰 ID
     * @param  int  $couponIssueId  쿠폰 발급 ID
     * @param  string  $name  쿠폰명
     * @param  string  $targetType  적용 대상 (product_amount, order_amount, shipping_fee)
     * @param  string|null  $targetScope  적용 범위 (all, products, categories) - product_amount 시
     * @param  string  $discountType  할인 타입 (fixed, rate)
     * @param  float  $discountValue  할인값 (정액: 금액, 정률: 퍼센트)
     * @param  int  $totalDiscount  해당 쿠폰의 총 할인금액
     * @param  string  $totalDiscountFormatted  총 할인금액 포맷된 문자열
     * @param  array|null  $appliedItems  적용 상품 [{product_option_id, discount_amount, discount_amount_formatted}]
     * @param  bool  $isExclusive  중복 불가 쿠폰 여부
     * @param  int  $minOrderAmount  최소 주문금액
     * @param  int  $maxDiscountAmount  최대 할인금액 (0이면 제한 없음)
     */
    public function __construct(
        public int $couponId = 0,
        public int $couponIssueId = 0,
        public string $name = '',
        public string $targetType = '',
        public ?string $targetScope = null,
        public string $discountType = '',
        public float $discountValue = 0,
        public int $totalDiscount = 0,
        public string $totalDiscountFormatted = '',
        public ?array $appliedItems = null,
        public bool $isExclusive = false,
        public int $minOrderAmount = 0,
        public int $maxDiscountAmount = 0,
    ) {}

    /**
     * 상품금액 할인 쿠폰인지 확인합니다.
     */
    public function isProductCoupon(): bool
    {
        return $this->targetType === 'product_amount';
    }

    /**
     * 주문금액 할인 쿠폰인지 확인합니다.
     */
    public function isOrderCoupon(): bool
    {
        return $this->targetType === 'order_amount';
    }

    /**
     * 배송비 할인 쿠폰인지 확인합니다.
     */
    public function isShippingCoupon(): bool
    {
        return $this->targetType === 'shipping_fee';
    }

    /**
     * 정액 할인인지 확인합니다.
     */
    public function isFixedDiscount(): bool
    {
        return $this->discountType === 'fixed';
    }

    /**
     * 정률 할인인지 확인합니다.
     */
    public function isRateDiscount(): bool
    {
        return $this->discountType === 'rate';
    }

    /**
     * 중복 불가 쿠폰인지 확인합니다.
     */
    public function isExclusiveCoupon(): bool
    {
        return $this->isExclusive;
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'coupon_id' => $this->couponId,
            'coupon_issue_id' => $this->couponIssueId,
            'name' => $this->name,
            'target_type' => $this->targetType,
            'target_scope' => $this->targetScope,
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue,
            'total_discount' => $this->totalDiscount,
            'total_discount_formatted' => $this->totalDiscountFormatted,
            'applied_items' => $this->appliedItems,
            'is_exclusive' => $this->isExclusive,
            'min_order_amount' => $this->minOrderAmount,
            'max_discount_amount' => $this->maxDiscountAmount,
        ];
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        return new self(
            couponId: $data['coupon_id'] ?? 0,
            couponIssueId: $data['coupon_issue_id'] ?? 0,
            name: $data['name'] ?? '',
            targetType: $data['target_type'] ?? '',
            targetScope: $data['target_scope'] ?? null,
            discountType: $data['discount_type'] ?? '',
            discountValue: $data['discount_value'] ?? 0,
            totalDiscount: $data['total_discount'] ?? 0,
            totalDiscountFormatted: $data['total_discount_formatted'] ?? '',
            appliedItems: $data['applied_items'] ?? null,
            isExclusive: $data['is_exclusive'] ?? false,
            minOrderAmount: $data['min_order_amount'] ?? 0,
            maxDiscountAmount: $data['max_discount_amount'] ?? 0,
        );
    }
}
