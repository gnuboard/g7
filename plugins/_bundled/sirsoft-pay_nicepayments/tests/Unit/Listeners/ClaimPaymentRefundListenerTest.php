<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayNicepayments\Listeners\ClaimPaymentRefundListener;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 클레임 추가결제 환급 리스너 단위 테스트
 *
 * 전액/부분 취소 코드 분기와 결제 당시 자격증명(MID) 복원, 필터 계약을 검증한다.
 */
class ClaimPaymentRefundListenerTest extends PluginTestCase
{
    private const TEST_MID = 'nicepay00m';

    private function mockPluginSettings(): void
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_merchant_key' => 'test-merchant-key-for-signature',
        ]);

        $this->app->instance(PluginSettingsService::class, $settings);
    }

    private function makePaidPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'nicepayments',
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'amount' => 3000,
            'transaction_id' => 'NICE-CLAIM-TID-9',
            'raw_response' => ['TID' => 'NICE-CLAIM-TID-9', 'mid' => self::TEST_MID, 'is_test_mode' => true],
            'paid_at' => now(),
        ], $overrides));
    }

    private function fakeCancelApi(): void
    {
        Http::fake([
            'pg-api.nicepay.co.kr/*' => Http::response(
                mb_convert_encoding(json_encode(['ResultCode' => '2001', 'ResultMsg' => 'OK', 'TID' => 'NICE-CANCEL-TID-1'], JSON_UNESCAPED_UNICODE), 'EUC-KR', 'UTF-8'),
                200,
            ),
        ]);
    }

    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'error_code' => null, 'error_message' => null];
    }

    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentRefundListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.refund', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.refund']['type']);
    }

    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePaidPayment(['pg_provider' => 'tosspayments']);

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertFalse($result['handled']);
    }

    public function test_missing_tid_is_rejected(): void
    {
        $payment = $this->makePaidPayment(['transaction_id' => null]);

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertFalse($result['success']);
        $this->assertSame('MISSING_TID', $result['error_code']);
    }

    public function test_full_refund_uses_the_full_cancel_code(): void
    {
        $this->mockPluginSettings();
        $this->fakeCancelApi();
        $payment = $this->makePaidPayment();

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, '철회 환급', 'g7-claimpay-9-0-300000');

        $this->assertTrue($result['success']);
        $this->assertSame('NICE-CANCEL-TID-1', $result['transaction_id']);
        Http::assertSent(fn ($request) => ($request->data()['PartialCancelCode'] ?? null) === 0
            && ($request->data()['CancelAmt'] ?? null) === 3000);
    }

    public function test_partial_refund_uses_the_partial_cancel_code(): void
    {
        $this->mockPluginSettings();
        $this->fakeCancelApi();
        $payment = $this->makePaidPayment();

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 1000.0, '차액 환급', 'g7-claimpay-9-0-100000');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => ($request->data()['PartialCancelCode'] ?? null) === 1
            && ($request->data()['CancelAmt'] ?? null) === 1000);
    }

    public function test_pg_api_error_returns_a_failure_result(): void
    {
        $this->mockPluginSettings();
        Http::fake([
            'pg-api.nicepay.co.kr/*' => Http::response(
                mb_convert_encoding(json_encode(['ResultCode' => '9999', 'ResultMsg' => '취소 불가'], JSON_UNESCAPED_UNICODE), 'EUC-KR', 'UTF-8'),
                200,
            ),
        ]);
        $payment = $this->makePaidPayment();

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-9-0-300000');

        $this->assertFalse($result['success']);
        $this->assertSame('PG_API_ERROR', $result['error_code']);
    }
}
