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
        Http::fake();
        $order = $this->createTossOrder(['pg_provider' => 'other_pg']);
        $default = ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null];

        $result = (new PaymentRefundListener)->processRefund(
            $default, $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['error_code']);
        Http::assertNothingSent();
    }

    /**
     * pg_provider가 'sirsoft-tosspayments'(플러그인 식별자)인 경우 스킵되는지 확인
     * (이전 버그: 플러그인 식별자와 PG provider ID를 혼동하여 항상 스킵됨)
     */
    public function test_process_refund_does_not_match_plugin_identifier(): void
    {
        Http::fake();
        $order = $this->createTossOrder(['pg_provider' => 'sirsoft-tosspayments']);
        $default = ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null];

        $result = (new PaymentRefundListener)->processRefund(
            $default, $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    /**
     * 결제 키가 없으면 API 를 호출하지 않고 실패를 반환하는지 확인
     */
    public function test_process_refund_fails_without_payment_key(): void
    {
        Http::fake();
        $order = $this->createTossOrder(['transaction_id' => null]);
        $default = ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null];

        $result = (new PaymentRefundListener)->processRefund(
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
     */
    public function test_r1_full_refund_omits_cancel_amount(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 10000, 'cancelled_amount' => 0]);

        $result = (new PaymentRefundListener)->processRefund(
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
     */
    public function test_r1_partial_refund_sends_cancel_amount(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 10000, 'cancelled_amount' => 0]);

        $result = (new PaymentRefundListener)->processRefund(
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

        $result = (new PaymentRefundListener)->processRefund(
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

        (new PaymentRefundListener)->processRefund(
            ['success' => false], $order, $order->payment, 5000.0, null, $this->createRefund($order, 5000)
        );

        // 환불 비율(5000/10000)만큼 면세분 안분: 4000 * 0.5 = 2000
        Http::assertSent(fn ($request) => ($request->data()['taxFreeAmount'] ?? null) === 2000);
    }

    /**
     * R2: 전액 환불 시 면세분 전체를 taxFreeAmount 로 전달하는지 확인
     */
    public function test_r2_full_refund_sends_entire_tax_free_amount(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 10000]);
        $order->update(['total_tax_free_amount' => 4000, 'total_tax_amount' => 6000]);
        $order = $order->fresh(['payment']);

        (new PaymentRefundListener)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        Http::assertSent(fn ($request) => ($request->data()['taxFreeAmount'] ?? null) === 4000);
    }

    /**
     * R2: 면세 금액이 없으면(전액 과세) taxFreeAmount 를 전달하지 않는지 확인
     */
    public function test_r2_omits_tax_free_amount_when_zero(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['paid_amount_local' => 10000]);
        $order->update(['total_tax_free_amount' => 0, 'total_tax_amount' => 10000]);
        $order = $order->fresh(['payment']);

        (new PaymentRefundListener)->processRefund(
            ['success' => false], $order, $order->payment, 5000.0, null, $this->createRefund($order, 5000)
        );

        Http::assertSent(fn ($request) => ! array_key_exists('taxFreeAmount', $request->data()));
    }

    // ================================================================
    // R3. 가상계좌 환불은 refundReceiveAccount 가 필수
    // ================================================================

    /**
     * R3: 가상계좌 결제 환불 시 환불계좌를 전달하는지 확인
     *
     * @effects vbank_refund_sends_refund_receive_account
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

        $result = (new PaymentRefundListener)->processRefund(
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

        $result = (new PaymentRefundListener)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('MISSING_REFUND_ACCOUNT', $result['error_code']);
        Http::assertNothingSent();
    }

    /**
     * R3: 가상계좌가 아니면 환불계좌 없이도 정상 취소되는지 확인
     */
    public function test_r3_card_refund_does_not_require_receive_account(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['payment_method' => 'card']);

        $result = (new PaymentRefundListener)->processRefund(
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
     */
    public function test_e4_escrow_partial_refund_blocked_before_api_call(): void
    {
        Http::fake();
        $order = $this->createTossOrder(['is_escrow' => true, 'paid_amount_local' => 10000]);

        $result = (new PaymentRefundListener)->processRefund(
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
     */
    public function test_e4_escrow_full_refund_is_allowed(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder(['is_escrow' => true, 'paid_amount_local' => 10000]);

        $result = (new PaymentRefundListener)->processRefund(
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
     */
    public function test_e4_blocking_is_based_on_payment_snapshot_not_current_setting(): void
    {
        Http::fake();
        // 현재 설정은 에스크로 미사용 — 그럼에도 결제 시점 스냅샷이 기준이어야 한다.
        config(['plugins.sirsoft-tosspayments.use_escrow' => 'off']);

        $order = $this->createTossOrder(['is_escrow' => true, 'paid_amount_local' => 10000]);

        $result = (new PaymentRefundListener)->processRefund(
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
     */
    public function test_attaches_idempotency_key_header_from_refund_record(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder();
        $refund = $this->createRefund($order, 10000);

        (new PaymentRefundListener)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $refund
        );

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key')
            && str_contains($request->header('Idempotency-Key')[0], (string) $refund->id));
    }

    /**
     * 멱등키: 환불 레코드가 없으면(레거시 호출) 헤더 없이도 취소가 동작하는지 확인
     *
     * @effects cancel_without_refund_record_still_succeeds
     */
    public function test_refund_without_record_still_cancels_without_idempotency_key(): void
    {
        $this->fakeCancelApi();
        $order = $this->createTossOrder();

        $result = (new PaymentRefundListener)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Idempotency-Key'));
    }

    /**
     * API 오류 시 실패 결과를 반환하는지 확인
     */
    public function test_process_refund_returns_failure_on_api_error(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['message' => '취소 불가'], 400),
        ]);
        $order = $this->createTossOrder();

        $result = (new PaymentRefundListener)->processRefund(
            ['success' => false], $order, $order->payment, 10000.0, null, $this->createRefund($order, 10000)
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('PG_API_ERROR', $result['error_code']);
    }
}
