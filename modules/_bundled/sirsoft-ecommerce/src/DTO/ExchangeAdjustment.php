<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Modules\Sirsoft\Ecommerce\Enums\AdjustmentType;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;

/**
 * 교환 변경 DTO (OrderAdjustment 구현)
 *
 * 교환은 반품·취소와 정산의 성격이 다르다. 상품이 주문에서 빠지는 것이 아니라
 * **다른 옵션으로 바뀌어 그대로 남는다.** 그래서 정산 대상은 상품 금액 전체가 아니라
 * 「왕복 배송비 + 옵션 차액」뿐이며, 승인된 시안(§6-3)의 예시가 그 계약이다 —
 * 원 상품 29,000원을 32,000원 옵션으로 교환하면 청구액은 왕복배송비 6,000 + 차액 3,000
 * = 9,000원이고, 29,000원은 환불 대상이 아니다.
 *
 * 따라서 `getExcludedItems()` 는 **빈 배열**이다. 대상 아이템을 제외 목록으로 넘기면
 * 재계산이 그 상품을 주문에서 뺀 것으로 보고 상품 금액을 통째로 환불액에 실어 버린다
 * (위 예시에서 9,000원 청구가 20,000원 환불로 뒤집힌다). 신청 대상 아이템은 근거 표시용으로
 * `$exchangeItems` 에 그대로 담아 두되, 재계산 입력으로는 쓰지 않는다.
 *
 * 옵션 차액(`$priceDifference`)은 **부호를 가진 합계**다. 신규 옵션이 비싸면 양수(추가 청구),
 * 싸면 음수(차액 환불 — D4)다. 음수를 0으로 깎으면 저가 교환의 차액 환불이 성립하지 않는다.
 *
 * 왕복 배송비는 반품과 같은 규칙으로 **청구 방식이 '환불금에서 차감'일 때만** 추가 비용에
 * 넣는다. 별도 결제 방식에서는 클레임 결제 건으로 이미 받으므로 여기서 또 빼면 이중 청구가 된다.
 */
class ExchangeAdjustment extends OrderAdjustment
{
    /**
     * @param  array  $exchangeItems  교환 대상 [{order_option_id, cancel_quantity}] — 근거 표시용(재계산 제외 대상 아님)
     * @param  int  $roundTripFee  왕복 교환 배송비 (`ClaimShippingFee::$fee`)
     * @param  int  $priceDifference  옵션 차액 합계 (신규단가 − 원단가) × 수량, 음수면 환불 대상
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식 (청구 0원이면 null)
     * @param  array  $substitutions  옵션 치환 입력 [order_option_id => {quantity, product_option_id, unit_price, option_snapshot, additional_options_snapshot}] — "교환 후" 열의 표시용 재계산에만 쓰인다
     */
    public function __construct(
        public array $exchangeItems = [],
        public int $roundTripFee = 0,
        public int $priceDifference = 0,
        public ?ClaimChargeMethodEnum $chargeMethod = null,
        public array $substitutions = [],
    ) {}

    /**
     * 변경 유형을 반환합니다.
     *
     * @return AdjustmentType 교환
     */
    public function getType(): AdjustmentType
    {
        return AdjustmentType::EXCHANGE;
    }

    /**
     * 제외 대상 아이템 목록을 반환합니다.
     *
     * 교환은 상품이 주문에 남으므로 재계산에서 빼지 않는다. 클래스 docblock 참조 —
     * 여기에 대상 아이템을 실으면 상품 금액 전체가 환불된다.
     *
     * @return array 항상 빈 배열
     */
    public function getExcludedItems(): array
    {
        return [];
    }

    /**
     * 신청 대상 아이템 목록을 반환합니다. (근거 표시용)
     *
     * @return array [{order_option_id, cancel_quantity}]
     */
    public function getClaimItems(): array
    {
        return $this->exchangeItems;
    }

    /**
     * 옵션 치환 입력을 반환합니다. (표시용 2-패스 재계산 — 환불 산식은 읽지 않는다)
     *
     * 치환 결과를 환불 산식에도 쓰면 옵션 차액이 `getAdditionalCharges()` 와 **두 번** 빠진다.
     *
     * @return array<int, array> 주문옵션 ID → 치환 입력
     */
    public function getSubstitutions(): array
    {
        return $this->substitutions;
    }

    /**
     * 추가 비용(환불액에서 차감할 왕복 배송비 + 옵션 차액)을 반환합니다.
     *
     * 옵션 차액은 부호를 유지한다 — 음수(저가 교환)면 추가 비용이 음수가 되어
     * `OrderAdjustmentService` 의 환불 산식에서 환불액으로 뒤집힌다.
     *
     * @return int|float 추가 비용 (음수면 환불 대상)
     */
    public function getAdditionalCharges(): int|float
    {
        $fee = $this->deductsFromRefund() ? $this->roundTripFee : 0;

        return $fee + $this->priceDifference;
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
     * 저가 교환(신규 옵션이 더 싸 차액 환불이 발생하는 교환)인지 반환합니다.
     *
     * @return bool 옵션 차액이 음수이면 true
     */
    public function hasRefundableDifference(): bool
    {
        return $this->priceDifference < 0;
    }

    /**
     * 산정된 클레임 배송비로 DTO를 생성합니다.
     *
     * @param  array  $items  교환 대상 [{order_option_id, cancel_quantity}]
     * @param  ClaimShippingFee  $shippingFee  `ClaimShippingFeeService::calculate()` 결과 (왕복)
     * @param  int  $priceDifference  옵션 차액 합계 (부호 유지)
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식
     * @param  array  $substitutions  옵션 치환 입력 (`OrderExchangeService::buildSubstitutionsFromPlans()`)
     * @return self 교환 변경 DTO
     */
    public static function fromShippingFee(
        array $items,
        ClaimShippingFee $shippingFee,
        int $priceDifference = 0,
        ?ClaimChargeMethodEnum $chargeMethod = null,
        array $substitutions = [],
    ): self {
        return new self(
            exchangeItems: $items,
            roundTripFee: $shippingFee->fee,
            priceDifference: $priceDifference,
            chargeMethod: $chargeMethod,
            substitutions: $substitutions,
        );
    }
}
