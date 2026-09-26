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
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
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
 * cancellable = max(0, min(① domain_refundable, ② payment_capacity))
 * ```
 *
 * ③(진행 중 클레임 예정액)은 참고 축이라 한도를 깎지 않는다 — S17 B5.
 *
 * 검증 축:
 * - 두 강제 축이 각각 최솟값이 되는 경우와 그때의 `binding` 표기
 * - 복수 결제수단(PG + 마일리지)의 수단별 잔여 내역
 * - 다통화 — 결제 통화 한도를 기본 통화로 환산해 비교하는지
 * - 예정액 집계 (진행 중 클레임 3종 · 터미널 상태는 집계하지 않음 · 반품은 합산 재계산)
 * - 자기 자신 제외 (실행 직전 가드가 자기 금액을 스스로 빼면 정당한 환불이 막힌다)
 * - 음수 방지
 */
class CancellableAmountCalculatorTest extends ModuleTestCase
{
    use BuildsClaimAdjustmentOrders;

    protected CancellableAmountCalculator $calculator;

    /**
     * 테스트 통화 설정을 세우고 취소 가능 금액 산정기를 컨테이너에서 받습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->calculator = app(CancellableAmountCalculator::class);
    }

    /**
     * 테스트가 세운 통화 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
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
     * 진행 중 저가 교환 신청서를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  float  $refundProductAmount  저가 차액 환불분
     * @return OrderExchange 교환 신청서
     */
    protected function createPendingExchange(Order $order, float $refundProductAmount): OrderExchange
    {
        return OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_status' => ExchangeStatusEnum::REDELIVERED,
            'refund_product_amount' => $refundProductAmount,
            'additional_product_amount' => 0,
            'claim_shipping_fee' => 0,
            'total_charge_amount' => 0,
        ]);
    }

    /**
     * 교환에서 접수된 환불신청서를 만듭니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  float  $amount  환불액 (배송비 차감 후)
     * @param  RefundStatusEnum  $status  환불 상태
     * @param  float  $deductedFee  차감한 왕복 배송비
     * @return OrderRefund 환불신청서
     */
    protected function createExchangeRefund(
        OrderExchange $exchange,
        float $amount,
        RefundStatusEnum $status = RefundStatusEnum::REQUESTED,
        float $deductedFee = 0,
    ): OrderRefund {
        return OrderRefund::create([
            'order_id' => $exchange->order_id,
            'refund_source_type' => RefundSourceTypeEnum::EXCHANGE,
            'refund_source_id' => $exchange->id,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => $status,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => $amount,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => -$deductedFee,
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

    /**
     * 도메인 환불 대상액이 결제 한도보다 작으면 그 값이 한도가 되고 `binding` 이 도메인 축을 가리킵니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=domain
     *
     * @effects domain_axis_binds_when_it_is_the_smallest
     */
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

    /**
     * 도메인 축과 결제 축이 같으면 `binding` 은 도메인 축으로 표기됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=domain
     *
     * @effects domain_axis_binds_on_a_tie
     */
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

    /**
     * 환불에서 차감하는 회수배송비만큼 도메인 환불 대상액이 줄어듭니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=domain
     *
     * @effects collection_fee_narrows_the_domain_axis
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario concern=domain
     *
     * @effects exchange_without_price_difference_has_zero_domain_axis
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

    /**
     * 저가 교환은 가격 차액만큼이 도메인 환불 대상액이 됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=domain
     *
     * @effects downgrade_exchange_domain_axis_is_the_price_difference
     */
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

    /**
     * 취소 유형은 취소 재계산 결과를 도메인 환불 대상액으로 씁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=domain
     *
     * @effects cancel_type_uses_the_cancellation_adjustment
     */
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

    /**
     * 환불 유형도 취소 재계산 결과를 도메인 환불 대상액으로 씁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=domain
     *
     * @effects refund_type_uses_the_cancellation_adjustment
     */
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

    /**
     * 결제가 이미 일부 취소돼 잔여 한도가 도메인 축보다 작으면 결제 축이 한도가 됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=payment
     *
     * @effects payment_axis_binds_when_capacity_is_already_consumed
     */
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

    /**
     * 전액 취소된 결제는 잔여 한도가 0 이라 취소 가능 금액도 0 입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=payment
     *
     * @effects fully_cancelled_payment_leaves_no_capacity
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario concern=payment
     *
     * @effects waiting_deposit_payment_has_no_capacity
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

    /**
     * 결제수단 내역에 결제 행 하나가 기본·결제 통화 잔여와 통화 코드와 함께 실립니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=payment
     *
     * @effects methods_list_reports_the_payment_row
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario concern=payment
     *
     * @effects multiple_payment_methods_are_summed_and_listed
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

    /**
     * 이미 환불된 마일리지 누계만큼 마일리지 잔여가 줄어듭니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=payment
     *
     * @effects accumulated_mileage_refund_narrows_the_mileage_remainder
     */
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

    /**
     * 마일리지를 쓰지 않은 주문은 결제수단 내역에 마일리지 항목이 없습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=payment
     *
     * @effects order_without_mileage_lists_no_mileage_method
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario concern=currency
     *
     * @effects payment_capacity_is_converted_from_payment_currency_to_base
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

    /**
     * 취소 가능 금액을 주문 통화 스냅샷 환율로 환산한 통화별 금액과 함께 보고합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=currency
     *
     * @effects multi_currency_amount_is_reported_with_snapshot_rates
     */
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

    /**
     * 결제 통화 환율이 스냅샷에 없으면 환산하지 않고 결제 통화 원값을 한도로 씁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=currency
     *
     * @effects missing_exchange_rate_falls_back_to_the_local_amount
     */
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

    /**
     * 진행 중인 다른 환불신청서는 예정액으로 **집계만** 되고 한도를 깎지 않는다. (S17 B5)
     *
     * 실지급 가능액은 ②(결제수단 잔여)가 이미 실제 취소 누적을 뺀 값이다. 여기서 예정액까지
     * 빼면 승인조차 되지 않은 남의 신청서가 이 건의 정당한 환불을 0 원으로 막는다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects pending_refund_is_reported_but_does_not_reduce_the_amount
     */
    public function test_pending_refund_is_reported_but_does_not_reduce_the_amount(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $this->createRefund($order, 20000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(20000.0, $result->reserved);
        $this->assertSame(50000.0, $result->amount);
        $this->assertSame(CancellableAmount::BINDING_DOMAIN, $result->binding);
    }

    /**
     * 예정액이 아무리 커도 `binding` 은 ①·② 두 강제 축만 가리킨다. (S17 B5)
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects binding_never_reports_the_reserved_axis
     */
    public function test_binding_never_reports_the_reserved_axis(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $this->createRefund($order, 49000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertGreaterThan(0.0, $result->reserved);
        $this->assertNotSame('reserved', $result->binding);
        $this->assertContains(
            $result->binding,
            [CancellableAmount::BINDING_DOMAIN, CancellableAmount::BINDING_PAYMENT],
        );
    }

    /**
     * 진행 중 환불신청서의 예정액에는 마일리지 환불분도 포함됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects reserved_includes_mileage_portion_of_a_pending_refund
     */
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
    /**
     * 완료·반려·철회된 환불신청서는 예정액으로 집계되지 않습니다.
     *
     * @param  RefundStatusEnum  $status  종결 환불 상태
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects terminal_refund_does_not_reserve
     */
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

    /**
     * 종결 환불 상태 3종(완료·반려·철회)을 공급합니다.
     *
     * @return array 케이스명별 종결 환불 상태
     */
    public static function terminalRefundStatusProvider(): array
    {
        return [
            '환불 완료' => [RefundStatusEnum::COMPLETED],
            '환불 반려' => [RefundStatusEnum::REJECTED],
            '환불 철회' => [RefundStatusEnum::WITHDRAWN],
        ];
    }

    /**
     * 예정액 목록의 각 항목이 클레임 유형·ID·번호·금액을 함께 싣습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects reserved_entries_carry_the_claim_identity
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects pending_return_reserves_its_estimated_refund
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
        $this->assertSame(30000.0, $result->amount);
        $this->assertSame(CancellableAmount::BINDING_DOMAIN, $result->binding);
    }

    /**
     * 반품 상품 행이 반려·철회된 환불신청서에 연결돼 있으면 그 행은 아직 환불되지 않은 몫이다 — 반품 쪽이
     * 다시 선점한다. 반품 서비스는 이 행을 「환불 미연결」 로 보고 재접수를 허용하는데, 선점 쪽이 `refund_id`
     * 유무만 보면 그 몫이 어느 쪽에서도 잡히지 않아 같은 금액을 주문 환불로 한 번 더 낼 수 있다
     * (3차 검증 인터뷰 2026-09-26).
     *
     * @param  RefundStatusEnum  $status  환불 상태(반려·철회)
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects return_row_linked_to_a_dead_refund_is_reserved_again
     */
    #[DataProvider('deadRefundStatusProvider')]
    public function test_return_row_linked_to_a_dead_refund_is_reserved_again(RefundStatusEnum $status): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);
        $orderReturn = $this->createPendingReturn($order, quantity: 1);
        $deadRefund = $this->createRefund($order, 30000, $status);
        $orderReturn->returnOptions->first()->update([
            'option_status' => ReturnStatusEnum::COMPLETED,
            'refund_id' => $deadRefund->id,
        ]);

        $option = $order->options->first();

        $result = $this->calculator->calculate(
            $order,
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(30000.0, $result->reserved);
        $this->assertSame(ClaimTypeEnum::RETURN->value, $result->reservedClaims[0]['type'] ?? null);
    }

    /**
     * 반품 행의 연결을 끊는 환불 상태(반려·철회)를 공급합니다.
     *
     * @return array 케이스명별 환불 상태
     */
    public static function deadRefundStatusProvider(): array
    {
        return [
            '환불 반려' => [RefundStatusEnum::REJECTED],
            '환불 철회' => [RefundStatusEnum::WITHDRAWN],
        ];
    }

    /**
     * 진행 중 반품이 여럿이면 **합산 재계산** 한 번으로 예정액을 구한다. (S17 B6)
     *
     * 한 건씩 따로 재계산해 더하면 배송비 환급처럼 공유되는 몫이 건마다 중복으로 잡혀
     * 예정액 합계가 결제 총액을 넘는다 — 실측에서 결제 13,000 원 주문의 예정액이
     * 16,000 원으로 잡혔다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects multiple_pending_returns_are_estimated_jointly
     */
    public function test_multiple_pending_returns_are_estimated_jointly(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 2);
        $this->createPendingReturn($order, quantity: 2);
        $this->createPendingReturn($order, quantity: 2);

        $option = $order->options->first();

        $result = $this->calculator->calculate(
            $order,
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            ClaimTypeEnum::RETURN,
        );

        // 건별 독립 추정의 합은 120,000 이지만, 두 건이 같은 상품을 겹쳐 담았으므로
        // 실제로 나갈 수 있는 최대치는 결제 총액 60,000 이다.
        $this->assertSame(60000.0, $result->reserved);
        $this->assertLessThanOrEqual((float) $order->total_paid_amount, $result->reserved);
    }

    /**
     * 목록에 실린 건별 예정액의 합은 언제나 총액과 같다. (S17 B6)
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects reserved_entries_sum_to_the_reserved_total
     */
    public function test_reserved_entries_sum_to_the_reserved_total(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 2);
        $this->createPendingReturn($order, quantity: 2);
        $this->createPendingReturn($order, quantity: 2);

        $option = $order->options->first();

        $result = $this->calculator->calculate(
            $order,
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(
            round($result->reserved, 2),
            round(array_sum(array_column($result->reservedClaims, 'amount')), 2),
        );
    }

    /**
     * 진행 중 반품의 예정액은 환불에서 차감하는 회수배송비를 뺀 값입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects pending_return_reserve_accounts_for_its_deducted_collection_fee
     */
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

    /**
     * 상품 행이 환불신청서에 연결된 반품은 환불신청서 쪽에서만 세고 반품으로 다시 세지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects return_with_a_refund_row_is_not_double_counted
     */
    public function test_return_with_a_refund_row_is_not_double_counted(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);
        $refund = $this->createRefund($order, 30000);

        $orderReturn = $this->createPendingReturn($order, quantity: 1);
        // 환불 연결은 반품 **상품 행**의 `refund_id` 다(S7 — 회차별 접수).
        $orderReturn->returnOptions()->update(['refund_id' => $refund->id]);

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
    /**
     * 완료·반려·철회된 반품은 예정액으로 집계되지 않습니다.
     *
     * @param  ReturnStatusEnum  $status  종결 반품 상태
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects terminal_return_does_not_reserve
     */
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

    /**
     * 종결 반품 상태 3종(완료·반려·철회)을 공급합니다.
     *
     * @return array 케이스명별 종결 반품 상태
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects pending_exchange_reserves_only_its_refundable_difference
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
        // 예정액은 참고 축이라 한도를 깎지 않는다 (S17 B5).
        $this->assertSame(50000.0, $result->amount);
    }

    /**
     * 돌려줄 차액이 없는 진행 중 교환은 아무것도 선점하지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects pending_exchange_without_refundable_difference_reserves_nothing
     */
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

    /**
     * 교환에서 이미 접수된 환불신청서 몫은 환불신청서 쪽에서 세므로 교환 선점에서 뺀다.
     *
     * 교환은 완료 라운드마다 환불신청서를 접수할 수 있어, 진행 중 교환과 그 교환에서 난
     * 환불신청서가 동시에 존재한다. 둘을 각각 전액 세면 같은 차액이 두 번 선점된다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects pending_exchange_reservation_excludes_refund_requests_already_filed
     */
    public function test_pending_exchange_reservation_excludes_refund_requests_already_filed(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $exchange = $this->createPendingExchange($order, 7000);
        // 차액 4,000 중 배송비 1,000 을 차감해 3,000 을 접수한 환불신청서 — 총액 4,000 이 접수된 것이다.
        $this->createExchangeRefund($exchange, 3000, RefundStatusEnum::REQUESTED, 1000);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        // 환불신청서 3,000 + 교환 잔여 (7,000 − 4,000) 3,000
        $this->assertSame(6000.0, $result->reserved);
        $this->assertSame(50000.0, $result->amount);
        $this->assertCount(2, $result->reservedClaims);
    }

    /**
     * 반려된 교환 발 환불신청서는 교환 선점액을 줄이지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects rejected_exchange_refund_request_does_not_reduce_the_exchange_reservation
     */
    public function test_rejected_exchange_refund_request_does_not_reduce_the_exchange_reservation(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $exchange = $this->createPendingExchange($order, 7000);
        $this->createExchangeRefund($exchange, 3000, RefundStatusEnum::REJECTED);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        // 반려된 신청서는 환불되지 않으므로 그 상품의 차액은 교환이 그대로 선점한다.
        $this->assertSame(7000.0, $result->reserved);
        $this->assertCount(1, $result->reservedClaims);
    }

    /**
     * 완료된 교환 발 환불은 이미 나간 돈이라 교환 선점액에서 빠집니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects completed_exchange_refund_is_subtracted_from_the_exchange_reservation
     */
    public function test_completed_exchange_refund_is_subtracted_from_the_exchange_reservation(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $exchange = $this->createPendingExchange($order, 7000);
        $this->createExchangeRefund($exchange, 4000, RefundStatusEnum::COMPLETED);

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        // 완료된 환불은 이미 나간 돈이라 환불신청서 축에도, 교환 선점에도 남지 않는다.
        $this->assertSame(3000.0, $result->reserved);
    }

    #[DataProvider('terminalExchangeStatusProvider')]
    /**
     * 완료·반려·철회된 교환은 예정액으로 집계되지 않습니다.
     *
     * @param  ExchangeStatusEnum  $status  종결 교환 상태
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects terminal_exchange_does_not_reserve
     */
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

    /**
     * 종결 교환 상태 3종(완료·반려·철회)을 공급합니다.
     *
     * @return array 케이스명별 종결 교환 상태
     */
    public static function terminalExchangeStatusProvider(): array
    {
        return [
            '교환 완료' => [ExchangeStatusEnum::COMPLETED],
            '교환 반려' => [ExchangeStatusEnum::REJECTED],
            '교환 철회' => [ExchangeStatusEnum::WITHDRAWN],
        ];
    }

    /**
     * 다른 주문의 진행 중 환불신청서는 이 주문의 예정액에 잡히지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects reserved_from_a_different_order_is_ignored
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects excluded_claim_does_not_reserve_against_itself
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

        // 예정액은 한도를 깎지 않으므로 두 값 모두 도메인 한도 그대로다. 제외 규율이 사는
        // 곳은 `reserved` 집계 자체 — 자기 신청서를 스스로 예정액으로 세면 화면 근거가 틀린다.
        $this->assertSame(50000.0, $withoutExclusion->reserved);
        $this->assertSame(0.0, $withExclusion->reserved);
        $this->assertSame(50000.0, $withoutExclusion->amount);
        $this->assertSame(50000.0, $withExclusion->amount);
    }

    /**
     * 제외 목록에 넣은 진행 중 반품은 자기 예정액을 선점하지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=reserved
     *
     * @effects exclusion_of_a_pending_return_removes_its_reserve
     */
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

    /**
     * 예정액이 두 강제 축을 넘어도 한도는 그대로다. (S17 B5)
     *
     * 예전에는 이 상황이 0 원으로 잘렸다. 그 결과 결제 잔여가 충분한데도 남의 신청서 때문에
     * 정당한 환불이 막혔다 — 실측에서 3회 재현된 결함이라 계약을 바꿨다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=contract
     *
     * @effects reserved_exceeding_the_ceiling_does_not_clamp_the_amount
     */
    public function test_reserved_exceeding_the_ceiling_does_not_clamp_the_amount(): void
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
        $this->assertSame(20000.0, $result->amount);
    }

    /**
     * 두 강제 축은 그대로 한도를 강제한다 — 예정액 축을 참고로 돌린 것이 한도 자체를
     * 없앤 것으로 번지지 않았음을 고정한다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=contract
     *
     * @effects amount_is_never_negative_when_capacity_is_exhausted
     */
    public function test_amount_is_never_negative_when_capacity_is_exhausted(): void
    {
        $order = $this->createSingleItemClaimOrder(
            price: 20000,
            paymentOverrides: ['cancelled_amount' => 20000, 'payment_status' => PaymentStatusEnum::CANCELLED],
        );

        $result = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        );

        $this->assertSame(0.0, $result->amount);
        $this->assertSame(CancellableAmount::BINDING_PAYMENT, $result->binding);
    }

    /**
     * `allows()` 는 한도 이하 금액만 받아들이고 한도를 넘는 금액은 거절합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=contract
     *
     * @effects allows_accepts_amounts_within_the_limit
     */
    public function test_allows_accepts_amounts_within_the_limit(): void
    {
        $result = new CancellableAmount(amount: 30000);

        $this->assertTrue($result->allows(30000));
        $this->assertTrue($result->allows(29999));
        $this->assertFalse($result->allows(30001));
    }

    /**
     * 두 강제 축의 `binding` 라벨 키가 모두 정의된 번역을 가리킵니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=contract
     *
     * @effects binding_label_key_points_at_a_defined_translation
     */
    public function test_binding_label_key_points_at_a_defined_translation(): void
    {
        foreach ([
            CancellableAmount::BINDING_DOMAIN,
            CancellableAmount::BINDING_PAYMENT,
        ] as $binding) {
            $key = (new CancellableAmount(binding: $binding))->bindingLabelKey();

            // 키가 정의되지 않으면 __() 가 원문 키를 그대로 돌려준다 — 화면에 내부 식별자가 노출된다.
            $this->assertNotSame($key, __($key), $binding.' 라벨 키가 정의되지 않았습니다.');
        }
    }

    /**
     * `toArray()` 가 한도·세 축·`binding`·수단 내역·예정 목록을 모두 노출합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=contract
     *
     * @effects to_array_exposes_every_axis
     */
    public function test_to_array_exposes_every_axis(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 50000);
        $this->createRefund($order, 20000);

        $array = $this->calculator->calculate(
            $order,
            $this->claimItemsForAll($order),
            ClaimTypeEnum::RETURN,
        )->toArray();

        $this->assertSame(50000.0, $array['amount']);
        $this->assertSame(50000.0, $array['domain_refundable']);
        $this->assertSame(50000.0, $array['payment_capacity']);
        $this->assertSame(20000.0, $array['reserved']);
        $this->assertSame(CancellableAmount::BINDING_DOMAIN, $array['binding']);
        $this->assertArrayHasKey('binding_label_key', $array);
        $this->assertArrayHasKey('methods', $array);
        $this->assertArrayHasKey('reserved_claims', $array);
    }

    /**
     * 화면 표시와 실행 가드가 같은 메서드를 쓰므로, 같은 입력은 같은 값을 내야 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=contract
     *
     * @effects repeated_calculation_is_stable
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

    // ================================================================
    // 회수비 기록 자리의 유형 한정 (S15a ⓑ · S17 이월)
    // ================================================================

    /**
     * 반품 발 환불신청서의 `refund_shipping_amount` 는 도메인 한도에 관여하지 않습니다.
     *
     * 두 유형이 회수비를 **같은 이름의 다른 자리**에 적는다 — 교환은 왕복 배송비를
     * `refund_shipping_amount` 에 음수로 남기고, 반품은 회수비가 이미 빠진 순액만
     * `refund_amount` 에 남긴다(회수비의 정본은 원 신청서의 `claim_shipping_fee` 다).
     * 그래서 교환 경로가 쓰는 "음수분 되더하기" 를 두 유형 공통 식으로 끌어올리면 반품에서만
     * 회수비만큼 한도가 부풀어, 운영자가 회수비까지 돌려주는 금액을 실행할 수 있게 된다.
     * 이 결함은 예외도 로그도 남기지 않는다 — 한도 숫자만 달라진다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=shipping_column
     *
     * @effects return_sourced_refund_ignores_the_shipping_amount_column
     */
    public function test_return_sourced_refund_ignores_the_shipping_amount_column(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);

        // 같은 반품·같은 상품인데 `refund_shipping_amount` 만 다른 두 벌.
        // 그 칸이 반품 한도에 관여하면 두 값이 갈린다.
        $clean = $this->calculator->calculateForRefund(
            $order,
            $this->createReturnSourcedRefund($order, shippingAmount: 0),
        );

        $withFeeInTheWrongColumn = $this->calculator->calculateForRefund(
            $order,
            $this->createReturnSourcedRefund($order, shippingAmount: -3000),
        );

        $this->assertSame(
            27000.0,
            $clean->domainRefundable,
            '반품 재계산 — 상품 30,000 에서 회수비 3,000 을 뺀 값이다.',
        );
        $this->assertSame(
            $clean->domainRefundable,
            $withFeeInTheWrongColumn->domainRefundable,
            '`refund_shipping_amount` 는 반품 한도의 근거가 아니다.',
        );
    }

    /**
     * 교환 발 환불신청서는 반대로 `refund_shipping_amount` 의 음수분을 총액으로 되돌립니다.
     *
     * 교환 차액은 재계산 산출물이 아니라 신청 시점 동결값(`refund_product_amount`)이라, 이미
     * 접수된 몫을 **차감 전 총액**으로 되돌려야 잔여가 맞는다. 되더하기를 빼면 회수비만큼
     * 잔여가 커져 같은 회수비를 두 번 돌려주게 된다 — 위 반품 축과 정확히 반대 방향이다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=shipping_column
     *
     * @effects exchange_sourced_refund_restores_the_fee_from_the_shipping_amount_column
     */
    public function test_exchange_sourced_refund_restores_the_fee_from_the_shipping_amount_column(): void
    {
        $order = $this->createSingleItemClaimOrder(price: 30000, quantity: 3);
        $exchange = $this->createPendingExchange($order, 10000);

        // 이미 접수된 차액 3,000 (회수비 1,000 을 빼고 남은 순액) — 총액으로는 4,000 이다.
        $this->createExchangeRefund($exchange, 3000, RefundStatusEnum::REQUESTED, 1000);

        $pending = $this->createExchangeRefund($exchange, 6000, RefundStatusEnum::REQUESTED);

        $result = $this->calculator->calculateForRefund($order, $pending);

        $this->assertSame(
            6000.0,
            $result->domainRefundable,
            '동결 차액 10,000 − 이미 접수된 총액 4,000. 되더하기가 빠지면 7,000 이 된다.',
        );
    }

    /**
     * 반품 발 환불신청서 1건을 만듭니다. (원 반품 + 신청상품 줄 포함)
     *
     * @param  Order  $order  주문
     * @param  float  $shippingAmount  `refund_shipping_amount` 에 적을 값
     * @param  int  $collectionFee  원 반품이 동결한 회수배송비
     * @return OrderRefund 환불신청서
     */
    protected function createReturnSourcedRefund(
        Order $order,
        float $shippingAmount,
        int $collectionFee = 3000,
    ): OrderRefund {
        $orderReturn = $this->createPendingReturn(
            $order,
            quantity: 1,
            collectionFee: $collectionFee,
            chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        );

        $option = $order->options->first();

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::RETURN,
            'refund_source_id' => $orderReturn->id,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 27000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => $shippingAmount,
        ]);

        OrderRefundOption::create([
            'order_refund_id' => $refund->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => RefundStatusEnum::REQUESTED,
            'quantity' => 1,
            'unit_price' => (float) $option->unit_price,
            'subtotal_amount' => (float) $option->unit_price,
            'discount_amount' => 0,
            'refund_amount' => 0,
        ]);

        return $refund->load('refundOptions');
    }
}
