<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 환불 실행 서비스 테스트
 *
 * OrderCancellationService 에서 추출한 RefundExecutionService 를 검증합니다.
 * - 추출 등가성: PG 환불 훅 / 쿠폰·마일리지·재고 복원 / 현금영수증 동기화가 이동 전과 같은 동작
 * - 환불 누적 정정: execute() 가 total_refunded_amount·total_refunded_points_amount 와
 *   OrderPayment.cancelled_amount·mc_cancelled_amount 를 누적하고, 그 누적이 다음 환불의
 *   잔여 한도(OrderAdjustmentService 의 remainingPg)를 실제로 좁히는지
 * - 취소 경로와의 경계: 취소는 total_paid_amount 를 깎으므로 이 누적을 쓰지 않는다는 불변식
 */
class RefundExecutionServiceTest extends ModuleTestCase
{
    protected RefundExecutionService $service;

    /**
     * 테스트 통화·채번 설정을 준비하고 환불 실행 서비스를 꺼냅니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->setupSequences();
        $this->service = app(RefundExecutionService::class);
    }

    /**
     * 테스트가 기록한 통화·주문 설정 파일을 지워 다음 테스트에 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $settingsDir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        foreach (['language_currency.json', 'order_settings.json'] as $file) {
            $path = $settingsDir.'/'.$file;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    // ================================================================
    // 헬퍼
    // ================================================================

    /**
     * 테스트용 통화 설정을 기록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setupTestCurrencySettings(): void
    {
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }

        $settings = [
            'default_language' => 'ko',
            'default_currency' => 'KRW',
            'currencies' => [
                [
                    'code' => 'KRW',
                    'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                    'exchange_rate' => null,
                    'rounding_unit' => '1',
                    'rounding_method' => 'floor',
                    'is_default' => true,
                ],
            ],
        ];

        file_put_contents(
            $settingsPath.'/language_currency.json',
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 재고 복원 설정을 기록합니다.
     *
     * @param  bool  $enabled  복원 여부
     * @return void 반환값 없음
     */
    protected function setStockRestoreSetting(bool $enabled): void
    {
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }

