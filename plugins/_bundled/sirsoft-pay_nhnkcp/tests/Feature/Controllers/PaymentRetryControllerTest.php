<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class PaymentRetryControllerTest extends PluginTestCase
{
    private const ENDPOINT = '/api/plugins/sirsoft-pay_nhnkcp/payment/retry';

    private function createOrder(
        int $amount = 10000,
        OrderStatusEnum $orderStatus = OrderStatusEnum::PENDING_ORDER,
        PaymentStatusEnum $paymentStatus = PaymentStatusEnum::READY,
        array $orderMeta = [],
        array $paymentMeta = [],
    ): Order {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-KCP-RETRY-' . random_int(10000, 99999),
            'order_status' => $orderStatus,
            'subtotal_amount' => $amount,
            'total_amount' => $amount,
            'total_due_amount' => $amount,
            'total_paid_amount' => 0,
            'order_meta' => $orderMeta,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => $paymentStatus,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'cancelled_at' => $paymentStatus === PaymentStatusEnum::CANCELLED ? now() : null,
            'transaction_id' => null,
            'card_approval_number' => null,
            'payment_meta' => $paymentMeta,
        ]);

        return $order;
    }

    private static function krwCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    private static function invalidUsdCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1000,
                ],
                'USD' => [
                    'rate' => 0,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
                    'decimal_places' => 2,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    public function test_retry_returns_ready_for_pending_order(): void
    {
        $order = $this->createOrder(10000);

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
    }

    public function test_retry_rejects_invalid_payment_currency_without_server_error(): void
    {
        $order = $this->createOrder(10000);
        $order->update([
            'currency' => 'USD',
            'currency_snapshot' => self::invalidUsdCurrencySnapshot(),
        ]);

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment currency is not chargeable.');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
    }

    public function test_retry_restores_cancelled_kcp_failure_order(): void
    {
        $order = $this->createOrder(
            20000,
            OrderStatusEnum::CANCELLED,
            PaymentStatusEnum::CANCELLED,
            [
                'payment_failure_code' => 'USER_CANCEL',
                'payment_failure_message' => '사용자가 NHN KCP 결제창을 닫았습니다.',
                'payment_failed_at' => now()->subMinute()->toIso8601String(),
            ],
            [
                'failure_source' => 'nhnkcp',
                'failure_code' => 'USER_CANCEL',
                'failure_message' => 'kcp-window-closed',
                'failure_stage' => 'window_closed',
                'failed_at' => now()->subMinute()->toIso8601String(),
            ],
        );

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 20000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'restored');

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertArrayNotHasKey('payment_failure_code', $order->order_meta);
        $this->assertSame(1, $order->order_meta['nhnkcp_retry_count'] ?? null);
        $this->assertNotEmpty($order->order_meta['nhnkcp_retry_failures'] ?? []);

        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->cancelled_at);
        $this->assertArrayNotHasKey('failure_source', $payment->payment_meta);
        $this->assertSame(1, $payment->payment_meta['retry_count'] ?? null);
    }

    public function test_retry_rejects_amount_mismatch_failure_order(): void
    {
        $order = $this->createOrder(
            20000,
            OrderStatusEnum::CANCELLED,
            PaymentStatusEnum::FAILED,
            [
                'payment_failure_code' => 'AMOUNT_MISMATCH',
                'payment_failure_message' => '금액 불일치',
                'payment_failed_at' => now()->subMinute()->toIso8601String(),
            ],
            [
                'failure_source' => 'nhnkcp',
                'failure_code' => 'AMOUNT_MISMATCH',
                'failure_message' => '금액 불일치',
                'failure_stage' => 'amount_mismatch',
                'failed_at' => now()->subMinute()->toIso8601String(),
            ],
        );

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 20000,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('errors.message.0', 'Order is not retryable for NHN KCP payment.');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->payment_status);
    }

    public function test_retry_rejects_buyer_mismatch(): void
    {
        $order = $this->createOrder(20000);
        OrderAddressFactory::new()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]);

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 20000,
            'buyer_email' => 'attacker@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', 'Order buyer verification failed.');
    }
}
