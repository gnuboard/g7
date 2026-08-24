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
    private function mockPluginSettings(): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_mock_key',
        ]);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }

    private function makePendingPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'tosspayments',
            'amount' => 3000,
        ], $overrides));
    }

    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'paid_amount' => null, 'raw_response' => null, 'error_code' => null, 'error_message' => null];
    }

    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentApproveListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.approve', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.approve']['type']);
        $this->assertSame('processApprove', $hooks['sirsoft-ecommerce.claim_payment.approve']['method']);
    }

    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePendingPayment(['pg_provider' => 'kginicis']);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertFalse($result['handled']);
        $this->assertFalse($result['success']);
    }

    public function test_rejects_a_callback_missing_required_parameters(): void
    {
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, []);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_CALLBACK', $result['error_code']);
    }

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

    public function test_pg_api_error_returns_a_failure_result(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'code' => 'NOT_FOUND_PAYMENT', 'message' => 'not found',
            ], 404),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'paymentKey' => 'pk_missing', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('PG_API_ERROR', $result['error_code']);
        $this->assertNull($result['transaction_id']);
    }
}
