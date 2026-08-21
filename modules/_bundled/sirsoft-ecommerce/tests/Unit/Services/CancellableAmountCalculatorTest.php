<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\DTO\CancellableAmount;
use Modules\Sirsoft\Ecommerce\DTO\ExchangeAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ReturnAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Services\CancellableAmountCalculator;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 취소 가능 금액 산정기 테스트 (S2c · D19)
 *
 * ```
 * cancellable = max(0, min(① domain_refundable, ② payment_capacity) − ③ reserved)
 * ```
 *
 * 검증 축:
 * - 세 축이 각각 최솟값이 되는 경우와 그때의 `binding` 표기
 * - 복수 결제수단(PG + 마일리지)의 수단별 잔여 내역
 * - 다통화 — 결제 통화 한도를 기본 통화로 환산해 비교하는지
 * - 선점 존재/해소 (진행 중 클레임 3종 · 터미널 상태는 선점하지 않음)
 * - 자기 자신 제외 (실행 직전 가드가 자기 금액을 스스로 빼면 정당한 환불이 막힌다)
 * - 음수 방지
 */
class CancellableAmountCalculatorTest extends ModuleTestCase
{
    use BuildsClaimAdjustmentOrders;

    protected CancellableAmountCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->calculator = app(CancellableAmountCalculator::class);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    // ================================================================
    // 헬퍼
    // ================================================================

