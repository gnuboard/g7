<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNicepayments\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 환불 금액은 결제 통화 최소 단위로 반올림해 보낸다 (P-D10)
 *
 * 이커머스는 결제 통화로 환산한 실환불액(소수 포함)을 넘긴다. 이 플러그인은 `(int)` 로 잘라 1원 적게 돌려줬고, 토스는
 * 반올림해 같은 환불이 PG 마다 1원씩 달랐다(소수 환산이 생기는 다통화 주문).
 *
 * 시나리오 매니페스트: `tests/scenarios/refund-amount-rounding.yaml`
 */
class RefundAmountRoundingTest extends PluginTestCase
{
    /**
     * 카드 결제완료 주문을 만듭니다.
     *
     * @return Order 주문
     */
    private function paidCardOrder(): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-PD10-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'currency' => 'KRW',
            'currency_snapshot' => ['base_currency' => 'KRW', 'order_currency' => 'KRW'],
            'total_amount' => 2000,
            'total_paid_amount' => 2000,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'transaction_id' => 'PD10_TX_'.$order->id,
            'paid_amount_local' => 2000,
            'paid_amount_base' => 2000,
            'cancelled_amount' => 0,
            'currency' => 'KRW',
            'paid_at' => now(),
            'payment_meta' => ['mid' => 'nicepay00m', 'is_test_mode' => true],
        ]);

        return $order->fresh(['payment']);
    }

    /**
     * 1,000.6원 환불은 1,001원으로 결제사에 보낸다 — 잘라서 1,000원으로 보내지 않는다.
     *
     * @scenario amount=fractional
     *
     * @effects refund_amount_is_rounded_not_truncated
     *
     * @return void 반환값 없음
     */
    public function test_fractional_refund_amount_is_rounded(): void
    {
        $api = $this->createMock(NicePaymentsApiService::class);
        $api->expects($this->once())->method('cancelPayment')
            ->with($this->anything(), $this->anything(), 1001, $this->anything(), $this->anything())
            ->willReturn(['ResultCode' => '2001', 'TID' => 'PD10_CANCEL']);
        $this->app->instance(NicePaymentsApiService::class, $api);
        $order = $this->paidCardOrder();

        $result = app(PaymentRefundListener::class)->processRefund(['success' => false], $order, $order->payment, 1000.6, '테스트');

        $this->assertTrue($result['success']);
    }
}
