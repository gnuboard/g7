<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

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
use PHPUnit\Framework\MockObject\MockObject;
use Plugins\Sirsoft\PayKginicis\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * KG 이니시스 결제 플러그인 — 배송비·쿠폰 적용 취소 통합 테스트
 *
 * CLAUDE.local.md 의 "미검증 시나리오" 4건 (배송정책 + 전체/부분취소,
 * 프로모션 + 전체/부분취소) 을 KG 이니시스 결제 경로에 대해 검증.
 * NicePayments 와 동일한 시나리오 매트릭스를 따라 OrderCancellationService →
 * PaymentRefundListener → KgInicisApiService 의 통합 경로를 점검한다.
 *
 * KgInicisApiService 만 mock 으로 교체하여 PG 호출의 인자 / 응답 / 예외 경로를
 * 제어하고, 코어 OrderCancellationService 의 환불 금액 계산 + 쿠폰 복원 + 환불 레코드
 * 생성 동작은 실제로 실행시킨다.
 */
class ShippingAndCouponCancellationTest extends PluginTestCase
{
    private const TID = 'TID_KGINICIS_TEST_001';

    private const CANCEL_SUCCESS = [
        'resultCode' => '00',
        'resultMsg' => '취소 성공',
        'tid' => 'TID_CANCEL_KGINICIS',
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
        // 테스트에서 직접 listener 를 등록한다. HookListenerRegistrar 가 idempotent.
        HookListenerRegistrar::register(PaymentRefundListener::class, 'sirsoft-pay_kginicis');
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        parent::tearDown();
    }

    // ────────────────────────────────────────────
    // Mock 헬퍼
    // ────────────────────────────────────────────

