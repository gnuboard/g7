<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayKginicis\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 클레임 추가결제 승인 리스너 단위 테스트
 *
 * PC(authToken/authUrl)·모바일(P_TID/P_REQ_URL) 분기, 승인 URL 화이트리스트(SSRF 방어),
 * 입금 대기 수단 망취소, 필터 계약을 검증한다.
 */
class ClaimPaymentApproveListenerTest extends PluginTestCase
{
    private const PC_AUTH_URL = 'https://fcstdpay.inicis.com/api/payAuth';

    private function mockPluginSettings(): void
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => '2IgsAQSbMqHkAkj3',
        ]);

        $this->app->instance(PluginSettingsService::class, $settings);
    }

    private function makePendingPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'kginicis',
            'amount' => 3000,
        ], $overrides));
    }

    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'paid_amount' => null, 'raw_response' => null, 'error_code' => null, 'error_message' => null];
    }

    private function pcCallback(array $overrides = []): array
    {
        return array_merge([
            'resultCode' => '0000',
            'authToken' => 'auth-token-1',
            'authUrl' => self::PC_AUTH_URL,
            'idc_name' => 'fc',
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
        $payment = $this->makePendingPayment(['pg_provider' => 'tosspayments']);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback());

        $this->assertFalse($result['handled']);
    }

    public function test_window_failure_is_rejected_with_the_pg_code(): void
    {
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback(['resultCode' => '9999', 'resultMsg' => '거절']));

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('9999', $result['error_code']);
    }

    public function test_an_auth_url_outside_the_whitelist_is_rejected_without_calling_it(): void
    {
        $this->mockPluginSettings();
        Http::fake();
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback([
            'authUrl' => 'https://evil.example.com/api/payAuth',
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame('AUTH_URL_INVALID', $result['error_code']);
        // SSRF 방어 — 화이트리스트 밖 주소로는 어떤 요청도 나가면 안 된다.
        Http::assertNothingSent();
    }

    public function test_pc_approval_returns_transaction_and_paid_amount(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            self::PC_AUTH_URL => Http::response([
                'resultCode' => '0000',
                'tid' => 'INI-TID-1',
                'payMethod' => 'Card',
                'TotPrice' => 3000,
                'mid' => 'INIpayTest',
            ], 200),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback());

        $this->assertTrue($result['success']);
        $this->assertSame('INI-TID-1', $result['transaction_id']);
        $this->assertSame(3000, $result['paid_amount']);
        // 보관 응답에 환급 자격증명 복원용 mid 가 남는다 (화이트리스트 통과 키).
        $this->assertSame('INIpayTest', $result['raw_response']['mid'] ?? null);
    }

    public function test_pc_vbank_approval_is_net_cancelled_and_rejected(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();

        Http::fake([
            self::PC_AUTH_URL => Http::response([
                'resultCode' => '0000',
                'tid' => 'INI-TID-VB',
                'payMethod' => 'VBank',
            ], 200),
            'https://fcstdpay.inicis.com/api/netCancel' => Http::response('OK', 200),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback());

        $this->assertFalse($result['success']);
        $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/netCancel'));
    }

    public function test_mobile_approval_returns_transaction_and_paid_amount(): void
    {
        $this->mockPluginSettings();
        $payment = $this->makePendingPayment();
        // 모바일 화이트리스트 주소를 서비스 상수에서 얻는다 — PC 주소와 다르다.
        $reqUrl = $this->mobileAuthUrl();

        Http::fake([
            $reqUrl => Http::response(http_build_query([
                'P_STATUS' => '00',
                'P_TID' => 'INIMX-TID-1',
                'P_AMT' => '3000',
                'P_TYPE' => 'CARD',
            ]), 200),
        ]);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, [
            'P_STATUS' => '00',
            'P_TID' => 'INIMX-TID-1',
            'P_REQ_URL' => $reqUrl,
            'idc_name' => 'fc',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('INIMX-TID-1', $result['transaction_id']);
        $this->assertSame(3000, $result['paid_amount']);
    }

    /**
     * 모바일 화이트리스트 승인 URL 을 서비스 상수에서 읽습니다.
     */
    private function mobileAuthUrl(): string
    {
        $ref = new \ReflectionClass(KgInicisApiService::class);
        $map = $ref->getConstant('IDC_MOBILE_AUTH_URLS');

        return $map['fc'];
    }
}
