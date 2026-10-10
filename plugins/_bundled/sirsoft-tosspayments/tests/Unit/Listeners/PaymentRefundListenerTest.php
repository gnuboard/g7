<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Plugins\Sirsoft\Tosspayments\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * PaymentRefundListener 단위 테스트
 *
 * 환불 정합성 3건(R1 전액취소 / R2 면세금액 / R3 가상계좌 환불계좌) +
 * 에스크로 부분취소 사전 차단(E4) + 멱등키를 검증한다.
 */
class PaymentRefundListenerTest extends PluginTestCase
{
    /**
     * 토스 취소 API 를 성공 응답으로 가짜 응답 처리합니다.
     *
     * @return void 반환값 없음
     */
    private function fakeCancelApi(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response([
                'cancels' => [['transactionKey' => 'TXN_CANCEL_1']],
            ], 200),
        ]);
    }

    /**
     * 토스 결제가 달린 주문을 생성합니다.
     *
     * @param  array<string, mixed>  $paymentAttributes  결제 레코드 덮어쓸 속성
     * @return Order 결제 레코드가 달린 주문
     */
    private function createTossOrder(array $paymentAttributes = []): Order
    {
        $order = Order::factory()->create();

        OrderPayment::factory()->create(array_merge([
            'order_id' => $order->id,
            'pg_provider' => 'tosspayments',
            'transaction_id' => 'test_payment_key',
            'paid_amount_local' => 10000,
            'cancelled_amount' => 0,
            'currency' => 'KRW',
            'is_escrow' => false,
        ], $paymentAttributes));

        return $order->fresh(['payment']);
    }

    /**
     * 환불 레코드를 생성합니다. (OrderRefundFactory 부재 — 직접 생성)
     *
     * @param  Order  $order  환불 대상 주문
     * @param  float  $amount  환불 금액
     * @return OrderRefund 생성된 환불 레코드
     */
    private function createRefund(Order $order, float $amount): OrderRefund
    {
        return OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => $amount,
        ]);
    }

    /**
     * getSubscribedHooks가 올바른 훅 매핑을 반환하는지 확인
     *
     * @effects refund_listener_subscribes_to_the_payment_refund_filter_at_its_declared_priority
     *
     * @return void 반환값 없음
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
     *
     * @effects refund_skips_when_the_payment_belongs_to_another_pg_provider
     *
     * @return void 반환값 없음
     */
    public function test_process_refund_skips_non_tosspayments_provider(): void
    {
        Http::fake();
        $order = $this->createTossOrder(['pg_provider' => 'other_pg']);
        $default = ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null];

        $result = app(PaymentRefundListener::class)->processRefund(
            $default, $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['error_code']);
        Http::assertNothingSent();
    }

    /**
     * pg_provider가 'sirsoft-tosspayments'(플러그인 식별자)인 경우 스킵되는지 확인
     * (이전 버그: 플러그인 식별자와 PG provider ID를 혼동하여 항상 스킵됨)
     *
     * @effects refund_matches_the_provider_id_not_the_plugin_identifier
     *
     * @return void 반환값 없음
     */
    public function test_process_refund_does_not_match_plugin_identifier(): void
    {
        Http::fake();
        $order = $this->createTossOrder(['pg_provider' => 'sirsoft-tosspayments']);
        $default = ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null];

        $result = app(PaymentRefundListener::class)->processRefund(
            $default, $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    /**
     * 결제 키가 없으면 API 를 호출하지 않고 실패를 반환하는지 확인
     *
     * @effects refund_without_a_payment_key_fails_before_the_api_call
     *
     * @return void 반환값 없음
     */
    public function test_process_refund_fails_without_payment_key(): void
    {
        Http::fake();
        $order = $this->createTossOrder(['transaction_id' => null]);
        $default = ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null];

        $result = app(PaymentRefundListener::class)->processRefund(
            $default, $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('MISSING_PAYMENT_KEY', $result['error_code']);
        Http::assertNothingSent();
    }

    // ================================================================
    // R1. 전액 환불은 cancelAmount 를 보내지 않는다 (토스: 미지정 = 전액취소)
    // ================================================================

    /**
     * R1: 전액 환불 시 cancelAmount 를 전달하지 않는지 확인
     *
     * 현행 결함: 전액이어도 cancelAmount 를 항상 넣어 부분취소로 오처리된다.
     *
     * @effects full_refund_omits_cancel_amount
     *
     * @return void 반환값 없음
     */
    public function test_r1_full_refund_omits_cancel_amount(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 10000, 'cancelled_amount' => 0]);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return ! array_key_exists('cancelAmount', $request->data());
        });
    }

    /**
     * R1: 부분 환불 시에는 cancelAmount 를 전달하는지 확인
     *
     * @effects partial_refund_sends_cancel_amount
     *
     * @return void 반환값 없음
     */
    public function test_r1_partial_refund_sends_cancel_amount(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 10000, 'cancelled_amount' => 0]);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 3000.0, null, $this->createRefund($order, 3000)
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => $request->data()['cancelAmount'] === 3000);
    }

    /**
     * R1: 이미 부분취소한 이력이 있으면 잔액 전부를 취소해도 부분취소로 전달하는지 확인
     *
     * 토스는 cancelAmount 미지정 시 "결제 전액" 을 취소하므로, 잔액 취소를 전액취소로
     * 보내면 이미 취소된 금액을 두 번 취소하게 된다.
     *
     * @effects remaining_refund_after_partial_stays_partial
     *
     * @return void 반환값 없음
     */
    public function test_r1_remaining_refund_after_partial_stays_partial(): void
    {
        $this->fakeCancelApi();
        // 10000 결제 중 3000 이 이미 취소됨 → 잔액 7000 취소
        $order = $this->createTossOrder([
            'paid_amount_local' => 10000,
            'cancelled_amount' => 7000,
            'mc_cancelled_amount' => ['KRW' => 7000],
        ]);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 7000.0, null, $this->createRefund($order, 7000)
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => ($request->data()['cancelAmount'] ?? null) === 7000);
    }

    // ================================================================
    // R2. taxFreeAmount 동반 전달 (복합과세 상점의 VAT 오산 방지)
    // ================================================================

    /**
     * R2: 면세 금액이 있으면 환불액 비율만큼 안분해 taxFreeAmount 를 동반 전달하는지 확인
     *
     * 면세/과세 분류의 SSoT 는 주문(total_tax_free_amount / total_tax_amount)이다.
     * 결제 레코드에는 면세 컬럼이 없다.
     *
     * @effects refund_sends_apportioned_tax_free_amount
     *
     * @return void 반환값 없음
     */
    public function test_r2_sends_apportioned_tax_free_amount_when_present(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 10000]);
        $order->update([
            'total_tax_free_amount' => 4000,
            'total_tax_amount' => 6000,
        ]);
        $order = $order->fresh(['payment']);

        app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 5000.0, null, $this->createRefund($order, 5000)
        );

        // 환불 비율(5000/10000)만큼 면세분 안분: 4000 * 0.5 = 2000
        Http::assertSent(fn ($request) => ($request->data()['taxFreeAmount'] ?? null) === 2000);
    }

    /**
     * R2: 전액 환불 시 면세분 전체를 taxFreeAmount 로 전달하는지 확인
     *
     * 안분 비율이 1.0 인 경계 — 같은 식이 전액에서도 성립한다.
     *
     * @effects refund_sends_apportioned_tax_free_amount
     *
     * @return void 반환값 없음
     */
    public function test_r2_full_refund_sends_entire_tax_free_amount(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 10000]);
        $order->update(['total_tax_free_amount' => 4000, 'total_tax_amount' => 6000]);
        $order = $order->fresh(['payment']);

        app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        Http::assertSent(fn ($request) => ($request->data()['taxFreeAmount'] ?? null) === 4000);
    }

    /**
     * R2: 면세 금액이 없으면(전액 과세) taxFreeAmount 를 전달하지 않는지 확인
     *
     * 안분 대상이 0 인 경계 — 0 을 보내는 것과 키 자체를 빼는 것은 다르다.
     *
     * @effects refund_sends_apportioned_tax_free_amount
     *
     * @return void 반환값 없음
     */
    public function test_r2_omits_tax_free_amount_when_zero(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 10000]);
        $order->update(['total_tax_free_amount' => 0, 'total_tax_amount' => 10000]);
        $order = $order->fresh(['payment']);

        app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 5000.0, null, $this->createRefund($order, 5000)
        );

        Http::assertSent(fn ($request) => ! array_key_exists('taxFreeAmount', $request->data()));
    }

    /**
     * S1-D14: 순차 부분취소에서 누적 taxFreeAmount 가 승인 면세액을 넘지 않는다
     *
     * `resolveTaxFreeAmount()` 는 주문의 `total_tax_free_amount` / `total_tax_amount` 를
     * 읽는데, 이 값은 환불이 완료될 때마다 **잔여로 줄어든다**. 그러므로
     * 매 회차 안분한 면세분의 합이 승인 시 등록된 면세액을 초과할 수 있다.
     * 토스는 누적 면세액이 승인분을 넘으면 「취소 할 수 없는 금액 입니다.」 로 거부한다.
     *
     * 실측(#599 통합실측 세션1 · 주문 20260915-1253009416 · 58,000 결제 / 면세 40,000):
     *
     *     1회차 사과 x1  classified=68000 cancel=20000 -> taxFree=11765
     *     2회차 USB  x1  classified=48000 cancel= 4000 -> taxFree= 1667
     *     3회차 USB  x1  classified=34000 cancel=14000 -> taxFree= 8235
     *     4회차 사과 x1  classified=20000 cancel=20000 -> taxFree=20000  (누적 41667 > 40000)
     *
     * 계약: 이번 회차에 보내는 면세분은 **잔여 면세 예산**(승인 면세액 − 이미 소진분)을
     * 넘지 않는다. 예산은 불변인 승인 스냅샷과 PG 취소 장부에서 온다 — 줄어드는
     * 주문 합계가 아니다.
     *
     * @effects refund_tax_free_never_exceeds_approved_total
     *
     * @return void 반환값 없음
     */
    public function test_r2_cumulative_tax_free_never_exceeds_approved_amount(): void
    {
        $this->fakeCancelApi();

        // 승인: 58,000 결제 · 면세 40,000 · 이미 3회차까지 38,000 취소(면세 21,667 소진)
        $order = $this->createTossOrder([
            'paid_amount_local' => 58000,
            'cancelled_amount' => 38000,
            'payment_meta' => [
                'pg_raw_response' => [
                    'taxFreeAmount' => 40000,
                    'totalAmount' => 58000,
                    'cancels' => [
                        ['cancelAmount' => 20000, 'taxFreeAmount' => 11765],
                        ['cancelAmount' => 4000, 'taxFreeAmount' => 1667],
                        ['cancelAmount' => 14000, 'taxFreeAmount' => 8235],
                    ],
                ],
            ],
        ]);

        // 환불이 진행된 뒤의 주문 잔여 — 사과 2개(전량 면세)만 남았다
        $order->update([
            'total_tax_free_amount' => 20000,
            'total_tax_amount' => 0,
        ]);
        $order = $order->fresh(['payment']);

        app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 20000.0, null, $this->createRefund($order, 20000)
        );

        // 잔여 예산 = 40000 - 21667 = 18333. 이것을 넘으면 토스가 거부한다.
        Http::assertSent(function ($request) {
            $sent = $request->data()['taxFreeAmount'] ?? 0;

            return $sent <= 18333;
        });
    }

    /**
     * S1-D14: 취소 성공 시 PG 장부를 승인 스냅샷에 반영한다
     *
     * 승인 스냅샷은 결제 시점에 한 번만 기록되고 갱신되지 않았다. 그랬 경우
     * 다음 회차가 「이미 소진한 면세분」을 알 길이 없어 잔여 예산 상한이 헛돌아간다.
     *
     * 승인 금액·승인 면세액은 상한의 근거라 덮어쓰지 않고 장부만 갱신한다.
     *
     * @effects refund_tax_free_never_exceeds_approved_total
     *
     * @return void 반환값 없음
     */
    public function test_r2_cancel_response_updates_snapshot_ledger(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response([
                'taxFreeAmount' => 40000,
                'cancels' => [
                    ['transactionKey' => 'TXN_CANCEL_1', 'cancelAmount' => 20000, 'taxFreeAmount' => 11765],
                ],
            ], 200),
        ]);

        $order = $this->createTossOrder([
            'paid_amount_local' => 58000,
            'payment_meta' => [
                'pg_raw_response' => ['taxFreeAmount' => 40000, 'totalAmount' => 58000],
            ],
        ]);
        $order->update(['total_tax_free_amount' => 40000, 'total_tax_amount' => 18000]);
        $order = $order->fresh(['payment']);

        app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 20000.0, null, $this->createRefund($order, 20000)
        );

        $raw = $order->payment->fresh()->payment_meta['pg_raw_response'];

        // 장부가 쌓혀 다음 회차가 소진분을 알 수 있어야 한다
        $this->assertSame(11765, $raw['cancels'][0]['taxFreeAmount']);

        // 승인 면세액(상한의 근거)은 보존된다
        $this->assertSame(40000, $raw['taxFreeAmount']);
    }

    // ================================================================
    // R3. 가상계좌 환불은 refundReceiveAccount 가 필수
    // ================================================================

    /**
     * R3: 가상계좌 결제 환불 시 환불계좌를 전달하는지 확인
     *
     * @effects vbank_refund_sends_refund_receive_account
     *
     * @return void 반환값 없음
     */
    public function test_r3_virtual_account_refund_sends_receive_account(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder([
            'payment_method' => 'vbank',
            'refund_bank_code' => '20',
            'refund_bank_account' => '1234567890',
            'refund_bank_holder' => '홍길동',
        ]);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            $account = $request->data()['refundReceiveAccount'] ?? null;

            return $account === [
                'bank' => '20',
                'accountNumber' => '1234567890',
                'holderName' => '홍길동',
            ];
        });
    }

    /**
     * R3: 가상계좌인데 환불계좌가 없으면 API 호출 없이 실패하는지 확인
     *
     * @effects vbank_refund_without_account_fails_before_api_call
     *
     * @return void 반환값 없음
     */
    public function test_r3_virtual_account_without_account_fails_before_api_call(): void
    {
        Http::fake();
        $order = $this->createTossOrder([
            'payment_method' => 'vbank',
            'refund_bank_code' => null,
            'refund_bank_account' => null,
            'refund_bank_holder' => null,
        ]);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('MISSING_REFUND_ACCOUNT', $result['error_code']);
        Http::assertNothingSent();
    }

    /**
     * R3: 가상계좌가 아니면 환불계좌 없이도 정상 취소되는지 확인
     *
     * @effects non_vbank_refund_omits_the_refund_receive_account
     *
     * @return void 반환값 없음
     */
    public function test_r3_card_refund_does_not_require_receive_account(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['payment_method' => 'card']);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => ! array_key_exists('refundReceiveAccount', $request->data()));
    }

    // ================================================================
    // E4. 에스크로 주문의 부분취소는 API 호출 전에 차단한다
    // ================================================================

    /**
     * E4: 에스크로 주문의 부분취소는 API 호출 없이 차단되는지 확인
     *
     * @effects escrow_partial_refund_blocked_with_zero_api_calls
     *
     * @return void 반환값 없음
     */
    public function test_e4_escrow_partial_refund_blocked_before_api_call(): void
    {
        Http::fake();
        $order = $this->createTossOrder(['is_escrow' => true, 'paid_amount_local' => 10000]);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 3000.0, null, $this->createRefund($order, 3000)
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('ESCROW_PARTIAL_REFUND_NOT_ALLOWED', $result['error_code']);
        $this->assertNotEmpty($result['error_message']);
        Http::assertNothingSent();
    }

    /**
     * E4: 에스크로 주문이라도 전액취소는 허용되는지 확인
     *
     * @effects escrow_full_refund_allowed
     *
     * @return void 반환값 없음
     */
    public function test_e4_escrow_full_refund_is_allowed(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['is_escrow' => true, 'paid_amount_local' => 10000]);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => ! array_key_exists('cancelAmount', $request->data()));
    }

    /**
     * E4: 결제 시점 스냅샷(is_escrow)이 기준이므로, 플러그인 설정을 off 로 바꿔도
     *     기존 에스크로 주문의 부분취소는 계속 차단되는지 확인
     *
     * @effects escrow_blocking_uses_payment_snapshot_not_current_setting
     *
     * @return void 반환값 없음
     */
    public function test_e4_blocking_is_based_on_payment_snapshot_not_current_setting(): void
    {
        Http::fake();
        // 현재 설정은 에스크로 미사용 — 그럼에도 결제 시점 스냅샷이 기준이어야 한다.
        config(['plugins.sirsoft-tosspayments.use_escrow' => 'off']);

        $order = $this->createTossOrder(['is_escrow' => true, 'paid_amount_local' => 10000]);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 3000.0, null, $this->createRefund($order, 3000)
        );

        $this->assertEquals('ESCROW_PARTIAL_REFUND_NOT_ALLOWED', $result['error_code']);
        Http::assertNothingSent();
    }

    // ================================================================
    // 멱등키 (Idempotency-Key)
    // ================================================================

    /**
     * 멱등키: 취소 요청에 환불 레코드 기반 Idempotency-Key 헤더를 부착하는지 확인
     *
     * @effects cancel_request_carries_idempotency_key_from_refund_record
     *
     * @return void 반환값 없음
     */
    public function test_attaches_idempotency_key_header_from_refund_record(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder();
        $refund = $this->createRefund($order, 10000);

        app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $refund
        );

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key')
            && str_contains($request->header('Idempotency-Key')[0], (string) $refund->id));
    }

    /**
     * 멱등키: 환불 레코드가 없으면(레거시 호출) 헤더 없이도 취소가 동작하는지 확인
     *
     * @effects cancel_without_refund_record_still_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_refund_without_record_still_cancels_without_idempotency_key(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder();

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Idempotency-Key'));
    }

    /**
     * 부분취소 사전 판정: 에스크로·달이 바뀐 휴대폰 결제는 부분취소를 지원하지 않는다 (P-D7)
     *
     * @effects partial_cancel_support_follows_toss_constraints
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_support_follows_toss_constraints(): void
    {
        $listener = app(PaymentRefundListener::class);

        $card = $this->createTossOrder(['transaction_id' => 'pk_pd7_card']);
        $this->assertTrue($listener->supportsPartialCancel(true, $card->payment));

        $escrow = $this->createTossOrder(['transaction_id' => 'pk_pd7_escrow', 'is_escrow' => true]);
        $this->assertFalse($listener->supportsPartialCancel(true, $escrow->payment));

        $phoneLastMonth = $this->createTossOrder(['transaction_id' => 'pk_pd7_phone_old', 'payment_method' => 'phone', 'paid_at' => now('Asia/Seoul')->subMonthNoOverflow()->startOfMonth()->addDays(2)]);
        $this->assertFalse($listener->supportsPartialCancel(true, $phoneLastMonth->payment), '달이 바뀐 휴대폰 결제는 취소할 수 없다');

        $phoneThisMonth = $this->createTossOrder(['transaction_id' => 'pk_pd7_phone_now', 'payment_method' => 'phone', 'paid_at' => now()]);
        $this->assertTrue($listener->supportsPartialCancel(true, $phoneThisMonth->payment));

        $other = $this->createTossOrder(['transaction_id' => 'pk_pd7_other', 'pg_provider' => 'other_pg', 'is_escrow' => true]);
        $this->assertTrue($listener->supportsPartialCancel(true, $other->payment), '다른 PG 결제는 판정하지 않는다');
    }

    /**
     * 멱등키: 같은 환불 레코드라도 금액이 다르면 키가 다르다 (P-D2)
     *
     * 운영자가 금액을 고쳐 다시 실행하면 같은 레코드로 다른 금액의 취소가 나간다. 키가 레코드 id 뿐이면 토스는
     * 두 번째 요청을 첫 요청의 재전송으로 보고 **처음 금액의 응답을 돌려준다** — 고친 금액은 취소되지 않는다.
     *
     * @effects cancel_idempotency_key_changes_with_amount_and_refund_account
     *
     * @return void 반환값 없음
     */
    public function test_idempotency_key_differs_when_the_amount_differs(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 20000]);
        $refund = $this->createRefund($order, 10000);
        $listener = app(PaymentRefundListener::class);

        $listener->processRefund(['success' => false], $order, $order->payment, 10000.0, null, $refund);
        $listener->processRefund(['success' => false], $order, $order->payment->fresh(), 5000.0, null, $refund);

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0] ?? null)->all();
        $this->assertCount(2, $keys);
        $this->assertStringStartsWith('g7-refund-'.$refund->id.'-', $keys[0]);
        $this->assertNotSame($keys[0], $keys[1], '금액이 다른 두 취소가 같은 멱등키를 썼다');
    }

    /**
     * 자기 결제의 환불 결과에는 `handled=true` 를 싣고, 다른 PG 결제는 결과를 그대로 넘긴다 (P-D6)
     *
     * 모듈은 `handled` 로 「이 주문의 결제 플러그인이 응답했는가」 를 가린다 — 없으면 비활성 플러그인의 주문도
     * 다른 플러그인이 구독만 하고 있으면 「PG 환불 실패」 로 뭉뚱그려졌다.
     *
     * @effects refund_result_marks_handled_only_for_own_payment
     *
     * @return void 반환값 없음
     */
    public function test_own_payment_result_is_marked_handled(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder();

        $own = app(PaymentRefundListener::class)->processRefund(
            ['success' => false, 'handled' => false], $order, $order->payment, 10000.0
        );
        $this->assertTrue($own['handled']);
        $this->assertTrue($own['success']);

        $other = $this->createTossOrder(['pg_provider' => 'other_pg', 'transaction_id' => 'other_payment_key']);
        $passed = app(PaymentRefundListener::class)->processRefund(
            ['success' => false, 'handled' => false], $other, $other->payment, 10000.0
        );
        $this->assertFalse($passed['handled']);
    }

    /**
     * API 오류 시 실패 결과를 반환하는지 확인
     *
     * @effects refund_api_error_returns_a_failure_result_with_the_pg_error_code
     *
     * @return void 반환값 없음
     */
    public function test_process_refund_returns_failure_on_api_error(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['message' => '취소 불가'], 400),
        ]);
        $order = $this->createTossOrder();

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('PG_API_ERROR', $result['error_code']);
    }
}