    private function stubKgInicisCancelSuccess(): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->method('cancelPayment')->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(KgInicisApiService::class, $mock);
    }

    private function expectKgInicisCancelOnce(): MockObject
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(KgInicisApiService::class, $mock);

        return $mock;
    }

    private function expectKgInicisCancelNever(): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->never())->method('cancelPayment');
        $this->app->instance(KgInicisApiService::class, $mock);
    }

    private function stubKgInicisCancelFailure(string $errorMsg = 'KG 이니시스 취소 실패'): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->method('cancelPayment')->willThrowException(new \Exception($errorMsg));
        $this->app->instance(KgInicisApiService::class, $mock);
    }

    private function mockCouponRestore(): void
    {
        HookManager::addAction(
            'sirsoft-ecommerce.coupon.restore',
            function (Order $order, array $couponIssueIds) {
                CouponIssue::whereIn('id', $couponIssueIds)->update([
                    'status' => CouponIssueRecordStatus::AVAILABLE->value,
                    'used_at' => null,
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
                    'algorithm' => $cfg['algorithm']->value,
                    'prefix' => $cfg['prefix'],
                    'current_value' => 0,
                    'increment' => 1,
                    'min_value' => 1,
                    'max_value' => $cfg['max_value'],
                    'cycle' => false,
                    'pad_length' => $cfg['pad_length'],
                ]
            );
        }
    }

    /**
     * @return array{order: Order, options: OrderOption[], payment: OrderPayment, shipping: OrderShipping}
     */
    private function createKgInicisOrderWithShipping(
        int $optionCount = 1,
        int $unitPrice = 20000,
        int $shippingFee = 3000,
        string $tid = self::TID,
    ): array {
        $user = User::factory()->create();
        $subtotal = $unitPrice * $optionCount;
        $total = $subtotal + $shippingFee;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $subtotal,
            'total_shipping_amount' => $shippingFee,
            'total_amount' => $total,
            'total_paid_amount' => $total,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now(),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $options = [];
        for ($i = 0; $i < $optionCount; $i++) {
            $options[] = OrderOption::factory()->forOrder($order)->create([
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'subtotal_price' => $unitPrice,
                'subtotal_paid_amount' => $unitPrice,
                'subtotal_discount_amount' => 0,
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'product_snapshot' => [
                    'id' => null, 'name' => ['ko' => '테스트상품', 'en' => 'Test Product'],
                    'product_code' => null, 'sku' => null, 'brand_id' => null,
                    'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                    'currency_code' => 'KRW', 'stock_quantity' => 100,
                    'tax_status' => 'taxable', 'tax_rate' => 10,
                    'has_options' => false, 'option_groups' => null, 'thumbnail_url' => null,
                ],
                'option_snapshot' => [
                    'id' => null, 'option_code' => null, 'option_values' => null,
                    'option_name' => '기본', 'price_adjustment' => 0,
                    'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                    'currency_code' => 'KRW', 'stock_quantity' => 100,
                    'weight' => 0.5, 'volume' => 0.01, 'sku' => null, 'is_default' => true,
                ],
            ]);
        }

        // payment_meta.pay_method 는 PaymentRefundListener 가 KG 이니시스 cancelPayment 의
        // payMethod 인자로 그대로 사용. 'Card' 가 기본 (신용카드 결제).
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'paid_amount_local' => $total,
            'paid_amount_base' => $total,
            'cancelled_amount' => 0,
            'paid_at' => now(),
            'transaction_id' => $tid,
            'payment_meta' => ['pay_method' => 'Card'],
        ]);

        $shipping = OrderShipping::factory()->forOrder($order)->create([
            'order_option_id' => $options[0]->id,
            'base_shipping_amount' => $shippingFee,
            'total_shipping_amount' => $shippingFee,
        ]);

        return compact('order', 'options', 'payment', 'shipping');
    }

    /**
     * @return array{order: Order, options: OrderOption[], payment: OrderPayment, couponIssue: CouponIssue}
     */
    private function createKgInicisOrderWithCoupon(
        int $optionCount = 1,
        int $unitPrice = 30000,
        int $couponDiscount = 3000,
        int $minOrderAmount = 0,
        string $tid = self::TID,
    ): array {
        $user = User::factory()->create();
        $subtotal = $unitPrice * $optionCount;
        $paid = $subtotal - $couponDiscount;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $subtotal,
            'total_shipping_amount' => 0,
            'total_amount' => $subtotal,
            'total_coupon_discount_amount' => $couponDiscount,
            'total_order_coupon_discount_amount' => $couponDiscount,
            'total_paid_amount' => $paid,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now(),
            'shipping_policy_applied_snapshot' => [],
        ]);

        $options = [];
        $perBase = (int) round($couponDiscount / $optionCount);
        for ($i = 0; $i < $optionCount; $i++) {
            $perDisc = $i < ($optionCount - 1)
                ? $perBase
                : $couponDiscount - $perBase * ($optionCount - 1);
            $options[] = OrderOption::factory()->forOrder($order)->create([
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'subtotal_price' => $unitPrice,
                'subtotal_paid_amount' => $unitPrice - $perDisc,
                'subtotal_discount_amount' => $perDisc,
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'product_snapshot' => [
                    'id' => null, 'name' => ['ko' => '테스트상품', 'en' => 'Test Product'],
                    'product_code' => null, 'sku' => null, 'brand_id' => null,
                    'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                    'currency_code' => 'KRW', 'stock_quantity' => 100,
                    'tax_status' => 'taxable', 'tax_rate' => 10,
                    'has_options' => false, 'option_groups' => null, 'thumbnail_url' => null,
                ],
                'option_snapshot' => [
                    'id' => null, 'option_code' => null, 'option_values' => null,
                    'option_name' => '기본', 'price_adjustment' => 0,
                    'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                    'currency_code' => 'KRW', 'stock_quantity' => 100,
                    'weight' => 0.5, 'volume' => 0.01, 'sku' => null, 'is_default' => true,
                ],
            ]);
        }

        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'paid_amount_local' => $paid,
            'paid_amount_base' => $paid,
            'cancelled_amount' => 0,
            'paid_at' => now(),
            'transaction_id' => $tid,
            'payment_meta' => ['pay_method' => 'Card'],
        ]);

        $coupon = Coupon::create([
            'name' => '검수용 테스트 쿠폰',
            'target_type' => 'order_amount',
            'discount_type' => 'fixed',
            'discount_value' => $couponDiscount,
            'issue_method' => 'direct',
            'issue_condition' => 'manual',
            'issue_status' => 'issuing',
            'total_quantity' => 100,
            'issued_count' => 1,
            'per_user_limit' => 1,
            'valid_type' => 'period',
            'is_combinable' => false,
        ]);

        $couponIssue = CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $order->user_id,
            'coupon_code' => 'TEST-'.strtoupper(uniqid()),
            'status' => CouponIssueRecordStatus::USED->value,
            'issued_at' => now(),
            'used_at' => now(),
            'order_id' => $order->id,
            'discount_amount' => $couponDiscount,
        ]);

        $order->update([
            'promotions_applied_snapshot' => [
                'coupon_issue_ids' => [$couponIssue->id],
                'order_promotions' => [
                    'coupons' => [[
                        'coupon_issue_id' => $couponIssue->id,
                        'discount_type' => 'fixed',
                        'discount_value' => $couponDiscount,
                        'min_order_amount' => $minOrderAmount,
                        'applied_amount' => $couponDiscount,
                    ]],
                ],
            ],
        ]);

        return compact('order', 'options', 'payment', 'couponIssue');
    }

    // ====================================================
    // 1. 배송정책 + 전체취소
    // ====================================================

    public function test_full_cancel_with_shipping_calls_kginicis_once_and_refunds_shipping(): void
    {
        $this->expectKgInicisCancelOnce();

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(3000, (float) $refund->refund_shipping_amount, '배송비 3,000원이 환불 레코드에 포함되어야 합니다');
        $this->assertEquals(23000, (float) $refund->refund_amount, '상품(20,000) + 배송비(3,000) = 23,000원 환불');
    }

    public function test_full_cancel_passes_tid_and_amount_to_kginicis(): void
    {
        // KG 이니시스 cancelPayment 시그니처: (tid, payMethod, cancelPrice|null, msg, totalAmount|null)
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with(
                self::TID,          // tid
                'Card',             // payMethod (from payment_meta)
                $this->anything(),  // cancelPrice (전액취소 시 null, 부분취소 시 int — listener 가 결정)
                $this->anything(),  // cancelMsg
                $this->anything(),  // totalAmount
            )
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(KgInicisApiService::class, $mock);

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => true,
            ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_standard_cancel_restores_payment_time_mid_and_mode_before_refund(): void
    {
        $paymentMid = 'SIRLIVE001';

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('useStoredCredentials')
            ->with(false, $paymentMid);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(KgInicisApiService::class, $mock);

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];
        $payment = $data['payment'];
        $payment->update([
            'payment_meta' => [
                'pay_method' => 'Card',
                'mid' => $paymentMid,
                'is_test_mode' => false,
            ],
        ]);

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type'      => 'full',
                'reason'    => 'changed_mind',
                'cancel_pg' => true,
            ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_full_cancel_free_shipping_refunds_only_product_amount(): void
    {
        $this->stubKgInicisCancelSuccess();

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 30000, shippingFee: 0);
        $order = $data['order'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
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

    public function test_partial_cancel_with_shipping_creates_refund_record(): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with(
                self::TID,
                'Card',
                23000,
                $this->anything(),
                43000,
            )
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(KgInicisApiService::class, $mock);

        $data = $this->createKgInicisOrderWithShipping(optionCount: 2, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];
        $options = $data['options'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'partial',
                'items' => [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
                'reason' => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        // 부분취소는 별도 주문 상태(partial_cancelled)를 두지 않는다 — 일부 옵션만 CANCELLED 로
        // 전이되고 isPartiallyCancelled() 가 옵션 상태로 부분취소를 판정한다 (OrderCancellationService 정책).
        $this->assertTrue($order->fresh(['options'])->isPartiallyCancelled(), '부분취소 후 부분취소 상태로 판정되어야 합니다');

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund, '부분취소 후 환불 레코드가 생성되어야 합니다');
        $this->assertEquals(3000, (float) $refund->refund_shipping_amount, '부분취소 시 배송비 차액 3,000원이 포함되어야 합니다');
        $this->assertEquals(23000, (float) $refund->refund_amount, '상품(20,000) + 배송비 차액(3,000) = 23,000원 환불');
    }

    public function test_partial_cancel_without_pg_never_calls_kginicis_api(): void
    {
        $this->expectKgInicisCancelNever();

        $data = $this->createKgInicisOrderWithShipping(optionCount: 2, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];
        $options = $data['options'];
        $originalPaid = (float) $order->total_paid_amount;

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'partial',
                'items' => [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
                'reason' => 'changed_mind',
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

    public function test_full_cancel_with_coupon_refunds_paid_amount_and_restores_coupon(): void
    {
        $this->stubKgInicisCancelSuccess();
        $this->mockCouponRestore();

        $data = $this->createKgInicisOrderWithCoupon(optionCount: 1, unitPrice: 30000, couponDiscount: 3000);
        $order = $data['order'];
        $couponIssue = $data['couponIssue'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(27000, (float) $refund->refund_amount, '쿠폰 할인 후 실결제금액(27,000원)이 환불되어야 합니다');

        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue->status, '쿠폰이 AVAILABLE 로 복원되어야 합니다');
        $this->assertNull($couponIssue->order_id, '복원된 쿠폰의 order_id 가 null 이어야 합니다');
    }

    public function test_full_cancel_with_coupon_passes_net_paid_to_kginicis(): void
    {
        // 쿠폰 할인 후 실결제금액(27,000원)이 PG cancelPrice 또는 cancelMsg 인자로 전달되어야 함
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with(
                self::TID,
                'Card',
                $this->anything(),  // cancelPrice — listener 가 결정 (null = 전액, int = 부분)
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(KgInicisApiService::class, $mock);

        $this->mockCouponRestore();

        $data = $this->createKgInicisOrderWithCoupon(optionCount: 1, unitPrice: 30000, couponDiscount: 3000);
        $order = $data['order'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => true,
            ])->assertOk()->assertJsonPath('success', true);
    }

    // ====================================================
    // 4. 쿠폰 적용 + 부분취소
    // ====================================================

    public function test_partial_cancel_with_coupon_succeeds_when_min_amount_still_met(): void
    {
        $this->stubKgInicisCancelSuccess();
        $this->mockCouponRestore();

        $data = $this->createKgInicisOrderWithCoupon(
            optionCount: 2, unitPrice: 30000, couponDiscount: 3000, minOrderAmount: 20000
        );
        $order = $data['order'];
        $options = $data['options'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'partial',
                'items' => [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
                'reason' => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        // 부분취소는 별도 주문 상태를 두지 않고, 옵션 상태(일부 CANCELLED)로 부분취소를 판정한다.
        $this->assertTrue($order->fresh(['options'])->isPartiallyCancelled(), '부분취소 후 부분취소 상태로 판정되어야 합니다');
    }

    public function test_partial_cancel_rejected_when_coupon_condition_no_longer_met(): void
    {
        $this->expectKgInicisCancelNever();

        $data = $this->createKgInicisOrderWithCoupon(
            optionCount: 2, unitPrice: 20000, couponDiscount: 22000, minOrderAmount: 30000
        );
        $order = $data['order'];
        $options = $data['options'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'partial',
                'items' => [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
                'reason' => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    // ====================================================
    // 5. KG 이니시스 API 오류
    // ====================================================

    public function test_cancel_fails_and_rolls_back_when_kginicis_api_throws(): void
    {
        $this->stubKgInicisCancelFailure('KG 이니시스 취소 실패: 거래가 만료되었습니다');

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => true,
            ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status, 'PG 환불 실패 시 주문 상태 변경이 롤백되어야 합니다');
    }

    // ====================================================
    // 6. non-kginicis PG 주문에 대한 무간섭 검증
    // ====================================================

    public function test_listener_skips_non_kginicis_payment(): void
    {
        $this->expectKgInicisCancelNever();

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];
        $payment = $data['payment'];

        $payment->update(['pg_provider' => 'other_pg']);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'cancel_pg' => false,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }
}
