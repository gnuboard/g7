<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 적용된 배송정책 정보 DTO
 *
 * order_shippings 테이블에 1:1 매핑됩니다.
 */
class AppliedShippingPolicy
{
    /**
     * @param  int  $policyId  배송정책 ID → order_shippings.shipping_policy_id
     * @param  string  $policyName  배송정책명
     * @param  string  $countryCode  적용 국가코드 (KR, US 등)
     * @param  string  $chargePolicy  부과정책 (ChargePolicyEnum value)
     * @param  int  $shippingAmount  기본 배송비 → order_shippings.base_shipping_amount
     * @param  int  $extraShippingAmount  추가배송비(도서산간) → order_shippings.extra_shipping_amount
     * @param  int  $totalShippingAmount  총 배송비 (기본 + 추가) → order_shippings.total_shipping_amount
     * @param  int  $shippingDiscountAmount  배송비 쿠폰 할인액 안분
     * @param  array  $policySnapshot  배송정책 스냅샷 → order_shippings.delivery_policy_snapshot
     * @param  int  $standaloneShippingAmount  단독 구매 시 예상 배송비 (UI 표시용)
     * @param  bool  $hookOverridden  훅에 의해 배송비가 덮어쓰여졌는지 여부
     */
    public function __construct(
        public int $policyId = 0,
        public string $policyName = '',
        public string $countryCode = 'KR',
        public string $chargePolicy = '',
        public int $shippingAmount = 0,
        public int $extraShippingAmount = 0,
        public int $totalShippingAmount = 0,
        public int $shippingDiscountAmount = 0,
        public array $policySnapshot = [],
        public int $standaloneShippingAmount = 0,
        public bool $hookOverridden = false,
    ) {}

    /**
     * 총 배송비를 계산합니다 (기본 + 추가).
     * 필드 값이 설정되어 있으면 필드 값을, 없으면 계산합니다.
     *
     * @return int 총 배송비 (할인 전)
     */
    public function calculateTotalShipping(): int
    {
        return $this->shippingAmount + $this->extraShippingAmount;
    }

    /**
     * 실제 적용 배송비 (할인 후)를 반환합니다.
     *
     * @return int 실제 배송비 (총 배송비 - 할인)
     */
    public function getNetShippingAmount(): int
    {
        return max(0, $this->totalShippingAmount - $this->shippingDiscountAmount);
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'policy_id' => $this->policyId,
            'policy_name' => $this->policyName,
            'country_code' => $this->countryCode,
            'charge_policy' => $this->chargePolicy,
            'shipping_amount' => $this->shippingAmount,
            'shipping_amount_formatted' => ecommerce_format_price($this->shippingAmount),
            'extra_shipping_amount' => $this->extraShippingAmount,
            'extra_shipping_amount_formatted' => ecommerce_format_price($this->extraShippingAmount),
            'total_shipping_amount' => $this->totalShippingAmount,
            'total_shipping_amount_formatted' => ecommerce_format_price($this->totalShippingAmount),
            'shipping_discount_amount' => $this->shippingDiscountAmount,
            'shipping_discount_amount_formatted' => ecommerce_format_price($this->shippingDiscountAmount),
            'standalone_shipping_amount' => $this->standaloneShippingAmount,
            'standalone_shipping_amount_formatted' => ecommerce_format_price($this->standaloneShippingAmount),
            'hook_overridden' => $this->hookOverridden,
            'policy_snapshot' => $this->policySnapshot,
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
            policyId: $data['policy_id'] ?? 0,
            policyName: $data['policy_name'] ?? '',
            countryCode: $data['country_code'] ?? 'KR',
            chargePolicy: $data['charge_policy'] ?? '',
            shippingAmount: $data['shipping_amount'] ?? 0,
            extraShippingAmount: $data['extra_shipping_amount'] ?? 0,
            totalShippingAmount: $data['total_shipping_amount'] ?? 0,
            shippingDiscountAmount: $data['shipping_discount_amount'] ?? 0,
            policySnapshot: $data['policy_snapshot'] ?? [],
            standaloneShippingAmount: $data['standalone_shipping_amount'] ?? 0,
            hookOverridden: $data['hook_overridden'] ?? false,
        );
    }
}