        file_put_contents(
            $settingsPath.'/order_settings.json',
            json_encode(['stock_restore_on_cancel' => $enabled], JSON_PRETTY_PRINT)
        );
    }

    /**
     * 취소/환불 시퀀스 레코드를 생성합니다.
     *
     * @return void 반환값 없음
     */
    protected function setupSequences(): void
    {
        foreach ([SequenceType::CANCEL, SequenceType::REFUND] as $type) {
            $config = $type->getDefaultConfig();
            Sequence::firstOrCreate(
                ['type' => $type->value],
                [
                    'algorithm' => $config['algorithm']->value,
                    'prefix' => $config['prefix'],
                    'current_value' => 0,
                    'increment' => 1,
                    'min_value' => 1,
                    'max_value' => $config['max_value'],
                    'cycle' => false,
                    'pad_length' => $config['pad_length'],
                ]
            );
        }
    }

    /**
     * 결제행이 딸린 최소 주문을 만듭니다.
     *
     * @param  array  $orderOverrides  주문 필드 override
     * @param  array  $paymentOverrides  결제행 필드 override
     * @return Order 생성된 주문 (payment 로드됨)
     */
    protected function createPaidOrder(array $orderOverrides = [], array $paymentOverrides = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_paid_amount' => 20000,
            'total_points_used_amount' => 0,
            'total_refunded_amount' => 0,
            'total_refunded_points_amount' => 0,
            'is_mileage_deducted' => false,
        ], $orderOverrides));

        OrderPayment::factory()->forOrder($order)->create(array_merge([
            'payment_method' => PaymentMethodEnum::CARD->value,
            'payment_status' => PaymentStatusEnum::PAID,
            'currency' => 'KRW',
            'paid_amount_local' => 20000,
            'paid_amount_base' => 20000,
            'cancelled_amount' => 0,
            'mc_cancelled_amount' => null,
            'paid_at' => now(),
        ], $paymentOverrides));

        return $order->load('payment');
    }

    /**
     * 환불 레코드를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  array  $overrides  필드 override
     * @return OrderRefund 생성된 환불 레코드
     */
    protected function createRefund(Order $order, array $overrides = []): OrderRefund
    {
        return OrderRefund::create(array_merge([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::CANCEL,
            'refund_source_id' => null,
            'refund_number' => 'RF-'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 5000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ], $overrides));
    }

    /**
     * PG 환불 훅을 성공으로 고정합니다.
     *
     * @param  string  $transactionId  반환할 거래 ID
     * @return void 반환값 없음
     */
    protected function stubPgRefundSuccess(string $transactionId = 'TXN-OK'): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true,
            'transaction_id' => $transactionId,
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    /**
     * 상품+옵션을 생성합니다.
     *
     * @param  int  $price  판매가
     * @param  int  $stock  재고
     * @param  ShippingPolicy|null  $shippingPolicy  배송정책
     * @return array{0: Product, 1: ProductOption} 상품과 옵션
     */
    protected function createProductWithOption(
        int $price = 10000,
        int $stock = 100,
        ?ShippingPolicy $shippingPolicy = null,
    ): array {
        $attrs = [
            'selling_price' => $price,
            'list_price' => $price,
            'tax_status' => 'taxable',
        ];
        if ($shippingPolicy) {
            $attrs['shipping_policy_id'] = $shippingPolicy->id;
        }
        $product = ProductFactory::new()->create($attrs);

        $option = ProductOption::factory()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => $stock,
        ]);

        return [$product, $option];
    }

    /**
     * 무료 배송정책을 생성합니다.
     *
     * @return ShippingPolicy 생성된 정책
     */
    protected function createFreeShippingPolicy(): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FREE,
            'base_fee' => 0,
            'free_threshold' => null,
            'ranges' => null,
            'extra_fee_enabled' => false,
            'extra_fee_settings' => null,
            'extra_fee_multiply' => false,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
    }

    /**
     * 계산 결과로부터 실제 재계산이 가능한 주문을 만듭니다.
     *
     * ② 축(잔여 결제 한도) 검증은 OrderAdjustmentService 의 부분취소 경로를 실제로 태워야 하므로
     * 옵션·배송·결제행이 모두 갖춰진 주문이 필요하다.
     *
     * @param  CalculationInput  $input  계산 입력
     * @return Order 생성된 주문
     */
    protected function createOrderFromCalculation(CalculationInput $input): Order
    {
        $result = app(OrderCalculationService::class)->calculate($input);
        $user = User::factory()->create();

        $shippingPolicySnapshot = [];
        foreach ($result->items as $item) {
            if ($item->appliedShippingPolicy !== null) {
                $shippingPolicySnapshot[] = [
                    'product_option_id' => $item->productOptionId,
                    'policy' => array_merge(
                        $item->appliedShippingPolicy->policySnapshot,
                        [
                            'policy_id' => $item->appliedShippingPolicy->policyId,
                            'policy_name' => $item->appliedShippingPolicy->policyName,
                        ]
                    ),
                ];
            }
        }

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $result->summary->subtotal,
            'total_discount_amount' => $result->summary->totalDiscount,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => $result->summary->baseShippingTotal,
            'extra_shipping_amount' => $result->summary->extraShippingTotal,
            'shipping_discount_amount' => $result->summary->shippingDiscount,
            'total_shipping_amount' => $result->summary->totalShipping,
            'total_amount' => $result->summary->paymentAmount,
            'total_paid_amount' => $result->summary->finalAmount,
            'total_due_amount' => 0,
            'total_points_used_amount' => $result->summary->pointsUsed,
            'total_tax_amount' => $result->summary->taxableAmount,
            'total_tax_free_amount' => $result->summary->taxFreeAmount,
            'total_cancelled_amount' => 0,
            'total_refunded_amount' => 0,
            'total_refunded_points_amount' => 0,
            'cancellation_count' => 0,
            'currency' => 'KRW',
            'promotions_applied_snapshot' => ['coupon_issue_ids' => [], 'item_coupons' => [], 'discount_code' => null],
            'shipping_policy_applied_snapshot' => $shippingPolicySnapshot,
        ]);

        foreach ($result->items as $item) {
            $product = Product::find($item->productId);
            $productOption = ProductOption::find($item->productOptionId);

            OrderOption::factory()->forOrder($order)->create([
                'product_id' => $item->productId,
                'product_option_id' => $item->productOptionId,
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice,
                'subtotal_price' => $item->subtotal,
                'subtotal_discount_amount' => 0,
                'coupon_discount_amount' => 0,
                'code_discount_amount' => 0,
                'subtotal_paid_amount' => $item->finalAmount,
                'subtotal_tax_amount' => $item->taxableAmount,
                'subtotal_tax_free_amount' => $item->taxFreeAmount,
                'subtotal_points_used_amount' => $item->pointsUsedShare,
                'product_snapshot' => $product?->toSnapshotArray() ?? [],
                'option_snapshot' => $productOption?->toSnapshotArray() ?? [],
            ]);
        }

        $shipping = $result->shippings[0] ?? null;
        OrderShipping::factory()->forOrder($order)->create([
            'shipping_status' => 'pending',
            'shipping_policy_id' => $shipping?->policyId,
            'base_shipping_amount' => $result->summary->baseShippingTotal,
            'extra_shipping_amount' => $result->summary->extraShippingTotal,
            'total_shipping_amount' => $result->summary->totalShipping,
            'shipping_discount_amount' => $result->summary->shippingDiscount,
            'delivery_policy_snapshot' => $shipping?->policySnapshot,
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::CARD->value,
            'payment_status' => PaymentStatusEnum::PAID,
            'currency' => 'KRW',
            'paid_amount_local' => $result->summary->finalAmount,
            'paid_amount_base' => $result->summary->finalAmount,
            'cancelled_amount' => 0,
            'paid_at' => now(),
        ]);

        return $order->load(['options', 'shippings', 'payment']);
    }

    // ================================================================
    // A. 추출 등가성 — PG 환불
    // ================================================================

    // ================================================================
    // A-0. 송금 대상 계좌 — 실행 시점 반영 (2026-08-31 확정)
    // ================================================================

    /**
     * 환불을 실행하면 그 환불이 든 계좌가 송금 대상(주문 결제행)에 반영된다.
     *
     * PG 환불 API 의 `refundReceiveAccount` 와 무통장 수동 이체가 읽는 자리는 결제행 하나뿐이다.
     * 구매자가 신청서에서 고친 계좌는 실행 직전 여기에 닿아야 실제 송금에 반영된다 — 닿지 않으면
     * 오류 없이 옛 계좌로 돈이 나간다.
     *
     * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
     *
     * @effects claim_refund_account_reaches_the_payout_target_at_execution, refund_account_on_the_refund_reaches_the_payment_row_at_execution
     *
     * @return void 반환값 없음
     */
    public function test_execute_applies_the_refunds_own_bank_account_to_the_payment(): void
    {
        $this->stubPgRefundSuccess();
        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order, [
            'refund_bank_code' => '004',
            'refund_bank_account' => '110234567890',
            'refund_bank_holder' => '홍길동',
        ]);

        $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 5000));

        $payment = $order->fresh('payment')->payment;

        $this->assertSame('004', $payment->refund_bank_code);
        $this->assertSame('110234567890', $payment->refund_bank_account);
        $this->assertSame('홍길동', $payment->refund_bank_holder);
    }

    /**
     * 계좌를 들지 않은 환불은 송금 대상을 지우지 않는다.
     *
     * 카드 취소처럼 계좌가 필요 없는 환불이 결제행을 비우면, 뒤이어 계좌로 나가야 하는 환불에서
     * 운영자가 이체할 근거가 사라진다.
     *
     * @effects refund_without_an_account_keeps_the_existing_payout_target
     *
     * @return void 반환값 없음
     */
    public function test_execute_keeps_the_existing_account_when_the_refund_carries_none(): void
    {
        $this->stubPgRefundSuccess();
        $order = $this->createPaidOrder();
        $order->payment->update([
            'refund_bank_code' => '088',
            'refund_bank_account' => '9999999999',
            'refund_bank_holder' => '김주문',
        ]);

        $this->service->execute($order, $this->createRefund($order), new AdjustmentResult(refundAmount: 5000));

        $payment = $order->fresh('payment')->payment;

        $this->assertSame('088', $payment->refund_bank_code);
        $this->assertSame('9999999999', $payment->refund_bank_account);
        $this->assertSame('김주문', $payment->refund_bank_holder);
    }

    /**
     * 세 값 중 하나라도 비면 반영하지 않는다.
     *
     * 은행만 바뀌고 계좌번호는 옛 값으로 남은 조합이 송금 대상이 되면, 어느 계좌로도 가지 않거나
     * 남의 계좌로 간다.
     *
     * @effects partially_filled_refund_account_is_ignored_entirely
     *
     * @return void 반환값 없음
     */
    public function test_execute_ignores_a_partially_filled_account_on_the_refund(): void
    {
        $this->stubPgRefundSuccess();
        $order = $this->createPaidOrder();
        $order->payment->update([
            'refund_bank_code' => '088',
            'refund_bank_account' => '9999999999',
            'refund_bank_holder' => '김주문',
        ]);

        $refund = $this->createRefund($order, ['refund_bank_code' => '004']);

        $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 5000));

        $this->assertSame('088', $order->fresh('payment')->payment->refund_bank_code);
    }

    /**
     * PG 환불이 성공하면 완료 도장과 거래 ID·환불 시각이 남는다.
     *
     * @effects pg_refund_marks_the_refund_completed_with_the_transaction_id_on_success
     *
     * @return void 반환값 없음
     */
    public function test_execute_pg_refund_marks_refund_completed_on_success(): void
    {
        $this->stubPgRefundSuccess('TXN-1234');
        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        $this->service->executePgRefund($order, $refund, new AdjustmentResult(refundAmount: 5000), '고객변심');

        $refund->refresh();
        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->refund_status);
        $this->assertSame('TXN-1234', $refund->pg_transaction_id);
        $this->assertNotNull($refund->refunded_at);
    }

    /**
     * 훅에 들어가기 전에 진행중으로 찍는다 — 훅 안에서 본 상태가 그 증거다.
     *
     * @effects pg_refund_marks_processing_before_calling_the_hook
     *
     * @return void 반환값 없음
     */
    public function test_execute_pg_refund_marks_processing_before_calling_hook(): void
    {
        $statusAtHookTime = null;
        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        HookManager::addFilter(
            'sirsoft-ecommerce.payment.refund',
            function ($default) use ($refund, &$statusAtHookTime) {
                $statusAtHookTime = $refund->fresh()->refund_status;

                return ['success' => true, 'transaction_id' => 'T', 'error_code' => null, 'error_message' => null];
            }
        );

        $this->service->executePgRefund($order, $refund, new AdjustmentResult(refundAmount: 5000), null);

        $this->assertSame(RefundStatusEnum::PROCESSING, $statusAtHookTime);
    }

    /**
     * PG 실패는 오류 코드·메시지를 남기고 예외로 올린다 — 완료로 찍지 않는다.
     *
     * @effects pg_refund_records_the_error_and_throws_on_failure
     *
     * @return void 반환값 없음
     */
    public function test_execute_pg_refund_throws_and_records_error_on_failure(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => false,
            'transaction_id' => null,
            'error_code' => 'E999',
            'error_message' => '한도 초과',
        ]);

        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        $this->expectException(OrderCancellationException::class);

        try {
            $this->service->executePgRefund($order, $refund, new AdjustmentResult(refundAmount: 5000), null);
        } finally {
            $refund->refresh();
            $this->assertSame('E999', $refund->pg_error_code);
            $this->assertSame('한도 초과', $refund->pg_error_message);
            $this->assertNotSame(RefundStatusEnum::COMPLETED, $refund->refund_status);
        }
    }

    /**
     * 리스너는 환불 레코드를 6번째 인자로 받는다 (멱등키의 근거).
     *
     * @effects pg_refund_hands_the_refund_record_to_the_listener
     *
     * @return void 반환값 없음
     */
    public function test_execute_pg_refund_passes_refund_record_as_sixth_argument(): void
    {
        $received = null;
        HookManager::addFilter(
            'sirsoft-ecommerce.payment.refund',
            function ($default, $order, $payment, $amount, $reason, $refund = null) use (&$received) {
                $received = $refund;

                return ['success' => true, 'transaction_id' => 'T', 'error_code' => null, 'error_message' => null];
            }
        );

        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        $this->service->executePgRefund($order, $refund, new AdjustmentResult(refundAmount: 5000), null);

        $this->assertInstanceOf(OrderRefund::class, $received);
        $this->assertSame($refund->id, $received->id);
    }

    /**
     * 5-파라미터 레거시 리스너도 그대로 호출되고 완료까지 간다.
     *
     * @effects pg_refund_stays_compatible_with_a_five_argument_listener
     *
     * @return void 반환값 없음
     */
    public function test_execute_pg_refund_is_backward_compatible_with_five_arg_listener(): void
    {
        $called = false;
        HookManager::addFilter(
            'sirsoft-ecommerce.payment.refund',
            function ($default, $order, $payment, $amount, $reason) use (&$called) {
                $called = true;

                return ['success' => true, 'transaction_id' => 'T', 'error_code' => null, 'error_message' => null];
            }
        );

        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        $this->service->executePgRefund($order, $refund, new AdjustmentResult(refundAmount: 5000), null);

        $this->assertTrue($called);
        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
    }

    /**
     * 리스너에 넘기는 금액은 주문 통화 금액이다 — 기준 통화 금액이 아니다.
     *
     * @effects pg_refund_sends_the_amount_in_the_order_currency
     *
     * @return void 반환값 없음
     */
    public function test_execute_pg_refund_sends_order_currency_amount_to_listener(): void
    {
        $receivedAmount = null;
        HookManager::addFilter(
            'sirsoft-ecommerce.payment.refund',
            function ($default, $order, $payment, $amount) use (&$receivedAmount) {
                $receivedAmount = $amount;

                return ['success' => true, 'transaction_id' => 'T', 'error_code' => null, 'error_message' => null];
            }
        );

        $order = $this->createPaidOrder(paymentOverrides: ['currency' => 'USD']);
        $refund = $this->createRefund($order);

        $this->service->executePgRefund(
            $order,
            $refund,
            new AdjustmentResult(refundAmount: 5000, mcRefundAmount: ['KRW' => 5000, 'USD' => 3.75]),
            null
        );

        $this->assertSame(3.75, $receivedAmount);
    }

    /**
     * 주문 통화 항목이 없으면 기준 통화 금액으로 떨어진다.
     *
     * @effects pg_refund_falls_back_to_the_base_amount_without_an_order_currency_entry
     *
     * @return void 반환값 없음
     */
    public function test_execute_pg_refund_falls_back_to_base_amount_when_order_currency_missing(): void
    {
        $receivedAmount = null;
        HookManager::addFilter(
            'sirsoft-ecommerce.payment.refund',
            function ($default, $order, $payment, $amount) use (&$receivedAmount) {
                $receivedAmount = $amount;

                return ['success' => true, 'transaction_id' => 'T', 'error_code' => null, 'error_message' => null];
            }
        );

        $order = $this->createPaidOrder(paymentOverrides: ['currency' => 'JPY']);
        $refund = $this->createRefund($order);

        $this->service->executePgRefund(
            $order,
            $refund,
            new AdjustmentResult(refundAmount: 5000, mcRefundAmount: ['KRW' => 5000]),
            null
        );

        $this->assertSame(5000.0, $receivedAmount);
    }

    /**
     * 통화 해석기는 주문 통화를 우선하고, 지정이 없으면 기준 금액을 낸다.
     *
     * @effects order_currency_refund_amount_prefers_the_order_currency_over_the_base_amount
     *
     * @return void 반환값 없음
     */
    public function test_resolve_order_currency_refund_amount_prefers_order_currency(): void
    {
        $result = new AdjustmentResult(refundAmount: 5000, mcRefundAmount: ['KRW' => 5000, 'USD' => 3.75]);

        $this->assertSame(3.75, $this->service->resolveOrderCurrencyRefundAmount($result, 'USD'));
        $this->assertSame(5000.0, $this->service->resolveOrderCurrencyRefundAmount($result, null));
    }

    // ================================================================
    // A. 추출 등가성 — 쿠폰 / 마일리지 / 재고 복원
    // ================================================================

    /**
     * 쿠폰 복원 훅은 복원 대상 발급 ID 를 그대로 싣는다.
     *
     * @effects coupon_restore_hook_carries_the_restored_issue_ids
     *
     * @return void 반환값 없음
     */
    public function test_restore_coupons_fires_hook_with_issue_ids(): void
    {
        $received = null;
        HookManager::addAction('sirsoft-ecommerce.coupon.restore', function ($order, $ids) use (&$received) {
            $received = $ids;
        });

        $order = $this->createPaidOrder();

        $this->service->restoreCoupons($order, new AdjustmentResult(restoredCouponIssueIds: [11, 22]));

        $this->assertSame([11, 22], $received);
    }

    /**
     * 복원할 쿠폰이 없으면 훅 자체를 쏘지 않는다.
     *
     * @effects coupon_restore_hook_is_not_fired_without_anything_to_restore
     *
     * @return void 반환값 없음
     */
    public function test_restore_coupons_does_not_fire_hook_when_nothing_to_restore(): void
    {
        $called = false;
        HookManager::addAction('sirsoft-ecommerce.coupon.restore', function () use (&$called) {
            $called = true;
        });

        $order = $this->createPaidOrder();

        $this->service->restoreCoupons($order, new AdjustmentResult(restoredCouponIssueIds: []));

        $this->assertFalse($called);
    }

    /**
     * 쿠폰 리스너 예외는 환불을 중단시키는 예외로 올라간다 — 삼키면 환불은 끝났는데 쿠폰은
     * 사용됨으로 남는다(D2). 호출부 트랜잭션이 전체를 되돌린다.
     *
     * @effects coupon_restore_listener_failure_aborts_the_refund
     *
     * @return void 반환값 없음
     */
    public function test_restore_coupons_listener_failure_aborts_with_a_refund_exception(): void
    {
        HookManager::addAction('sirsoft-ecommerce.coupon.restore', function () {
            throw new \RuntimeException('쿠폰 복원 실패');
        });

        $order = $this->createPaidOrder();

        try {
            $this->service->restoreCoupons($order, new AdjustmentResult(restoredCouponIssueIds: [1]));
            $this->fail('쿠폰 복원 실패가 호출부로 올라가야 한다');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.coupon_restore_failed', $e->getMessageKey());
        }
    }

    /**
     * 애초에 마일리지를 쓰지 않은 주문은 복원 훅을 타지 않는다.
     *
     * @effects mileage_restore_skips_when_the_order_never_deducted_points
     *
     * @return void 반환값 없음
     */
    public function test_restore_mileage_skips_when_order_never_deducted(): void
    {
        $called = false;
        HookManager::addAction('sirsoft-ecommerce.mileage.restore', function () use (&$called) {
            $called = true;
        });

        $order = $this->createPaidOrder(['is_mileage_deducted' => false]);

        $this->service->restoreMileage($order, new AdjustmentResult(refundPointsAmount: 1000));

        $this->assertFalse($called);
    }

    /**
     * 돌려줄 마일리지가 0 이면 훅을 쏘지 않는다.
     *
     * @effects mileage_restore_skips_when_there_are_no_points_to_refund
     *
     * @return void 반환값 없음
     */
    public function test_restore_mileage_skips_when_no_points_to_refund(): void
    {
        $called = false;
        HookManager::addAction('sirsoft-ecommerce.mileage.restore', function () use (&$called) {
            $called = true;
        });

        $order = $this->createPaidOrder(['is_mileage_deducted' => true]);

        $this->service->restoreMileage($order, new AdjustmentResult(refundPointsAmount: 0));

        $this->assertFalse($called);
    }

    /**
     * 마일리지 복원 훅은 금액과 함께 취소 ID 를 멱등 축으로 넘긴다.
     *
     * @effects mileage_restore_hook_carries_the_cancel_id_as_its_idempotency_axis
     *
     * @return void 반환값 없음
     */
    public function test_restore_mileage_fires_hook_with_cancel_id_as_idempotency_axis(): void
    {
        $received = [];
        HookManager::addAction(
            'sirsoft-ecommerce.mileage.restore',
            function ($amount, $order, $cancelId = null) use (&$received) {
                $received = ['amount' => $amount, 'cancel_id' => $cancelId];
            }
        );

        $order = $this->createPaidOrder(['is_mileage_deducted' => true, 'total_points_used_amount' => 3000]);
        $cancel = $this->createOrderCancel($order);

        $this->service->restoreMileage($order, new AdjustmentResult(refundPointsAmount: 1000), $cancel);

        $this->assertSame(1000.0, (float) $received['amount']);
        $this->assertSame($cancel->id, $received['cancel_id']);
    }

    /**
     * 마일리지 리스너 예외는 환불을 중단시키는 예외로 올라간다(D2) — 삼키면 카드는 취소되고
     * 마일리지는 돌아오지 않은 채 완료로 닫힌다.
     *
     * @effects mileage_restore_listener_failure_aborts_the_refund
     *
     * @return void 반환값 없음
     */
    public function test_restore_mileage_listener_failure_aborts_with_a_refund_exception(): void
    {
        HookManager::addAction('sirsoft-ecommerce.mileage.restore', function () {
            throw new \RuntimeException('마일리지 복원 실패');
        });

        $order = $this->createPaidOrder(['is_mileage_deducted' => true]);

        try {
            $this->service->restoreMileage($order, new AdjustmentResult(refundPointsAmount: 500));
            $this->fail('마일리지 반환 실패가 호출부로 올라가야 한다');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.mileage_refund_failed', $e->getMessageKey());
        }
    }

    /**
     * 환불 현금분 마일리지 지급은 그 환불에 묶인 무기한 lot 한 건을 남긴다(D1·Q2).
     *
     * @effects points_refund_credits_the_cash_amount_as_mileage
     *
     * @return void 반환값 없음
     */
    public function test_credit_mileage_records_a_non_expiring_lot_for_the_refund(): void
    {
        $order = $this->createPaidOrder(['user_id' => User::factory()->create()->id]);
        $refund = $this->createRefund($order, ['refund_method' => RefundMethodEnum::POINTS]);

        $this->service->creditMileage($order, $refund, 5000);

        $credits = MileageTransaction::query()
            ->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_CREDIT->value)
            ->get();
        $this->assertCount(1, $credits);
        $this->assertSame(5000.0, (float) $credits->first()->amount);
        $this->assertNull($credits->first()->expires_at);
    }

    /**
     * 지급할 현금이 0 이면 훅을 쏘지 않고 원장에도 아무것도 남지 않는다.
     *
     * @effects credit_mileage_skips_a_zero_amount
     *
     * @return void 반환값 없음
     */
    public function test_credit_mileage_skips_a_zero_amount(): void
    {
        $called = false;
        HookManager::addAction('sirsoft-ecommerce.mileage.refund_credit', function () use (&$called) {
            $called = true;
        });

        $order = $this->createPaidOrder(['user_id' => User::factory()->create()->id]);
        $refund = $this->createRefund($order, ['refund_method' => RefundMethodEnum::POINTS]);

        $this->service->creditMileage($order, $refund, 0);

        $this->assertFalse($called);
        $this->assertSame(0, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());
    }

    /**
     * 비회원 주문에는 마일리지를 지급할 원장이 없다 — 조용히 넘기면 돈이 사라진다.
     *
     * @effects credit_mileage_rejects_a_guest_order
     *
     * @return void 반환값 없음
     */
    public function test_credit_mileage_rejects_a_guest_order(): void
    {
        $order = $this->createPaidOrder(['user_id' => null]);
        $refund = $this->createRefund($order, ['refund_method' => RefundMethodEnum::POINTS]);

        try {
            $this->service->creditMileage($order, $refund, 5000);
            $this->fail('비회원 주문의 마일리지 지급은 거절돼야 한다');
        } catch (OrderClaimException $e) {
            $this->assertSame(__('sirsoft-ecommerce::exceptions.claim_refund_points_requires_member'), $e->getMessage());
        }
    }

    /**
     * 지급 리스너가 던지면 환불을 중단시키는 예외로 바뀌어 올라간다(D2).
     *
     * @effects credit_mileage_listener_failure_aborts_the_refund
     *
     * @return void 반환값 없음
     */
    public function test_credit_mileage_listener_failure_aborts_with_a_refund_exception(): void
    {
        HookManager::addAction('sirsoft-ecommerce.mileage.refund_credit', function () {
            throw new \RuntimeException('지급 실패');
        }, 5);

        $order = $this->createPaidOrder(['user_id' => User::factory()->create()->id]);
        $refund = $this->createRefund($order, ['refund_method' => RefundMethodEnum::POINTS]);

        try {
            $this->service->creditMileage($order, $refund, 5000);
            $this->fail('지급 실패가 호출부로 올라가야 한다');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.mileage_refund_failed', $e->getMessageKey());
        }
    }

    /**
     * 지급 훅에 응답한 리스너가 없어 원장이 남지 않으면 실패로 본다 — 「완료」인데 돈이 없는
     * refund 274 의 재발을 원장 확인으로 막는다(fail-closed).
     *
     * @effects credit_mileage_fails_closed_when_no_ledger_row_appears
     *
     * @return void 반환값 없음
     */
    public function test_credit_mileage_fails_closed_when_no_ledger_row_appears(): void
    {
        HookManager::clearAction('sirsoft-ecommerce.mileage.refund_credit');

        $order = $this->createPaidOrder(['user_id' => User::factory()->create()->id]);
        $refund = $this->createRefund($order, ['refund_method' => RefundMethodEnum::POINTS]);

        try {
            $this->service->creditMileage($order, $refund, 5000);
            $this->fail('원장 기록이 없으면 실패로 올라가야 한다');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.mileage_refund_failed', $e->getMessageKey());
        }
    }

    /**
     * 재고 복원 설정이 켜져 있으면 취소 수량만큼 되돌린다.
     *
     * @effects stock_restore_returns_the_cancelled_quantity_when_the_setting_is_on
     *
     * @return void 반환값 없음
     */
    public function test_restore_stock_returns_quantity_when_setting_enabled(): void
    {
        $this->setStockRestoreSetting(true);
        [$order, $option, $productOption] = $this->createOrderWithDeductedOption(stock: 90, quantity: 2);

        $this->service->restoreStock($order, [
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]);

        $this->assertSame(92, (int) $productOption->fresh()->stock_quantity);
    }

    /**
     * 설정이 꺼져 있으면 재고를 건드리지 않는다.
     *
     * @effects stock_restore_is_skipped_entirely_when_the_setting_is_off
     *
     * @return void 반환값 없음
     */
    public function test_restore_stock_skips_entirely_when_setting_disabled(): void
    {
        $this->setStockRestoreSetting(false);
        [$order, $option, $productOption] = $this->createOrderWithDeductedOption(stock: 90, quantity: 2);

        $this->service->restoreStock($order, [
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]);

        $this->assertSame(90, (int) $productOption->fresh()->stock_quantity);
    }

    /**
     * 차감된 적 없는 옵션은 되돌릴 것이 없다 — 재고가 늘지 않는다.
     *
     * @effects stock_restore_skips_options_that_were_never_deducted
     *
     * @return void 반환값 없음
     */
    public function test_restore_stock_skips_options_that_were_never_deducted(): void
    {
        $this->setStockRestoreSetting(true);
        [$order, $option, $productOption] = $this->createOrderWithDeductedOption(stock: 90, quantity: 2);
        $order->options()->update(['is_stock_deducted' => false]);
        $order->load('options');

        $this->service->restoreStock($order, [
            ['order_option_id' => $option->id, 'cancel_quantity' => 2],
        ]);

        $this->assertSame(90, (int) $productOption->fresh()->stock_quantity);
    }

    /**
     * 클레임 환불신청서의 환불수단은 돌려줄 현금이 남지 않은 회원 주문에서만 「마일리지」다(결함 4).
     *
     * 고객은 마일리지 결제수단을 고를 수 없어 전액 마일리지 주문도 무통장·카드 수단을 단다 — 결제수단만
     * 보면 계좌·PG 환불로 기록되고, 계좌를 묻거나 승인에서 멈춘다.
     *
     * @return void 반환값 없음
     *
     * @effects refund_method_for_claim_is_points_only_when_no_cash_remains
     */
    public function test_refund_method_for_claim_is_points_only_when_no_cash_remains(): void
    {
        $member = User::factory()->create()->id;
        $zeroCash = ['user_id' => $member, 'total_paid_amount' => 0, 'total_points_used_amount' => 20000];
        $zeroPayment = ['payment_method' => PaymentMethodEnum::DBANK->value, 'paid_amount_base' => 0, 'paid_amount_local' => 0];

        $this->assertSame(
            RefundMethodEnum::POINTS,
            $this->service->refundMethodForClaim($this->createPaidOrder($zeroCash, $zeroPayment)),
        );
        $this->assertSame(
            RefundMethodEnum::BANK,
            $this->service->refundMethodForClaim($this->createPaidOrder(['user_id' => $member], ['payment_method' => PaymentMethodEnum::DBANK->value])),
        );
        $this->assertSame(
            RefundMethodEnum::BANK,
            $this->service->refundMethodForClaim($this->createPaidOrder(['user_id' => null] + $zeroCash, $zeroPayment)),
        );
    }

    /**
     * 재고 복원 실패는 환불을 중단시키는 예외로 올라간다(F2) — 삼키면 환불은 끝났는데 재고가
     * 돌아오지 않는다.
     *
     * @effects stock_restore_failure_aborts_the_refund
     *
     * @return void 반환값 없음
     */
    public function test_restore_stock_failure_aborts_with_a_refund_exception(): void
    {
        $this->setStockRestoreSetting(true);
        [$order, $option] = $this->createOrderWithDeductedOption(stock: 90, quantity: 2);

        $this->mock(StockService::class, function ($mock) {
            $mock->shouldReceive('restoreOptionStockForOrderOption')->andThrow(new \RuntimeException('재고 잠금 실패'));
        });
        $this->service = app(RefundExecutionService::class);

        try {
            $this->service->restoreStock($order, [
                ['order_option_id' => $option->id, 'cancel_quantity' => 2],
            ]);
            $this->fail('재고 복원 실패가 호출부로 올라가야 한다');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.stock_restore_failed', $e->getMessageKey());
        }
    }

    /**
     * 장부 준비(prepareLedger)는 트랜잭션 밖에서 부르면 거절한다 — PG 앞에서 저장하는 누적은
     * 트랜잭션 롤백만이 되돌릴 수 있다(P-D5).
     *
     * @effects prepare_ledger_refuses_to_run_outside_a_transaction
     *
     * @return void 반환값 없음
     */
    public function test_prepare_ledger_refuses_to_run_outside_a_transaction(): void
    {
        $service = $this->partialMock(RefundExecutionService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('inTransaction')->andReturn(false);
        });

        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        // 메시지까지 본다 — 메서드가 없을 때 Mockery 가 던지는 BadMethodCallException 도 LogicException 이다.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('트랜잭션 안에서만');
        $service->prepareLedger($order, $refund, new AdjustmentResult(refundAmount: 5000), true);
    }

    // ================================================================
    // A. 추출 등가성 — 현금영수증
    // ================================================================

    /**
     * 현금영수증 동기화는 주문과 사유를 그대로 전용 서비스에 넘긴다.
     *
     * @effects cash_receipt_sync_is_delegated_with_the_order_and_the_reason
     *
     * @return void 반환값 없음
     */
    public function test_sync_cash_receipt_delegates_to_cash_receipt_service(): void
    {
        $order = $this->createPaidOrder();

        $this->mock(CashReceiptService::class, function ($mock) use ($order) {
            $mock->shouldReceive('syncFromOrder')->once()->withArgs(
                fn ($given, $reason) => $given->id === $order->id && $reason === '고객변심'
            );
        });

        app(RefundExecutionService::class)->syncCashReceipt($order, '고객변심');
    }

    /**
     * 국세청·프로바이더 통신 실패는 호출부로 전파되지 않는다.
     *
     * @effects cash_receipt_provider_failure_does_not_reach_the_caller
     *
     * @return void 반환값 없음
     */
    public function test_sync_cash_receipt_swallows_provider_failure(): void
    {
        $order = $this->createPaidOrder();

        $this->mock(CashReceiptService::class, function ($mock) {
            $mock->shouldReceive('syncFromOrder')->andThrow(new \RuntimeException('국세청 통신 실패'));
        });

        app(RefundExecutionService::class)->syncCashReceipt($order, null);

        $this->assertTrue(true, '프로바이더 예외가 호출부로 전파되지 않는다');
    }

    // ================================================================
    // B. 환불 누적 정정 (§4-3-1)
    // ================================================================

    /**
     * 실행은 주문의 환불 누적을 쌓는다.
     *
     * @effects execute_accumulates_the_orders_total_refunded_amount
     *
     * @return void 반환값 없음
     */
    public function test_execute_accumulates_total_refunded_amount(): void
    {
        $this->stubPgRefundSuccess();
        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 5000));

        $this->assertSame(5000.0, (float) $order->fresh()->total_refunded_amount);
    }

    /**
     * 마일리지 환불도 주문의 마일리지 환불 누적으로 쌓인다.
     *
     * @effects execute_accumulates_the_orders_total_refunded_points_amount
     *
     * @return void 반환값 없음
     */
    public function test_execute_accumulates_total_refunded_points_amount(): void
    {
        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order, ['refund_amount' => 0, 'refund_points_amount' => 1200]);

        $this->service->execute($order, $refund, new AdjustmentResult(refundPointsAmount: 1200));

        $this->assertSame(1200.0, (float) $order->fresh()->total_refunded_points_amount);
    }

    /**
     * 이미 쌓인 값에 더한다 — 덮어쓰지 않는다.
     *
     * @effects execute_adds_to_the_values_already_accumulated
     *
     * @return void 반환값 없음
     */
    public function test_execute_adds_to_existing_accumulated_values(): void
    {
        $this->stubPgRefundSuccess();
        $order = $this->createPaidOrder([
            'total_refunded_amount' => 3000,
            'total_refunded_points_amount' => 500,
        ]);
        $refund = $this->createRefund($order);

        $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 2000, refundPointsAmount: 700));

        $fresh = $order->fresh();
        $this->assertSame(5000.0, (float) $fresh->total_refunded_amount);
        $this->assertSame(1200.0, (float) $fresh->total_refunded_points_amount);
    }

    /**
     * 결제행의 취소 누적도 함께 쌓인다 — PG 환불가능액의 근거다.
     *
     * @effects execute_accumulates_the_payment_rows_cancelled_amount
     *
     * @return void 반환값 없음
     */
    public function test_execute_accumulates_payment_cancelled_amount(): void
    {
        $this->stubPgRefundSuccess();
        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 5000));

        $this->assertSame(5000.0, (float) $order->payment->fresh()->cancelled_amount);
    }

    /**
     * 다통화 취소 누적은 통화별로 각각 쌓인다.
     *
     * @effects execute_accumulates_the_multi_currency_cancelled_amount_per_currency
     *
     * @return void 반환값 없음
     */
    public function test_execute_accumulates_mc_cancelled_amount_per_currency(): void
    {
        $this->stubPgRefundSuccess();
        $order = $this->createPaidOrder(paymentOverrides: ['mc_cancelled_amount' => ['KRW' => 1000, 'USD' => 0.75]]);
        $refund = $this->createRefund($order);

        $this->service->execute(
            $order,
            $refund,
            new AdjustmentResult(refundAmount: 5000, mcRefundAmount: ['KRW' => 5000, 'USD' => 3.75])
        );

        $mc = $order->payment->fresh()->mc_cancelled_amount;
        $this->assertSame(6000.0, (float) $mc['KRW']);
        $this->assertSame(4.5, (float) $mc['USD']);
    }

    /**
     * 두 번 실행하면 두 번 다 쌓인다 (회차마다 누적).
     *
     * @effects execute_accumulates_on_every_round_not_just_the_first
     *
     * @return void 반환값 없음
     */
    public function test_execute_twice_accumulates_both_times(): void
    {
        $this->stubPgRefundSuccess();
        $order = $this->createPaidOrder();

        $this->service->execute($order, $this->createRefund($order), new AdjustmentResult(refundAmount: 4000));
        $order->refresh()->load('payment');
        $this->service->execute($order, $this->createRefund($order), new AdjustmentResult(refundAmount: 3000));

        $fresh = $order->fresh();
        $this->assertSame(7000.0, (float) $fresh->total_refunded_amount);
        $this->assertSame(7000.0, (float) $fresh->payment->cancelled_amount);
    }

    /**
     * 취소 고유 필드(결제상태·취소시각·취소이력)는 환불 경로가 건드리지 않는다.
     *
     * @effects execute_leaves_the_cancel_only_payment_fields_untouched
     *
     * @return void 반환값 없음
     */
    public function test_execute_does_not_touch_cancel_specific_payment_fields(): void
    {
        $this->stubPgRefundSuccess();
        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 5000));

        $payment = $order->payment->fresh();
        $this->assertSame(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertNull($payment->cancelled_at);
        $this->assertEmpty($payment->cancel_history ?? []);
    }

    /**
     * 결제행이 없는 주문도 주문 축 누적은 쌓인다.
     *
     * @effects execute_accumulates_order_totals_even_without_a_payment_row
     *
     * @return void 반환값 없음
     */
    public function test_execute_accumulates_order_totals_when_order_has_no_payment_row(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_paid_amount' => 10000,
            'total_refunded_amount' => 0,
            'total_refunded_points_amount' => 0,
        ]);
        $refund = $this->createRefund($order);

        $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 4000));

        $this->assertSame(4000.0, (float) $order->fresh()->total_refunded_amount);
    }

    /**
     * 무통장입금은 PG 훅에 들어가지 않지만 주문 누적은 쌓인다.
     *
     * @effects execute_skips_the_pg_hook_for_a_non_pg_payment_method_but_still_accumulates
     *
     * @return void 반환값 없음
     */
    public function test_execute_skips_pg_hook_for_non_pg_payment_method_but_still_accumulates(): void
    {
        $called = false;
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default) use (&$called) {
            $called = true;

            return $default;
        });

        $order = $this->createPaidOrder(paymentOverrides: ['payment_method' => PaymentMethodEnum::DBANK->value]);
        // 무통장입금의 환불수단은 계좌 환불이다 — PG 로 적힌 신청서는 실행이 422 로 막는다(P-D4, PgRefundProviderGuardTest).
        $refund = $this->createRefund($order, ['refund_method' => RefundMethodEnum::BANK]);

        $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 5000));

        $this->assertFalse($called, '무통장입금은 PG 환불 훅에 진입하지 않는다');
        $this->assertSame(5000.0, (float) $order->fresh()->total_refunded_amount);
    }

    /**
     * 호출부가 왕복을 건너뛰라고 하면 훅을 부르지 않는다.
     *
     * @effects execute_skips_the_pg_hook_when_the_caller_asks_it_not_to
     *
     * @return void 반환값 없음
     */
    public function test_execute_skips_pg_hook_when_execute_pg_is_false(): void
    {
        $called = false;
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default) use (&$called) {
            $called = true;

            return $default;
        });

        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 5000), null, false);

        $this->assertFalse($called);
        $this->assertSame(5000.0, (float) $order->fresh()->total_refunded_amount);
    }

    /**
     * PG 를 부르지 않은 환불은 **결제행의 취소 누적을 건드리지 않는다.** (S1-D24)
     *
     * `cancelled_amount` 는 「그 결제수단에서 실제로 취소된 금액」이다 — PG 환불가능액을
     * 되짚는 근거이자 대사의 기준이다. 그런데 카드 주문에 `계좌이체` 환불수단을 지정하면
     * PG 왕복은 건너뛰면서(bank 이므로 당연) 이 누적만 깎였다. 카드사 잔액은 그대로인데
     * 장부의 환불가능액만 줄어, 이후 정상적인 카드 환불이 한도에 먼저 걸린다.
     *
     * 실측(#599 세션1 · 1-P): 결제 58,000 카드 주문에 bank 수단으로 14,000 을 실행하니
     * 200 으로 통과하고 `cancelled_amount` 가 14,000 — 환불가능액이 58,000 → 44,000 으로
     * 줄었다. 토스 잔액은 58,000 그대로다.
     *
     * 방향은 운영 결정(2026-09-16): **허용하되 장부는 건드리지 않는다.** 운영자가 카드 주문을
     * 손으로 계좌 송금하는 운영은 막지 않는다 — 다만 그 송금은 카드 취소가 아니므로 카드
     * 결제행의 취소 누적이 아니다. 주문 축 누적(`total_refunded_amount`)은 그대로 쌓인다.
     *
     * @scenario refund_method=bank, pg_roundtrip=skipped_not_applicable, restore_target=none
     *
     * @effects non_pg_refund_leaves_payment_cancelled_amount_untouched
     *
     * @return void 반환값 없음
     */
    public function test_execute_does_not_touch_payment_cancelled_amount_without_pg_call(): void
    {
        $order = $this->createPaidOrder(paymentOverrides: ['cancelled_amount' => 0]);
        $refund = $this->createRefund($order, ['refund_method' => RefundMethodEnum::BANK]);

        // 실측 1-P 과 같은 호출 — 호출부(OrderRefundRequestService)는 수단이 PG 가 아니고
        // 바깥 집행도 아니므로 장부를 쌓지 않도록 부른다.
        $this->service->execute(
            $order,
            $refund,
            new AdjustmentResult(refundAmount: 14000),
            null,
            executePg: false,
            recordPaymentLedger: false,
        );

        $this->assertSame(
            0.0,
            (float) $order->payment->fresh()->cancelled_amount,
            'PG 를 부르지 않았는데 카드 결제행의 취소 누적이 깎이면 환불가능액이 실제와 어긋난다.'
        );

        // 주문 축 누적은 그대로다 — 돈은 실제로 나갔고 그 사실은 기록되어야 한다.
        $this->assertSame(14000.0, (float) $order->fresh()->total_refunded_amount);
    }

    /**
     * 바깥에서 PG 환불을 이미 끝낸 건은 **장부에 그대로 남는다.** (S1-D24 경계)
     *
     * `executePg=false` 는 두 가지를 함께 뜻한다 — ① 바깥에서 이미 집행(가상계좌 환불 화면 ·
     * `cancelPg: false`)과 ② 애초에 PG 로 나가지 않는 환불. S1-D24 가 막아야 하는 것은 ② 뿐이다.
     * 둘을 묶어 막으면 **정상 취소의 취소 누적이 사라진다** — 이 테스트가 그 과잉 구현을 잡는다.
     *
     * @scenario refund_method=pg, pg_roundtrip=skipped_settled_outside, restore_target=none
     *
     * @effects externally_settled_pg_refund_still_records_ledger
     *
     * @return void 반환값 없음
     */
    public function test_execute_records_ledger_when_pg_was_settled_outside(): void
    {
        $order = $this->createPaidOrder(paymentOverrides: ['cancelled_amount' => 0]);
        $refund = $this->createRefund($order, ['refund_method' => RefundMethodEnum::PG]);

        // 수단은 PG 인데 코어는 왕복을 건너뛴다 — 이미 바깥에서 취소됐기 때문이다.
        $this->service->execute(
            $order,
            $refund,
            new AdjustmentResult(refundAmount: 5000),
            null,
            executePg: false,
            recordPaymentLedger: true,
        );

        $this->assertSame(
            5000.0,
            (float) $order->payment->fresh()->cancelled_amount,
            '실제로 취소된 금액이 장부에서 빠지면 이후 환불가능액이 실제보다 크게 남는다.'
        );
    }

    /**
     * 마일리지만 돌려주는 건은 PG 훅에 진입하지 않는다.
     *
     * @effects execute_skips_the_pg_hook_when_only_points_are_refunded
     *
     * @return void 반환값 없음
     */
    public function test_execute_skips_pg_hook_when_refund_amount_is_zero(): void
    {
        $called = false;
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default) use (&$called) {
            $called = true;

            return $default;
        });

        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order, ['refund_amount' => 0, 'refund_points_amount' => 800]);

        $this->service->execute($order, $refund, new AdjustmentResult(refundPointsAmount: 800));

        $this->assertFalse($called, '마일리지만 환불하는 건은 PG 훅에 진입하지 않는다');
        $this->assertSame(800.0, (float) $order->fresh()->total_refunded_points_amount);
    }

    /**
     * PG 실패는 예외로 올라가고 누적은 한 푼도 쌓이지 않는다.
     *
     * @effects execute_does_not_accumulate_anything_when_the_pg_call_fails
     *
     * @return void 반환값 없음
     */
    public function test_execute_propagates_pg_failure_before_accumulating(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => false,
            'transaction_id' => null,
            'error_code' => 'E1',
            'error_message' => '실패',
        ]);

        $order = $this->createPaidOrder();
        $refund = $this->createRefund($order);

        try {
            $this->service->execute($order, $refund, new AdjustmentResult(refundAmount: 5000));
            $this->fail('PG 실패 시 예외가 전파되어야 한다');
        } catch (OrderCancellationException $e) {
            // PG 가 실패하면 PG 앞에서 저장한 누적이 트랜잭션째 롤백된다(P-D5) — 실패한 환불이
            // 잔여 한도를 깎으면 안 된다.
            $this->assertSame(0.0, (float) $order->fresh()->total_refunded_amount);
            $this->assertSame(0.0, (float) $order->payment->fresh()->cancelled_amount);
        }
    }

    /**
     * 취소 누적 갱신 규칙은 취소 경로와 하나다 — 취소 고유 필드는 그 규칙에 없다.
     *
     * @effects cancelled_amount_updates_share_one_rule_with_the_cancellation_path
     *
     * @return void 반환값 없음
     */
    public function test_build_cancelled_amount_updates_shares_one_rule_with_cancel_path(): void
    {
        $order = $this->createPaidOrder(paymentOverrides: [
            'cancelled_amount' => 1000,
            'mc_cancelled_amount' => ['KRW' => 1000],
        ]);

        $updates = $this->service->buildCancelledAmountUpdates(
            $order->payment,
            new AdjustmentResult(refundAmount: 2500, mcRefundAmount: ['KRW' => 2500])
        );

        $this->assertSame(3500.0, (float) $updates['cancelled_amount']);
        $this->assertSame(3500.0, (float) $updates['mc_cancelled_amount']['KRW']);
        $this->assertArrayNotHasKey('payment_status', $updates, '취소 고유 필드는 공용 규칙에 없다');
    }

    // ================================================================
    // C. ② 축(잔여 결제 한도)이 실제로 좁아지는가
    // ================================================================

    /**
     * 쌓인 환불 누적은 다음 환불의 잔여 한도를 실제로 좁힌다.
     *
     * @effects accumulated_refunds_narrow_the_remaining_refundable_capacity
     *
     * @return void 반환값 없음
     */
    public function test_accumulated_refund_narrows_remaining_payment_capacity(): void
    {
        $policy = $this->createFreeShippingPolicy();
        [$productA, $optionA] = $this->createProductWithOption(price: 10000, shippingPolicy: $policy);
        [$productB, $optionB] = $this->createProductWithOption(price: 10000, shippingPolicy: $policy);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]);

        // ── 대조군: 선행 환불이 없으면 대상 아이템 금액 전액이 환불 가능하다.
        $control = $this->createOrderFromCalculation($input);
        $controlItems = [[
            'order_option_id' => $control->options->first()->id,
            'cancel_quantity' => 1,
        ]];
        $controlResult = app(OrderAdjustmentService::class)
            ->calculate($control, CancellationAdjustment::fromArray($controlItems));

        $this->assertSame(10000.0, (float) $controlResult->refundAmount);

        // ── 실험군: 같은 주문에 15,000 을 먼저 환불해 두면 잔여 한도가 5,000 으로 좁아진다.
        $subject = $this->createOrderFromCalculation($input);
        $this->service->execute(
            $subject,
            $this->createRefund($subject, ['refund_amount' => 15000]),
            new AdjustmentResult(refundAmount: 15000),
            null,
            false
        );
        $subject->refresh()->load(['options', 'shippings', 'payment']);

        $this->assertSame(15000.0, (float) $subject->total_refunded_amount);

        $subjectItems = [[
            'order_option_id' => $subject->options->first()->id,
            'cancel_quantity' => 1,
        ]];
        $subjectResult = app(OrderAdjustmentService::class)
            ->calculate($subject, CancellationAdjustment::fromArray($subjectItems));

        $this->assertSame(5000.0, (float) $subjectResult->refundAmount);
    }

    // ================================================================
    // C. 취소 경로와의 경계 — 누적 지점은 하나다
    // ================================================================

    /**
     * 누적 지점은 하나다 — 취소 경로도 **환불 누적으로** 장부를 말한다.
     *
     * 예전에는 취소가 `total_paid_amount` 를 재계산값으로 덮어써 잔여 한도를 좁혔고, 그래서
     * `total_refunded_amount` 를 누적하면 같은 금액이 두 번 빠졌다. 지금은 취소·환불이 클레임
     * 접수 한 경로로 모이면서 원 결제금액을 깎지 않고 누적이 잔여를 말한다(§12-B B1) —
     * 10만원 주문의 6만원 환불 뒤 잔여가 `4만 − 6만 = 0` 이 되어 2차 부분환불이 0원으로
     * 실행되던 것이 그 이중 차감이었다.
     *
     * 여기서 잠그는 것은 **두 축을 동시에 건드리지 않는다**는 것이다: 원금은 그대로, 잔여는
     * `total_paid_amount − total_refunded_amount` 로 읽는다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects cancellation_path_reports_remaining_capacity_through_refund_accumulation
     *
     * @return void 반환값 없음
     */
    public function test_cancellation_path_reports_remaining_capacity_through_refund_accumulation(): void
    {
        $this->stubPgRefundSuccess();

        $policy = $this->createFreeShippingPolicy();
        [$product, $option] = $this->createProductWithOption(price: 10000, shippingPolicy: $policy);
        $order = $this->createOrderFromCalculation(new CalculationInput(items: [
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
        ]));

        app(OrderCancellationService::class)->cancelOrderOptions(
            $order,
            [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
            '고객변심'
        );

        $fresh = $order->fresh();

        // 원금은 그대로 두고 누적으로 말한다 — 두 축을 동시에 깎으면 같은 금액이 두 번 빠진다.
        $this->assertSame(20000.0, (float) $fresh->total_paid_amount);
        $this->assertSame(10000.0, (float) $fresh->total_refunded_amount);
        $this->assertSame(10000.0, (float) $fresh->total_cancelled_amount);

        // 잔여 환불 한도는 두 값의 차다.
        $this->assertSame(
            10000.0,
            (float) $fresh->total_paid_amount - (float) $fresh->total_refunded_amount
        );
    }

    /**
     * 취소 경로를 탄 환불도 결제행의 취소 누적을 쌓고 부분취소 상태를 찍는다.
     *
     * @effects cancellation_path_also_accumulates_the_payment_rows_cancelled_amount
     *
     * @return void 반환값 없음
     */
    public function test_cancellation_path_still_accumulates_payment_cancelled_amount(): void
    {
        $this->stubPgRefundSuccess();

        $policy = $this->createFreeShippingPolicy();
        [$product, $option] = $this->createProductWithOption(price: 10000, shippingPolicy: $policy);
        $order = $this->createOrderFromCalculation(new CalculationInput(items: [
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
        ]));

        app(OrderCancellationService::class)->cancelOrderOptions(
            $order,
            [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
            '고객변심'
        );

        $payment = $order->fresh()->payment;
        $this->assertSame(10000.0, (float) $payment->cancelled_amount);
        $this->assertSame(PaymentStatusEnum::PARTIAL_CANCELLED, $payment->payment_status);
    }

    // ================================================================
    // 헬퍼 (뒤쪽에 두어 테스트 흐름을 가리지 않는다)
    // ================================================================

    /**
     * 재고가 차감된 옵션 1건이 딸린 주문을 만듭니다.
     *
     * @param  int  $stock  현재 재고
     * @param  int  $quantity  주문 수량
     * @return array{0: Order, 1: OrderOption, 2: ProductOption} 주문·주문옵션·상품옵션
     */
    protected function createOrderWithDeductedOption(int $stock, int $quantity): array
    {
        [$product, $productOption] = $this->createProductWithOption(price: 10000, stock: $stock);
        $order = $this->createPaidOrder();

        $orderOption = OrderOption::factory()->forOrder($order)->create([
            'product_id' => $product->id,
            'product_option_id' => $productOption->id,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => $quantity,
            'unit_price' => 10000,
            'is_stock_deducted' => true,
        ]);

        return [$order->load('options'), $orderOption, $productOption];
    }

    /**
     * 취소 레코드를 만듭니다.
     *
     * @param  Order  $order  주문
     * @return OrderCancel 생성된 취소 레코드
     */
    protected function createOrderCancel(Order $order): OrderCancel
    {
        return OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => '고객변심',
            'items_snapshot' => [],
        ]);
    }
}
