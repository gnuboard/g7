<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Modules\Sirsoft\Ecommerce\Enums\AdjustmentType;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;

/**
 * 반품 변경 DTO (OrderAdjustment 구현)
 *
 * 반품은 상품이 주문에서 빠진다. 따라서 대상 아이템을 그대로 제외 목록으로 넘겨
 * `OrderAdjustmentService` 의 재계산(안분·조건부무료 재판정·쿠폰 최소금액 복원)을
 * 취소와 동일한 경로로 통과시킨다 — 제외 아이템 키는 기존 `cancel_quantity` 를 재사용한다.
 *
 * 회수 배송비는 **청구 방식이 '환불금에서 차감'일 때만** 추가 비용으로 잡는다.
 * 신청·승인 시점 결제(`pay_on_request` / `pay_on_approval`)는 별도 클레임 결제 건
 * (`ecommerce_claim_payments`)으로 이미 받으므로, 여기서도 차감하면 같은 배송비를
 * 두 번 받는다. 이 중복은 예외도 경고도 남기지 않고 환불액만 조용히 줄인다.
 */
class ReturnAdjustment extends OrderAdjustment
{
    /**
     * @param  array  $returnItems  반품 대상 [{order_option_id, cancel_quantity}]
     * @param  int|float  $collectionFee  편도 회수 배송비 (`ClaimShippingFee::$fee` · 저장된 `claim_shipping_fee` — 소수 자릿수 통화는 소수 보존)
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식 (청구 0원이면 null)
     */
    public function __construct(
        public array $returnItems = [],
        public int|float $collectionFee = 0,
        public ?ClaimChargeMethodEnum $chargeMethod = null,
    ) {}

    /**
     * 변경 유형을 반환합니다.
     *
     * @return AdjustmentType 반품
     */
    public function getType(): AdjustmentType
    {
        return AdjustmentType::ORDER_RETURN;
    }

    /**
     * 제외 대상 아이템 목록을 반환합니다.
     *
     * @return array [{order_option_id, cancel_quantity}]
     */
    public function getExcludedItems(): array
    {
        return $this->returnItems;
    }

    /**
     * 추가 비용(환불액에서 차감할 회수 배송비)을 반환합니다.
     *
     * @return int|float 차감 대상 회수 배송비 (별도 결제 방식이면 0)
     */
    public function getAdditionalCharges(): int|float
    {
        return $this->deductsFromRefund() ? $this->collectionFee : 0;
    }

    /**
     * 청구액을 환불금에서 차감하는 방식인지 반환합니다.
     *
     * @return bool 환불금 차감 방식이면 true
     */
    public function deductsFromRefund(): bool
    {
        return $this->chargeMethod === ClaimChargeMethodEnum::DEDUCT_FROM_REFUND;
    }

    /**
     * 산정된 클레임 배송비로 DTO를 생성합니다.
     *
     * @param  array  $items  반품 대상 [{order_option_id, cancel_quantity}]
     * @param  ClaimShippingFee  $shippingFee  `ClaimShippingFeeService::calculate()` 결과
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식
     * @return self 반품 변경 DTO
     */
    public static function fromShippingFee(
        array $items,
        ClaimShippingFee $shippingFee,
        ?ClaimChargeMethodEnum $chargeMethod = null,
    ): self {
        return new self(
            returnItems: $items,
            collectionFee: $shippingFee->fee,
            chargeMethod: $chargeMethod,
        );
    }
}
