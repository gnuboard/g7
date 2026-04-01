<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\Tosspayments\Listeners\PaymentRefundListener;

/**
 * PaymentRefundListener 단위 테스트
 */
class PaymentRefundListenerTest extends TestCase
{
    /**
     * getSubscribedHooks가 올바른 훅 매핑을 반환하는지 확인
     */
    public function test_get_subscribed_hooks_returns_correct_hooks(): void
    {
        $hooks = PaymentRefundListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.payment.refund', $hooks);
        $this->assertEquals('filter', $hooks['sirsoft-ecommerce.payment.refund']['type']);
        $this->assertEquals('processRefund', $hooks['sirsoft-ecommerce.payment.refund']['method']);
        $this->assertEquals(10, $hooks['sirsoft-ecommerce.payment.refund']['priority']);
    }

    /**
     * pg_provider가 'tosspayments'가 아닌 결제는 스킵하는지 확인
     */
    public function test_process_refund_skips_non_tosspayments_provider(): void
    {
        $listener = new PaymentRefundListener();

        $order = $this->createMock(Order::class);
        $payment = $this->createStub(OrderPayment::class);
        $payment->method('__get')->willReturnCallback(function ($key) {
            return match ($key) {
                'pg_provider' => 'other_pg',
                default => null,
            };
        });

        $defaultResult = ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null];

        $result = $listener->processRefund($defaultResult, $order, $payment, 10000.0);

        $this->assertFalse($result['success']);
        $this->assertNull($result['error_code']);
    }

    /**
     * pg_provider가 'sirsoft-tosspayments'(플러그인 식별자)인 경우 스킵되는지 확인
     * (이전 버그: 플러그인 식별자와 PG provider ID를 혼동하여 항상 스킵됨)
     */
    public function test_process_refund_does_not_match_plugin_identifier(): void
    {
        $listener = new PaymentRefundListener();

        $order = $this->createMock(Order::class);
        $payment = $this->createStub(OrderPayment::class);
        $payment->method('__get')->willReturnCallback(function ($key) {
            return match ($key) {
                'pg_provider' => 'sirsoft-tosspayments',
                default => null,
            };
        });

        $defaultResult = ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null];

        $result = $listener->processRefund($defaultResult, $order, $payment, 10000.0);

        // 플러그인 식별자는 PG provider ID가 아니므로 스킵되어야 함
        $this->assertFalse($result['success']);
        $this->assertNull($result['error_code']);
    }

    /**
     * pg_provider가 'tosspayments'일 때 스킵하지 않고 처리에 진입하는지 확인
     */
    public function test_process_refund_enters_processing_for_tosspayments(): void
    {
        $listener = new PaymentRefundListener();

        $order = $this->createMock(Order::class);
        $payment = $this->createStub(OrderPayment::class);
        $payment->method('__get')->willReturnCallback(function ($key) {
            return match ($key) {
                'pg_provider' => 'tosspayments',
                'transaction_id' => 'test_payment_key',
                default => null,
            };
        });

        $defaultResult = ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null];

        // getApiService()에서 app() 호출 시 예외 발생 → 정상적으로 진입했다는 증거
        // (Unit 테스트에서 Laravel 컨테이너 없으므로 BindingResolutionException 발생)
        $this->expectException(\RuntimeException::class);

        $listener->processRefund($defaultResult, $order, $payment, 10000.0);
    }
}
