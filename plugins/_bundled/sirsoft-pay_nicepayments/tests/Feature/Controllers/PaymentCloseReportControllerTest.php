<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class PaymentCloseReportControllerTest extends PluginTestCase
{
    /**
     * 주문 소유자의 닫힘 보고는 결제대기 주문과 결제를 모두 취소로 기록한다.
     *
     * 다른 PG(KG이니시스·NHN KCP·토스페이먼츠)와 같이 결제 상태는 실패가 아니라 취소이고,
     * 결제 취소 이력이 1건 남는다.
     *
     * @effects close_report_marks_pending_order_failed_and_payment_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_close_report_marks_pending_order_failed_and_payment_cancelled(): void
    {
        $order = OrderFactory::new()->create([
            'order_number' => 'ORD-NICE-CLOSE-001',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 0,
        ]);

        $this->actAsOrderOwner($order);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-001',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'nicepay-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'recorded');

        $order->refresh();
        $payment = $order->payment()->first();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertCount(1, $payment->cancel_history ?? []);
        $this->assertSame('USER_CANCEL', $payment->cancel_history[0]['cancel_code'] ?? null);
        $this->assertSame('nicepay-window-closed', $payment->cancel_history[0]['cancel_message'] ?? null);
    }

    /**
     * 주문 소유자의 닫힘 보고는 실제 결제 레코드를 취소로 남기고, 재결제 판정에 쓸 실패 코드를 메타에 보존한다.
     *
     * @effects close_report_marks_real_payment_cancelled_with_history
     *
     * @return void 반환값 없음
     */
    public function test_close_report_marks_real_payment_cancelled_with_history(): void
    {
        $order = OrderFactory::new()->create([
            'order_number' => 'ORD-NICE-CLOSE-REAL',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 0,
        ]);

        $this->actAsOrderOwner($order);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-REAL',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'nicepay-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'recorded');

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertEquals('USER_CANCEL', $order->order_meta['payment_failure_code'] ?? null);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertCount(1, $payment->cancel_history ?? []);
        $this->assertSame('USER_CANCEL', $payment->payment_meta['failure_code'] ?? null);
        $this->assertSame('nicepayments', $payment->payment_meta['failure_source'] ?? null);
    }

    /**
     * 같은 닫힘 보고가 두 번 와도 두 번째는 무시되어 취소 이력이 1건으로 유지된다.
     *
     * @effects close_report_second_report_keeps_single_cancel_history
     *
     * @return void 반환값 없음
     */
    public function test_close_report_second_report_keeps_single_cancel_history(): void
    {
        $order = OrderFactory::new()->create([
            'order_number' => 'ORD-NICE-CLOSE-TWICE',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 0,
        ]);

        $this->actAsOrderOwner($order);

        $payload = [
            'oid' => 'ORD-NICE-CLOSE-TWICE',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'nicepay-window-closed',
        ];

        $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'recorded');

        $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'order_not_payable');

        $payment = $order->payment()->first();
        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertCount(1, $payment->cancel_history ?? []);
    }

    /**
     * 보고 금액이 주문 청구액과 다르면 거부한다.
     *
     * @effects close_report_rejects_amount_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_amount_mismatch(): void
    {
        $order = $this->makeOrder('ORD-NICE-CLOSE-002', 10000);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-NICE-CLOSE-002')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $this->actAsOrderOwner($order);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-002',
            'price' => 9000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment amount does not match the order amount.');
    }

    /**
     * 청구할 수 없는 통화 설정이면 실패로 기록하지 않고 거부한다.
     *
     * @effects close_report_rejects_unchargeable_payment_currency_without_marking_failed
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_unchargeable_payment_currency_without_marking_failed(): void
    {
        $order = $this->makeOrder('ORD-NICE-CLOSE-CURRENCY', 10000);
        $order->currency = 'USD';
        $order->currency_snapshot = self::unchargeableUsdCurrencySnapshot();

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-NICE-CLOSE-CURRENCY')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $this->actAsOrderOwner($order);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-CURRENCY',
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment currency is not chargeable.');
    }

    /**
     * 주문자 연락처를 정확히 맞춰 보내도 소유자 인증이 없으면 거부된다 (KVE-2026-2434).
     *
     * 연락처는 제3자도 알 수 있어 소유 증명이 아니다. 종전에는 이 요청이 주문을 취소시켰다.
     *
     * @effects close_report_rejects_matching_buyer_contact_without_ownership
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_matching_buyer_contact_without_ownership(): void
    {
        $order = $this->makeOrder('ORD-NICE-CLOSE-003', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-NICE-CLOSE-003')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-003',
            'price' => 10000,
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', 'Order ownership verification failed.');
    }

    /**
     * 이미 결제할 수 없는 주문의 닫힘 보고는 무시한다.
     *
     * @effects close_report_ignores_order_that_is_no_longer_payable
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_order_that_is_no_longer_payable(): void
    {
        $order = $this->makeOrder('ORD-NICE-CLOSE-004', 10000);
        $order->order_status = OrderStatusEnum::PAYMENT_COMPLETE;

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-NICE-CLOSE-004')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $this->actAsOrderOwner($order);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-004',
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'order_not_payable');
    }

    /**
     * 행을 잠근 뒤 주문을 다시 확인해, 그사이 결제완료된 주문은 실패 처리하지 않는다.
     *
     * @effects close_report_rechecks_locked_order_before_marking_failed
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rechecks_locked_order_before_marking_failed(): void
    {
        $order = OrderFactory::new()->create([
            'order_number' => 'ORD-NICE-CLOSE-RACE',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 0,
        ]);

        $staleOrder = $order->fresh(['payment']);
        $order->update([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_due_amount' => 0,
            'total_paid_amount' => 10000,
            'paid_at' => now(),
        ]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => 10000,
            'paid_at' => now(),
            'transaction_id' => 'TID_ALREADY_PAID',
        ]);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-NICE-CLOSE-RACE')
            ->andReturn($staleOrder);
        $orderService->shouldNotReceive('failPayment');
        $this->app->instance(OrderProcessingService::class, $orderService);

        // 컨트롤러는 목이 돌려주는 $staleOrder 로 소유자를 확인한다.
        $this->actAsOrderOwner($staleOrder);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-RACE',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'nicepay-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'order_not_payable');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::PAID, $order->payment->payment_status);
    }

    /**
     * 주문 상태가 아직 결제대기여도 결제가 성공했으면 닫힘 보고를 무시한다.
     *
     * @effects close_report_ignores_order_when_payment_already_paid_but_status_still_pending
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_order_when_payment_already_paid_but_status_still_pending(): void
    {
        // race 재현: 승인 콜백이 payment 를 먼저 PAID 로 갱신했으나 order_status 는 아직 PENDING_ORDER.
        // 카드 주문은 승인 직전까지 PENDING_ORDER(=isBeforePayment) 라 order_status 가드만으로는
        // 통과한다. 행 락 획득 후 payment_status=PAID 재확인으로만 차단된다(payment_already_paid).
        $order = OrderFactory::new()->create([
            'order_number' => 'ORD-NICE-CLOSE-PAID',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 10000,
            'paid_at' => now(),
            'transaction_id' => 'TID_PAID_RACE',
        ]);

        $this->actAsOrderOwner($order);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-PAID',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'nicepay-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'payment_already_paid');

        // 결제 성공이 닫힘 보고로 덮이지 않아야 한다.
        $order->refresh();
        $this->assertEquals(PaymentStatusEnum::PAID, $order->payment->payment_status);
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 가상계좌 입금대기 주문은 뒤늦은 닫힘 보고로 취소되지 않는다.
     *
     * @effects close_report_ignores_vbank_order_waiting_for_deposit
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_vbank_order_waiting_for_deposit(): void
    {
        $order = OrderFactory::new()->create([
            'order_number' => 'ORD-NICE-CLOSE-VBANK-WAIT',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 0,
            'transaction_id' => 'TID_VBANK_WAITING',
            'vbank_name' => '국민은행',
            'vbank_number' => '1234567890',
            'vbank_issued_at' => now(),
            'payment_meta' => [
                'vbank_tid' => 'TID_VBANK_WAITING',
                'vbank_num' => '1234567890',
                'vbank_name' => '국민은행',
            ],
        ]);

        $this->actAsOrderOwner($order);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-VBANK-WAIT',
            'price' => 10000,
            'payment_method' => 'vbank',
            'reason' => 'nicepay-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'payment_not_ready');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $order->payment->payment_status);
        $this->assertSame('TID_VBANK_WAITING', $order->payment->transaction_id);
        $this->assertNull($order->order_meta['payment_failure_code'] ?? null);
    }

    /**
     * 주문을 회원 소유로 만들고 그 회원으로 로그인합니다.
     *
     * 소유자 확인이 이 테스트의 관심사가 아닐 때 게이트를 정당하게 통과시키기 위한 준비다.
     * 소유자 확인 자체는 PaymentCloseReportOwnershipTest 가 검증한다.
     *
     * @param  Order  $order  대상 주문 (저장된 주문이면 DB 에도 반영)
     * @return User 로그인한 소유 회원
     */
    private function actAsOrderOwner(Order $order): User
    {
        $owner = User::factory()->create();
        $order->user_id = $owner->id;
        if ($order->exists) {
            Order::query()->whereKey($order->getKey())->update(['user_id' => $owner->id]);
        }
        Sanctum::actingAs($owner);

        return $owner;
    }

    /**
     * 저장하지 않은 결제대기 주문 모델을 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  int  $amount  금액
     * @return Order 생성한 주문
     */
    private function makeOrder(string $orderNumber, int $amount): Order
    {
        $order = new Order;
        $order->order_number = $orderNumber;
        $order->order_status = OrderStatusEnum::PENDING_ORDER;
        $order->currency = 'KRW';
        $order->currency_snapshot = self::krwCurrencySnapshot();
        $order->total_due_amount = $amount;

        return $order;
    }
}
