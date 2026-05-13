<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 아이템별 계산 결과 DTO
 *
 * 개별 상품 옵션의 계산 결과를 담습니다.
 * 부분취소/환불 시 사용되며, order_options + order_shippings 테이블에 직접 매핑됩니다.
 */
class ItemCalculation
{
    /**
     * @param  int  $productId  상품 ID
     * @param  int  $productOptionId  상품 옵션 ID
     * @param  int  $quantity  수량
     * @param  int  $unitPrice  옵션 단가
     * @param  int  $subtotal  소계 (단가 × 수량)
     * @param  int  $productCouponDiscountAmount  상품/카테고리 쿠폰 할인액 → order_options.product_coupon_discount_amount
     * @param  int  $codeDiscountAmount  할인코드 할인액 → order_options.code_discount_amount
     * @param  int  $orderCouponDiscountShare  주문 쿠폰 할인 안분액 → order_options.order_coupon_discount_amount
     * @param  int  $pointsUsedShare  마일리지 사용 안분액 → order_options.subtotal_points_used_amount
     * @param  int  $pointsEarning  적립 예정 마일리지
     * @param  int  $taxableAmount  과세 금액
     * @param  int  $taxFreeAmount  면세 금액
     * @param  int  $finalAmount  최종 금액
     * @param  AppliedShippingPolicy|null  $appliedShippingPolicy  옵션별 배송 정보 → order_shippings 테이블
     * @param  AppliedPromotions|null  $appliedPromotions  옵션별 적용 프로모션 → order_options.promotions_applied_snapshot
     * @param  string|null  $productName  상품명 (조회용)
     * @param  string|null  $optionName  옵션명 (조회용)
     * @param  MultiCurrencyPrices|null  $multiCurrency  다중 통화 변환 금액
     * @param  array<string, mixed>  $metadata  플러그인 확장용 메타데이터
     *         - deposit_used_share: 예치금 사용 안분액 (예치금 플러그인)
     *         - gift_card_used_share: 상품권 사용 안분액 (상품권 플러그인)
     *         - grade_discount_amount: 회원등급 할인액 (회원등급 플러그인)
     */
    public function __construct(
        public int $productId = 0,
        public int $productOptionId = 0,
        public int $quantity = 0,
        public int $unitPrice = 0,
        public int $subtotal = 0,
        public int $productCouponDiscountAmount = 0,
        public int $codeDiscountAmount = 0,
        public int $orderCouponDiscountShare = 0,
        public int $pointsUsedShare = 0,
        public int $pointsEarning = 0,
        public int $taxableAmount = 0,
        public int $taxFreeAmount = 0,
        public int $finalAmount = 0,
        public ?AppliedShippingPolicy $appliedShippingPolicy = null,
        public ?AppliedPromotions $appliedPromotions = null,
        public ?string $productName = null,
        public ?string $optionName = null,
        public ?MultiCurrencyPrices $multiCurrency = null,
        public array $metadata = [],
    ) {}

    /**
     * 총 할인액을 반환합니다.
     */
    public function getTotalDiscount(): int
    {
        return $this->productCouponDiscountAmount + $this->codeDiscountAmount + $this->orderCouponDiscountShare;
    }

    /**
     * 할인 후 금액을 반환합니다 (마일리지 사용 전).
     */
    public function getDiscountedAmount(): int
    {
        return max(0, $this->subtotal - $this->productCouponDiscountAmount - $this->codeDiscountAmount);
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        $result = [
            'product_id' => $this->productId,
            'product_option_id' => $this->productOptionId,
            'product_name' => $this->productName,
            'option_name' => $this->optionName,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'subtotal' => $this->subtotal,
            'product_coupon_discount_amount' => $this->productCouponDiscountAmount,
            'code_discount_amount' => $this->codeDiscountAmount,
            'order_coupon_discount_share' => $this->orderCouponDiscountShare,
            'total_discount' => $this->getTotalDiscount(),
            'points_used_share' => $this->pointsUsedShare,
            'points_earning' => $this->pointsEarning,
            'taxable_amount' => $this->taxableAmount,
            'tax_free_amount' => $this->taxFreeAmount,
            'final_amount' => $this->finalAmount,
            'applied_shipping_policy' => $this->appliedShippingPolicy?->toArray(),
            'applied_promotions' => $this->appliedPromotions?->toArray(),
            // 하위 호환 (deprecated)
            'coupon_discount_amount' => $this->productCouponDiscountAmount,
            'order_discount_share' => $this->orderCouponDiscountShare,
        ];

        if ($this->multiCurrency !== null) {
            $result['multi_currency'] = $this->multiCurrency->toArray();
        }

        if (! empty($this->metadata)) {
            $result['metadata'] = $this->metadata;
        }

        return $result;
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productId: $data['product_id'] ?? 0,
            productOptionId: $data['product_option_id'] ?? 0,
            quantity: $data['quantity'] ?? 0,
            unitPrice: $data['unit_price'] ?? 0,
            subtotal: $data['subtotal'] ?? 0,
            productCouponDiscountAmount: $data['product_coupon_discount_amount'] ?? $data['coupon_discount_amount'] ?? 0,
            codeDiscountAmount: $data['code_discount_amount'] ?? 0,
            orderCouponDiscountShare: $data['order_coupon_discount_share'] ?? $data['order_discount_share'] ?? 0,
            pointsUsedShare: $data['points_used_share'] ?? 0,
            pointsEarning: $data['points_earning'] ?? 0,
            taxableAmount: $data['taxable_amount'] ?? 0,
            taxFreeAmount: $data['tax_free_amount'] ?? 0,
            finalAmount: $data['final_amount'] ?? 0,
            appliedShippingPolicy: isset($data['applied_shipping_policy'])
                ? AppliedShippingPolicy::fromArray($data['applied_shipping_policy'])
                : null,
            appliedPromotions: isset($data['applied_promotions'])
                ? AppliedPromotions::fromArray($data['applied_promotions'])
                : null,
            productName: $data['product_name'] ?? null,
            optionName: $data['option_name'] ?? null,
            multiCurrency: isset($data['multi_currency'])
                ? MultiCurrencyPrices::fromArray($data['multi_currency'])
                : null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
