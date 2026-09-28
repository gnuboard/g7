<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\DTO;

use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\DTO\ExchangeAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ReturnAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\AdjustmentType;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 교환 변경 DTO 테스트 (S2c)
 *
 * 교환은 상품이 주문에서 빠지지 않고 다른 옵션으로 바뀌어 남는다. 그래서 정산 대상은
 * 「왕복 배송비 + 옵션 차액」뿐이며, 상품 금액 자체는 환불 대상이 아니다.
 *
 * 검증 축:
 * - 제외 아이템이 항상 비어 있는지 (실리면 상품 금액이 통째로 환불된다 — 회귀 축)
 * - 옵션 차액의 부호 보존 (저가 교환의 차액 환불 — D4)
 * - 청구 방식별 왕복배송비 차감 여부
 * - 승인 시안(§6-3)의 9,000원 청구 예시가 그대로 재현되는지
 * - 같은 아이템을 반품으로 냈을 때와의 대비 (교환은 상품 금액을 돌려주지 않는다)
 */
class ExchangeAdjustmentTest extends ModuleTestCase
{
    use BuildsClaimAdjustmentOrders;

    protected OrderAdjustmentService $adjustmentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->adjustmentService = app(OrderAdjustmentService::class);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    // ================================================================
    // DTO 계약
    // ================================================================

    public function test_type_is_exchange(): void
    {
        $this->assertSame(AdjustmentType::EXCHANGE, (new ExchangeAdjustment)->getType());
    }

    /**
     * 교환은 재계산에서 상품을 빼지 않는다.
     *
     * 이 단언이 깨지면 교환이 상품 금액을 통째로 환불하게 된다 — 승인 시안의
     * 「9,000원 청구」가 「20,000원 환불」로 뒤집히는 형태이며 오류는 남지 않는다.
     */
    public function test_excluded_items_are_always_empty(): void
    {
        $adjustment = new ExchangeAdjustment(exchangeItems: [
            ['order_option_id' => 11, 'cancel_quantity' => 2],
        ]);

        $this->assertSame([], $adjustment->getExcludedItems());
    }

    public function test_claim_items_are_kept_for_reference(): void
    {
        $items = [['order_option_id' => 11, 'cancel_quantity' => 2]];

        $adjustment = new ExchangeAdjustment(exchangeItems: $items);

        $this->assertSame($items, $adjustment->getClaimItems());
    }

    /**
     * 추가 비용 = (환불금 차감 방식일 때만 왕복배송비) + 부호를 가진 옵션 차액.
     */
    #[DataProvider('additionalChargesProvider')]
    public function test_additional_charges(
        int $roundTripFee,
        int $priceDifference,
        ?ClaimChargeMethodEnum $method,
        int $expected,
    ): void {
        $adjustment = new ExchangeAdjustment(
            roundTripFee: $roundTripFee,
            priceDifference: $priceDifference,
            chargeMethod: $method,
        );

        $this->assertSame($expected, $adjustment->getAdditionalCharges());
    }

    public static function additionalChargesProvider(): array
    {
        return [
            '고가 교환 · 신청 시점 결제 (배송비는 별도 결제)' => [
                6000, 3000, ClaimChargeMethodEnum::PAY_ON_REQUEST, 3000,
            ],
            '고가 교환 · 승인 시점 결제' => [
                6000, 3000, ClaimChargeMethodEnum::PAY_ON_APPROVAL, 3000,
            ],
            '고가 교환 · 환불금 차감' => [
                6000, 3000, ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, 9000,
            ],
            '동가 교환 · 신청 시점 결제' => [
                6000, 0, ClaimChargeMethodEnum::PAY_ON_REQUEST, 0,
            ],
            '저가 교환 · 신청 시점 결제 (차액만 환불)' => [
                6000, -10000, ClaimChargeMethodEnum::PAY_ON_REQUEST, -10000,
            ],
            '저가 교환 · 환불금 차감 (차액에서 배송비 상계)' => [
                6000, -10000, ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, -4000,
            ],
            '판매자 귀책 저가 교환 (배송비 0)' => [
                0, -10000, ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, -10000,
            ],
            '청구 방식 미지정' => [
                6000, 0, null, 0,
            ],
        ];
    }

