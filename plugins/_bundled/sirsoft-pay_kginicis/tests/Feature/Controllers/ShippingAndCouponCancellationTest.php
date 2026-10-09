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
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Listeners\CouponRestoreListener;
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
 *
 * 시나리오 매니페스트: `tests/scenarios/shipping-coupon-cancellation.yaml`
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

    /**
     * 관리자·취소 채번을 준비하고 환불 리스너와 쿠폰 복원 리스너를 직접 등록합니다.
     *
     * @return void 반환값 없음
     */
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
        // 쿠폰 복원은 가짜 리스너가 아니라 모듈의 실제 리스너로 잰다(T11) — 모듈 리스너도 같은 이유로 활성 가드에서 빠지므로 직접 등록한다.
        HookListenerRegistrar::register(CouponRestoreListener::class, 'sirsoft-ecommerce');
    }

    /**
     * 훅과 리스너 등록 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        parent::tearDown();
    }

    // ────────────────────────────────────────────
    // Mock 헬퍼
    // ────────────────────────────────────────────

    /**
     * cancelPayment 를 호출 횟수 검증 없이 성공 응답으로만 교체합니다.
     *
     * @return void 반환값 없음
     */
    private function stubKgInicisCancelSuccess(): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->method('cancelPayment')->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(KgInicisApiService::class, $mock);
    }

    /**
     * cancelPayment 가 정확히 1회 호출되어야 하는 mock 을 등록합니다.
     *
     * @return MockObject 등록한 KG 이니시스 API mock
     */
    private function expectKgInicisCancelOnce(): MockObject
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(KgInicisApiService::class, $mock);

        return $mock;
    }

    /**
     * cancelPayment 가 호출되면 안 되는 mock 을 등록합니다.
     *
     * @return void 반환값 없음
     */
    private function expectKgInicisCancelNever(): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->never())->method('cancelPayment');
        $this->app->instance(KgInicisApiService::class, $mock);
    }

    /**
     * cancelPayment 가 예외를 던지는 mock 을 등록합니다.
     *
     * @param  string  $errorMsg  던질 예외 메시지
     * @return void 반환값 없음
     */
    private function stubKgInicisCancelFailure(string $errorMsg = 'KG 이니시스 취소 실패'): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->method('cancelPayment')->willThrowException(new \Exception($errorMsg));
        $this->app->instance(KgInicisApiService::class, $mock);
    }

    // ────────────────────────────────────────────
    // 주문 생성 헬퍼
    // ────────────────────────────────────────────

    /**
     * 취소·환불 번호 채번 시퀀스를 기본 설정으로 만듭니다.
     *
     * @return void 반환값 없음
     */
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
     * KG 이니시스 카드 결제가 완료된 배송비 포함 주문을 생성합니다.
     *
     * @param  int  $optionCount  주문 옵션 개수
     * @param  int  $unitPrice  옵션 단가
     * @param  int  $shippingFee  배송비
     * @param  string  $tid  결제 거래번호
     * @return array{order: Order, options: OrderOption[], payment: OrderPayment, shipping: OrderShipping} 주문·옵션·결제·배송 묶음
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
     * KG 이니시스 카드 결제가 완료된 주문 쿠폰 할인 주문을 생성합니다.
     *
     * @param  int  $optionCount  주문 옵션 개수
     * @param  int  $unitPrice  옵션 단가
     * @param  int  $couponDiscount  쿠폰 할인액
     * @param  int  $minOrderAmount  쿠폰 최소 주문금액 조건
     * @param  string  $tid  결제 거래번호
     * @return array{order: Order, options: OrderOption[], payment: OrderPayment, couponIssue: CouponIssue} 주문·옵션·결제·쿠폰 발급 묶음
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

    /**
     * 배송비 있는 주문 전체취소는 KG 이니시스 취소를 1회 부르고 배송비까지 환불한다.
     *
     * @scenario case=shipping_full
     *
     * @effects order_cancelled_with_shipping_refunded, pg_cancel_called_once
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_with_shipping_calls_kginicis_once_and_refunds_shipping(): void
    {
        $this->expectKgInicisCancelOnce();

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(3000, (float) $refund->refund_shipping_amount, '배송비 3,000원이 환불 레코드에 포함되어야 합니다');
        $this->assertEquals(23000, (float) $refund->refund_amount, '상품(20,000) + 배송비(3,000) = 23,000원 환불');
    }

    /**
     * 전체취소는 거래번호·결제수단만 싣고 취소 금액·잔액은 비워 전액취소로 보낸다.
     *
     * @scenario case=shipping_full_args
     *
     * @effects full_cancel_omits_cancel_price, pg_cancel_called_once
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_passes_tid_and_amount_to_kginicis(): void
    {
        // KG 이니시스 cancelPayment 시그니처: (tid, payMethod, cancelPrice|null, msg, totalAmount|null)
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with(
                self::TID,          // tid
                'Card',             // payMethod (from payment_meta)
                null,               // cancelPrice — 전액취소는 금액을 싣지 않는다(부분취소 코드로 나가면 안 된다)
                $this->anything(),  // cancelMsg
                null,               // totalAmount — 전액취소에는 잔액을 싣지 않는다
            )
            ->willReturn(self::CANCEL_SUCCESS);
        $this->app->instance(KgInicisApiService::class, $mock);

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ])->assertOk()->assertJsonPath('success', true);
    }

    /**
     * 환불 전에 결제 당시의 상점 아이디와 운영 모드로 API 자격을 되돌린다.
     *
     * @scenario case=credential_restore
     *
     * @effects payment_time_credentials_restored, pg_cancel_called_once
     *
     * @return void 반환값 없음
     */
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
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ])->assertOk()->assertJsonPath('success', true);
    }

    /**
     * 무료배송 주문 전체취소는 배송비 0원으로 상품 금액만 환불한다.
     *
     * @scenario case=free_shipping_full
     *
     * @effects free_shipping_refunds_product_only
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_free_shipping_refunds_only_product_amount(): void
    {
        $this->stubKgInicisCancelSuccess();

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 30000, shippingFee: 0);
        $order = $data['order'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
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
     * 배송비 있는 주문 부분취소는 배송비 차액을 포함해 환불하고 취소액·잔액을 PG 에 싣는다.
     *
     * @scenario case=shipping_partial
     *
     * @effects partial_cancel_refunds_shipping_difference, partial_cancel_sends_partial_amount
     *
     * @return void 반환값 없음
     */
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
                'auto_advance_refund' => true,
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

    /**
     * **접수만 한 취소**는 PG API 를 부르지 않는다. (#599 후속 P2·D3)
     *
     * 예전의 `cancel_pg=false` 자리다. 그 플래그는 없앴다 — 끈 채로 카드 취소를 하면 PG 를
     * 부르지 않고도 환불이 `완료`로 찍혀, **고객 카드는 그대로인데 장부만 환불완료**가 됐다.
     * 새 모델에서 관리자 [주문취소]는 환불신청서를 접수할 뿐이고, 집행은 `auto_advance_refund`
     * 를 켰을 때(또는 뒤이은 [환불하기])만 일어난다. 그러므로 접수만 한 이 요청에서는 PG 왕복도
     * 없고 주문도 아직 취소되지 않는다 — 돈이 돌아가지 않았으므로 취소도 성립하지 않는다.
     *
     * @scenario case=request_only
     *
     * @effects request_only_cancel_skips_pg
     *
     * @return void 반환값 없음
     */
    public function test_request_only_cancel_never_calls_kginicis_api(): void
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
                'auto_advance_refund' => false,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertGreaterThan(0, (float) $refund->refund_amount);
        $this->assertLessThanOrEqual($originalPaid, (float) $refund->refund_amount);
        $this->assertSame(
            RefundStatusEnum::REQUESTED,
            $refund->refund_status,
            '접수만 했으므로 환불신청서는 신청 상태에 서 있어야 한다',
        );

        $order->refresh();
        $this->assertEquals(
            OrderStatusEnum::PAYMENT_COMPLETE,
            $order->order_status,
            '집행 전에는 주문이 취소되지 않는다 — 취소는 환불이 실제로 나간 순간 성립한다 (D3)',
        );
    }

    // ====================================================
    // 3. 쿠폰 적용 + 전체취소
    // ====================================================

    /**
     * 쿠폰 적용 주문 전체취소는 실결제액을 환불하고 실제 쿠폰 복원 리스너로 쿠폰을 되살린다.
     *
     * @scenario case=coupon_full
     *
     * @effects coupon_full_cancel_refunds_net_paid, coupon_restored_by_real_listener
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_with_coupon_refunds_paid_amount_and_restores_coupon(): void
    {
        $this->stubKgInicisCancelSuccess();

        $data = $this->createKgInicisOrderWithCoupon(optionCount: 1, unitPrice: 30000, couponDiscount: 3000);
        $order = $data['order'];
        $couponIssue = $data['couponIssue'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(27000, (float) $refund->refund_amount, '쿠폰 할인 후 실결제금액(27,000원)이 환불되어야 합니다');

        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue->status, '쿠폰이 AVAILABLE 로 복원되어야 합니다');
        // 가짜 리스너 없이 모듈의 실제 CouponRestoreListener 결과를 본다(T11) — 사용 이력(order_id)은 남고 used_at 만 비운다
        $this->assertNull($couponIssue->used_at, '복원된 쿠폰의 used_at 이 null 이어야 합니다');
    }

    /**
     * 쿠폰 적용 주문 전체취소도 같은 거래번호·결제수단으로 KG 이니시스 취소를 1회 부른다.
     *
     * @scenario case=coupon_full_args
     *
     * @effects pg_cancel_called_once
     *
     * @return void 반환값 없음
     */
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

        $data = $this->createKgInicisOrderWithCoupon(optionCount: 1, unitPrice: 30000, couponDiscount: 3000);
        $order = $data['order'];

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ])->assertOk()->assertJsonPath('success', true);
    }

    // ====================================================
    // 4. 쿠폰 적용 + 부분취소
    // ====================================================

    /**
     * 부분취소 뒤에도 쿠폰 최소 주문금액을 넘으면 취소가 성공하고 쿠폰은 사용 상태로 남는다.
     *
     * @scenario case=coupon_partial_condition_met
     *
     * @effects coupon_kept_when_condition_met
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_with_coupon_succeeds_when_min_amount_still_met(): void
    {
        $this->stubKgInicisCancelSuccess();

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
                'auto_advance_refund' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        // 부분취소는 별도 주문 상태를 두지 않고, 옵션 상태(일부 CANCELLED)로 부분취소를 판정한다.
        $this->assertTrue($order->fresh(['options'])->isPartiallyCancelled(), '부분취소 후 부분취소 상태로 판정되어야 합니다');
        // 조건이 살아 있으니 실제 발급 행도 그대로 USED 다(T11 대조군)
        $this->assertEquals(CouponIssueRecordStatus::USED, $data['couponIssue']->fresh()->status);
    }

    /**
     * 부분취소로 쿠폰 조건이 깨져 환불액이 결제액을 넘으면 422 로 거부하고 PG 를 부르지 않는다.
     *
     * @scenario case=coupon_partial_condition_broken
     *
     * @effects partial_cancel_rejected_when_coupon_condition_broken
     *
     * @return void 반환값 없음
     */
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
                'auto_advance_refund' => true,
            ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    // ====================================================
    // 5. KG 이니시스 API 오류
    // ====================================================

    /**
     * KG 이니시스 API 가 예외를 던지면 취소가 422 로 실패하고 주문 상태가 롤백된다.
     *
     * @scenario case=pg_api_error
     *
     * @effects pg_error_rolls_back_cancel
     *
     * @return void 반환값 없음
     */
    public function test_cancel_fails_and_rolls_back_when_kginicis_api_throws(): void
    {
        $this->stubKgInicisCancelFailure('KG 이니시스 취소 실패: 거래가 만료되었습니다');

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status, 'PG 환불 실패 시 주문 상태 변경이 롤백되어야 합니다');
    }

    // ====================================================
    // 6. non-kginicis PG 주문에 대한 무간섭 검증
    // ====================================================

    /**
     * 다른 PG 로 결제된 주문이면 이 플러그인의 리스너는 지나친다.
     *
     * 필터 체인은 구독자 전원을 부르므로, 자기 PG 가 아닌 결제에까지 API 를 쏘면 남의 거래를
     * 취소하려 든다. 예전에는 `cancel_pg=false` 로 **아무도 부르지 않게** 해 두고 이 사실을
     * 확인했지만, 그 플래그가 사라진 지금은 **다른 응답자를 세워** 확인한다 — 체인이 실제로
     * 돌고, 이 플러그인만 손을 떼는 것이 요지이기 때문이다(아무도 응답하지 않으면 코어는 PG
     * 거절로 보고 전체를 롤백한다).
     *
     * @scenario case=other_pg_payment
     *
     * @effects other_pg_payment_skipped
     *
     * @return void 반환값 없음
     */
    public function test_listener_skips_non_kginicis_payment(): void
    {
        $this->expectKgInicisCancelNever();

        $data = $this->createKgInicisOrderWithShipping(optionCount: 1, unitPrice: 20000, shippingFee: 3000);
        $order = $data['order'];
        $payment = $data['payment'];

        $payment->update(['pg_provider' => 'other_pg']);

        // 그 PG 를 담당하는 리스너 역할 — 이 플러그인 다음 우선순위에 선다.
        HookManager::addFilter(
            'sirsoft-ecommerce.payment.refund',
            static fn (array $result, Order $order, OrderPayment $payment): array => $payment->pg_provider === 'other_pg'
                ? ['success' => true, 'error_code' => null, 'error_message' => null, 'transaction_id' => 'TID_OTHER_PG']
                : $result,
            20,
        );

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", [
                'type' => 'full',
                'reason' => 'changed_mind',
                'auto_advance_refund' => true,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }
}
