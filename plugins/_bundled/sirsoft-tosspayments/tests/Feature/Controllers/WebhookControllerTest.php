<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 토스페이먼츠 웹훅 컨트롤러 기능 테스트.
 *
 * 가상계좌 입금통보(deposit) — secret 대조 / 리플레이 멱등 / status 분기 / 상태 가드.
 *
 * @scenario webhook_status=done, webhook_secret=match, replay=first
 *
 * @effects deposit_done_completes_payment, deposit_canceled_fails_payment,
 *          webhook_secret_verified, webhook_replay_idempotent, non_waiting_deposit_is_noop
 */
class WebhookControllerTest extends PluginTestCase
{
    private const DEPOSIT_URL = '/plugins/sirsoft-tosspayments/webhook/deposit';

    private const PAYMENT_STATUS_URL = '/plugins/sirsoft-tosspayments/webhook/payment-status';

    private const SECRET = 'wsec_test_secret_value';

    /**
     * 입금대기(vbank) 주문을 생성합니다.
     *
     * 발급 때 청구액과 대조해 둔 금액(`toss_approved_amount`)을 결제행에 싣는다 — 입금통보는 그 값을
     * 결제완료 금액 근거로 쓰고, 없으면 토스 결제 조회로 확인한다(`PaymentAmountVerificationTest`).
     * 원화 스냅샷은 청구액이 상점 통화 설정에 따라 달라지지 않도록 자릿수까지 고정한다.
     *
     * @param  string  $secret  저장할 웹훅 secret
     * @param  int  $totalAmount  주문 총액
     * @param  int  $optionCount  주문 상품 행 수 (0 이면 상품 행 없음 — 기존 테스트 기본값)
     * @return Order 생성된 주문
     */
    private function createWaitingDepositOrder(string $secret = self::SECRET, int $totalAmount = 50000, int $optionCount = 0): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-VBANK-'.random_int(10000, 99999),
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
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'tosspayments',
            'transaction_id' => 'pk_test_vbank_'.$order->id,
            'paid_amount_base' => $totalAmount,
            'paid_amount_local' => $totalAmount,
            'paid_at' => null,
            'payment_meta' => [
                'toss_secret' => $secret,
                'status' => 'WAITING_FOR_DEPOSIT',
                'toss_approved_amount' => $totalAmount,
            ],
        ]);

        for ($i = 0; $i < $optionCount; $i++) {
            OrderOptionFactory::new()->create([
                'order_id' => $order->id,
                'option_status' => OrderStatusEnum::PENDING_ORDER,
                'quantity' => 1,
                'unit_price' => intdiv($totalAmount, $optionCount),
                'subtotal_price' => intdiv($totalAmount, $optionCount),
                'subtotal_paid_amount' => intdiv($totalAmount, $optionCount),
            ]);
        }

        return $order;
    }

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
     * 입금통보 DONE 은 결제를 완료로 전이한다.
     *
     * @return void 반환값 없음
     *
     * @scenario webhook_status=done, webhook_secret=match, replay=first
     *
     * @effects deposit_done_completes_payment
     */
    public function test_deposit_done_completes_payment(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => 'txn_abc',
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->payment->payment_status->value);
    }

    /**
     * 입금 전 가상계좌의 부분취소는 거절되고, 원래 금액 입금통보로 결제가 완료된다. (Q17)
     *
     * 발급된 가상계좌의 입금액은 바꿀 수 없다. 부분취소가 통과하면 결제행이 부분취소로 바뀌어 이 웹훅이
     * `waiting_deposit` 가드에서 200 으로 조용히 무시되고, 고객이 입금해도 주문은 영영 미결제로 남았다.
     *
     * @return void 반환값 없음
     *
     * @scenario webhook_status=done, webhook_secret=match, replay=first
     *
     * @effects deposit_done_completes_payment
     */
    public function test_deposit_done_after_a_rejected_partial_cancel_completes_payment(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder(optionCount: 2);
        $first = $order->options()->orderBy('id')->first();

        try {
            app(OrderCancellationService::class)->cancelOrderOptions(
                order: $order->fresh(['options', 'payment']),
                cancelItems: [['order_option_id' => $first->id, 'cancel_quantity' => 1]],
                reason: 'changed_mind',
            );
            $this->fail('입금 전 가상계좌 부분취소가 통과했습니다.');
        } catch (OrderCancellationException) {
            // 기대한 거절
        }

        $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => 'txn_after_rejected_partial',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->payment->payment_status->value);
    }

    /**
     * 입금통보가 발급 시점의 paymentKey 를 덮어쓰지 않는다. (S1-D10)
     *
     * `transaction_id` 는 **취소 API 가 쓰는 paymentKey** 다. 입금통보가 싣고 오는
     * `transactionKey` 는 거래 회차마다 달라지는 별개 식별자(`txrd_…`)이고, 그것으로 덮어쓰면
     * 이후 환불이 토스에서 **「존재하지 않는 결제 정보 입니다」** 로 거절된다.
     *
     * 실측(주문 20260915-0631411217): 발급 시 `tviva20260915153144ZJqh9` 였던 값이 입금통보 뒤
     * `txrd_a01m2hx5hqvxk3bb3vy852hfjgx` 로 바뀌어, 환불이 승인까지 간 뒤 실행에서만 실패했다
     * (`PG_API_ERROR`). 올바른 paymentKey 는 `payment_meta.pg_raw_response` 에 남아 있었다.
     *
     * @return void 반환값 없음
     *
     * @scenario webhook_case=deposit_done_keeps_payment_key
     *
     * @effects deposit_webhook_preserves_payment_key
     */
    public function test_deposit_done_keeps_the_original_payment_key(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();
        $issuedKey = $order->payment->transaction_id;

        $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => 'txrd_should_not_overwrite',
        ])->assertOk();

        $order->refresh();

        $this->assertSame(
            $issuedKey,
            $order->payment->transaction_id,
            '입금통보가 발급 시점 paymentKey 를 덮어썼다 — 이후 취소/환불이 PG 에서 거절된다'
        );
        // 입금통보의 거래 식별자는 추적용으로 남는다.
        $this->assertSame(
            'txrd_should_not_overwrite',
            $order->payment->payment_meta['deposit_transaction_key'] ?? null,
        );
    }

    /**
     * 입금통보 CANCELED 는 주문을 취소로 전이한다. (결제 상태는 코어 동작대로 유지)
     *
     * @return void 반환값 없음
     *
     * @scenario webhook_status=canceled, webhook_secret=match, replay=first
     *
     * @effects deposit_canceled_fails_payment
     */
    public function test_deposit_canceled_fails_payment(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'CANCELED',
            'secret' => self::SECRET,
        ]);

        $response->assertOk();
        $order->refresh();
        // failPayment 은 order_status 를 CANCELLED 로 전이한다 (payment_status 는 유지 — 코어 동작).
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * secret 이 어긋나면 401 이고 결제완료로 전이되지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario webhook_status=done, webhook_secret=mismatch, replay=first
     *
     * @effects webhook_secret_verified
     */
    public function test_secret_mismatch_returns_401(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => 'wrong_secret',
        ]);

        $response->assertStatus(401);
        $order->refresh();
        // 결제완료로 전이되지 않아야 함
        $this->assertNotSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * secret 검증을 끈 상점에서는 어떤 값이 와도 통보가 통과한다.
     *
     * @return void 반환값 없음
     *
     * @scenario webhook_status=done, webhook_secret=disabled, replay=first
     *
     * @effects webhook_secret_verified
     */
    public function test_secret_verification_can_be_disabled(): void
    {
        $this->mockPluginSettings(['webhook_secret_verify' => false]);
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => 'anything',
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 이미 PAID 인 거래의 중복 통보는 멱등 200 이고 입금 시각도 바뀌지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario webhook_status=done, webhook_secret=match, replay=duplicate
     *
     * @effects webhook_replay_idempotent
     */
    public function test_replay_after_paid_is_idempotent(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        // 1차 입금통보 → PAID
        $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => $order->payment->transaction_id,
        ])->assertOk();

        $order->refresh();
        $paidAt = $order->payment->paid_at;

        // 2차 (중복) 통보 → 멱등 200, 상태 불변
        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => $order->payment->transaction_id,
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->payment->payment_status->value);
        $this->assertEquals($paidAt->timestamp, $order->payment->paid_at->timestamp);
    }

    /**
     * 알 수 없는 주문번호는 200 + 본문 `FAIL` 로 답한다 — 수신 자체는 성립시켜 재시도를 멈춘다.
     *
     * @return void 반환값 없음
     *
     * @effects deposit_for_an_unknown_order_number_answers_fail
     */
    public function test_order_not_found_returns_fail(): void
    {
        $this->mockPluginSettings();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => 'ORD-NONEXISTENT',
            'status' => 'DONE',
            'secret' => self::SECRET,
        ]);

        $response->assertOk();
        $this->assertSame('FAIL', $response->getContent());
    }

    /**
     * 규격 밖 status 는 분기에 들어가기 전 검증에서 422 로 걸린다.
     *
     * @return void 반환값 없음
     *
     * @effects deposit_with_an_unknown_status_is_rejected_by_validation
     */
    public function test_invalid_status_rejected_by_validation(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'BOGUS',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 입금대기가 아닌 결제행에는 통보가 아무 일도 하지 않는다 (200 무해).
     *
     * @return void 반환값 없음
     *
     * @effects non_waiting_deposit_is_noop
     */
    public function test_deposit_on_non_waiting_order_is_noop_200(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();
        // 이미 PAID 로 바꿔 놓되 transaction_id 는 달라 리플레이 가드는 안 걸리게 한다
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::READY,
        ]);

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
        ]);

        $response->assertOk();
        $order->refresh();
        // WAITING_DEPOSIT 가 아니므로 completePayment 하지 않는다
        $this->assertNotSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 클레임 추가결제(CP 채번) 건의 상태변경 웹훅은 주문을 조회하지 않는다.
     *
     * 토스는 클레임 결제의 승인·취소에도 PAYMENT_STATUS_CHANGED 를 보내는데, `orderId` 가
     * CP 결제번호라 주문 분기를 그대로 태우면 정상 동작마다 `order not found` warning 이
     * 남는다 — 운영자 로그에서 결함으로 읽히므로 CP 는 자기 경로로 종결시킨다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_status_webhook_terminates_without_an_order_lookup
     */
    public function test_payment_status_webhook_with_a_claim_payment_number_does_not_look_up_an_order(): void
    {
        $this->mockPluginSettings();

        Log::spy();

        $response = $this->postJson(self::PAYMENT_STATUS_URL, [
            'eventType' => 'PAYMENT_STATUS_CHANGED',
            'data' => [
                'orderId' => 'CP20260824-120000123',
                'status' => 'CANCELED',
                'paymentKey' => 'pk_claim_ws_1',
            ],
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        Log::shouldNotHaveReceived('warning');
    }
}
