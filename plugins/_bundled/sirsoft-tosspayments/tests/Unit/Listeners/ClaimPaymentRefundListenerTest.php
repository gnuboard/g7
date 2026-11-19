<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\Tosspayments\Listeners\ClaimPaymentRefundListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 클레임 추가결제 환급 리스너 단위 테스트
 *
 * 전액/부분 취소 분기(부분 이력이 있으면 잔액 전액도 부분취소)와 모듈 멱등키의
 * Idempotency-Key 헤더 전달을 검증한다.
 */
class ClaimPaymentRefundListenerTest extends PluginTestCase
{
    /**
     * 테스트 모드 시크릿 키를 돌려주는 플러그인 설정으로 바꿔 끼웁니다.
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
     * 토스 결제로 확정된 클레임 결제 건을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     * @return ClaimPayment 결제 건
     */
    private function makePaidPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'tosspayments',
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'amount' => 3000,
            'transaction_id' => 'pk_claim_refund',
            'paid_at' => now(),
        ], $overrides));
    }

    /**
     * 토스 결제 취소 API 가 취소 거래 1건으로 응답하게 합니다.
     *
     * @return void 반환값 없음
     */
    private function fakeCancelApi(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['cancels' => [['transactionKey' => 'TXN_CANCEL_1']]], 200),
        ]);
    }

    /**
     * 필터 기본값(아무 리스너도 처리하지 않은 결과)을 돌려줍니다.
     *
     * @return array<string, mixed> 기본 결과
     */
    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'error_code' => null, 'error_message' => null];
    }

    /**
     * claim_payment.refund 를 filter 로 구독하고 processRefund 로 처리한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=hook_subscription
     *
     * @effects toss_claim_refund_listener_subscribes_the_refund_filter
     */
    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentRefundListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.refund', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.refund']['type']);
        $this->assertSame('processRefund', $hooks['sirsoft-ecommerce.claim_payment.refund']['method']);
    }

    /**
     * 다른 결제사 결제 건은 건드리지 않고 결과를 그대로 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=other_provider
     *
     * @effects toss_claim_refund_listener_skips_other_providers
     */
    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePaidPayment(['pg_provider' => 'nhnkcp']);

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertFalse($result['handled']);
        $this->assertFalse($result['success']);
    }

    /**
     * 토스 결제 키가 없으면 취소를 부르지 않고 실패로 답한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=missing_payment_key
     *
     * @effects toss_claim_refund_without_payment_key_is_rejected
     */
    public function test_missing_payment_key_is_rejected(): void
    {
        $payment = $this->makePaidPayment(['transaction_id' => null]);

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('MISSING_PAYMENT_KEY', $result['error_code']);
    }

    /**
     * 전액 환급은 취소 금액을 빼고 보내며 모듈 멱등키를 Idempotency-Key 로 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=full_refund
     *
     * @effects toss_claim_full_refund_omits_amount_and_sends_idempotency_key
     */
    public function test_full_refund_omits_cancel_amount_and_sends_the_idempotency_key(): void
    {
        $this->mockPluginSettings();
        $this->fakeCancelApi();
        $payment = $this->makePaidPayment();

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 3000.0, '철회 환급', 'g7-claimpay-9-0-300000');

        $this->assertTrue($result['success']);
        $this->assertSame('TXN_CANCEL_1', $result['transaction_id']);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/cancel')
                && ! array_key_exists('cancelAmount', $body)
                && $request->header('Idempotency-Key') === ['g7-claimpay-9-0-300000'];
        });
    }

    /**
     * 부분 환급은 취소 금액을 실어 보낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=partial_refund
     *
     * @effects toss_claim_partial_refund_sends_cancel_amount
     */
    public function test_partial_refund_sends_the_cancel_amount(): void
    {
        $this->mockPluginSettings();
        $this->fakeCancelApi();
        $payment = $this->makePaidPayment();

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 1000.0, '차액 환급', 'g7-claimpay-9-0-100000');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => ($request->data()['cancelAmount'] ?? null) === 1000);
    }

    /**
     * 부분 환급 이력이 있으면 남은 잔액 전액도 부분취소로 보낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=remaining_balance_after_partial_refund
     *
     * @effects toss_claim_remaining_balance_after_partial_refund_is_partial_cancel
     */
    public function test_remaining_balance_after_a_partial_refund_is_still_a_partial_cancel(): void
    {
        $this->mockPluginSettings();
        $this->fakeCancelApi();
        // 이미 1,000 이 부분 환급된 건의 잔액 2,000 전액 — 금액 미지정(전액취소)으로 보내면
        // 토스가 결제 전액 3,000 을 취소해 이미 돌려준 1,000 이 두 번 나간다.
        $payment = $this->makePaidPayment(['refunded_amount' => 1000]);

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 2000.0, null, 'g7-claimpay-9-100000-200000');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => ($request->data()['cancelAmount'] ?? null) === 2000);
    }

    /**
     * 토스가 취소를 거절하면 실패 결과(PG_API_ERROR)를 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=pg_api_error
     *
     * @effects toss_claim_refund_pg_api_error_returns_failure
     */
    public function test_pg_api_error_returns_a_failure_result(): void
    {
        $this->mockPluginSettings();
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['code' => 'ALREADY_CANCELED_PAYMENT', 'message' => 'cancelled'], 400),
        ]);
        $payment = $this->makePaidPayment();

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-9-0-300000');

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('PG_API_ERROR', $result['error_code']);
    }
}
