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

    /**
     * 플러그인 설정을 테스트 모드 자격증명으로 고정합니다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이 결제사의 결제 대기 클레임 결제 건을 만듭니다.
     *
     * @param  array  $overrides  덮어쓸 속성
     * @return ClaimPayment 결제 건
     */
    private function makePendingPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'kginicis',
            'amount' => 3000,
        ], $overrides));
    }

    /**
     * 승인 필터의 기본 입력값을 돌려줍니다.
     *
     * @return array 필터 기본값
     */
    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'paid_amount' => null, 'raw_response' => null, 'error_code' => null, 'error_message' => null];
    }

    /**
     * PC 결제창 인증 콜백 값을 만듭니다.
     *
     * @param  array  $overrides  덮어쓸 값
     * @return array 콜백 값
     */
    private function pcCallback(array $overrides = []): array
    {
        return array_merge([
            'resultCode' => '0000',
            'authToken' => 'auth-token-1',
            'authUrl' => self::PC_AUTH_URL,
            'idc_name' => 'fc',
        ], $overrides);
    }

    /**
     * 클레임 결제 승인 훅을 필터로 구독한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=hook_subscription
     *
     * @effects inicis_claim_approve_listener_subscribes_the_approve_filter
     */
    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentApproveListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.approve', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.approve']['type']);
    }

    /**
     * 다른 결제사의 결제 건은 처리하지 않고 넘긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=other_provider
     *
     * @effects inicis_claim_approve_listener_skips_other_providers
     */
    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePendingPayment(['pg_provider' => 'tosspayments']);

        $result = app(ClaimPaymentApproveListener::class)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback());

        $this->assertFalse($result['handled']);
    }

    /**
     * 결제창 단계 실패는 결제사 코드로 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=window_failure
     *
     * @effects inicis_claim_window_failure_is_rejected_with_the_pg_code
     */
    public function test_window_failure_is_rejected_with_the_pg_code(): void
    {
        $payment = $this->makePendingPayment();

        $result = app(ClaimPaymentApproveListener::class)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback(['resultCode' => '9999', 'resultMsg' => '거절']));

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['success']);
        $this->assertSame('9999', $result['error_code']);
    }

    /**
     * 허용 목록 밖 승인 주소는 호출하지 않고 거절한다(SSRF 방어).
     *
     * @return void 반환값 없음
     *
     * @scenario case=auth_url_outside_whitelist
     *
     * @effects inicis_claim_auth_url_outside_whitelist_is_rejected_without_call
     */
    public function test_an_auth_url_outside_the_whitelist_is_rejected_without_calling_it(): void
    {
        $this->mockPluginSettings();
        Http::fake();
        $payment = $this->makePendingPayment();

        $result = app(ClaimPaymentApproveListener::class)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback([
            'authUrl' => 'https://evil.example.com/api/payAuth',
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame('AUTH_URL_INVALID', $result['error_code']);
        // SSRF 방어 — 화이트리스트 밖 주소로는 어떤 요청도 나가면 안 된다.
        Http::assertNothingSent();
    }

    /**
     * PC 승인 성공은 거래번호와 승인 금액을 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=pc_approved
     *
     * @effects inicis_claim_pc_approval_returns_transaction_and_paid_amount
     */
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

        $result = app(ClaimPaymentApproveListener::class)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback());

        $this->assertTrue($result['success']);
        $this->assertSame('INI-TID-1', $result['transaction_id']);
        $this->assertSame(3000, $result['paid_amount']);
        // 보관 응답에 환급 자격증명 복원용 mid 가 남는다 (화이트리스트 통과 키).
        $this->assertSame('INIpayTest', $result['raw_response']['mid'] ?? null);
    }

    /**
     * PC 가상계좌 승인은 망취소하고 거절한다(클레임 결제는 입금 대기 수단 미지원).
     *
     * @return void 반환값 없음
     *
     * @scenario case=pc_vbank
     *
     * @effects inicis_claim_pc_vbank_approval_is_net_cancelled_and_rejected
     */
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

        $result = app(ClaimPaymentApproveListener::class)->processApprove($this->defaultFilterValue(), $payment, $this->pcCallback());

        $this->assertFalse($result['success']);
        $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/netCancel'));
    }

    /**
     * 모바일 승인 성공은 거래번호와 승인 금액을 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=mobile_approved
     *
     * @effects inicis_claim_mobile_approval_returns_transaction_and_paid_amount
     */
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

        $result = app(ClaimPaymentApproveListener::class)->processApprove($this->defaultFilterValue(), $payment, [
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
     *
     * @return string 승인 URL
     */
    private function mobileAuthUrl(): string
    {
        $ref = new \ReflectionClass(KgInicisApiService::class);
        $map = $ref->getConstant('IDC_MOBILE_AUTH_URLS');

        return $map['fc'];
    }
}
