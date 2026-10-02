<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 결제 승인·취소 완료 훅이 넘기는 토스 응답에 가상계좌 웹훅 검증값(`secret`)이 실리지 않는다.
 *
 * 토스 Payment 객체의 `secret` 은 가상계좌 입금통보 웹훅이 진짜인지 대조하는 값이다. 승인 응답(`after_confirm`)과
 * 취소 응답(`after_cancel`)을 통째로 훅 인자로 넘기면, 큐로 실행되는 구독자가 생기는 순간 작업 표(실패하면 실패 작업 표)에
 * 그 값의 사본이 남는다. 구독자 중 이 값을 읽어야 하는 곳은 없다 — 웹훅 대조는 결제행 `payment_meta.toss_secret` 으로 한다.
 *
 * @scenario hook=after_confirm
 *
 * @effects after_confirm_payload_excludes_webhook_secret, after_cancel_payload_excludes_webhook_secret,
 *          hook_payload_keeps_other_response_fields, webhook_secret_is_still_stored_for_deposit_verification
 */
class HookPayloadSecretTest extends PluginTestCase
{
    private const SECRET = 'wsk_hook_payload_secret';

    /**
     * 플러그인 설정을 mock 합니다.
     *
     * @return PluginSettingsService 설정 서비스 mock
     */
    private function mockPluginSettings(): PluginSettingsService
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_hook_payload',
            'webhook_secret_verify' => true,
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);

        return $mock;
    }

    /**
     * 원화 스냅샷을 고정한 가상계좌 결제대기 주문을 만듭니다.
     *
     * @param  int  $totalAmount  주문 청구액
     * @return Order 생성된 주문
     */
    private function createVbankOrder(int $totalAmount = 50000): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-HPS-'.random_int(100000, 999999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'KRW',
                'exchange_rate' => 1.0,
                'exchange_rates' => [
                    'KRW' => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round'],
                ],
                'snapshot_at' => now()->toIso8601String(),
            ],
            'subtotal_amount' => $totalAmount,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'total_due_amount' => $totalAmount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'tosspayments',
            'paid_amount_base' => $totalAmount,
            'paid_amount_local' => $totalAmount,
            'paid_at' => null,
        ]);

        return $order;
    }

    /**
     * 승인 완료 훅의 토스 응답에서 `secret` 이 빠지고, 나머지 응답 필드와 결제행의 웹훅 대조값은 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario hook=after_confirm
     *
     * @effects after_confirm_payload_excludes_webhook_secret, hook_payload_keeps_other_response_fields,
     *          webhook_secret_is_still_stored_for_deposit_verification
     */
    public function test_after_confirm_payload_carries_no_webhook_secret(): void
    {
        $this->mockPluginSettings();
        $order = $this->createVbankOrder(50000);
        $paymentKey = 'pk_hook_payload_confirm';

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => $paymentKey,
                'orderId' => $order->order_number,
                'status' => 'WAITING_FOR_DEPOSIT',
                'totalAmount' => 50000,
                'method' => '가상계좌',
                'secret' => self::SECRET,
                'virtualAccount' => [
                    'bankCode' => '20',
                    'bank' => '우리',
                    'accountNumber' => '56212345678901',
                    'customerName' => '홍길동',
                    'dueDate' => now()->addDay()->toIso8601String(),
                    'useEscrow' => false,
                ],
            ], 200),
        ]);

        $captured = null;
        HookManager::addAction('sirsoft-tosspayments.payment.after_confirm', function ($order, $pgResponse) use (&$captured) {
            $captured = $pgResponse;
        }, 1);

        $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $order->order_number,
            'amount' => 50000,
        ]))->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertIsArray($captured, '승인 완료 훅이 발화하지 않았다');
        $this->assertArrayNotHasKey('secret', $captured, '승인 완료 훅 인자에 가상계좌 웹훅 검증값 원문이 실렸다');
        $this->assertStringNotContainsString(self::SECRET, serialize($captured));
        $this->assertSame(50000, $captured['totalAmount'] ?? null, 'secret 외 응답 필드는 그대로 넘긴다');
        $this->assertSame('56212345678901', $captured['virtualAccount']['accountNumber'] ?? null);

        // 입금통보 웹훅 대조에 쓰는 저장값은 훅 인자와 무관하게 그대로 남아야 한다.
        $payment = $order->payment->refresh();
        $this->assertSame(PaymentStatusEnum::WAITING_DEPOSIT->value, $payment->payment_status->value);
        $this->assertSame(self::SECRET, $payment->payment_meta['toss_secret'] ?? null);
    }

    /**
     * 취소 완료 훅의 토스 응답에서 `secret` 이 빠지고, 서비스의 반환값(호출자가 쓰는 응답)은 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario hook=after_cancel
     *
     * @effects after_cancel_payload_excludes_webhook_secret, hook_payload_keeps_other_response_fields
     */
    public function test_after_cancel_payload_carries_no_webhook_secret(): void
    {
        $paymentKey = 'pk_hook_payload_cancel';

        Http::fake([
            "api.tosspayments.com/v1/payments/{$paymentKey}/cancel" => Http::response([
                'paymentKey' => $paymentKey,
                'orderId' => 'ORD-HPS-CANCEL',
                'status' => 'CANCELED',
                'totalAmount' => 50000,
                'secret' => self::SECRET,
                'cancels' => [['cancelAmount' => 50000, 'cancelReason' => '테스트 취소']],
            ], 200),
        ]);

        $captured = null;
        HookManager::addAction('sirsoft-tosspayments.payment.after_cancel', function ($paymentKey, $response) use (&$captured) {
            $captured = $response;
        }, 1);

        $result = (new TossPaymentsApiService($this->mockPluginSettings()))->cancelPayment($paymentKey, '테스트 취소');

        $this->assertIsArray($captured, '취소 완료 훅이 발화하지 않았다');
        $this->assertArrayNotHasKey('secret', $captured, '취소 완료 훅 인자에 가상계좌 웹훅 검증값 원문이 실렸다');
        $this->assertStringNotContainsString(self::SECRET, serialize($captured));
        $this->assertSame('CANCELED', $captured['status'] ?? null, 'secret 외 응답 필드는 그대로 넘긴다');
        $this->assertSame(50000, $captured['cancels'][0]['cancelAmount'] ?? null);

        // 호출자(환불 리스너)가 받는 서비스 반환값은 바꾸지 않는다 — 훅 인자만 걸러 넘긴다.
        $this->assertSame(self::SECRET, $result['secret'] ?? null);
    }
}
