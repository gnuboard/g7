<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class PaymentCloseReportControllerTest extends PluginTestCase
{
    public function test_close_report_marks_pending_order_failed_and_payment_cancelled(): void
    {
        $order = $this->makeOrder('ORD-CLOSE-001', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-001')
            ->andReturn($order);
        $orderService->shouldReceive('failPayment')
            ->once()
            ->with($order, 'USER_CANCEL', '사용자가 KG 이니시스 결제창을 닫았습니다.')
            ->andReturn($order);
        $orderService->shouldReceive('recordPaymentCancellation')
            ->once()
            ->with($order, 'USER_CANCEL', 'inicis-overlay-closed')
            ->andReturn($order);

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-001',
            'price' => 10000,
            'buyer_email' => 'BUYER@example.com',
            'buyer_phone' => '01012345678',
            'payment_method' => 'card',
            'reason' => 'inicis-overlay-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'recorded');
    }

    public function test_close_report_rejects_amount_mismatch(): void
    {
        $order = $this->makeOrder('ORD-CLOSE-002', 10000);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-002')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-002',
            'price' => 9000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment amount does not match the order amount.');
    }

    public function test_close_report_rejects_non_krw_order_without_failing_payment(): void
    {
        $order = $this->makeOrder('ORD-CLOSE-USD-001', 10000, 'USD');

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-USD-001')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-USD-001',
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Standard KG Inicis close report is only available for KRW orders.');
    }

    public function test_close_report_rejects_buyer_mismatch(): void
    {
        $order = $this->makeOrder('ORD-CLOSE-003', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-003')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-003',
            'price' => 10000,
            'buyer_email' => 'attacker@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', 'Order buyer verification failed.');
    }

    public function test_close_report_ignores_order_that_is_no_longer_payable(): void
    {
        $order = $this->makeOrder('ORD-CLOSE-004', 10000);
        $order->order_status = OrderStatusEnum::PAYMENT_COMPLETE;

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-004')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-004',
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'order_not_payable');
    }

    public function test_close_report_ignores_order_when_payment_already_paid(): void
    {
        // race 재현: 승인 콜백이 payment 를 먼저 PAID 로 갱신했으나 order_status 는 아직 PENDING_ORDER.
        $order = $this->makeOrder('ORD-CLOSE-PAID-001', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));
        $order->setRelation('payment', new OrderPayment([
            'payment_status' => PaymentStatusEnum::PAID,
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-PAID-001')
            ->andReturn($order);
        // 결제가 이미 성공했으므로 실패/취소 처리를 호출하면 안 된다.
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-PAID-001',
            'price' => 10000,
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01012345678',
            'payment_method' => 'card',
            'reason' => 'inicis-overlay-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'payment_already_paid');
    }

    public function test_close_report_preserves_easy_pay_context_on_cancelled_order(): void
    {
        $order = OrderFactory::new()->create([
            'order_number' => 'ORD-CLOSE-EASYPAY-001',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);

        $payment = OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'embedded_pg_provider' => null,
            'transaction_id' => null,
            'paid_amount_local' => 0,
            'paid_amount_base' => 10000,
            'payment_meta' => ['existing' => 'value'],
            'paid_at' => null,
        ]);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-EASYPAY-001',
            'price' => 10000,
            'payment_method' => 'kginicis_kakaopay',
            'reason' => 'inicis-overlay-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'recorded');

        $payment->refresh();

        $this->assertSame(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertSame('kakaopay', $payment->embedded_pg_provider);
        $this->assertSame('value', $payment->payment_meta['existing'] ?? null);
        $this->assertSame('kginicis_kakaopay', $payment->payment_meta['selected_payment_method'] ?? null);
        $this->assertSame('kakaopay', $payment->payment_meta['embedded_pg_provider'] ?? null);
        $this->assertSame('카카오페이', $payment->payment_meta['embedded_pg_provider_label'] ?? null);
        $this->assertSame('kginicis_kakaopay', $payment->payment_meta['close_report_payment_method'] ?? null);
    }

    private function makeOrder(string $orderNumber, int $amount, string $currency = 'KRW'): Order
    {
        $order = new Order;
        $order->order_number = $orderNumber;
        $order->order_status = OrderStatusEnum::PENDING_ORDER;
        $order->currency = $currency;
        $order->total_due_amount = $amount;

        return $order;
    }
}
