<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Tosspayments\Support\ShopRedirectUrl;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 결제 금액 검증 회귀 테스트 — 결제완료·가상계좌 발급·입금 반영의 금액 근거.
 *
 * 결제 성립의 금액 근거는 토스가 실제로 승인·발급한 금액(`totalAmount`)과 주문에 저장된
 * 청구액뿐이다. 성공 리다이렉트의 `amount` 쿼리는 브라우저가 보낸 값이라 누구나 바꿀 수 있다.
 * 금액이 어긋나거나 확인할 수 없으면 거절하고, 이미 성립한 토스 승인은 취소하며, 결제완료로
 * 전이하지 않는다.
 *
 * @scenario path=card_confirm, check=callback_amount
 *
 * @effects tampered_callback_amount_is_rejected_before_confirm,
 *          confirm_is_requested_with_the_order_charge,
 *          approved_amount_mismatch_cancels_and_does_not_mark_paid,
 *          missing_approved_amount_cancels_and_does_not_mark_paid,
 *          module_amount_rejection_after_confirm_cancels_the_approval,
 *          not_payable_order_is_not_confirmed,
 *          already_recorded_payment_key_skips_confirm,
 *          vbank_issue_amount_mismatch_cancels_and_writes_no_row,
 *          vbank_issue_stores_the_verified_amount,
 *          deposit_webhook_completes_with_the_issuance_verified_amount,
 *          deposit_webhook_rejects_a_stored_amount_that_differs_from_the_charge,
 *          legacy_deposit_is_verified_by_payment_lookup,
 *          legacy_deposit_is_rejected_when_lookup_is_not_done,
 *          legacy_deposit_lookup_failure_asks_for_a_retry
 */
class PaymentAmountVerificationTest extends PluginTestCase
{
    private const SUCCESS_URL = '/plugins/sirsoft-tosspayments/payment/success';

    private const DEPOSIT_URL = '/plugins/sirsoft-tosspayments/webhook/deposit';

    private const SECRET = 'wsec_amount_verification';

    /** 주문번호 충돌 방지용 시퀀스. */
    private static int $sequence = 0;

