<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Plugins\Sirsoft\PayNicepayments\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 나이스페이먼츠 결제 플러그인 — 배송비·쿠폰 적용 취소 통합 테스트
 *
 * ShippingAndPromotionCancellationTest.php 가 mockPgRefundSuccess() 로 훅을 가짜로 대체한 것과 달리,
 * 이 테스트는 실제 PaymentRefundListener 를 등록하고 NicePaymentsApiService 만 mock 으로 교체한다.
 * 경로 전체를 검증한다:
 *   admin cancel request → OrderCancellationService → PaymentRefundListener → NicePaymentsApiService
 *
 * Mock 규칙:
 *   - 호출 횟수/인자 검증이 필요한 테스트: $mock->expects($this->once())->method(...)->willReturn(...)
 *   - 성공만 필요한 테스트: $this->stubNicePayCancelSuccess()
 *   - 호출되면 안 되는 테스트: $mock->expects($this->never())->method(...)
 *   ※ 같은 메서드에 method()와 expects()를 동시에 걸면 PHPUnit이 이중 호출로 인식하므로 혼용 금지.
 */
class ShippingAndCouponCancellationTest extends PluginTestCase
{
    private const TID = 'TID_NICEPAY_TEST_001';

    private const CANCEL_SUCCESS = [
        'ResultCode' => '2001',
        'ResultMsg'  => '취소 성공',
        'TID'        => 'TID_CANCEL_NICEPAY',
    ];

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // HookArgumentSerializer PHP 8.1 enum 직렬화 버그 우회
        Queue::fake();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->createCancelSequences();

