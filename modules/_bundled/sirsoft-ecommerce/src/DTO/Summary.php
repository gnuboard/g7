<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 주문 계산 합계 정보 DTO
 *
 * 주문의 전체 금액 합계, 할인, 배송비, 세금 등의 요약 정보를 담습니다.
 */
class Summary
{
    /**
     * @param  int  $subtotal  상품 금액 소계 (할인 전)
     * @param  int  $productCouponDiscount  상품/카테고리 쿠폰 할인금액 합계
     * @param  int  $codeDiscount  할인코드 할인금액 합계
     * @param  int  $orderCouponDiscount  주문 쿠폰 할인금액 합계
     * @param  int  $totalDiscount  총 할인금액
     * @param  int  $baseShippingTotal  기본 배송비 합계
     * @param  int  $extraShippingTotal  추가 배송비(도서산간) 합계
     * @param  int  $totalShipping  총 배송비 (기본 + 추가)
     * @param  int  $shippingDiscount  배송비 할인
     * @param  int  $taxableAmount  과세 금액 합계
     * @param  int  $taxFreeAmount  면세 금액 합계
     * @param  int  $pointsEarning  적립 예정 마일리지 합계
     * @param  int  $pointsUsed  사용 마일리지 합계
     * @param  int  $paymentAmount  결제금액 (마일리지 사용 전)
     * @param  int  $finalAmount  최종 지불금액
     * @param  MultiCurrencyPrices|null  $multiCurrency  다중 통화 변환 금액
     * @param  string|null  $selectedPaymentCurrency  선택된 결제 통화
     * @param  array<string, mixed>  $metadata  플러그인 확장용 메타데이터
     *         - deposit_used: 예치금 사용 합계 (예치금 플러그인)
     *         - gift_card_used: 상품권 사용 합계 (상품권 플러그인)
     *         - grade_discount: 회원등급 할인 합계 (회원등급 플러그인)
     */
    public function __construct(
        public int $subtotal = 0,
        public int $productCouponDiscount = 0,
        public int $codeDiscount = 0,
        public int $orderCouponDiscount = 0,
        public int $totalDiscount = 0,
        public int $baseShippingTotal = 0,
        public int $extraShippingTotal = 0,
        public int $totalShipping = 0,
        public int $shippingDiscount = 0,
        public int $taxableAmount = 0,
        public int $taxFreeAmount = 0,
        public int $pointsEarning = 0,
        public int $pointsUsed = 0,
        public int $paymentAmount = 0,
        public int $finalAmount = 0,
        public ?MultiCurrencyPrices $multiCurrency = null,
        public ?string $selectedPaymentCurrency = null,
        public array $metadata = [],
    ) {}

    /**
     * 총 할인금액을 계산합니다.
     */
    public function calculateTotalDiscount(): int
    {
        return $this->productCouponDiscount + $this->codeDiscount + $this->orderCouponDiscount;
    }

    /**
     * 총 배송비를 계산합니다 (기본 + 추가).
     *
     * @return int 총 배송비
     */
    public function calculateTotalShipping(): int
    {
        return $this->baseShippingTotal + $this->extraShippingTotal;
    }

    /**
     * 결제금액을 계산합니다.
     *
     * @return int 결제금액
     */
    public function calculatePaymentAmount(): int
    {
        return max(0, $this->subtotal - $this->totalDiscount + $this->totalShipping - $this->shippingDiscount);
    }

    /**
     * 최종 지불금액을 계산합니다.
     */
    public function calculateFinalAmount(): int
    {
        return max(0, $this->paymentAmount - $this->pointsUsed);
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        $result = [
            'subtotal' => $this->subtotal,
            'subtotal_formatted' => ecommerce_format_price($this->subtotal),
            'product_coupon_discount' => $this->productCouponDiscount,
            'product_coupon_discount_formatted' => ecommerce_format_price($this->productCouponDiscount),
            'code_discount' => $this->codeDiscount,
            'code_discount_formatted' => ecommerce_format_price($this->codeDiscount),
            'order_coupon_discount' => $this->orderCouponDiscount,
            'order_coupon_discount_formatted' => ecommerce_format_price($this->orderCouponDiscount),
            'total_coupon_discount' => $this->productCouponDiscount + $this->orderCouponDiscount,
            'total_coupon_discount_formatted' => ecommerce_format_price($this->productCouponDiscount + $this->orderCouponDiscount),
            'total_discount' => $this->totalDiscount,
            'discount_formatted' => ecommerce_format_price($this->totalDiscount),
            'base_shipping_total' => $this->baseShippingTotal,
            'extra_shipping_total' => $this->extraShippingTotal,
            'total_shipping' => $this->totalShipping,
            'total_shipping_fee' => $this->totalShipping,
            'shipping_fee_formatted' => ecommerce_format_price($this->totalShipping),
            'shipping_discount' => $this->shippingDiscount,
            'shipping_discount_formatted' => ecommerce_format_price($this->shippingDiscount),
            'taxable_amount' => $this->taxableAmount,
            'tax_free_amount' => $this->taxFreeAmount,
            'points_earning' => $this->pointsEarning,
            'total_mileage' => $this->pointsEarning,
            'mileage_formatted' => number_format($this->pointsEarning).'P',
            'points_used' => $this->pointsUsed,
            'payment_amount' => $this->paymentAmount,
            'final_amount' => $this->finalAmount,
            'final_amount_formatted' => ecommerce_format_price($this->finalAmount),
            // 하위 호환 (deprecated)
            'coupon_discount' => $this->productCouponDiscount,
            'coupon_discount_formatted' => ecommerce_format_price($this->productCouponDiscount),
            'order_discount' => $this->orderCouponDiscount,
            'order_discount_formatted' => ecommerce_format_price($this->orderCouponDiscount),
        ];

        if ($this->selectedPaymentCurrency !== null) {
            $result['selected_payment_currency'] = $this->selectedPaymentCurrency;
        }

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
            subtotal: $data['subtotal'] ?? 0,
            productCouponDiscount: $data['product_coupon_discount'] ?? $data['coupon_discount'] ?? 0,
            codeDiscount: $data['code_discount'] ?? 0,
            orderCouponDiscount: $data['order_coupon_discount'] ?? $data['order_discount'] ?? 0,
            totalDiscount: $data['total_discount'] ?? 0,
            baseShippingTotal: $data['base_shipping_total'] ?? 0,
            extraShippingTotal: $data['extra_shipping_total'] ?? 0,
            totalShipping: $data['total_shipping'] ?? 0,
            shippingDiscount: $data['shipping_discount'] ?? 0,
            taxableAmount: $data['taxable_amount'] ?? 0,
            taxFreeAmount: $data['tax_free_amount'] ?? 0,
            pointsEarning: $data['points_earning'] ?? 0,
            pointsUsed: $data['points_used'] ?? 0,
            paymentAmount: $data['payment_amount'] ?? 0,
            finalAmount: $data['final_amount'] ?? 0,
            multiCurrency: isset($data['multi_currency'])
                ? MultiCurrencyPrices::fromArray($data['multi_currency'])
                : null,
            selectedPaymentCurrency: $data['selected_payment_currency'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
