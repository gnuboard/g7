<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * KVE-2026-2135 특성화(characterization) 테스트 — 검수 전용, 현재 동작을 실측한다.
 *
 * 신고 PoC: 주문 청구액 50,000원, 토스 confirm totalAmount 1,000원(가상계좌 WAITING_FOR_DEPOSIT).
 * 이 테스트는 "수정 후 기대치" 가 아니라 **현재 코드의 실제 동작**을 단언해, 취약점이 실존하는지
 * 자동 판정한다. 통과하면 취약(과소입금 승인)이라는 뜻이다.
 */
class Kve20262135VirtualAccountUnderpaymentTest extends PluginTestCase
{
    private const SECRET = 'wsk_kve_2026_2135';

    private function mockPluginSettings(array $overrides = []): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn(array_merge([
            'is_test_mode' => true,
            'webhook_secret_verify' => true,
        ], $overrides));
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    private function createOrder(int $totalAmount = 50000): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-KVE-'.random_int(100000, 999999),
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

    private function underAmountVaConfirm(string $paymentKey, string $orderId, int $totalAmount): array
    {
        return [
            'paymentKey' => $paymentKey,
            'orderId' => $orderId,
            'status' => 'WAITING_FOR_DEPOSIT',
            'totalAmount' => $totalAmount,
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
        ];
    }

    /** 단계 1 — 성공 콜백: 50,000 주문에 1,000 confirm 이 와도 가상계좌가 저장되는가? */
    public function test_step1_success_callback_saves_virtual_account_for_underamount(): void
    {
        $this->mockPluginSettings();
        $order = $this->createOrder(50000);
        $paymentKey = 'pk_test_kve_2135';

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response(
                $this->underAmountVaConfirm($paymentKey, $order->order_number, 1000), 200
            ),
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $order->order_number,
            'amount' => 1000,
        ]));

        $location = $response->headers->get('Location');
        fwrite(STDERR, "\n[KVE-2135 step1] redirect Location = {$location}\n");

        $order->refresh();
        $payment = $order->payment->refresh();
        fwrite(STDERR, "[KVE-2135 step1] payment_status = {$payment->payment_status->value}, vbank_number = ".($payment->vbank_number ?? 'null').", pg_raw totalAmount = ".($payment->payment_meta['pg_raw_response']['totalAmount'] ?? 'null')."\n");

        // 현재(취약) 동작: amount_mismatch 로 가지 않고 가상계좌가 저장된다.
        $this->assertStringNotContainsString('amount_mismatch', (string) $location, 'VULN: 과소금액인데 amount_mismatch 거부가 없다');
        $this->assertSame(PaymentStatusEnum::WAITING_DEPOSIT->value, $payment->payment_status->value, 'VULN: 과소금액 가상계좌가 입금대기로 저장됨');
    }

    /** 단계 2 — 입금통보 DONE: 1,000 만 입금됐는데 주문이 결제완료로 전이되는가? */
    public function test_step2_deposit_webhook_completes_order_despite_underpayment(): void
    {
        $this->mockPluginSettings();

        // 단계 1 이후 저장된 상태를 재현: WAITING_DEPOSIT, pg_raw_response.totalAmount = 1000, secret 저장.
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-KVE-'.random_int(100000, 999999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'KRW', 'order_currency' => 'KRW', 'exchange_rate' => 1.0,
                'exchange_rates' => ['KRW' => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round']],
                'snapshot_at' => now()->toIso8601String(),
            ],
            'subtotal_amount' => 50000, 'total_product_coupon_discount_amount' => 0, 'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0, 'base_shipping_amount' => 0, 'extra_shipping_amount' => 0, 'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0, 'total_amount' => 50000, 'total_due_amount' => 50000,
            'total_points_used_amount' => 0, 'total_deposit_used_amount' => 0, 'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'tosspayments',
            'transaction_id' => 'pk_test_kve_2135',
            'paid_amount_base' => 50000,   // 애플리케이션 예정 금액 (신고서의 핵심)
            'paid_amount_local' => 50000,
            'paid_at' => null,
            'payment_meta' => [
                'toss_secret' => self::SECRET,
                'status' => 'WAITING_FOR_DEPOSIT',
                'pg_raw_response' => ['status' => 'WAITING_FOR_DEPOSIT', 'totalAmount' => 1000], // 실제 PG 확인액 1,000
            ],
        ]);

        $response = $this->postJson('/plugins/sirsoft-tosspayments/webhook/deposit', [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => 'txrd_kve_2135',
        ]);

        $order->refresh();
        $payment = $order->payment->refresh();
        fwrite(STDERR, "\n[KVE-2135 step2] webhook body = ".$response->getContent().", http = {$response->getStatusCode()}\n");
        fwrite(STDERR, "[KVE-2135 step2] order_status = {$order->order_status->value}, payment_status = {$payment->payment_status->value}, total_paid_amount = {$order->total_paid_amount}\n");

        // 현재(취약) 동작: 실입금 1,000 인데 주문이 결제완료로 전이된다.
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status, 'VULN: 과소입금인데 주문이 결제완료로 전이됨');
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->payment_status->value, 'VULN: 과소입금인데 결제 PAID');
    }
}
