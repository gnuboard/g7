<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

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

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-001',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'nicepay-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'recorded');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->payment_status);
    }

    public function test_close_report_marks_real_payment_failed(): void
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
        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertEquals('USER_CANCEL', $order->order_meta['payment_failure_code'] ?? null);
        $this->assertNull($payment->cancelled_at);
    }

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

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-002',
            'price' => 9000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment amount does not match the order amount.');
    }

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

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-CURRENCY',
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment currency is not chargeable.');
    }

    public function test_close_report_rejects_buyer_mismatch(): void
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
            'buyer_email' => 'attacker@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', 'Order buyer verification failed.');
    }

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

        $response = $this->postJson('/api/plugins/sirsoft-pay_nicepayments/payment/close-report', [
            'oid' => 'ORD-NICE-CLOSE-004',
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'order_not_payable');
    }

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