    /**
     * 플러그인 설정을 테스트 모드 고정값으로 대체합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_mock_key',
            'webhook_secret_verify' => true,
            'redirect_success_url' => ShopRedirectUrl::DEFAULT_SUCCESS_URL,
            'redirect_fail_url' => ShopRedirectUrl::DEFAULT_FAIL_URL,
        ]);
        $this->app->instance(PluginSettingsService::class, $settings);
    }

    /**
     * 원화 스냅샷을 고정한 주문과 결제행을 만듭니다.
     *
     * 스냅샷에 `decimal_places` 를 명시해 상점 통화 설정 조회로 떨어지지 않게 한다 —
     * 떨어지면 원화가 2자리로 해석되어 청구액이 100배가 된다.
     *
     * @param  int  $totalAmount  주문 청구액
     * @param  array<string, mixed>  $orderOverrides  주문 덮어쓸 속성
     * @param  array<string, mixed>  $paymentOverrides  결제행 덮어쓸 속성
     * @return Order 생성된 주문
     */
    private function createOrder(int $totalAmount = 50000, array $orderOverrides = [], array $paymentOverrides = []): Order
    {
        $order = OrderFactory::new()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-AMTV-'.(++self::$sequence),
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
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
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
        ], $orderOverrides));

        OrderPaymentFactory::new()->create(array_merge([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'tosspayments',
            'currency' => 'KRW',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'payment_meta' => null,
        ], $paymentOverrides));

        return $order;
    }

    /**
     * 입금대기(가상계좌 발급 완료) 주문을 만듭니다.
     *
     * @param  int  $totalAmount  주문 청구액
     * @param  array<string, mixed>  $paymentMeta  결제행 payment_meta
     * @return Order 생성된 주문
     */
    private function createWaitingDepositOrder(int $totalAmount, array $paymentMeta): Order
    {
        $seq = self::$sequence + 1;

        return $this->createOrder($totalAmount, [], [
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'transaction_id' => 'pk_amtv_vbank_'.$seq,
            'paid_amount_base' => $totalAmount,
            'paid_amount_local' => $totalAmount,
            'payment_meta' => array_merge(['toss_secret' => self::SECRET, 'status' => 'WAITING_FOR_DEPOSIT'], $paymentMeta),
        ]);
    }

    /**
     * 카드 승인 응답을 만듭니다.
     *
     * @param  string  $paymentKey  결제 키
     * @param  string  $orderId  주문번호
     * @param  int|null  $totalAmount  승인 금액 (null 이면 응답에서 뺀다)
     * @return array<string, mixed> 승인 응답
     */
    private function cardConfirm(string $paymentKey, string $orderId, ?int $totalAmount): array
    {
        $response = [
            'paymentKey' => $paymentKey,
            'orderId' => $orderId,
            'status' => 'DONE',
            'method' => '카드',
            'card' => ['issuerCode' => '41', 'number' => '4330-****-****-1234', 'approveNo' => '12345678'],
        ];

        if ($totalAmount !== null) {
            $response['totalAmount'] = $totalAmount;
        }

        return $response;
    }

    /**
     * 가상계좌 발급 응답을 만듭니다.
     *
     * @param  string  $paymentKey  결제 키
     * @param  string  $orderId  주문번호
     * @param  int  $totalAmount  발급 금액
     * @return array<string, mixed> 발급 응답
     */
    private function vbankConfirm(string $paymentKey, string $orderId, int $totalAmount): array
    {
        return [
            'paymentKey' => $paymentKey,
            'orderId' => $orderId,
            'status' => 'WAITING_FOR_DEPOSIT',
            'method' => '가상계좌',
            'totalAmount' => $totalAmount,
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
     * 토스 승인·취소 API 를 가짜로 세웁니다.
     *
     * @param  array<string, mixed>  $confirmResponse  승인 응답
     * @return void 반환값 없음
     */
    private function fakeToss(array $confirmResponse): void
    {
        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response($confirmResponse, 200),
            'api.tosspayments.com/v1/payments/*/cancel' => Http::response(['status' => 'CANCELED'], 200),
        ]);
    }

    /**
     * 성공 리다이렉트를 요청합니다.
     *
     * @param  string  $paymentKey  결제 키
     * @param  string  $orderId  주문번호
     * @param  int  $amount  브라우저가 보낸 금액
     * @return TestResponse 응답
     */
    private function callSuccess(string $paymentKey, string $orderId, int $amount)
    {
        return $this->get(self::SUCCESS_URL.'?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $orderId,
            'amount' => $amount,
        ]));
    }

    /**
     * 승인 API 가 불렸는지 판정하는 조건입니다.
     *
     * @return \Closure(HttpRequest): bool 조건
     */
    private function isConfirmCall(): \Closure
    {
        return fn (HttpRequest $request) => str_contains($request->url(), '/v1/payments/confirm');
    }

    /**
     * 취소 API 가 불렸는지 판정하는 조건입니다.
     *
     * @return \Closure(HttpRequest): bool 조건
     */
    private function isCancelCall(): \Closure
    {
        return fn (HttpRequest $request) => str_contains($request->url(), '/cancel');
    }

    // ===== 성공 리다이렉트 — 승인 전 대조 =====

    /**
     * 브라우저가 바꾼 금액은 승인을 부르기 전에 거절되고 주문·결제행은 그대로다.
     *
     * 수정 전: 콜백 금액 1,000 을 그대로 Confirm 에 실어 50,000 원 주문이 1,000 원 승인으로 결제완료됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=card_confirm, check=callback_amount
     *
     * @effects tampered_callback_amount_is_rejected_before_confirm
     */
    public function test_tampered_callback_amount_is_rejected_before_confirm(): void
    {
        $order = $this->createOrder(50000);
        $this->fakeToss($this->cardConfirm('pk_amtv_tampered', $order->order_number, 1000));

        $response = $this->callSuccess('pk_amtv_tampered', $order->order_number, 1000);

        $this->assertStringContainsString('error=amount_mismatch', (string) $response->headers->get('Location'));
        Http::assertNotSent($this->isConfirmCall());

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertSame(PaymentStatusEnum::READY->value, $order->payment->refresh()->payment_status->value);
    }

    /**
     * 승인 요청 금액은 주문 청구액이고, 정상 승인은 종전대로 결제완료된다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=card_confirm, check=approved_amount
     *
     * @effects confirm_is_requested_with_the_order_charge
     */
    public function test_confirm_is_requested_with_the_order_charge(): void
    {
        $order = $this->createOrder(50000);
        $this->fakeToss($this->cardConfirm('pk_amtv_ok', $order->order_number, 50000));

        $this->callSuccess('pk_amtv_ok', $order->order_number, 50000)
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v1/payments/confirm')
            && $request['amount'] === 50000);
        Http::assertNotSent($this->isCancelCall());

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(50000, $order->payment->refresh()->payment_meta['toss_approved_amount'] ?? null);
    }

    // ===== 성공 리다이렉트 — 승인 후 대조 =====

    /**
     * 토스 승인 금액이 청구액과 다르면 승인을 취소하고 결제완료로 가지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=card_confirm, check=approved_amount
     *
     * @effects approved_amount_mismatch_cancels_and_does_not_mark_paid
     */
    public function test_approved_amount_mismatch_cancels_and_does_not_mark_paid(): void
    {
        $order = $this->createOrder(50000);
        $this->fakeToss($this->cardConfirm('pk_amtv_mismatch', $order->order_number, 1000));

        $response = $this->callSuccess('pk_amtv_mismatch', $order->order_number, 50000);

        $this->assertStringContainsString('error=amount_mismatch', (string) $response->headers->get('Location'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v1/payments/pk_amtv_mismatch/cancel'));

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertNotSame(PaymentStatusEnum::PAID->value, $order->payment->refresh()->payment_status->value);
    }

    /**
     * 승인 응답에 금액이 없으면 검증할 수 없으므로 불일치와 같게 취소한다.
     *
     * 응답 금액이 없을 때 콜백 금액으로 채우면 대조가 항등식이 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=card_confirm, check=approved_amount
     *
     * @effects missing_approved_amount_cancels_and_does_not_mark_paid
     */
    public function test_missing_approved_amount_cancels_and_does_not_mark_paid(): void
    {
        $order = $this->createOrder(50000);
        $this->fakeToss($this->cardConfirm('pk_amtv_missing', $order->order_number, null));

        $response = $this->callSuccess('pk_amtv_missing', $order->order_number, 50000);

        $this->assertStringContainsString('error=amount_mismatch', (string) $response->headers->get('Location'));
        Http::assertSent($this->isCancelCall());

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 승인 뒤 모듈이 금액을 거절해도(주문 금액 구성 불일치) 토스 승인을 취소한다.
     *
     * 수정 전: 거절 사유만 로그에 남고 토스 승인은 그대로 남아 고객 돈이 빠져나간 채였다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=card_confirm, check=approved_amount
     *
     * @effects module_amount_rejection_after_confirm_cancels_the_approval
     */
    public function test_module_amount_rejection_after_confirm_cancels_the_approval(): void
    {
        // 구성 합계(60,000)가 청구액(50,000)과 달라 모듈의 구성 대조가 거절한다.
        $order = $this->createOrder(50000, ['subtotal_amount' => 60000]);
        $this->fakeToss($this->cardConfirm('pk_amtv_module', $order->order_number, 50000));

        $response = $this->callSuccess('pk_amtv_module', $order->order_number, 50000);

        $this->assertStringContainsString('error=amount_mismatch', (string) $response->headers->get('Location'));
        Http::assertSent($this->isCancelCall());

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    // ===== 성공 리다이렉트 — 주문 상태 가드 =====

    /**
     * 결제 불가 상태 목록 (이미 결제완료 / 취소).
     *
     * @return array<string, array{0: OrderStatusEnum, 1: PaymentStatusEnum}> 주문 상태·결제 상태
     */
    public static function notPayableStates(): array
    {
        return [
            'already_paid' => [OrderStatusEnum::PAYMENT_COMPLETE, PaymentStatusEnum::PAID],
            'cancelled' => [OrderStatusEnum::CANCELLED, PaymentStatusEnum::CANCELLED],
        ];
    }

    /**
     * 결제 불가 상태의 주문은 승인을 부르지 않고 결제행도 바꾸지 않는다.
     *
     * 수정 전: 승인이 먼저 성립하고 completePayment 가 조용히 되돌아가 토스 승인만 남았다.
     *
     * @param  OrderStatusEnum  $orderStatus  주문 상태
     * @param  PaymentStatusEnum  $paymentStatus  결제 상태
     * @return void 반환값 없음
     *
     * @scenario path=card_confirm, check=order_state
     *
     * @effects not_payable_order_is_not_confirmed
     */
    #[DataProvider('notPayableStates')]
    public function test_not_payable_order_is_not_confirmed(OrderStatusEnum $orderStatus, PaymentStatusEnum $paymentStatus): void
    {
        $order = $this->createOrder(50000, ['order_status' => $orderStatus], [
            'payment_status' => $paymentStatus,
            'transaction_id' => 'pk_amtv_earlier',
        ]);
        $this->fakeToss($this->cardConfirm('pk_amtv_late', $order->order_number, 50000));

        $response = $this->callSuccess('pk_amtv_late', $order->order_number, 50000);

        $this->assertStringContainsString('error=confirm_failed', (string) $response->headers->get('Location'));
        Http::assertNotSent($this->isConfirmCall());

        $payment = $order->payment->refresh();
        $this->assertSame($paymentStatus->value, $payment->payment_status->value);
        $this->assertSame('pk_amtv_earlier', $payment->transaction_id);
    }

    /**
     * 같은 결제 키로 이미 반영된 주문의 새로고침은 승인을 다시 부르지 않고 결과 화면으로 보낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=card_confirm, check=order_state
     *
     * @effects already_recorded_payment_key_skips_confirm
     */
    public function test_already_recorded_payment_key_skips_confirm(): void
    {
        $order = $this->createOrder(50000, ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE], [
            'payment_status' => PaymentStatusEnum::PAID,
            'transaction_id' => 'pk_amtv_refresh',
        ]);
        $this->fakeToss($this->cardConfirm('pk_amtv_refresh', $order->order_number, 50000));

        $this->callSuccess('pk_amtv_refresh', $order->order_number, 50000)
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        Http::assertNotSent($this->isConfirmCall());
    }

    // ===== 성공 리다이렉트 — 가상계좌 발급 =====

    /**
     * 발급 금액이 청구액과 다르면 발급을 취소하고 계좌 정보를 쓰지 않는다.
     *
     * 수정 전: 50,000 원 주문에 1,000 원 계좌가 입금대기로 저장되고, 입금통보가 오면 결제완료됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=vbank_issue, check=approved_amount
     *
     * @effects vbank_issue_amount_mismatch_cancels_and_writes_no_row
     */
    public function test_vbank_issue_amount_mismatch_cancels_and_writes_no_row(): void
    {
        $order = $this->createOrder(50000, [], ['payment_method' => PaymentMethodEnum::VBANK]);
        $this->fakeToss($this->vbankConfirm('pk_amtv_vbank_bad', $order->order_number, 1000));

        $response = $this->callSuccess('pk_amtv_vbank_bad', $order->order_number, 50000);

        $this->assertStringContainsString('error=amount_mismatch', (string) $response->headers->get('Location'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v1/payments/pk_amtv_vbank_bad/cancel'));

        $payment = $order->payment->refresh();
        $this->assertSame(PaymentStatusEnum::READY->value, $payment->payment_status->value);
        $this->assertNull($payment->vbank_number);
        $this->assertNull($payment->transaction_id);
        $this->assertArrayNotHasKey('toss_secret', $payment->payment_meta ?? []);
    }

    /**
     * 정상 발급은 대조를 마친 발급 금액을 입금 반영의 근거로 저장한다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=vbank_issue, check=approved_amount
     *
     * @effects vbank_issue_stores_the_verified_amount
     */
    public function test_vbank_issue_stores_the_verified_amount(): void
    {
        $order = $this->createOrder(50000, [], ['payment_method' => PaymentMethodEnum::VBANK]);
        $this->fakeToss($this->vbankConfirm('pk_amtv_vbank_ok', $order->order_number, 50000));

        $this->callSuccess('pk_amtv_vbank_ok', $order->order_number, 50000)
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        Http::assertNotSent($this->isCancelCall());

        $payment = $order->payment->refresh();
        $this->assertSame(PaymentStatusEnum::WAITING_DEPOSIT->value, $payment->payment_status->value);
        $this->assertSame(50000, $payment->payment_meta['toss_approved_amount'] ?? null);
    }

    // ===== 입금통보 웹훅 =====

    /**
     * 입금통보는 발급 때 대조해 둔 금액으로 결제완료하고 결제 조회를 부르지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=deposit_webhook, check=stored_amount
     *
     * @effects deposit_webhook_completes_with_the_issuance_verified_amount
     */
    public function test_deposit_webhook_completes_with_the_issuance_verified_amount(): void
    {
        Http::fake();
        $order = $this->createWaitingDepositOrder(50000, ['toss_approved_amount' => 50000]);

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        Http::assertNothingSent();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->payment->refresh()->payment_status->value);
    }

    /**
     * 발급 금액이 현재 청구액과 다르면(발급 뒤 청구액 변경 등) 결제완료로 전이하지 않는다.
     *
     * 수정 전: 결제행 `paid_amount_base`(저장해 둔 예정액)끼리 대조해 항상 통과했다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=deposit_webhook, check=stored_amount
     *
     * @effects deposit_webhook_rejects_a_stored_amount_that_differs_from_the_charge
     */
    public function test_deposit_webhook_rejects_a_stored_amount_that_differs_from_the_charge(): void
    {
        Http::fake();
        $order = $this->createWaitingDepositOrder(50000, ['toss_approved_amount' => 1000]);

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
        ]);

        $this->assertSame('FAIL', $response->getContent());

        $order->refresh();
        $payment = $order->payment->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertSame(PaymentStatusEnum::WAITING_DEPOSIT->value, $payment->payment_status->value);
        $this->assertNull($payment->paid_at);
    }

    /**
     * 발급 금액 기록이 없는 결제행은 토스 결제 조회로 입금 완료와 금액을 확인한 뒤 결제완료한다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=deposit_webhook, check=legacy_lookup
     *
     * @effects legacy_deposit_is_verified_by_payment_lookup
     */
    public function test_legacy_deposit_is_verified_by_payment_lookup(): void
    {
        $order = $this->createWaitingDepositOrder(50000, []);
        $paymentKey = $order->payment->transaction_id;

        Http::fake([
            "api.tosspayments.com/v1/payments/{$paymentKey}" => Http::response([
                'paymentKey' => $paymentKey,
                'orderId' => $order->order_number,
                'status' => 'DONE',
                'totalAmount' => 50000,
            ], 200),
        ]);

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
        ]);

        $this->assertSame('OK', $response->getContent());
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET'
            && str_ends_with($request->url(), "/v1/payments/{$paymentKey}"));

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(50000, $order->payment->refresh()->payment_meta['toss_approved_amount'] ?? null);
    }

    /**
     * 결제 조회가 입금 완료를 보여 주지 않으면 결제완료로 전이하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=deposit_webhook, check=legacy_lookup
     *
     * @effects legacy_deposit_is_rejected_when_lookup_is_not_done
     */
    public function test_legacy_deposit_is_rejected_when_lookup_is_not_done(): void
    {
        $order = $this->createWaitingDepositOrder(50000, []);
        $paymentKey = $order->payment->transaction_id;

        Http::fake([
            "api.tosspayments.com/v1/payments/{$paymentKey}" => Http::response([
                'paymentKey' => $paymentKey,
                'orderId' => $order->order_number,
                'status' => 'WAITING_FOR_DEPOSIT',
                'totalAmount' => 50000,
            ], 200),
        ]);

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
        ]);

        $this->assertSame('FAIL', $response->getContent());

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 결제 조회 호출이 실패하면 대조를 건너뛰지 않고 재전송을 받도록 실패 상태로 답한다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=deposit_webhook, check=legacy_lookup
     *
     * @effects legacy_deposit_lookup_failure_asks_for_a_retry
     */
    public function test_legacy_deposit_lookup_failure_asks_for_a_retry(): void
    {
        $order = $this->createWaitingDepositOrder(50000, []);
        $paymentKey = $order->payment->transaction_id;

        Http::fake([
            "api.tosspayments.com/v1/payments/{$paymentKey}" => Http::response([
                'code' => 'PROVIDER_ERROR',
                'message' => '일시적인 오류가 발생했습니다.',
            ], 500),
        ]);

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
        ]);

        $response->assertStatus(500);
        $this->assertSame('FAIL', $response->getContent());

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertSame(PaymentStatusEnum::WAITING_DEPOSIT->value, $order->payment->refresh()->payment_status->value);
    }
}
