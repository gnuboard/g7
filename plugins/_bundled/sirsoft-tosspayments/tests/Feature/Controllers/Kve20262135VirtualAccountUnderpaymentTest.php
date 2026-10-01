<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 회귀 테스트 — 가상계좌 과소금액 발급·입금이 결제완료로 이어지지 않는다.
 *
 * 신고 재현: 주문 청구액 50,000원, 성공 리다이렉트 amount 1,000원, 토스 발급 totalAmount 1,000원.
 * 수정 전에는 발급 단계에서 금액을 대조하지 않아 1,000원 계좌가 입금대기로 저장됐고, 입금통보는
 * 결제행에 저장해 둔 예정액(`paid_amount_base`)끼리 대조해 주문을 결제완료로 전이했다.
 *
 * @scenario path=vbank_issue, check=callback_amount
 *
 * @effects tampered_callback_amount_is_rejected_before_confirm,
 *          legacy_deposit_is_verified_by_payment_lookup
 */
class Kve20262135VirtualAccountUnderpaymentTest extends PluginTestCase
{
    private const SECRET = 'wsk_kve_2026_2135';

    /**
     * 플러그인 설정을 mock 합니다.
     *
     * @param  array<string, mixed>  $overrides  기본 설정을 덮어쓸 값
     * @return void 반환값 없음
     */
    private function mockPluginSettings(array $overrides = []): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn(array_merge([
            'is_test_mode' => true,
            'webhook_secret_verify' => true,
        ], $overrides));
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 원화 스냅샷을 고정한 결제대기 주문을 만듭니다.
     *
     * @param  int  $totalAmount  주문 청구액
     * @param  array<string, mixed>  $paymentOverrides  결제행 덮어쓸 속성
     * @return Order 생성된 주문
     */
    private function createOrder(int $totalAmount = 50000, array $paymentOverrides = []): Order
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

        OrderPaymentFactory::new()->create(array_merge([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'tosspayments',
            'paid_amount_base' => $totalAmount,
            'paid_amount_local' => $totalAmount,
            'paid_at' => null,
        ], $paymentOverrides));

        return $order;
    }

    /**
     * 과소금액 가상계좌 발급 응답을 만듭니다.
     *
     * @param  string  $paymentKey  결제 키
     * @param  string  $orderId  주문번호
     * @param  int  $totalAmount  발급 금액
     * @return array<string, mixed> 발급 응답
     */
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

    /**
     * 단계 1 — 성공 콜백: 50,000 원 주문에 1,000 원 콜백이 오면 발급을 요청하지 않고 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=vbank_issue, check=callback_amount
     *
     * @effects tampered_callback_amount_is_rejected_before_confirm
     */
    public function test_step1_underamount_callback_is_rejected_before_issuing_a_virtual_account(): void
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

        $this->assertStringContainsString('error=amount_mismatch', (string) $response->headers->get('Location'));
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/v1/payments/confirm'));

        $payment = $order->payment->refresh();
        $this->assertSame(PaymentStatusEnum::READY->value, $payment->payment_status->value, '과소금액 가상계좌가 입금대기로 저장됨');
        $this->assertNull($payment->vbank_number);
    }

    /**
     * 단계 2 — 입금통보 DONE: 수정 전에 1,000 원으로 발급된 결제행은 결제 조회로 금액을 확인해 거절한다.
     *
     * 발급 금액 기록(`toss_approved_amount`)이 없는 결제행이다. 결제 조회가 1,000 원 입금 완료를
     * 돌려주므로 청구액 50,000 원과 어긋나 결제완료로 전이하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=deposit_webhook, check=legacy_lookup
     *
     * @effects legacy_deposit_is_verified_by_payment_lookup
     */
    public function test_step2_legacy_underpaid_deposit_is_not_completed(): void
    {
        $this->mockPluginSettings();

        // 수정 전 단계 1 이 남긴 상태를 재현: WAITING_DEPOSIT, pg_raw_response.totalAmount = 1000, secret 저장.
        $order = $this->createOrder(50000, [
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => 'pk_test_kve_2135',
            'payment_meta' => [
                'toss_secret' => self::SECRET,
                'status' => 'WAITING_FOR_DEPOSIT',
                'pg_raw_response' => ['status' => 'WAITING_FOR_DEPOSIT', 'totalAmount' => 1000],
            ],
        ]);

        Http::fake([
            'api.tosspayments.com/v1/payments/pk_test_kve_2135' => Http::response([
                'paymentKey' => 'pk_test_kve_2135',
                'orderId' => $order->order_number,
                'status' => 'DONE',
                'totalAmount' => 1000,
            ], 200),
        ]);

        $response = $this->postJson('/plugins/sirsoft-tosspayments/webhook/deposit', [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => 'txrd_kve_2135',
        ]);

        $this->assertSame('FAIL', $response->getContent());
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/v1/payments/pk_test_kve_2135'));

        $order->refresh();
        $payment = $order->payment->refresh();

        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status, '과소입금인데 주문이 결제완료로 전이됨');
        $this->assertSame(PaymentStatusEnum::WAITING_DEPOSIT->value, $payment->payment_status->value, '과소입금인데 결제 PAID');
    }
}
