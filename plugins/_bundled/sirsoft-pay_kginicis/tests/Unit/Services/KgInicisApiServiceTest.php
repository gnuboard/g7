<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * KG이니시스 API 서비스가 결제사로 보내는 요청(MID·서명·승인·망취소·취소·조회·CBT 취소)을 검증한다.
 *
 * 실제 HTTP 는 Http::fake 로 막고, 보낸 경로·필드·해시가 이니시스 매뉴얼과 맞는지 단언한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/inicis-api-service-requests.yaml`
 */
class KgInicisApiServiceTest extends PluginTestCase
{
    private const TEST_MID = 'INIpayTest';

    private const TEST_SIGN_KEY = 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS';

    private const TEST_INIAPI_KEY = 'ItEQKi3rY7uvDS8l';

    private const TEST_INIAPI_IV = 'HYb3yQ4f65QL89==';

    /**
     * 테스트 상점 설정(덮어쓰기 반영)으로 API 서비스를 만듭니다.
     *
     * @param  array<string, mixed>  $settingsOverrides  기본 설정 위에 덮어쓸 값
     * @return KgInicisApiService API 서비스
     */
    private function makeService(array $settingsOverrides = []): KgInicisApiService
    {
        $defaults = [
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_sign_key' => self::TEST_SIGN_KEY,
            'test_iniapi_key' => self::TEST_INIAPI_KEY,
            'test_iniapi_iv' => self::TEST_INIAPI_IV,
            'live_mid' => '',
            'live_sign_key' => '',
            'live_iniapi_key' => '',
            'live_iniapi_iv' => '',
            'japan_enabled' => true,
            'test_japan_sign_key' => '5AL5Djb1Ipualn0F',
            'live_japan_mid' => '',
            'live_japan_sign_key' => '',
        ];

        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $settingsOverrides));

        return new KgInicisApiService($settingsMock);
    }

    /**
     * 테스트 모드에서는 설정의 테스트 MID 를 가맹점 ID 로 쓴다.
     *
     * @scenario call=mid
     *
     * @effects get_mid_returns_test_mid_in_test_mode
     *
     * @return void 반환값 없음
     */
    public function test_get_mid_returns_test_mid_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertEquals(self::TEST_MID, $service->getMid());
    }

    /**
     * 운영 모드에서는 입력한 운영 MID 앞에 'SIR' 접두어를 붙여 쓴다.
     *
     * @scenario call=mid
     *
     * @effects get_mid_returns_live_mid_in_live_mode
     *
     * @return void 반환값 없음
     */
    public function test_get_mid_returns_live_mid_in_live_mode(): void
    {
        // CLAUDE.local.md 규정 — 라이브 MID 는 항상 'SIR' prefix (수익 직결 가맹점 계약).
        // 입력값에 prefix 가 없으면 buildLiveMid 가 자동 prepend.
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_mid' => 'live_mid_value',
            'live_sign_key' => 'live_sign_key_value',
        ]);

        $this->assertEquals('SIRlive_mid_value', $service->getMid());
    }

    /**
     * 라이브 MID 입력값이 이미 SIR prefix 를 포함하면 중복 prepend 하지 않는다.
     *
     * @scenario call=mid
     *
     * @effects get_mid_does_not_double_prefix_when_input_already_has_sir
     *
     * @return void 반환값 없음
     */
    public function test_get_mid_does_not_double_prefix_when_input_already_has_sir(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_mid' => 'SIRshoptest',
            'live_sign_key' => 'live_sign_key_value',
        ]);

        $this->assertEquals('SIRshoptest', $service->getMid());
    }

    /**
     * 테스트 모드에서는 스테이징 결제창 JS URL 을 돌려준다.
     *
     * @scenario call=js_url
     *
     * @effects get_js_url_returns_test_url_in_test_mode
     *
     * @return void 반환값 없음
     */
    public function test_get_js_url_returns_test_url_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertEquals('https://stgstdpay.inicis.com/stdjs/INIStdPay.js', $service->getJsUrl());
    }

    /**
     * 운영 모드에서는 운영 결제창 JS URL 을 돌려준다.
     *
     * @scenario call=js_url
     *
     * @effects get_js_url_returns_live_url_in_live_mode
     *
     * @return void 반환값 없음
     */
    public function test_get_js_url_returns_live_url_in_live_mode(): void
    {
        $service = $this->makeService(['is_test_mode' => false]);

        $this->assertEquals('https://stdpay.inicis.com/stdjs/INIStdPay.js', $service->getJsUrl());
    }

    /**
     * mKey 는 signKey 의 SHA-256 해시다.
     *
     * @scenario call=signature
     *
     * @effects get_mkey_returns_sha256_of_sign_key
     *
     * @return void 반환값 없음
     */
    public function test_get_mkey_returns_sha256_of_sign_key(): void
    {
        $service = $this->makeService();

        $expected = hash('sha256', self::TEST_SIGN_KEY);
        $this->assertEquals($expected, $service->getMKey());
    }

    /**
     * 결제 서명은 oid·price·timestamp 를 이은 문자열의 SHA-256 해시다.
     *
     * @scenario call=signature
     *
     * @effects generate_signature_returns_correct_sha256
     *
     * @return void 반환값 없음
     */
    public function test_generate_signature_returns_correct_sha256(): void
    {
        $service = $this->makeService();

        $oid = 'ORD-001';
        $price = 50000;
        $timestamp = '1714000000000';

        $expected = hash('sha256', 'oid='.$oid.'&price='.$price.'&timestamp='.$timestamp);
        $this->assertEquals($expected, $service->generateSignature($oid, $price, $timestamp));
    }

    /**
     * 금액이 다르면 결제 서명도 달라진다(금액 위변조 방지).
     *
     * @scenario call=signature
     *
     * @effects generate_signature_differs_for_different_amounts
     *
     * @return void 반환값 없음
     */
    public function test_generate_signature_differs_for_different_amounts(): void
    {
        $service = $this->makeService();

        $sig1 = $service->generateSignature('ORD-001', 50000, '1714000000000');
        $sig2 = $service->generateSignature('ORD-001', 99999, '1714000000000');

        $this->assertNotEquals($sig1, $sig2);
    }

    /**
     * 서버 승인은 받은 승인 URL 로 authToken 을 보내고 PG 응답을 그대로 돌려준다.
     *
     * @scenario call=authorize
     *
     * @effects authorize_payment_posts_to_auth_url_and_returns_response
     *
     * @return void 반환값 없음
     */
    public function test_authorize_payment_posts_to_auth_url_and_returns_response(): void
    {
        $service = $this->makeService();

        $authUrl = 'https://stginiapi.inicis.com/api/v1/auth';
        $authToken = 'AUTH_TOKEN_TEST';

        Http::fake([
            $authUrl => Http::response([
                'resultCode' => '0000',
                'resultMsg' => '성공',
                'tid' => 'TID_123456',
                'payMethod' => 'Card',
            ], 200),
        ]);

        $result = $service->authorizePayment($authUrl, $authToken);

        $this->assertEquals('0000', $result['resultCode']);
        $this->assertEquals('TID_123456', $result['tid']);

        Http::assertSent(function ($request) use ($authUrl, $authToken) {
            return $request->url() === $authUrl
                && $request['authToken'] === $authToken;
        });
    }

    /**
     * 서버 승인 HTTP 오류는 예외로 올린다.
     *
     * @scenario call=authorize
     *
     * @effects authorize_payment_throws_on_http_error
     *
     * @return void 반환값 없음
     */
    public function test_authorize_payment_throws_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->authorizePayment('https://stginiapi.inicis.com/api/v1/auth', 'TOKEN');
    }

    /**
     * 망취소는 받은 망취소 URL 로 authToken 을 보낸다.
     *
     * @scenario call=net_cancel
     *
     * @effects send_net_cancel_posts_auth_token_to_net_cancel_url
     *
     * @return void 반환값 없음
     */
    public function test_send_net_cancel_posts_auth_token_to_net_cancel_url(): void
    {
        $service = $this->makeService();

        $netCancelUrl = 'https://stginiapi.inicis.com/api/v1/netcancel';
        $authToken = 'AUTH_TOKEN_TEST';

        Http::fake([
            $netCancelUrl => Http::response('OK', 200),
        ]);

        $service->sendNetCancel($netCancelUrl, $authToken);

        Http::assertSent(function ($request) use ($netCancelUrl, $authToken) {
            return $request->url() === $netCancelUrl
                && $request['authToken'] === $authToken;
        });
    }

    /**
     * 망취소 HTTP 오류는 기록만 하고 예외를 올리지 않는다.
     *
     * @scenario call=net_cancel
     *
     * @effects send_net_cancel_does_not_throw_on_http_error
     *
     * @return void 반환값 없음
     */
    public function test_send_net_cancel_does_not_throw_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        // 망취소 실패는 무시
        $service->sendNetCancel('https://stginiapi.inicis.com/api/v1/netcancel', 'TOKEN');

        $this->assertTrue(true);
    }

    /**
     * 전액취소는 type=refund 로 tid·사유·해시를 보내고 금액은 싣지 않는다.
     *
     * @scenario call=cancel
     *
     * @effects cancel_payment_sends_full_refund_request
     *
     * @return void 반환값 없음
     */
    public function test_cancel_payment_sends_full_refund_request(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stginiapi.inicis.com/v2/pg/refund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '취소 성공',
                'tid' => 'TID_CANCEL',
            ], 200),
        ]);

        $result = $service->cancelPayment('TID_ORIG', 'Card', null, '고객 요청');

        $this->assertEquals('00', $result['resultCode']);

        Http::assertSent(function ($request) {
            $data = $request['data'] ?? [];

            return $request['type'] === 'refund'
                && $request['mid'] === self::TEST_MID
                && $data['tid'] === 'TID_ORIG'
                && $data['msg'] === '고객 요청'
                && ! isset($data['price'])
                && isset($request['hashData'])
                && isset($request['timestamp'])
                && isset($request['clientIp']);
        });
    }

    /**
     * 사유를 넘기지 않은 취소·CBT 환불·현금영수증 취소·구매거절 확인은 화면 언어의 기본 문구를 보낸다(한국어 고정 아님).
     *
     * @scenario call=cancel
     *
     * @effects default_pg_messages_follow_locale
     *
     * @return void 반환값 없음
     */
    public function test_default_pg_messages_follow_locale(): void
    {
        app()->setLocale('en');
        $service = $this->makeService();

        Http::fake(['*' => Http::response(['resultCode' => '00', 'resultMsg' => 'OK', 'tid' => 'T'], 200)]);

        $service->cancelPayment('TID_ORIG', 'Card');
        $service->refundCbtPayment('CBT_TID');
        $service->cancelCashReceipt('CSH_TID');
        $service->denyConfirmEscrow(['originalTid' => 'ESC_TID']);

        $cancel = __('sirsoft-pay_kginicis::messages.refund.admin_cancel_reason', [], 'en');
        $cashCancel = __('sirsoft-pay_kginicis::messages.cash_receipt.cancel_reason', [], 'en');
        $confirmer = __('sirsoft-pay_kginicis::messages.escrow.default_confirmer', [], 'en');

        Http::assertSent(fn ($request) => ($request['data']['tid'] ?? null) === 'TID_ORIG' && $request['data']['msg'] === $cancel);
        Http::assertSent(fn ($request) => ($request['tid'] ?? null) === 'CBT_TID' && $request['msg'] === $cancel);
        Http::assertSent(fn ($request) => ($request['data']['tid'] ?? null) === 'CSH_TID' && $request['data']['msg'] === $cashCancel);
        Http::assertSent(fn ($request) => ($request['originalTid'] ?? null) === 'ESC_TID' && $request['dcnfName'] === $confirmer);
        $this->assertSame('Administrator', $confirmer);
    }

    /**
     * 부분취소는 type=partialRefund 로 취소 금액을 실어 보낸다.
     *
     * @scenario call=cancel
     *
     * @effects cancel_payment_sends_partial_refund_request
     *
     * @return void 반환값 없음
     */
    public function test_cancel_payment_sends_partial_refund_request(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stginiapi.inicis.com/v2/pg/partialRefund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '부분 취소 성공',
            ], 200),
        ]);

        $result = $service->cancelPayment('TID_ORIG', 'Card', 10000, '부분 취소');

        $this->assertEquals('00', $result['resultCode']);

        Http::assertSent(function ($request) {
            $data = $request['data'] ?? [];

            return $request['type'] === 'partialRefund'
                && $data['price'] === '10000';
        });
    }

    /**
     * P5 — 입금완료 가상계좌는 **환불받을 계좌**가 취소 요청에 실려야 PG 가 받아 준다.
     *
     * 이 값들이 취소 API 가 아닌 거래조회 API 에 붙어 있으면, 조회는 멀쩡히 돌고 취소만
     * 조용히 거절된다 — 운영자에게는 "취소가 안 된다" 로만 보인다. 그래서 단언 대상은
     * `cancelPayment()` 가 보낸 payload 다. 가상계좌 환불은 전용 경로(`/vacct`)이고 계좌번호는 AES 암호화 대상이다(#747 C8).
     *
     * @scenario call=cancel
     *
     * @effects cancel_payment_sends_the_refund_account_for_a_settled_virtual_account
     *
     * @return void 반환값 없음
     */
    public function test_cancel_payment_sends_the_refund_account_for_a_settled_virtual_account(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stginiapi.inicis.com/v2/pg/refund/vacct' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '취소 성공',
            ], 200),
        ]);

        $service->cancelPayment('TID_ORIG', 'VBank', null, '고객 요청', null, [
            'bank_code' => '004',
            'account_number' => '12345678901234',
            'holder' => '홍길동',
        ]);

        $encrypted = base64_encode(openssl_encrypt('12345678901234', 'aes-128-cbc', self::TEST_INIAPI_KEY, OPENSSL_RAW_DATA, self::TEST_INIAPI_IV));

        Http::assertSent(function ($request) use ($encrypted) {
            $data = $request['data'] ?? [];

            return $request['type'] === 'refund'
                && str_ends_with($request->url(), '/v2/pg/refund/vacct')
                && ($data['refundAcctNum'] ?? null) === $encrypted
                && ($data['refundBankCode'] ?? null) === '004'
                && ($data['refundAcctName'] ?? null) === '홍길동';
        });
    }

    /**
     * P5 — 계좌가 없는 수단(카드 등)에는 세 키를 붙이지 않는다.
     *
     * @scenario call=cancel
     *
     * @effects cancel_payment_omits_the_refund_account_when_none_is_given
     *
     * @return void 반환값 없음
     */
    public function test_cancel_payment_omits_the_refund_account_when_none_is_given(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stginiapi.inicis.com/v2/pg/refund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '취소 성공',
            ], 200),
        ]);

        $service->cancelPayment('TID_ORIG', 'Card', null, '고객 요청');

        Http::assertSent(function ($request) {
            $data = $request['data'] ?? [];

            return ! isset($data['refundAcctNum'])
                && ! isset($data['refundBankCode'])
                && ! isset($data['refundAcctName']);
        });
    }

    /**
     * P5 — 거래조회는 환불계좌를 받지 않는다. 그 자리에 붙으면 취소가 값을 잃는다.
     *
     * @scenario call=inquiry
     *
     * @effects query_transaction_does_not_carry_refund_account_keys
     *
     * @return void 반환값 없음
     */
    public function test_query_transaction_does_not_carry_refund_account_keys(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stginiapi.inicis.com/v2/pg/inquiry' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '조회 성공',
            ], 200),
        ]);

        $service->queryTransaction('TID_ORIG');

        Http::assertSent(function ($request) {
            $data = $request['data'] ?? [];

            return $request['type'] === 'inquiry'
                && ! isset($data['refundAcctNum'])
                && ! isset($data['refundBankCode'])
                && ! isset($data['refundAcctName']);
        });
    }

    /**
     * 취소 응답 resultCode 가 '00' 이 아니면 PG 메시지로 예외를 올린다.
     *
     * @scenario call=cancel
     *
     * @effects cancel_payment_throws_on_non_00_result_code
     *
     * @return void 반환값 없음
     */
    public function test_cancel_payment_throws_on_non_00_result_code(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stginiapi.inicis.com/v2/pg/refund' => Http::response([
                'resultCode' => '9999',
                'resultMsg' => '취소 실패',
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('취소 실패');

        $service->cancelPayment('TID_ORIG', 'Card', null, '취소');
    }

    /**
     * 취소 HTTP 오류는 예외로 올린다.
     *
     * @scenario call=cancel
     *
     * @effects cancel_payment_throws_on_http_error
     *
     * @return void 반환값 없음
     */
    public function test_cancel_payment_throws_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->cancelPayment('TID_ORIG', 'Card', null, '취소');
    }

    /**
     * 부분취소 hashData 는 INIAPI v2 공식(inapiKey+mid+type+timestamp+detailJson)의 SHA-512 다.
     *
     * @scenario call=cancel
     *
     * @effects cancel_payment_hash_data_includes_detail_for_partial_refund
     *
     * @return void 반환값 없음
     */
    public function test_cancel_payment_hash_data_includes_detail_for_partial_refund(): void
    {
        $service = $this->makeService();

        $tid = 'TID_ORIG';
        $payMethod = 'Card';
        $cancelPrice = 10000;

        Http::fake([
            'stginiapi.inicis.com/v2/pg/partialRefund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '성공',
            ], 200),
        ]);

        $service->cancelPayment($tid, $payMethod, $cancelPrice, '부분취소');

        // INIAPI v2 hash 공식: SHA512(inapiKey + mid + type + timestamp + detailJson)
        Http::assertSent(function ($request) use ($tid, $cancelPrice) {
            $detail = $request['data'] ?? [];
            $detailJson = str_replace('\\/', '/', json_encode($detail, JSON_UNESCAPED_UNICODE));
            $expectedHash = hash(
                'sha512',
                self::TEST_INIAPI_KEY
                    .self::TEST_MID
                    .'partialRefund'
                    .$request['timestamp']
                    .$detailJson
            );

            return $request['hashData'] === $expectedHash
                && $detail['tid'] === $tid
                && $detail['price'] === (string) $cancelPrice;
        });
    }

    /**
     * CBT 전액취소는 매뉴얼 v0.71 대로 /api/v1/refund 에 Refund 요청과 SHA-512 hashData 를 보낸다.
     *
     * @scenario call=cbt_refund
     *
     * @effects refund_cbt_payment_sends_manual_v071_full_refund_request
     *
     * @return void 반환값 없음
     */
    public function test_refund_cbt_payment_sends_manual_v071_full_refund_request(): void
    {
        $service = $this->makeService();

        Http::fake([
            'deviniapi.inicis.com/api/v1/refund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '취소 성공',
                'tid' => 'CBT_TID',
            ], 200),
        ]);

        $result = $service->refundCbtPayment('CBT_TID', null, '고객 요청');

        $this->assertEquals('00', $result['resultCode']);

        Http::assertSent(function ($request) {
            $expectedHash = hash(
                'sha512',
                '5AL5Djb1Ipualn0F'
                .'Refund'
                .'CBT'
                .$request['timestamp']
                .$request['clientIp']
                .KgInicisApiService::JAPAN_TEST_MID
                .'CBT_TID'
            );

            return $request->url() === 'https://deviniapi.inicis.com/api/v1/refund'
                && $request['type'] === 'Refund'
                && $request['paymethod'] === 'CBT'
                && $request['mid'] === KgInicisApiService::JAPAN_TEST_MID
                && $request['tid'] === 'CBT_TID'
                && $request['msg'] === '고객 요청'
                && $request['hashData'] === $expectedHash;
        });
    }

    /**
     * CBT 부분취소는 price·confirmPrice 를 싣고 해시에도 이어 붙인다.
     *
     * @scenario call=cbt_refund
     *
     * @effects refund_cbt_payment_sends_partial_refund_request
     *
     * @return void 반환값 없음
     */
    public function test_refund_cbt_payment_sends_partial_refund_request(): void
    {
        $service = $this->makeService();

        Http::fake([
            'deviniapi.inicis.com/api/v1/refund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '부분취소 성공',
                'refundTid' => 'CBT_REFUND_TID',
            ], 200),
        ]);

        $service->refundCbtPayment('CBT_TID', 300, '부분 취소', 1000);

        Http::assertSent(function ($request) {
            $expectedHash = hash(
                'sha512',
                '5AL5Djb1Ipualn0F'
                .'PartialRefund'
                .'CBT'
                .$request['timestamp']
                .$request['clientIp']
                .KgInicisApiService::JAPAN_TEST_MID
                .'CBT_TID'
                .'300'
                .'700'
            );

            return $request['type'] === 'PartialRefund'
                && $request['price'] === '300'
                && $request['confirmPrice'] === '700'
                && $request['hashData'] === $expectedHash;
        });
    }

    /**
     * CBT 취소는 현재 설정이 아닌 결제 당시 모드·MID 의 자격증명으로 보낸다.
     *
     * @scenario call=cbt_refund
     *
     * @effects use_stored_cbt_credentials_uses_payment_time_live_mid_for_refund
     *
     * @return void 반환값 없음
     */
    public function test_use_stored_cbt_credentials_uses_payment_time_live_mid_for_refund(): void
    {
        $service = $this->makeService([
            'is_test_mode' => true,
            'live_japan_mid' => 'JP_LIVE_CURRENT',
            'live_japan_sign_key' => 'LIVE_CBT_KEY',
        ]);

        $service->useStoredCbtCredentials(false, 'JP_LIVE_PAID');

        Http::fake([
            'iniapi.inicis.com/api/v1/refund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '취소 성공',
            ], 200),
        ]);

        $service->refundCbtPayment('CBT_LIVE_TID', null, '고객 요청');

        Http::assertSent(function ($request) {
            $expectedHash = hash(
                'sha512',
                'LIVE_CBT_KEY'
                .'Refund'
                .'CBT'
                .$request['timestamp']
                .$request['clientIp']
                .'JP_LIVE_PAID'
                .'CBT_LIVE_TID'
            );

            return $request->url() === 'https://iniapi.inicis.com/api/v1/refund'
                && $request['mid'] === 'JP_LIVE_PAID'
                && $request['hashData'] === $expectedHash;
        });
    }
}
