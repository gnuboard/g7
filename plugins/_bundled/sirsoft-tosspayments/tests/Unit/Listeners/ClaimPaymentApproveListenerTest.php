<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\Tosspayments\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 클레임 추가결제 승인 리스너 단위 테스트
 *
 * 필터 계약(handled/success/transaction_id/paid_amount)과 provider 스킵,
 * 교차 승인 차단(orderId ↔ payment_number), 입금 대기 수단 거절을 검증한다.
 */
class ClaimPaymentApproveListenerTest extends PluginTestCase
{
    /**
     * 플러그인 설정을 테스트 모드 고정값으로 대체합니다.
     *
     * @return void 반환값 없음
     */
    private function mockPluginSettings(): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_mock_key',
        ]);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }

    /**
     * 토스 결제 대기 건을 만듭니다.
     *
     * @param  array  $overrides  기본값을 덮어쓸 컬럼
     * @return ClaimPayment 생성된 결제 대기 건
     */
    private function makePendingPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'tosspayments',
            'amount' => 3000,
        ], $overrides));
    }

    /**
     * `claim_payment.approve` 필터의 초기값을 반환합니다.
     *
     * @return array 필터 초기값 {handled, success, transaction_id, paid_amount, raw_response, error_code, error_message}
     */
    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'paid_amount' => null, 'raw_response' => null, 'error_code' => null, 'error_message' => null];
    }

    /**
     * 승인 필터 구독 매핑이 선언대로인지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_approve_listener_subscribes_the_filter
     */
    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentApproveListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.approve', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.approve']['type']);
        $this->assertSame('processApprove', $hooks['sirsoft-ecommerce.claim_payment.approve']['method']);
    }

    /**
     * 다른 PG 의 결제 건은 건드리지 않고 그대로 넘기는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_approval_skips_other_providers
     */
    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePendingPayment(['pg_provider' => 'kginicis']);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertFalse($result['handled']);
        $this->assertFalse($result['success']);
    }

    /**
     * 승인에 필요한 콜백 파라미터가 없으면 거절하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_approval_rejects_incomplete_callback
     */
    public function test_rejects_a_callback_missing_required_parameters(): void
    {
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, []);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_CALLBACK', $result['error_code']);
    }

    /**
     * 다른 건의 결제 키를 이 결제 건에 붙이는 교차 승인을 차단하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_approval_blocks_cross_payment_key_before_confirm
     */
    public function test_rejects_a_callback_whose_order_id_differs_from_the_payment_number(): void
    {
        $this->mockPluginSettings();
        Http::fake();
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk_other', 'orderId' => 'CP9999999999', 'amount' => 3000,
        ]);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('ORDER_ID_MISMATCH', $result['error_code']);
        // 교차 승인 차단은 Confirm 호출 전이어야 한다 — 호출됐다면 남의 paymentKey 가 청구된 것.
        Http::assertNothingSent();
    }

    /**
     * 정상 승인 시 거래 식별자와 승인 금액을 돌려주는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_approval_returns_transaction_and_paid_amount
     */
    public function test_confirms_the_payment_and_returns_transaction_and_paid_amount(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => 'pk_claim_1',
                'orderId' => $payment->payment_number,
                'status' => 'DONE',
                'totalAmount' => 3000,
            ], 200),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk_claim_1', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);
        $this->assertSame('pk_claim_1', $result['transaction_id']);
        $this->assertSame(3000, $result['paid_amount']);
        $this->assertSame('DONE', $result['raw_response']['status'] ?? null);
    }

    /**
     * 입금 대기 수단(가상계좌) 발급은 즉시 취소하고 거절하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_approval_cancels_deposit_pending_issue
     */
    public function test_deposit_pending_confirmation_is_cancelled_and_rejected(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => 'pk_vbank_1',
                'orderId' => $payment->payment_number,
                'status' => 'WAITING_FOR_DEPOSIT',
            ], 200),
            'api.tosspayments.com/v1/payments/pk_vbank_1/cancel' => Http::response(['cancels' => []], 200),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk_vbank_1', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
        // 발급을 그대로 두면 고객이 입금해도 확정할 경로가 없다 — 취소 API 가 호출되어야 한다.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/payments/pk_vbank_1/cancel'));
    }

    /**
     * 결제창을 연 뒤 청구액이 바뀌면 **승인 호출 전에** 거절하는지 검증합니다.
     *
     * 결제창이 열려 있는 동안 청구액은 바뀔 수 있다 — 신청서 수정(사유를 바꾸면 귀책이 바뀌어
     * 왕복 배송비가 청구에서 빠진다)·관리자 재산정이 모두 `settleChargeDifference()` 로 결제행
     * `amount` 를 갱신한다. 그때 낡은 창으로 결제하면 PG 는 옛 금액을 승인한다.
     *
     * 승인 **뒤**에 거절하면 고객 돈이 실제로 빠져나갔다가 망취소로 돌아오는 왕복이 생기고,
     * 그 보상이 실패하면 화면은 실패인데 PG 는 청구된 채 남는다(`cancelClaimConfirmation()` 이
     * 경고하는 상태). 모듈도 통화 축에 같은 원칙을 적어 두었다 — 「진입도 같은 판정으로 닫는다
     * — 열어 두면 PG 승인이 확정된 **뒤**에 금액 불일치로 거절되어 망취소 보상이 필요해진다」.
     *
     * 형제 플러그인 3종(kginicis·nhnkcp·nicepayments)은 이미 승인/서명 전에 `resolvePaymentCharge()`
     * 로 대조한다. 토스만 빠져 있었다 (#599 3차 실측 F-6, 2026-09-22: 결제창 9,000원 · 결제행
     * 5,000원 상태를 화면에서 확인했고 경고는 없었다).
     *
     * @return void 반환값 없음
     *
     * @effects claim_approval_rejects_stale_window_amount_before_confirm
     */
    public function test_청구액이_바뀌면_승인을_부르기_전에_거절한다(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment(['amount' => 5000]);

        // 토스는 낡은 창의 금액(9,000)을 그대로 승인해 준다 — 막아야 할 쪽은 우리다.
        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => 'pk_stale_window', 'totalAmount' => 9000, 'status' => 'DONE',
            ], 200),
        ]);

        // 결제창은 9,000원으로 열렸는데 그 사이 청구액이 5,000원으로 바뀐 상태.
        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk_stale_window', 'orderId' => $payment->payment_number, 'amount' => 9000,
        ]);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);

        Http::assertNotSent(
            fn ($request) => str_contains($request->url(), '/v1/payments/confirm'),
        );
    }

    /**
     * 청구액이 그대로면 승인을 정상 호출하는지 검증합니다. (위 가드의 오거절 방지)
     *
     * @return void 반환값 없음
     *
     * @effects claim_approval_confirms_when_amount_unchanged
     */
    public function test_청구액이_그대로면_승인을_부른다(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment(['amount' => 3000]);

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => 'pk_ok', 'totalAmount' => 3000, 'status' => 'DONE',
            ], 200),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk_ok', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('pk_ok', $result['transaction_id']);
    }

    /**
     * 토스가 준 거절 코드·사유·원본 응답이 필터 결과까지 살아서 올라오는지 검증합니다.
     *
     * 이 값들은 결제행의 `pg_error_code`·`pg_error_message`·`raw_response` 가 되고, 코드는
     * 다시 신청서 상세의 「실패 코드 {code}」 배너가 된다. 여기서 일반 토큰으로 접으면 서로
     * 다른 거절(한도 초과·분실 카드·세션 만료)이 전부 같은 값이 되어, 구매자도 관리자도
     * 무엇이 잘못됐는지 알 수 없고 대사할 원본도 남지 않는다.
     *
     * 회귀 배경 (#599 3차 실측 F-5, 2026-09-22): 실거래에서 토스가 거절했을 때 결제행에
     * `PG_API_ERROR` 만 남고 `raw_response` 는 null 이었다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_approval_failure_keeps_pg_code_and_raw_response
     */
    public function test_토스가_준_거절_코드와_원본_응답이_그대로_올라온다(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'code' => 'NOT_FOUND_PAYMENT', 'message' => '존재하지 않는 결제 입니다.',
            ], 404),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk_missing', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame(
            'NOT_FOUND_PAYMENT',
            $result['error_code'],
            '토스가 준 거절 코드가 버려지고 일반 토큰으로 접혔다 — 거절 사유를 구분할 수 없다',
        );
        $this->assertSame('존재하지 않는 결제 입니다.', $result['error_message']);
        $this->assertSame(
            ['code' => 'NOT_FOUND_PAYMENT', 'message' => '존재하지 않는 결제 입니다.'],
            $result['raw_response'],
            '실패 건에 PG 원본 응답이 남지 않아 대사할 근거가 없다',
        );
        $this->assertNull($result['transaction_id']);
    }

    /**
     * PG 응답을 얻지 못한 실패(네트워크 단절 등)는 일반 코드로 닫는지 검증합니다.
     *
     * 토스가 코드를 주지 않은 실패까지 억지로 특정 코드로 부르면 안 된다 — 그 자리는
     * 「PG 와 통신 자체가 실패했다」는 별개의 사실이다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_approval_transport_failure_uses_generic_code
     */
    public function test_응답_없는_통신_실패는_일반_코드로_닫는다(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => fn () => throw new \RuntimeException('connection refused'),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk_down', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('PG_API_ERROR', $result['error_code']);
    }
}
