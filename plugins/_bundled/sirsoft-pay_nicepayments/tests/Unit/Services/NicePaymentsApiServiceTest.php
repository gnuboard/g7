<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayNicepayments\Exceptions\NicePayApiException;
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
        $signature = bin2hex(hash('sha256', $authToken.$mid.(string) $amt.self::TEST_MERCHANT_KEY, true));

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
        $signature = bin2hex(hash('sha256', $authToken.self::TEST_MID.(string) $amt.self::TEST_MERCHANT_KEY, true));

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

    /**
     * 결제창이 넘겨준 콜백 URL 은 공격자가 지정할 수 있고, 서버는 여기에 인증 토큰과
     * MID 를 실어 POST 한다. 도메인 대조가 연결 계층의 host 해석과 어긋나면 그 자격증명이
     * 외부로 나가고 내부망 호출까지 가능해진다.
     *
     * @param  string  $url  차단되어야 하는 NextAppURL
     * @param  string  $reason  차단 사유 (실패 메시지용)
     */
    #[DataProvider('forgedCallbackUrlProvider')]
    public function test_authorize_payment_rejects_urls_that_only_look_like_nicepay(string $url, string $reason): void
    {
        $service = $this->makeService();

        Http::fake(['*' => Http::response(['ResultCode' => '3001'], 200)]);

        try {
            $service->authorizePayment($url, 'TID', 'TOKEN', 50000);
            $this->fail("차단되어야 하는 NextAppURL 이 통과함 ({$reason}): {$url}");
        } catch (NicePayApiException) {
            // 기대 동작
        }

        Http::assertNothingSent();
    }

    /**
     * 정규화 후 나이스페이먼츠 공식 도메인인 URL 은 그대로 통과한다.
     *
     * @param  string  $url  허용되어야 하는 NextAppURL
     */
    #[DataProvider('legitimateCallbackUrlProvider')]
    public function test_authorize_payment_still_accepts_official_nicepay_urls(string $url): void
    {
        $service = $this->makeService();

        Http::fake(['*' => Http::response(['ResultCode' => '3001', 'TID' => 'T'], 200)]);

        $result = $service->authorizePayment($url, 'TID', 'TOKEN', 50000);

        $this->assertSame('3001', $result['ResultCode']);
    }

    /**
     * 공식 도메인으로 위장한 콜백 URL 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function forgedCallbackUrlProvider(): array
    {
        return [
            // U+FF0F 는 UTS#46 에서 ASCII `/` 로 매핑된다 — 접미사 대조는 통과하지만
            // 연결 계층은 `evil.example` 을 host 로 읽는다.
            '전각 슬래시로 감춘 외부 호스트' => ["https://evil.example\u{FF0F}.nicepay.co.kr/v1/authorize", '정규화 시 host 는 evil.example'],
            '전각 슬래시로 감춘 루프백' => ["https://127.0.0.1\u{FF0F}.nicepay.co.kr/v1/authorize", '정규화 시 host 는 127.0.0.1'],
            '전각 슬래시로 감춘 메타데이터' => ["https://169.254.169.254\u{FF0F}.nicepay.co.kr/latest/meta-data/", '정규화 시 host 는 169.254.169.254'],
            'userinfo 위장' => ['https://pay.nicepay.co.kr@evil.example/v1/authorize', 'userinfo(@) 뒤가 실제 host'],
            '접미사 확장 도메인' => ['https://pay.nicepay.co.kr.evil.example/v1/authorize', '화이트리스트가 접두사일 뿐'],
            'http scheme' => ['http://pay.nicepay.co.kr/v1/authorize', 'https 아님'],
            '완전 무관 도메인' => ['https://evil.example/v1/authorize', '공식 도메인 아님'],
            '하이픈 접미사' => ['https://pay.nicepay.co.kr-evil.example/v1/authorize', '접미사 확장'],
        ];
    }

    /**
     * 정상 통과해야 하는 공식 콜백 URL 목록.
     *
     * @return array<string, array{string}>
     */
    public static function legitimateCallbackUrlProvider(): array
    {
        return [
            '표준 인증 URL' => ['https://pay.nicepay.co.kr/v1/authorize'],
            '다른 서브도메인' => ['https://webapi.nicepay.co.kr/webapi/cancel_process.jsp'],
            '대소문자 혼용' => ['https://PAY.NicePay.CO.KR/v1/authorize'],
            '후행 점 표기' => ['https://pay.nicepay.co.kr./v1/authorize'],
            // U+00AD SOFT HYPHEN 은 UTS#46 에서 제거된다. 정규화 후 host 는
            // `evil.example.nicepay.co.kr` — 나이스페이먼츠가 소유한 서브도메인이므로
            // 검증기와 연결 계층의 판정이 일치한다(위장 통로가 아니다).
            'soft hyphen 제거 후 공식 서브도메인' => ["https://evil.example\u{00AD}.nicepay.co.kr/v1/authorize"],
        ];
    }
}