        // 테스트 DB 시더가 plugin 레코드를 등록하지 않아
        // PluginManager::registerPluginHookListeners() 의 active 가드에서 skip 되므로
        // 테스트에서 직접 listener 를 등록한다. HookListenerRegistrar 가 idempotent 이라
        // 운영 환경에서 listener 가 이미 등록되어 있으면 중복 호출되지 않음.
        HookListenerRegistrar::register(PaymentRefundListener::class, 'sirsoft-pay_nicepayments');
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        // HookListenerRegistrar 의 static $registered 캐시도 비워야
        // 다음 테스트의 setUp 에서 register() 가 실제 등록을 수행한다.
        HookListenerRegistrar::clear();
        parent::tearDown();
    }

    // ────────────────────────────────────────────
    // Mock 헬퍼
    // ────────────────────────────────────────────

    /**
     * cancelPayment 를 성공 stub 으로만 설정합니다 (호출 횟수 검증 없음).
     * 호출 횟수·인자를 검증해야 하는 테스트에서는 이 메서드 대신 인라인 mock 을 사용하세요.
     */
    private function stubNicePayCancelSuccess(): void
    {
        $mock = $this->createMock(NicePaymentsApiService::class);
        $mock->method('cancelPayment')->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(NicePaymentsApiService::class, $mock);
    }

    /**
     * cancelPayment 가 정확히 1회 호출되는 mock 을 생성합니다.
     * 반환된 mock 에 with() 제약을 추가할 수 있습니다.
     */
    private function expectNicePayCancelOnce(): \PHPUnit\Framework\MockObject\MockObject
    {
        $mock = $this->createMock(NicePaymentsApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(NicePaymentsApiService::class, $mock);

        return $mock;
    }

    /**
     * cancelPayment 가 호출되지 않아야 하는 mock 을 생성합니다.
     */
    private function expectNicePayCancelNever(): void
    {
        $mock = $this->createMock(NicePaymentsApiService::class);
        $mock->expects($this->never())->method('cancelPayment');
        $this->app->instance(NicePaymentsApiService::class, $mock);
    }

    /**
     * cancelPayment 에서 예외를 던지는 mock 을 생성합니다.
     */
    private function stubNicePayCancelFailure(string $errorMsg = 'NicePayments 취소 실패'): void
    {
        $mock = $this->createMock(NicePaymentsApiService::class);
        $mock->method('cancelPayment')->willThrowException(new \Exception($errorMsg));
        $this->app->instance(NicePaymentsApiService::class, $mock);
    }

    /**
     * 쿠폰 복원 훅을 등록합니다.
     */
    private function mockCouponRestore(): void
    {
        HookManager::addAction(
            'sirsoft-ecommerce.coupon.restore',
            function (Order $order, array $couponIssueIds) {
                CouponIssue::whereIn('id', $couponIssueIds)->update([
                    'status'   => CouponIssueRecordStatus::AVAILABLE->value,
                    'used_at'  => null,
                    'order_id' => null,
                ]);
            },
            10
        );
    }

    // ────────────────────────────────────────────
    // 주문 생성 헬퍼
    // ────────────────────────────────────────────

    private function createCancelSequences(): void
    {
        foreach ([SequenceType::CANCEL, SequenceType::REFUND] as $type) {
            $cfg = $type->getDefaultConfig();
            Sequence::firstOrCreate(
                ['type' => $type->value],
                [
                    'algorithm'     => $cfg['algorithm']->value,
                    'prefix'        => $cfg['prefix'],
                    'current_value' => 0,
                    'increment'     => 1,
                    'min_value'     => 1,
                    'max_value'     => $cfg['max_value'],
                    'cycle'         => false,
                    'pad_length'    => $cfg['pad_length'],
                ]
            );
        }
    }

    /**
     * 나이스페이먼츠 결제가 완료된 배송비 포함 주문을 생성합니다.
     *
     * @return array{order: Order, options: OrderOption[], payment: OrderPayment, shipping: OrderShipping}
     */
    private function createNicepayOrderWithShipping(
        int $optionCount = 1,
        int $unitPrice = 20000,
        int $shippingFee = 3000,
        string $tid = self::TID,
    ): array {
        $user     = User::factory()->create();
        $subtotal = $unitPrice * $optionCount;
        $total    = $subtotal + $shippingFee;

        $order = Order::factory()->create([
            'user_id'                          => $user->id,
            'order_status'                     => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount'                  => $subtotal,
            'total_shipping_amount'            => $shippingFee,
            'total_amount'                     => $total,
            'total_paid_amount'                => $total,
            'total_due_amount'                 => 0,
            'total_cancelled_amount'           => 0,
            'cancellation_count'               => 0,
            'paid_at'                          => now(),
            'promotions_applied_snapshot'      => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $options = [];
        for ($i = 0; $i < $optionCount; $i++) {
            $options[] = OrderOption::factory()->forOrder($order)->create([
                'quantity'                 => 1,
                'unit_price'               => $unitPrice,
                'subtotal_price'           => $unitPrice,
                'subtotal_paid_amount'     => $unitPrice,
                'subtotal_discount_amount' => 0,
                'option_status'            => OrderStatusEnum::PAYMENT_COMPLETE,
                'product_snapshot'         => [
                    'id' => null, 'name' => ['ko' => '테스트상품', 'en' => 'Test Product'],
                    'product_code' => null, 'sku' => null, 'brand_id' => null,
                    'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                    'currency_code' => 'KRW', 'stock_quantity' => 100,
                    'tax_status' => 'taxable', 'tax_rate' => 10,
                    'has_options' => false, 'option_groups' => null, 'thumbnail_url' => null,
                ],
                'option_snapshot'          => [
                    'id' => null, 'option_code' => null, 'option_values' => null,
                    'option_name' => '기본', 'price_adjustment' => 0,
                    'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                    'currency_code' => 'KRW', 'stock_quantity' => 100,
                    'weight' => 0.5, 'volume' => 0.01, 'sku' => null, 'is_default' => true,
                ],
            ]);
        }

        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status'    => PaymentStatusEnum::PAID,
            'payment_method'    => PaymentMethodEnum::CARD,
            'pg_provider'       => 'nicepayments',
            'paid_amount_local' => $total,
            'paid_amount_base'  => $total,
            'cancelled_amount'  => 0,
            'paid_at'           => now(),
            'transaction_id'    => $tid,
        ]);

        $shipping = OrderShipping::factory()->forOrder($order)->create([
            'order_option_id'       => $options[0]->id,
            'base_shipping_amount'  => $shippingFee,
            'total_shipping_amount' => $shippingFee,
        ]);

        return compact('order', 'options', 'payment', 'shipping');
    }

    /**
     * 나이스페이먼츠 결제가 완료된 쿠폰 할인 주문을 생성합니다.
     *
     * @return array{order: Order, options: OrderOption[], payment: OrderPayment, couponIssue: CouponIssue}
     */
    private function createNicepayOrderWithCoupon(
        int $optionCount = 1,
        int $unitPrice = 30000,
        int $couponDiscount = 3000,
        int $minOrderAmount = 0,
        string $tid = self::TID,
    ): array {
        $user     = User::factory()->create();
        $subtotal = $unitPrice * $optionCount;
        $paid     = $subtotal - $couponDiscount;

        $order = Order::factory()->create([
            'user_id'                            => $user->id,
            'order_status'                       => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount'                    => $subtotal,
            'total_shipping_amount'              => 0,
            'total_amount'                       => $subtotal,
            'total_coupon_discount_amount'       => $couponDiscount,
            'total_order_coupon_discount_amount' => $couponDiscount,
            'total_paid_amount'                  => $paid,
            'total_due_amount'                   => 0,
            'total_cancelled_amount'             => 0,
            'cancellation_count'                 => 0,
            'paid_at'                            => now(),
            'shipping_policy_applied_snapshot'   => [],
        ]);

        // 쿠폰 할인을 옵션별로 균등 분배 (단가보다 할인이 큰 경우에도 음수 방지)
        $options = [];
        $perBase = (int) round($couponDiscount / $optionCount);
        for ($i = 0; $i < $optionCount; $i++) {
            $perDisc   = $i < ($optionCount - 1)
                ? $perBase
                : $couponDiscount - $perBase * ($optionCount - 1);
            $options[] = OrderOption::factory()->forOrder($order)->create([
                'quantity'                 => 1,
                'unit_price'               => $unitPrice,
                'subtotal_price'           => $unitPrice,
                'subtotal_paid_amount'     => $unitPrice - $perDisc,
                'subtotal_discount_amount' => $perDisc,
                'option_status'            => OrderStatusEnum::PAYMENT_COMPLETE,
                'product_snapshot'         => [
                    'id' => null, 'name' => ['ko' => '테스트상품', 'en' => 'Test Product'],
                    'product_code' => null, 'sku' => null, 'brand_id' => null,
                    'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                    'currency_code' => 'KRW', 'stock_quantity' => 100,
                    'tax_status' => 'taxable', 'tax_rate' => 10,
                    'has_options' => false, 'option_groups' => null, 'thumbnail_url' => null,
                ],
                'option_snapshot'          => [
                    'id' => null, 'option_code' => null, 'option_values' => null,
                    'option_name' => '기본', 'price_adjustment' => 0,
                    'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                    'currency_code' => 'KRW', 'stock_quantity' => 100,
                    'weight' => 0.5, 'volume' => 0.01, 'sku' => null, 'is_default' => true,
                ],
            ]);
        }

        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status'    => PaymentStatusEnum::PAID,
            'payment_method'    => PaymentMethodEnum::CARD,
            'pg_provider'       => 'nicepayments',
            'paid_amount_local' => $paid,
            'paid_amount_base'  => $paid,
            'cancelled_amount'  => 0,
            'paid_at'           => now(),
            'transaction_id'    => $tid,
        ]);

        $coupon = Coupon::create([
            'name'            => '검수용 테스트 쿠폰',
            'target_type'     => 'order_amount',
            'discount_type'   => 'fixed',
            'discount_value'  => $couponDiscount,
            'issue_method'    => 'direct',
            'issue_condition' => 'manual',
            'issue_status'    => 'issuing',
            'total_quantity'  => 100,
            'issued_count'    => 1,
            'per_user_limit'  => 1,
            'valid_type'      => 'period',
            'is_combinable'   => false,
        ]);

        $couponIssue = CouponIssue::create([
            'coupon_id'       => $coupon->id,
            'user_id'         => $order->user_id,
            'coupon_code'     => 'TEST-' . strtoupper(uniqid()),
            'status'          => CouponIssueRecordStatus::USED->value,
            'issued_at'       => now(),
            'used_at'         => now(),
            'order_id'        => $order->id,
            'discount_amount' => $couponDiscount,
        ]);

        $order->update([
            'promotions_applied_snapshot' => [
                'coupon_issue_ids' => [$couponIssue->id],
                'order_promotions' => [
                    'coupons' => [[
                        'coupon_issue_id'  => $couponIssue->id,
                        'discount_type'    => 'fixed',
                        'discount_value'   => $couponDiscount,
                        'min_order_amount' => $minOrderAmount,
                        'applied_amount'   => $couponDiscount,
                    ]],
                ],
            ],
        ]);

        return compact('order', 'options', 'payment', 'couponIssue');
    }

    // ====================================================
    // 1. 배송정책 + 전체취소
    // ====================================================

    /**
     * 배송비 있는 주문 전체취소 시 NicePayments cancelPayment 가 정확히 1회 호출되고
     * 배송비가 환불 레코드에 포함되어야 한다.
     */
    public function test_full_cancel_with_shipping_calls_nicepayments_once_and_refunds_shipping(): void
    {
        // Given: 20,000원 상품 + 3,000원 배송비 = 23,000원 결제
        $this->expectNicePayCancelOnce(); // 정확히 1회만 호출되어야 함

        $data  = $this->createNicepayOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        // When: 전체취소 (PG 연동 on)
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ]);

        // Then: 성공 + 배송비 환불 포함
        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(3000, (float) $refund->refund_shipping_amount, '배송비 3,000원이 환불되어야 합니다');
        $this->assertEquals(23000, (float) $refund->refund_amount, '상품(20,000) + 배송비(3,000) = 23,000원 환불');
    }

    /**
     * 전체취소 시 NicePayments cancelPayment 에 isPartial=0 (전액취소) 이 전달되어야 한다.
     */
    public function test_full_cancel_passes_partial_code_zero_to_nicepayments(): void
    {
        $mock = $this->createMock(NicePaymentsApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with(
                self::TID,          // tid
                $this->anything(),  // moid
                23000,              // cancelAmt
                $this->anything(),  // cancelMsg
                0,                  // isPartial = 0 (전액취소)
                null, null, null,
            )
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(NicePaymentsApiService::class, $mock);

        $data  = $this->createNicepayOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ])->assertOk()->assertJsonPath('success', true);
    }

    /**
     * 무료배송 주문 전체취소 시 환불 레코드에 배송비가 0원이어야 한다.
     */
    public function test_full_cancel_free_shipping_refunds_only_product_amount(): void
    {
        $this->stubNicePayCancelSuccess();

        $data  = $this->createNicepayOrderWithShipping(optionCount: 1, unitPrice: 30000, shippingFee: 0);
        $order = $data['order'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(0, (float) $refund->refund_shipping_amount);
        $this->assertEquals(30000, (float) $refund->refund_amount);
    }

    // ====================================================
    // 2. 배송정책 + 부분취소
    // ====================================================

    /**
     * 배송비 있는 주문 부분취소 시 NicePayments cancelPayment 에 isPartial=1 이 전달되어야 한다.
     */
    public function test_partial_cancel_with_shipping_calls_nicepayments_with_partial_flag(): void
    {
        // Given: 20,000원 × 2개 + 배송비 3,000원 = 43,000원
        $mock = $this->createMock(NicePaymentsApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with(
                self::TID,
                $this->anything(),
                23000,
                $this->anything(),
                1,          // isPartial = 1 (부분취소)
                null, null, null,
            )
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(NicePaymentsApiService::class, $mock);

        $data    = $this->createNicepayOrderWithShipping(optionCount: 2, unitPrice: 20000, shippingFee: 3000);
        $order   = $data['order'];
        $options = $data['options'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'partial',
                'items'     => [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        // 부분취소는 주문 상태(결제완료)를 유지하고 취소된 옵션만 CANCELLED 로 전이된다(PARTIAL_CANCELLED 폐기).
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(OrderStatusEnum::CANCELLED, $options[0]->refresh()->option_status);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund, '부분취소 후 환불 레코드가 생성되어야 합니다');
        $this->assertEquals(3000, (float) $refund->refund_shipping_amount, '부분취소 시 배송비 차액 3,000원이 포함되어야 합니다');
        $this->assertEquals(23000, (float) $refund->refund_amount, '상품(20,000) + 배송비 차액(3,000) = 23,000원 환불');
    }

    /**
     * 이미 부분취소된 거래의 남은 잔액을 취소할 때도 NicePayments 에는 부분취소 코드가 전달되어야 한다.
     */
    public function test_remaining_cancel_after_prior_partial_cancel_keeps_partial_flag(): void
    {
        $calls = [];
        $mock = $this->createMock(NicePaymentsApiService::class);
        $mock->expects($this->exactly(2))
            ->method('cancelPayment')
            ->willReturnCallback(function (
                string $tid,
                string $moid,
                int $cancelAmt,
                string $cancelMsg,
                int $partialCancelCode,
            ) use (&$calls) {
                $calls[] = compact('tid', 'moid', 'cancelAmt', 'cancelMsg', 'partialCancelCode');

                return self::CANCEL_SUCCESS;
            });
        $this->app->instance(NicePaymentsApiService::class, $mock);

        $data = $this->createNicepayOrderWithShipping(optionCount: 2, unitPrice: 1000, shippingFee: 0);
        $order = $data['order'];
        $options = $data['options'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'partial',
                'items'     => [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ])->assertOk()->assertJsonPath('success', true);

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'partial',
                'items'     => [['order_option_id' => $options[1]->id, 'cancel_quantity' => 1]],
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, $calls[0]['partialCancelCode']);
        $this->assertSame(1, $calls[1]['partialCancelCode']);
    }

    /**
     * cancel_pg=false 이면 NicePayments API 를 호출하지 않아야 한다.
     */
    public function test_partial_cancel_without_pg_never_calls_nicepayments_api(): void
    {
        $this->expectNicePayCancelNever(); // cancel_pg=false 이므로 API 미호출

        $data    = $this->createNicepayOrderWithShipping(optionCount: 2, unitPrice: 20000, shippingFee: 3000);
        $order   = $data['order'];
        $options = $data['options'];
        $originalPaid = (float) $order->total_paid_amount;

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'partial',
                'items'     => [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
                'reason'    => 'changed_mind',
                'cancel_pg' => false,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertGreaterThan(0, (float) $refund->refund_amount);
        $this->assertLessThanOrEqual($originalPaid, (float) $refund->refund_amount);
    }

    // ====================================================
    // 3. 쿠폰 적용 + 전체취소
    // ====================================================

    /**
     * 쿠폰 적용 주문 전체취소 시 실결제금액(할인 후)이 환불되고 쿠폰이 복원되어야 한다.
     */
    public function test_full_cancel_with_coupon_refunds_paid_amount_and_restores_coupon(): void
    {
        // Given: 30,000원 - 쿠폰 3,000원 = 27,000원 실결제 (나이스페이먼츠)
        $this->stubNicePayCancelSuccess();
        $this->mockCouponRestore();

        $data        = $this->createNicepayOrderWithCoupon(optionCount: 1, unitPrice: 30000, couponDiscount: 3000);
        $order       = $data['order'];
        $couponIssue = $data['couponIssue'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        // 실결제금액(27,000원) 환불 검증
        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(27000, (float) $refund->refund_amount, '쿠폰 할인 후 실결제금액(27,000원)이 환불되어야 합니다');

        // 쿠폰 복원 검증
        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue->status, '쿠폰이 AVAILABLE 로 복원되어야 합니다');
        $this->assertNull($couponIssue->order_id, '복원된 쿠폰의 order_id 가 null 이어야 합니다');
    }

    /**
     * 쿠폰 적용 주문 전체취소 시 NicePayments cancelPayment 에 실결제금액이 전달되어야 한다.
     */
    public function test_full_cancel_with_coupon_calls_nicepayments_with_paid_amount(): void
    {
        // 쿠폰 할인 후 실결제금액(27,000원)이 PG 취소 금액으로 전달되어야 함
        $mock = $this->createMock(NicePaymentsApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with(self::TID, $this->anything(), 27000, $this->anything(), 0, null, null, null)
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(NicePaymentsApiService::class, $mock);

        $this->mockCouponRestore();

        $data  = $this->createNicepayOrderWithCoupon(optionCount: 1, unitPrice: 30000, couponDiscount: 3000);
        $order = $data['order'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ])->assertOk()->assertJsonPath('success', true);
    }

    // ====================================================
    // 4. 쿠폰 적용 + 부분취소
    // ====================================================

    /**
     * 부분취소 후 잔여금액이 쿠폰 최소 조건을 충족하는 경우 성공해야 한다.
     */
    public function test_partial_cancel_with_coupon_succeeds_when_min_amount_still_met(): void
    {
        // 30,000원 × 2개, 쿠폰 3,000원 (최소 20,000원) → 부분취소 후 잔여 30,000원 > 최소
        $this->stubNicePayCancelSuccess();
        $this->mockCouponRestore();

        $data    = $this->createNicepayOrderWithCoupon(
            optionCount: 2, unitPrice: 30000, couponDiscount: 3000, minOrderAmount: 20000
        );
        $order   = $data['order'];
        $options = $data['options'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'partial',
                'items'     => [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $order->refresh();
        // 부분취소는 주문 상태(결제완료)를 유지하고 취소된 옵션만 CANCELLED 로 전이된다(PARTIAL_CANCELLED 폐기).
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(OrderStatusEnum::CANCELLED, $options[0]->refresh()->option_status);
    }

    /**
     * 쿠폰 최소 주문금액 조건 미달 부분취소 시 422 를 반환하고 NicePayments API 를 호출하지 않아야 한다.
     *
     * 20,000원 × 2개, 쿠폰 22,000원 할인 (최소 30,000원) → 결제 18,000원
     * 옵션 1개 취소 후 잔여 20,000 < 최소 30,000 → 쿠폰 소멸
     * 재계산: 20,000 > 원 결제 18,000 → 422 (추가 결제 필요, 취소 거부)
     */
    public function test_partial_cancel_rejected_when_coupon_condition_no_longer_met(): void
    {
        $this->expectNicePayCancelNever(); // 취소 거부되므로 PG API 미호출

        $data    = $this->createNicepayOrderWithCoupon(
            optionCount: 2, unitPrice: 20000, couponDiscount: 22000, minOrderAmount: 30000
        );
        $order   = $data['order'];
        $options = $data['options'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'partial',
                'items'     => [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    // ====================================================
    // 5. 가상계좌 결제 + 취소
    // ====================================================

    /**
     * 입금 완료된 가상계좌 주문 취소 시 VBANK_REQUIRES_BANK_INFO 오류로 취소 실패해야 한다.
     *
     * vbank_number 가 있고 payment_status = PAID 이면 PaymentRefundListener 가
     * VBANK_REQUIRES_BANK_INFO 를 반환 → OrderCancellationService 가 취소를 거부.
     * 환불 계좌 정보는 AdminVbankRefundController 를 통해 별도 처리한다.
     */
    public function test_full_cancel_deposited_vbank_fails_with_pg_refund_error(): void
    {
        $this->expectNicePayCancelNever(); // vbank 는 일반 API 로 처리 불가 → 미호출

        $data    = $this->createNicepayOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 0);
        $order   = $data['order'];
        $payment = $data['payment'];

        // 가상계좌 입금 완료 상태로 업데이트
        $payment->update([
            'vbank_number'   => '1234567890',
            'vbank_name'     => '국민은행',
            'payment_status' => PaymentStatusEnum::PAID,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ]);

        // 가상계좌 입금 완료 건은 환불 계좌 없이 PG 환불 불가 → 취소 실패
        $response->assertStatus(422)->assertJsonPath('success', false);

        // 주문 상태가 CANCELLED 로 변경되지 않아야 함 (롤백)
        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    // ====================================================
    // 6. NicePayments API 오류
    // ====================================================

    /**
     * NicePayments cancelPayment API 오류 시 주문 취소가 실패하고 롤백되어야 한다.
     */
    public function test_cancel_fails_and_rolls_back_when_nicepayments_api_throws(): void
    {
        $this->stubNicePayCancelFailure('NicePayments 취소 실패: 결제가 만료되었습니다');

        $data  = $this->createNicepayOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ]);

        // PG 취소 실패 → 422
        $response->assertStatus(422)->assertJsonPath('success', false);

        // 주문이 CANCELLED 로 변경되지 않아야 함 (트랜잭션 롤백)
        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status, 'PG 환불 실패 시 주문 상태 변경이 롤백되어야 합니다');
    }

    /**
     * NicePayments API 오류 시 refund_failed 훅이 발동되어야 한다.
     */
    public function test_nicepayments_api_error_fires_refund_failed_hook(): void
    {
        $this->stubNicePayCancelFailure('API 호출 실패');

        $data  = $this->createNicepayOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        $hookFired = false;
        HookManager::addAction('sirsoft-pay_nicepayments.payment.refund_failed', function () use (&$hookFired) {
            $hookFired = true;
        }, 20);

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $this->assertTrue($hookFired, 'PG 환불 실패 시 refund_failed 훅이 발동되어야 합니다');
    }

    // ====================================================
    // 7. non-nicepayments PG 주문에 대한 무간섭 검증
    // ====================================================

    /**
     * pg_provider 가 nicepayments 가 아닌 주문에는 PaymentRefundListener 가 개입하지 않아야 한다.
     */
    public function test_listener_skips_non_nicepayments_payment(): void
    {
        $this->expectNicePayCancelNever(); // 다른 PG 이므로 NicePayments API 미호출

        $data    = $this->createNicepayOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order   = $data['order'];
        $payment = $data['payment'];

        // pg_provider 를 다른 PG 로 변경
        $payment->update(['pg_provider' => 'other_pg']);

        // cancel_pg=false 로 PG 없이 취소 (다른 PG 는 환불 핸들러 없음)
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => false,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }
}