    #[DataProvider('refundableDifferenceProvider')]
    public function test_has_refundable_difference(int $priceDifference, bool $expected): void
    {
        $this->assertSame(
            $expected,
            (new ExchangeAdjustment(priceDifference: $priceDifference))->hasRefundableDifference()
        );
    }

    public static function refundableDifferenceProvider(): array
    {
        return [
            '고가 교환' => [3000, false],
            '동가 교환' => [0, false],
            '저가 교환' => [-1, true],
        ];
    }

    #[DataProvider('deductsFromRefundProvider')]
    public function test_deducts_from_refund_flag(?ClaimChargeMethodEnum $method, bool $expected): void
    {
        $this->assertSame($expected, (new ExchangeAdjustment(chargeMethod: $method))->deductsFromRefund());
    }

    public static function deductsFromRefundProvider(): array
    {
        return [
            '미지정' => [null, false],
            '신청 시점 결제' => [ClaimChargeMethodEnum::PAY_ON_REQUEST, false],
            '승인 시점 결제' => [ClaimChargeMethodEnum::PAY_ON_APPROVAL, false],
            '환불금 차감' => [ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, true],
        ];
    }

    public function test_from_shipping_fee_carries_fee_and_difference(): void
    {
        $fee = new ClaimShippingFee(fee: 6000, claimType: ClaimTypeEnum::EXCHANGE, charged: true);

        $adjustment = ExchangeAdjustment::fromShippingFee(
            [['order_option_id' => 7, 'cancel_quantity' => 1]],
            $fee,
            3000,
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        );

        $this->assertSame(6000, $adjustment->roundTripFee);
        $this->assertSame(3000, $adjustment->priceDifference);
        $this->assertSame(9000, $adjustment->getAdditionalCharges());
        $this->assertSame([], $adjustment->getExcludedItems());
    }

    public function test_from_shipping_fee_keeps_zero_fee_for_seller_fault(): void
    {
        $fee = ClaimShippingFee::notCharged(
            claimType: ClaimTypeEnum::EXCHANGE,
            fault: ClaimReasonFaultTypeEnum::SELLER,
            reason: ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER,
        );

        $adjustment = ExchangeAdjustment::fromShippingFee(
            [['order_option_id' => 7, 'cancel_quantity' => 1]],
            $fee,
            0,
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        );

        $this->assertSame(0, $adjustment->getAdditionalCharges());
    }

    // ================================================================
    // 재계산 통합
    // ================================================================

    /**
     * 승인 시안(§6-3)의 예시 — 원 상품 29,000원을 32,000원 옵션으로 교환하면
     * 왕복배송비 6,000 + 차액 3,000 = 9,000원 청구이고, 환불은 0원이다.
     */
    public function test_upgrade_exchange_produces_no_refund(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 29000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(
                exchangeItems: $this->claimItemsForAll($order),
                roundTripFee: 6000,
                priceDifference: 3000,
                chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            ),
        );

