<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayNicepayments\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 클레임 추가결제 승인 리스너 단위 테스트
 *
 * 서명 검증(AuthToken + MID + Amt), MID 대조, 승인 결과 매핑, 실패 경로 망취소,
 * 필터 계약을 검증한다.
 */
class ClaimPaymentApproveListenerTest extends PluginTestCase
{
    private const TEST_MID = 'nicepay00m';

    private const TEST_MERCHANT_KEY = 'test-merchant-key-for-signature';

    private const NEXT_APP_URL = 'https://webapi.nicepay.co.kr/webapi/pay_process.jsp';

    private const NET_CANCEL_URL = 'https://webapi.nicepay.co.kr/webapi/cancel_process.jsp';

    private function mockPluginSettings(): void
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_merchant_key' => self::TEST_MERCHANT_KEY,
        ]);

        $this->app->instance(PluginSettingsService::class, $settings);
    }

    private function makePendingPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'nicepayments',
            'amount' => 3000,
        ], $overrides));
    }

    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'paid_amount' => null, 'raw_response' => null, 'error_code' => null, 'error_message' => null];
    }

    private function signedCallback(int $amt = 3000, array $overrides = []): array
    {
        $authToken = 'nice-auth-token-1';
        $signature = bin2hex(hash('sha256', $authToken.self::TEST_MID.(string) $amt.self::TEST_MERCHANT_KEY, true));

        return array_merge([
            'AuthResultCode' => '0000',
            'NextAppURL' => self::NEXT_APP_URL,
            'TxTid' => 'nicepay00m01012608240000000001',
            'AuthToken' => $authToken,
            'MID' => self::TEST_MID,
            'Amt' => $amt,
            'NetCancelURL' => self::NET_CANCEL_URL,
            'Signature' => $signature,
        ], $overrides);
    }

    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentApproveListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.approve', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.approve']['type']);
    }

    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePendingPayment(['pg_provider' => 'nhnkcp']);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->signedCallback());

        $this->assertFalse($result['handled']);
    }

    public function test_an_invalid_signature_is_rejected_without_calling_the_pg(): void
    {
        $this->mockPluginSettings();
        Http::fake();
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->signedCallback(3000, [
            'Signature' => str_repeat('0', 64),
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame('SIGNATURE_MISMATCH', $result['error_code']);
        Http::assertNothingSent();
    }

    public function test_a_mid_mismatch_is_rejected(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->signedCallback(3000, [
            'MID' => 'othermid00',
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame('MID_MISMATCH', $result['error_code']);
    }

    public function test_authorization_returns_transaction_and_credential_meta(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            self::NEXT_APP_URL => Http::response(json_encode([
                'ResultCode' => '3001',
                'ResultMsg' => 'OK',
                'TID' => 'NICE-CLAIM-TID-1',
                'PayMethod' => 'CARD',
                'Amt' => '3000',
            ]), 200),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->signedCallback());

        $this->assertTrue($result['success']);
        $this->assertSame('NICE-CLAIM-TID-1', $result['transaction_id']);
        $this->assertSame(3000, $result['paid_amount']);
        // 환급이 결제 당시 MID/모드로 호출되도록 보관 응답에 메타가 남아야 한다.
        $this->assertSame(self::TEST_MID, $result['raw_response']['mid'] ?? null);
        $this->assertTrue($result['raw_response']['is_test_mode'] ?? null);
    }

    public function test_authorization_refusal_sends_a_net_cancel_and_fails(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            self::NEXT_APP_URL => Http::response(json_encode([
                'ResultCode' => '9999',
                'ResultMsg' => '승인 거절',
            ]), 200),
            self::NET_CANCEL_URL => Http::response('OK', 200),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->signedCallback());

        $this->assertFalse($result['success']);
        $this->assertSame('9999', $result['error_code']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'cancel_process'));
    }

    public function test_a_vbank_authorization_is_net_cancelled_and_rejected(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            self::NEXT_APP_URL => Http::response(json_encode([
                'ResultCode' => '4100',
                'PayMethod' => 'VBANK',
                'TID' => 'NICE-CLAIM-VB-1',
            ]), 200),
            self::NET_CANCEL_URL => Http::response('OK', 200),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->signedCallback());

        $this->assertFalse($result['success']);
        $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'cancel_process'));
    }
}
