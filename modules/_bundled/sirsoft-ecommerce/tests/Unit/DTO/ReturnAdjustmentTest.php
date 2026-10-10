<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\DTO;

use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
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
 * 반품 변경 DTO 테스트 (S2c)
 *
 * 검증 축:
 * - 변경 유형과 제외 아이템 (반품은 상품이 주문에서 빠진다)
 * - 청구 방식별 추가 비용 (환불금 차감 방식에서만 회수배송비를 뺀다)
 * - `OrderAdjustmentService` 재계산 통합 — 전량 반품 · 부분 반품 · 조건부무료 소멸
 * - 회수배송비가 환불 대상을 넘어설 때 음수 방지
 * - 마일리지 혼합 결제의 우선순위 배분
 */
class ReturnAdjustmentTest extends ModuleTestCase
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

    public function test_type_is_order_return(): void
    {
        $this->assertSame(AdjustmentType::ORDER_RETURN, (new ReturnAdjustment)->getType());
    }

    public function test_excluded_items_are_the_return_items(): void
    {
        $items = [
            ['order_option_id' => 11, 'cancel_quantity' => 2],
            ['order_option_id' => 12, 'cancel_quantity' => 1],
        ];

        $adjustment = new ReturnAdjustment(returnItems: $items);

        $this->assertSame($items, $adjustment->getExcludedItems());
    }

    public function test_excluded_items_are_empty_when_no_items_given(): void
    {
        $this->assertSame([], (new ReturnAdjustment)->getExcludedItems());
    }

    /**
     * 청구 방식별 추가 비용 — 환불금 차감 방식에서만 회수배송비를 뺀다.
     *
     * 신청·승인 시점 결제는 별도 클레임 결제 건으로 이미 받으므로, 여기서 또 빼면
     * 같은 배송비를 두 번 받는다.
     */
    #[DataProvider('chargeMethodProvider')]
    public function test_additional_charges_depend_on_charge_method(
        ?ClaimChargeMethodEnum $method,
        int $expected,
    ): void {
        $adjustment = new ReturnAdjustment(collectionFee: 3000, chargeMethod: $method);

        $this->assertSame($expected, $adjustment->getAdditionalCharges());
    }

    public static function chargeMethodProvider(): array
    {
        return [
            '청구 방식 미지정(청구 0원)' => [null, 0],
            '신청 시점 결제' => [ClaimChargeMethodEnum::PAY_ON_REQUEST, 0],
            '승인 시점 결제' => [ClaimChargeMethodEnum::PAY_ON_APPROVAL, 0],
            '환불금에서 차감' => [ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, 3000],
        ];
    }

    #[DataProvider('deductsFromRefundProvider')]
    public function test_deducts_from_refund_flag(?ClaimChargeMethodEnum $method, bool $expected): void
    {
        $this->assertSame($expected, (new ReturnAdjustment(chargeMethod: $method))->deductsFromRefund());
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

    public function test_zero_fee_yields_zero_charges_even_in_deduct_mode(): void
    {
        $adjustment = new ReturnAdjustment(
            collectionFee: 0,
            chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        );

        $this->assertSame(0, $adjustment->getAdditionalCharges());
    }

    public function test_from_shipping_fee_carries_the_calculated_total(): void
    {
        $fee = new ClaimShippingFee(fee: 3000, charged: true);

        $adjustment = ReturnAdjustment::fromShippingFee(
            [['order_option_id' => 7, 'cancel_quantity' => 1]],
            $fee,
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        );

        $this->assertSame(3000, $adjustment->collectionFee);
        $this->assertSame(3000, $adjustment->getAdditionalCharges());
        $this->assertSame([['order_option_id' => 7, 'cancel_quantity' => 1]], $adjustment->getExcludedItems());
    }

    public function test_from_shipping_fee_keeps_zero_when_not_charged(): void
    {
        $fee = ClaimShippingFee::notCharged(
            claimType: ClaimTypeEnum::RETURN,
            fault: ClaimReasonFaultTypeEnum::SELLER,
            reason: ClaimShippingFee::REASON_FAULT_NOT_CUSTOMER,
        );

        $adjustment = ReturnAdjustment::fromShippingFee(
            [['order_option_id' => 7, 'cancel_quantity' => 1]],
            $fee,
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        );

        $this->assertSame(0, $adjustment->getAdditionalCharges());
    }

    // ================================================================
    // 재계산 통합
    // ================================================================

    public function test_full_return_refunds_the_whole_paid_amount(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 39000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(returnItems: $this->claimItemsForAll($order)),
        );

        $this->assertSame(39000.0, $result->getTotalRefundAmount());
    }

    /**
     * 전량 반품 + 환불금 차감 — 이 경로는 `buildFullCancelResult` 를 타므로
     * 추가 비용이 여기서도 빠지는지가 회귀 축이다 (빠지지 않으면 회수배송비가 사라진다).
     */
    public function test_full_return_deducts_the_collection_fee_in_deduct_mode(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 39000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(
                returnItems: $this->claimItemsForAll($order),
                collectionFee: 3000,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
        );

        $this->assertSame(36000.0, $result->getTotalRefundAmount());
    }

    public function test_full_return_does_not_deduct_a_separately_paid_fee(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 39000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(
                returnItems: $this->claimItemsForAll($order),
                collectionFee: 3000,
                chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            ),
        );

        $this->assertSame(39000.0, $result->getTotalRefundAmount());
    }

    public function test_partial_return_refunds_only_the_returned_quantity(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 10000, quantity: 3);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(returnItems: [
                ['order_option_id' => $option->id, 'cancel_quantity' => 1],
            ]),
        );

        $this->assertSame(10000.0, $result->getTotalRefundAmount());
    }

    public function test_partial_return_deducts_the_collection_fee_in_deduct_mode(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 10000, quantity: 3);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(
                returnItems: [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
                collectionFee: 2500,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
        );

        $this->assertSame(7500.0, $result->getTotalRefundAmount());
    }

    /**
     * 조건부 무료배송이 부분 반품으로 소멸하면 되살아난 배송비만큼 환불액이 줄어든다.
     *
     * 이 재판정은 `OrderAdjustmentService` 재계산이 담당하며, 반품 DTO 는 제외 아이템을
     * 넘기는 것만으로 그 경로를 그대로 통과한다.
     */
    public function test_partial_return_that_breaks_free_shipping_threshold_reduces_refund(): void
    {
        $policy = $this->createClaimShippingPolicy(
            chargePolicy: ChargePolicyEnum::CONDITIONAL_FREE,
            baseFee: 3000,
            freeThreshold: 50000,
        );

        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 2, policy: $policy);
        $option = $order->options->first();

        // 60,000원 주문(무료배송) → 1개 반품 시 잔여 30,000원이라 배송비 3,000원이 되살아난다.
        $this->assertSame(0.0, (float) $order->total_shipping_amount);

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(returnItems: [
                ['order_option_id' => $option->id, 'cancel_quantity' => 1],
            ]),
        );

        $this->assertSame(27000.0, $result->getTotalRefundAmount());
    }

    public function test_collection_fee_larger_than_refundable_is_clamped_to_zero(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 2000);

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(
                returnItems: $this->claimItemsForAll($order),
                collectionFee: 5000,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
        );

        $this->assertSame(0.0, $result->getTotalRefundAmount());
        $this->assertGreaterThanOrEqual(0.0, $result->refundAmount);
        $this->assertGreaterThanOrEqual(0.0, $result->refundPointsAmount);
    }

    public function test_partial_return_collection_fee_larger_than_refundable_is_clamped_to_zero(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 2000, quantity: 3);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(
                returnItems: [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
                collectionFee: 9000,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
        );

        $this->assertSame(0.0, $result->getTotalRefundAmount());
    }

    /**
     * 마일리지 혼합 결제의 전량 반품 — 회수배송비 차감 후에도 우선순위 배분이 유지된다.
     */
    public function test_full_return_with_mileage_splits_by_pg_first_priority(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 20000,
            orderOverrides: ['total_paid_amount' => 15000, 'total_points_used_amount' => 5000],
            paymentOverrides: ['paid_amount_local' => 15000, 'paid_amount_base' => 15000],
        );

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(
                returnItems: $this->claimItemsForAll($order),
                collectionFee: 3000,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
            RefundPriorityEnum::PG_FIRST,
        );

        // 20,000 − 3,000 = 17,000 → PG 15,000 우선 소진 후 마일리지 2,000
        $this->assertSame(15000.0, $result->refundAmount);
        $this->assertSame(2000.0, $result->refundPointsAmount);
        $this->assertSame(17000.0, $result->getTotalRefundAmount());
    }

    public function test_full_return_with_mileage_splits_by_points_first_priority(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 20000,
            orderOverrides: ['total_paid_amount' => 15000, 'total_points_used_amount' => 5000],
            paymentOverrides: ['paid_amount_local' => 15000, 'paid_amount_base' => 15000],
        );

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(
                returnItems: $this->claimItemsForAll($order),
                collectionFee: 3000,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
            RefundPriorityEnum::POINTS_FIRST,
        );

        // 17,000 → 마일리지 5,000 우선 소진 후 PG 12,000
        $this->assertSame(5000.0, $result->refundPointsAmount);
        $this->assertSame(12000.0, $result->refundAmount);
        $this->assertSame(17000.0, $result->getTotalRefundAmount());
    }

    /**
     * 다품목 주문에서 일부 상품만 반품하면 그 상품 금액만 환불된다.
     */
    public function test_return_of_one_of_two_items_refunds_only_that_item(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(29000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(39000, $policy);

        $order = $this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]));

        $target = $order->options->firstWhere('product_option_id', $optionB->id);

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(returnItems: [
                ['order_option_id' => $target->id, 'cancel_quantity' => 1],
            ]),
        );

        $this->assertSame(39000.0, $result->getTotalRefundAmount());
    }

    public function test_return_result_reports_order_return_adjusted_items(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 12000, quantity: 2);
        $option = $order->options->first();

        $result = $this->adjustmentService->calculate(
            $order,
            new ReturnAdjustment(returnItems: [
                ['order_option_id' => $option->id, 'cancel_quantity' => 1],
            ]),
        );

        $this->assertCount(1, $result->adjustedItems);
        $this->assertSame($option->id, $result->adjustedItems[0]['order_option_id']);
        $this->assertSame(1, $result->adjustedItems[0]['cancel_quantity']);
    }
}