        $this->assertSame(0.0, $result->getTotalRefundAmount());
    }

    /**
     * 교환은 상품 금액을 돌려주지 않는다 — 같은 아이템을 반품으로 낸 결과와 대비한다.
     *
     * 대조군이 없으면 "환불 0원" 이 계산이 맞아서인지 아무 일도 일어나지 않아서인지
     * 구분되지 않는다.
     */
    public function test_exchange_does_not_refund_the_item_price_unlike_return(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 29000);
        $items = $this->claimItemsForAll($order);

        $exchange = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(exchangeItems: $items, roundTripFee: 6000, priceDifference: 3000),
        );

        $return = $this->adjustmentService->calculate(
            $order->fresh(['options', 'shippings', 'payment']),
            new ReturnAdjustment(returnItems: $items),
        );

        $this->assertSame(0.0, $exchange->getTotalRefundAmount());
        $this->assertSame(29000.0, $return->getTotalRefundAmount());
    }

    public function test_equal_price_exchange_produces_no_refund(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 29000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(
                exchangeItems: $this->claimItemsForAll($order),
                roundTripFee: 6000,
                priceDifference: 0,
                chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            ),
        );

        $this->assertSame(0.0, $result->getTotalRefundAmount());
    }

    /**
     * 저가 교환 — 배송비를 별도 결제하면 차액 전액이 환불된다 (D4).
     */
    public function test_downgrade_exchange_refunds_the_price_difference(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 29000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(
                exchangeItems: $this->claimItemsForAll($order),
                roundTripFee: 6000,
                priceDifference: -4000,
                chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            ),
        );

        $this->assertSame(4000.0, $result->getTotalRefundAmount());
    }

    /**
     * 저가 교환 + 환불금 차감 — 차액에서 왕복배송비를 상계한 잔액만 환불된다.
     */
    public function test_downgrade_exchange_offsets_the_round_trip_fee_in_deduct_mode(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 29000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(
                exchangeItems: $this->claimItemsForAll($order),
                roundTripFee: 6000,
                priceDifference: -10000,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
        );

        $this->assertSame(4000.0, $result->getTotalRefundAmount());
    }

    public function test_downgrade_exchange_with_fee_exceeding_difference_is_clamped_to_zero(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 29000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(
                exchangeItems: $this->claimItemsForAll($order),
                roundTripFee: 6000,
                priceDifference: -2000,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
        );

        $this->assertSame(0.0, $result->getTotalRefundAmount());
        $this->assertGreaterThanOrEqual(0.0, $result->refundAmount);
    }

    /**
     * 판매자 귀책 저가 교환 — 배송비가 0원이라 차액 전액이 환불된다.
     */
    public function test_seller_fault_downgrade_exchange_refunds_full_difference(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 29000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(
                exchangeItems: $this->claimItemsForAll($order),
                roundTripFee: 0,
                priceDifference: -7000,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
        );

        $this->assertSame(7000.0, $result->getTotalRefundAmount());
    }

    /**
     * 저가 교환 차액이 결제 잔여 한도를 넘지 않는다 — 환불 상한은 원 결제금액이다.
     */
    public function test_downgrade_refund_is_capped_by_the_paid_amount(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 5000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(
                exchangeItems: $this->claimItemsForAll($order),
                priceDifference: -50000,
                chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            ),
        );

        $this->assertLessThanOrEqual(5000.0, $result->getTotalRefundAmount());
    }

    /**
     * 마일리지 혼합 결제의 저가 교환 차액 환불도 우선순위 배분을 따른다.
     */
    public function test_downgrade_refund_splits_by_points_first_priority(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 20000,
            orderOverrides: ['total_paid_amount' => 15000, 'total_points_used_amount' => 5000],
            paymentOverrides: ['paid_amount_local' => 15000, 'paid_amount_base' => 15000],
        );

        $result = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(
                exchangeItems: $this->claimItemsForAll($order),
                priceDifference: -8000,
                chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            ),
            RefundPriorityEnum::POINTS_FIRST,
        );

        $this->assertSame(5000.0, $result->refundPointsAmount);
        $this->assertSame(3000.0, $result->refundAmount);
    }

    /**
     * 교환은 재계산에서 상품을 빼지 않으므로 조건부 무료배송이 소멸하지 않는다.
     *
     * 상품이 주문에 남는다는 사실이 금액에 실제로 반영되는지 보는 축이다 —
     * 제외 아이템이 잘못 실리면 여기서 배송비가 되살아난다.
     */
    public function test_exchange_does_not_break_conditional_free_shipping(): void
    {
        $policy = $this->createClaimShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );

        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 2, policy: $policy);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate(
            $order,
            new ExchangeAdjustment(exchangeItems: [
                ['order_option_id' => $option->id, 'cancel_quantity' => 1],
            ]),
        );

        // 배송비 재판정이 일어나지 않으므로 차이가 0 이고 환불도 0 이다.
        $this->assertSame(0.0, $result->getTotalRefundAmount());
        $this->assertSame(0.0, $result->shippingDifference);
    }
}
