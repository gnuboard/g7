<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class NicePaymentsApiServiceTest extends PluginTestCase
{
    private const TEST_MID = 'nicepay00m';

    private const TEST_MERCHANT_KEY = 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==';

    private function makeService(array $settingsOverrides = []): NicePaymentsApiService
    {
        $defaults = [
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_merchant_key' => self::TEST_MERCHANT_KEY,
            'live_mid' => '',
            'live_merchant_key' => '',
        ];

        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $settingsOverrides));

        return new NicePaymentsApiService($settingsMock);
    }

    public function test_get_mid_returns_test_mid_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertEquals(self::TEST_MID, $service->getMid());
    }

    public function test_get_mid_returns_live_mid_in_live_mode(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_mid' => 'live_mid_value',
            'live_merchant_key' => 'live_key',
        ]);

        $this->assertEquals('SRlive_mid_value', $service->getMid());
    }

    public function test_get_mid_returns_empty_when_live_mid_is_missing(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_mid' => '',
            'live_merchant_key' => 'live_key',
        ]);

        $this->assertSame('', $service->getMid());
    }

    public function test_verify_callback_signature_returns_true_on_valid_signature(): void
    {
        $service = $this->makeService();

        $authToken = 'AUTH_TOKEN_TEST';
        $mid = self::TEST_MID;
        $amt = 50000;
        $signature = bin2hex(hash('sha256', $authToken . $mid . (string) $amt . self::TEST_MERCHANT_KEY, true));

        $this->assertTrue($service->verifyCallbackSignature($authToken, $mid, $amt, $signature));
    }

    public function test_verify_callback_signature_returns_false_on_invalid_signature(): void
    {
        $service = $this->makeService();

        $this->assertFalse($service->verifyCallbackSignature('token', self::TEST_MID, 50000, 'INVALID'));
    }

    public function test_verify_callback_signature_returns_false_on_tampered_amount(): void
    {
        $service = $this->makeService();

        $authToken = 'AUTH_TOKEN_TEST';
        $amt = 50000;
        $signature = bin2hex(hash('sha256', $authToken . self::TEST_MID . (string) $amt . self::TEST_MERCHANT_KEY, true));

        // 금액을 변조하여 서명 검증
        $this->assertFalse($service->verifyCallbackSignature($authToken, self::TEST_MID, 99999, $signature));
    }

    public function test_authorize_payment_calls_next_app_url_with_correct_params(): void
    {
        $service = $this->makeService();

        $nextAppUrl = 'https://pay.nicepay.co.kr/v1/authorize';
        $txTid = 'TX_TID_TEST';
        $authToken = 'AUTH_TOKEN_TEST';
        $amt = 50000;

        Http::fake([
            $nextAppUrl => Http::response([
                'ResultCode' => '3001',
                'ResultMsg' => '정상처리',
                'TID' => 'TID_FINAL',
                'Amt' => (string) $amt,
            ], 200),
        ]);

        $result = $service->authorizePayment($nextAppUrl, $txTid, $authToken, $amt);

        $this->assertEquals('3001', $result['ResultCode']);
        $this->assertEquals('TID_FINAL', $result['TID']);

        Http::assertSent(function ($request) use ($nextAppUrl, $txTid, $authToken, $amt) {
            return $request->url() === $nextAppUrl
                && $request['TID'] === $txTid
                && $request['AuthToken'] === $authToken
                && $request['MID'] === self::TEST_MID
                && $request['Amt'] == $amt
                && isset($request['EdiDate'])
                && isset($request['SignData'])
                && $request['CharSet'] === 'utf-8';
        });
    }

    public function test_authorize_payment_throws_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->authorizePayment('https://pay.nicepay.co.kr/v1/authorize', 'TID', 'TOKEN', 50000);
    }

    public function test_cancel_payment_calls_cancel_api_and_returns_response(): void
    {
        $service = $this->makeService();

        Http::fake([
            'pg-api.nicepay.co.kr/webapi/cancel_process.jsp' => Http::response([
                'ResultCode' => '2001',
                'ResultMsg' => '취소 성공',
                'TID' => 'TID_CANCEL',
            ], 200),
        ]);

        $result = $service->cancelPayment('TID_ORIG', 'ORD-001', 50000, '고객 요청', 0);

        $this->assertEquals('2001', $result['ResultCode']);
    }

    public function test_cancel_payment_throws_on_non_2001_result_code(): void
    {
        $service = $this->makeService();

        Http::fake([
            'pg-api.nicepay.co.kr/webapi/cancel_process.jsp' => Http::response([
                'ResultCode' => '9999',
                'ResultMsg' => '취소 실패',
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('취소 실패');

        $service->cancelPayment('TID_ORIG', 'ORD-001', 50000, '고객 요청', 0);
    }

    public function test_send_net_cancel_posts_to_net_cancel_url(): void
    {
        $service = $this->makeService();

        $netCancelUrl = 'https://pay.nicepay.co.kr/v1/netcancel';

        Http::fake([
            $netCancelUrl => Http::response('OK', 200),
        ]);

        // 예외 없이 실행되어야 함
        $service->sendNetCancel($netCancelUrl, 'TX_TID_TEST', 'AUTH_TOKEN_TEST', 50000);

        Http::assertSent(function ($request) use ($netCancelUrl) {
            return $request->url() === $netCancelUrl
                && $request['NetCancel'] == 1
                && $request['MID'] === self::TEST_MID;
        });
    }

    public function test_send_net_cancel_does_not_throw_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        // 예외 없이 실행되어야 함 (망취소 실패는 무시)
        $service->sendNetCancel('https://pay.nicepay.co.kr/v1/netcancel', 'TX_TID', 'TOKEN', 50000);

        $this->assertTrue(true);
    }

    public function test_query_transaction_calls_query_api(): void
    {
        $service = $this->makeService();
        $tid = 'TID_QUERY_TEST';

        Http::fake([
            'webapi.nicepay.co.kr/webapi/inquery/trans_status.jsp' => Http::response([
                'ResultCode' => '2000',
                'ResultMsg' => '정상처리',
                'TID' => $tid,
                'Amt' => '50000',
            ], 200),
        ]);

        $result = $service->queryTransaction($tid);

        $this->assertEquals('2000', $result['ResultCode']);
        $this->assertEquals($tid, $result['TID']);

        Http::assertSent(function ($request) use ($tid) {
            return str_contains($request->url(), 'trans_status.jsp')
                && $request['TID'] === $tid
                && $request['MID'] === self::TEST_MID
                && isset($request['EdiDate'])
                && isset($request['SignData'])
                && $request['CharSet'] === 'utf-8'
                && $request['EdiType'] === 'JSON';
        });
    }

    public function test_query_transaction_throws_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->queryTransaction('TID_TEST');
    }
}
