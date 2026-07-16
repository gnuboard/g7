<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Listeners;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNicepayments\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class PaymentRefundListenerTest extends PluginTestCase
{
    private function createOrderWithPayment(string $tid = 'TID_REFUND_001', int $amount = 50000): array
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $amount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $amount,
            'total_due_amount' => $amount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => $amount,
        ]);

        $payment = OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => $amount,
            'paid_at' => now(),
            'transaction_id' => $tid,
        ]);

        return [$order, $payment];
    }

    public function test_process_refund_skips_non_nicepayments_provider(): void
    {
        [$order, $payment] = $this->createOrderWithPayment();
        $payment->update(['pg_provider' => 'other_pg']);
        $payment->refresh();

        $listener = new PaymentRefundListener();
        $initial = ['success' => false, 'error_code' => null];

        $result = $listener->processRefund($initial, $order, $payment, 50000.0, null);

        $this->assertSame($initial, $result);
    }

    public function test_process_refund_returns_error_when_tid_is_missing(): void
    {
        [$order, $payment] = $this->createOrderWithPayment();
        $payment->update(['transaction_id' => null]);
        $payment->refresh();

        $listener = new PaymentRefundListener();
        $result = $listener->processRefund([], $order, $payment, 50000.0, null);

        $this->assertFalse($result['success']);
        $this->assertEquals('MISSING_TID', $result['error_code']);
    }

    public function test_process_refund_returns_success_on_successful_cancel(): void
    {
        [$order, $payment] = $this->createOrderWithPayment('TID_SUCCESS', 50000);

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->method('cancelPayment')
            ->willReturn(['ResultCode' => '2001', 'TID' => 'TID_CANCEL']);

        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $listener = new PaymentRefundListener();
        $result = $listener->processRefund([], $order, $payment, 50000.0, '고객 요청');

        $this->assertTrue($result['success']);
        $this->assertNull($result['error_code']);
        $this->assertEquals('TID_CANCEL', $result['transaction_id']);
    }

    public function test_process_refund_restores_payment_time_mid_and_mode(): void
    {
        [$order, $payment] = $this->createOrderWithPayment('TID_STORED_MID', 50000);
        $payment->update([
            'payment_meta' => [
                'mid' => 'SRLIVE001',
                'is_test_mode' => false,
            ],
        ]);
        $payment->refresh();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->once())
            ->method('useStoredCredentials')
            ->with(false, 'SRLIVE001');
        $apiServiceMock->expects($this->once())
            ->method('cancelPayment')
            ->willReturn(['ResultCode' => '2001', 'TID' => 'TID_CANCEL']);

        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $listener = new PaymentRefundListener();
        $result = $listener->processRefund([], $order, $payment, 50000.0, '고객 요청');

        $this->assertTrue($result['success']);
        $this->assertEquals('TID_CANCEL', $result['transaction_id']);
    }

    public function test_process_refund_fires_refund_failed_hook_on_api_error(): void
    {
        [$order, $payment] = $this->createOrderWithPayment('TID_FAIL', 50000);

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->method('cancelPayment')
            ->willThrowException(new \Exception('PG API 오류'));

        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $hookFired = false;
        $capturedArgs = [];

        HookManager::addAction(
            'sirsoft-pay_nicepayments.payment.refund_failed',
            function (Order $o, OrderPayment $p, array $context) use (&$hookFired, &$capturedArgs) {
                $hookFired = true;
                $capturedArgs = $context;
            },
            10
        );

        $listener = new PaymentRefundListener();
        $result = $listener->processRefund([], $order, $payment, 50000.0, null);

        $this->assertFalse($result['success']);
        $this->assertEquals('PG_API_ERROR', $result['error_code']);
        $this->assertTrue($hookFired, 'refund_failed 훅이 발동되어야 합니다');
        $this->assertEquals('TID_FAIL', $capturedArgs['tid']);
        $this->assertEquals(50000, $capturedArgs['cancel_amt']);
        $this->assertEquals('PG API 오류', $capturedArgs['error']);
    }
}