    /**
     * 다통화 환산이 가능한 통화 스냅샷을 반환합니다.
     *
     * base_unit 1000 · KRW→USD 0.71 → 100,000원 = 71 USD (역산도 동일).
     *
     * @return array 통화 스냅샷
     */
    protected function multiCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => ['rate' => 1.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'base_unit' => 1000],
                'USD' => ['rate' => 0.71, 'rounding_unit' => '0.01', 'rounding_method' => 'round'],
            ],
        ];
    }

    /**
     * 진행 중 환불신청서를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  float  $amount  환불액
     * @param  RefundStatusEnum  $status  환불 상태
     * @param  float  $pointsAmount  마일리지 환불액
     * @return OrderRefund 환불신청서
     */
    protected function createRefund(
        Order $order,
        float $amount,
        RefundStatusEnum $status = RefundStatusEnum::APPROVED,
        float $pointsAmount = 0,
    ): OrderRefund {
        return OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => $status,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => $amount,
            'refund_points_amount' => $pointsAmount,
            'refund_shipping_amount' => 0,
        ]);
    }

    /**
     * 진행 중 반품 신청서를 만듭니다. (환불신청서 미생성 상태)
     *
     * @param  Order  $order  주문
     * @param  int  $quantity  반품 수량
     * @param  ReturnStatusEnum  $status  반품 상태
     * @param  int  $collectionFee  회수배송비
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식
     * @return OrderReturn 반품 신청서
     */
    protected function createPendingReturn(
        Order $order,
        int $quantity = 1,
        ReturnStatusEnum $status = ReturnStatusEnum::APPROVED,
        int $collectionFee = 0,
        ?ClaimChargeMethodEnum $chargeMethod = null,
    ): OrderReturn {
        $orderReturn = OrderReturn::factory()->create([
            'order_id' => $order->id,
            'return_status' => $status,
            'claim_shipping_fee' => $collectionFee,
            'charge_method' => $chargeMethod,
        ]);

        $option = $order->options->first();

        OrderReturnOption::factory()->create([
            'order_return_id' => $orderReturn->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => $status,
            'return_quantity' => $quantity,
            'original_quantity' => $option->quantity,
            'unit_price' => $option->unit_price,
            'subtotal_amount' => $option->unit_price * $quantity,
        ]);

        return $orderReturn->load('returnOptions');
    }

    // ================================================================
    // ① 도메인 환불 대상 축
    // ================================================================

    public function test_domain_axis_binds_when_it_is_the_smallest(): void
    {
        // 30,000원 3개 주문(90,000 결제) 중 1개만 반품 → 도메인 30,000 < 결제 한도 90,000
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);
        $option = $order->options->first();

        $result = $this->calculator->calculate(
            $order,
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(30000.0, $result->domainRefundable);
        $this->assertSame(90000.0, $result->paymentCapacity);
        $this->assertSame(30000.0, $result->amount);
        $this->assertSame(CancellableAmount::BINDING_DOMAIN, $result->binding);
    }

    public function test_domain_axis_binds_on_a_tie(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 40000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(40000.0, $result->domainRefundable);
        $this->assertSame(40000.0, $result->paymentCapacity);
        $this->assertSame(CancellableAmount::BINDING_DOMAIN, $result->binding);
    }

    public function test_collection_fee_narrows_the_domain_axis(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 40000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
            new ReturnAdjustment(
                returnItems: $this->claimItemsForAll($order),
                collectionFee: 3000,
                chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            ),
        );

        $this->assertSame(37000.0, $result->domainRefundable);
        $this->assertSame(37000.0, $result->amount);
    }

    /**
     * 교환은 상품 금액을 돌려주지 않으므로 도메인 축이 0 이다.
     */
    public function test_exchange_without_price_difference_has_zero_domain_axis(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 29000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::EXCHANGE,
        );

        $this->assertSame(0.0, $result->domainRefundable);
        $this->assertSame(0.0, $result->amount);
    }

    public function test_downgrade_exchange_domain_axis_is_the_price_difference(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 29000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::EXCHANGE,
            new ExchangeAdjustment(
                exchangeItems: $this->claimItemsForAll($order),
                priceDifference: -5000,
                chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            ),
        );

        $this->assertSame(5000.0, $result->domainRefundable);
        $this->assertSame(5000.0, $result->amount);
    }

    public function test_cancel_type_uses_the_cancellation_adjustment(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 25000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::CANCEL,
        );

        $this->assertSame(25000.0, $result->domainRefundable);
    }

    public function test_refund_type_uses_the_cancellation_adjustment(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 25000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::REFUND,
        );

        $this->assertSame(25000.0, $result->domainRefundable);
    }

    // ================================================================
    // ② 결제수단 잔여 한도 축
    // ================================================================

    public function test_payment_axis_binds_when_capacity_is_already_consumed(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 50000,
            paymentOverrides: [
                'payment_status' => PaymentStatusEnum::PARTIAL_CANCELLED,
                'cancelled_amount' => 30000,
            ],
        );

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(50000.0, $result->domainRefundable);
        $this->assertSame(20000.0, $result->paymentCapacity);
        $this->assertSame(20000.0, $result->amount);
        $this->assertSame(CancellableAmount::BINDING_PAYMENT, $result->binding);
    }

    public function test_fully_cancelled_payment_leaves_no_capacity(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 50000,
            paymentOverrides: [
                'payment_status' => PaymentStatusEnum::CANCELLED,
                'cancelled_amount' => 50000,
            ],
        );

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(0.0, $result->paymentCapacity);
        $this->assertSame(0.0, $result->amount);
    }

    /**
     * 입금 대기 결제는 돈이 들어오지 않았으므로 잔여 한도가 없다.
     */
    public function test_waiting_deposit_payment_has_no_capacity(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 50000,
            paymentOverrides: ['payment_status' => PaymentStatusEnum::WAITING_DEPOSIT],
        );

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(0.0, $result->paymentCapacity);
        $this->assertSame(0.0, $result->amount);
    }

    public function test_methods_list_reports_the_payment_row(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertCount(1, $result->methods);
        $this->assertSame(CancellableAmount::METHOD_PAYMENT, $result->methods[0]['type']);
        $this->assertSame(50000.0, $result->methods[0]['remaining_base']);
        $this->assertSame(50000.0, $result->methods[0]['remaining_local']);
        $this->assertSame('KRW', $result->methods[0]['currency']);
    }

    /**
     * 복수 결제수단 — PG 결제행과 마일리지가 각각 항목으로 잡히고 합계가 한도가 된다.
     */
    public function test_multiple_payment_methods_are_summed_and_listed(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 50000,
            orderOverrides: ['total_paid_amount' => 40000, 'total_points_used_amount' => 10000],
            paymentOverrides: ['paid_amount_local' => 40000, 'paid_amount_base' => 40000],
        );

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(50000.0, $result->paymentCapacity);
        $this->assertCount(2, $result->methods);

        $types = array_column($result->methods, 'type');
        $this->assertContains(CancellableAmount::METHOD_PAYMENT, $types);
        $this->assertContains(CancellableAmount::METHOD_MILEAGE, $types);
    }

    public function test_accumulated_mileage_refund_narrows_the_mileage_remainder(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 50000,
            orderOverrides: [
                'total_paid_amount' => 40000,
                'total_points_used_amount' => 10000,
                'total_refunded_points_amount' => 4000,
            ],
            paymentOverrides: ['paid_amount_local' => 40000, 'paid_amount_base' => 40000],
        );

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $mileage = collect($result->methods)->firstWhere('type', CancellableAmount::METHOD_MILEAGE);

        $this->assertSame(6000.0, $mileage['remaining_base']);
    }

    public function test_order_without_mileage_lists_no_mileage_method(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertNull(collect($result->methods)->firstWhere('type', CancellableAmount::METHOD_MILEAGE));
    }

    /**
     * 결제 통화가 기본 통화와 다르면 한도를 기본 통화로 환산해 비교한다.
     *
     * 환산하지 않으면 71 USD 가 71원으로 읽혀 한도가 통째로 무너진다 — 값은 맞고
     * 단위만 틀린 형태라 오류가 남지 않는다.
     */
    public function test_payment_capacity_is_converted_from_payment_currency_to_base(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 100000,
            orderOverrides: ['currency_snapshot' => $this->multiCurrencySnapshot()],
            paymentOverrides: ['currency' => 'USD', 'paid_amount_local' => 71, 'paid_amount_base' => 100000],
        );

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(100000.0, $result->paymentCapacity);
        $this->assertSame(71.0, $result->methods[0]['remaining_local']);
        $this->assertSame('USD', $result->methods[0]['currency']);
    }

    public function test_multi_currency_amount_is_reported_with_snapshot_rates(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 100000,
            orderOverrides: ['currency_snapshot' => $this->multiCurrencySnapshot()],
        );

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(100000.0, $result->mcAmount['KRW']);
        $this->assertSame(71.0, $result->mcAmount['USD']);
        $this->assertSame('KRW', $result->currency);
    }

    public function test_missing_exchange_rate_falls_back_to_the_local_amount(): void
    {
        $snapshot = $this->multiCurrencySnapshot();
        unset($snapshot['exchange_rates']['USD']);

        $order = $this->createSingleItemClaimOrder(
            price: 100000,
            orderOverrides: ['currency_snapshot' => $snapshot],
            paymentOverrides: ['currency' => 'USD', 'paid_amount_local' => 71, 'paid_amount_base' => 100000],
        );

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        // 환율을 모르면 환산하지 않고 원값을 쓴다 — 한도가 좁아질지언정 과대 산정하지 않는다.
        $this->assertSame(71.0, $result->paymentCapacity);
    }

    // ================================================================
    // ③ 진행 중 클레임 선점액 축
    // ================================================================

    public function test_reserved_axis_binds_when_another_refund_is_in_flight(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $this->createRefund($order, 20000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(20000.0, $result->reserved);
        $this->assertSame(30000.0, $result->amount);
        $this->assertSame(CancellableAmount::BINDING_RESERVED, $result->binding);
    }

    public function test_reserved_includes_mileage_portion_of_a_pending_refund(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $this->createRefund($order, 10000, RefundStatusEnum::APPROVED, 5000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(15000.0, $result->reserved);
    }

    #[DataProvider('terminalRefundStatusProvider')]
    public function test_terminal_refund_does_not_reserve(RefundStatusEnum $status): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $this->createRefund($order, 20000, $status);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(0.0, $result->reserved);
        $this->assertSame(50000.0, $result->amount);
    }

    public static function terminalRefundStatusProvider(): array
    {
        return [
            '환불 완료' => [RefundStatusEnum::COMPLETED],
            '환불 반려' => [RefundStatusEnum::REJECTED],
            '환불 철회' => [RefundStatusEnum::WITHDRAWN],
        ];
    }

    public function test_reserved_entries_carry_the_claim_identity(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $refund = $this->createRefund($order, 20000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertCount(1, $result->reservedClaims);
        $this->assertSame(ClaimTypeEnum::REFUND->value, $result->reservedClaims[0]['type']);
        $this->assertSame($refund->id, $result->reservedClaims[0]['id']);
        $this->assertSame($refund->refund_number, $result->reservedClaims[0]['number']);
        $this->assertSame(20000.0, $result->reservedClaims[0]['amount']);
    }

    /**
     * 환불신청서가 아직 없는 진행 중 반품은 같은 재계산기로 예정액을 추정해 선점한다.
     */
    public function test_pending_return_reserves_its_estimated_refund(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);
        $this->createPendingReturn($order, quantity: 1);

        $option = $order->options->first();

        $result = $this->calculator->calculate(
            $order,
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(30000.0, $result->reserved);
        $this->assertSame(0.0, $result->amount);
        $this->assertSame(CancellableAmount::BINDING_RESERVED, $result->binding);
    }

    public function test_pending_return_reserve_accounts_for_its_deducted_collection_fee(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);
        $this->createPendingReturn(
            $order,
            quantity: 1,
            collectionFee: 3000,
            chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        );

        $option = $order->options->first();

        $result = $this->calculator->calculate(
            $order,
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(27000.0, $result->reserved);
    }

    public function test_return_with_a_refund_row_is_not_double_counted(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);
        $refund = $this->createRefund($order, 30000);

        $orderReturn = $this->createPendingReturn($order, quantity: 1);
        $orderReturn->update(['refund_id' => $refund->id]);

        $option = $order->options->first();

        $result = $this->calculator->calculate(
            $order,
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            ClaimTypeEnum::RETURN,
        );

        // 환불신청서 30,000 만 잡히고 반품이 다시 세지지 않는다.
        $this->assertSame(30000.0, $result->reserved);
        $this->assertCount(1, $result->reservedClaims);
    }

    #[DataProvider('terminalReturnStatusProvider')]
    public function test_terminal_return_does_not_reserve(ReturnStatusEnum $status): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);
        $this->createPendingReturn($order, quantity: 1, status: $status);

        $option = $order->options->first();

        $result = $this->calculator->calculate(
            $order,
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(0.0, $result->reserved);
    }

    public static function terminalReturnStatusProvider(): array
    {
        return [
            '반품 완료' => [ReturnStatusEnum::COMPLETED],
            '반품 반려' => [ReturnStatusEnum::REJECTED],
            '반품 철회' => [ReturnStatusEnum::WITHDRAWN],
        ];
    }

    /**
     * 교환은 저가 교환 차액 환불분만 선점한다 — 청구액은 받는 돈이라 선점 대상이 아니다.
     */
    public function test_pending_exchange_reserves_only_its_refundable_difference(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);

        OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_status' => ExchangeStatusEnum::APPROVED,
            'refund_product_amount' => 7000,
            'additional_product_amount' => 0,
            'claim_shipping_fee' => 6000,
            'total_charge_amount' => 6000,
        ]);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(7000.0, $result->reserved);
        $this->assertSame(43000.0, $result->amount);
    }

    public function test_pending_exchange_without_refundable_difference_reserves_nothing(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);

        OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_status' => ExchangeStatusEnum::APPROVED,
            'refund_product_amount' => 0,
            'additional_product_amount' => 3000,
        ]);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(0.0, $result->reserved);
        $this->assertSame([], $result->reservedClaims);
    }

    #[DataProvider('terminalExchangeStatusProvider')]
    public function test_terminal_exchange_does_not_reserve(ExchangeStatusEnum $status): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);

        OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_status' => $status,
            'refund_product_amount' => 7000,
        ]);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(0.0, $result->reserved);
    }

    public static function terminalExchangeStatusProvider(): array
    {
        return [
            '교환 완료' => [ExchangeStatusEnum::COMPLETED],
            '교환 반려' => [ExchangeStatusEnum::REJECTED],
            '교환 철회' => [ExchangeStatusEnum::WITHDRAWN],
        ];
    }

    public function test_reserved_from_a_different_order_is_ignored(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $other = $this->createSingleItemClaimOrder(price: 50000);

        $this->createRefund($other, 20000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(0.0, $result->reserved);
    }

    /**
     * 자기 자신 제외 — 실행 직전 가드는 실행 대상 클레임을 선점 목록에서 빼야 한다.
     *
     * 빼지 않으면 자기 금액을 스스로 차감해 한도가 0 이 되고 정당한 환불이 막힌다.
     */
    public function test_excluded_claim_does_not_reserve_against_itself(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $refund = $this->createRefund($order, 50000);

        $withoutExclusion = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $withExclusion = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
            null,
            [ClaimTypeEnum::REFUND->value => [$refund->id]],
        );

        $this->assertSame(0.0, $withoutExclusion->amount);
        $this->assertSame(50000.0, $withExclusion->amount);
    }

    public function test_exclusion_of_a_pending_return_removes_its_reserve(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);
        $orderReturn = $this->createPendingReturn($order, quantity: 1);

        $option = $order->options->first();
        $items = [['order_option_id' => $option->id, 'cancel_quantity' => 1]];

        $result = $this->calculator->calculate(
            $order,
            $items,
            ClaimTypeEnum::RETURN,
            null,
            [ClaimTypeEnum::RETURN->value => [$orderReturn->id]],
        );

        $this->assertSame(0.0, $result->reserved);
        $this->assertSame(30000.0, $result->amount);
    }

    // ================================================================
    // 음수 방지 · DTO 계약
    // ================================================================

    public function test_reserved_exceeding_the_ceiling_is_clamped_to_zero(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 20000);
        $this->createRefund($order, 20000);
        $this->createRefund($order, 20000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(40000.0, $result->reserved);
        $this->assertSame(0.0, $result->amount);
    }

    public function test_allows_accepts_amounts_within_the_limit(): void
    {
        $result = new CancellableAmount(amount: 30000);

        $this->assertTrue($result->allows(30000));
        $this->assertTrue($result->allows(29999));
        $this->assertFalse($result->allows(30001));
    }

    public function test_binding_label_key_points_at_a_defined_translation(): void
    {
        foreach ([
            CancellableAmount::BINDING_DOMAIN,
            CancellableAmount::BINDING_PAYMENT,
            CancellableAmount::BINDING_RESERVED,
        ] as $binding) {
            $key = (new CancellableAmount(binding: $binding))->bindingLabelKey();

            // 키가 정의되지 않으면 __() 가 원문 키를 그대로 돌려준다 — 화면에 내부 식별자가 노출된다.
            $this->assertNotSame($key, __($key), $binding.' 라벨 키가 정의되지 않았습니다.');
        }
    }

    public function test_to_array_exposes_every_axis(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $this->createRefund($order, 20000);

        $array = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        )->toArray();

        $this->assertSame(30000.0, $array['amount']);
        $this->assertSame(50000.0, $array['domain_refundable']);
        $this->assertSame(50000.0, $array['payment_capacity']);
        $this->assertSame(20000.0, $array['reserved']);
        $this->assertSame(CancellableAmount::BINDING_RESERVED, $array['binding']);
        $this->assertArrayHasKey('binding_label_key', $array);
        $this->assertArrayHasKey('methods', $array);
        $this->assertArrayHasKey('reserved_claims', $array);
    }

    /**
     * 화면 표시와 실행 가드가 같은 메서드를 쓰므로, 같은 입력은 같은 값을 내야 한다.
     */
    public function test_repeated_calculation_is_stable(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $items = $this->claimItemsForAll($order);

        $first = $this->calculator->calculate($order, $items, ClaimTypeEnum::RETURN);
        $second = $this->calculator->calculate($order, $items, ClaimTypeEnum::RETURN);

        $this->assertSame($first->amount, $second->amount);
        $this->assertSame($first->binding, $second->binding);
    }
}
