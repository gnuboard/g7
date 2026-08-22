<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class KgInicisApiServiceTest extends PluginTestCase
{
    private const TEST_MID = 'INIpayTest';

    private const TEST_SIGN_KEY = 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS';

    private const TEST_INIAPI_KEY = 'ItEQKi3rY7uvDS8l';

    private const TEST_INIAPI_IV = 'HYb3yQ4f65QL89==';

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

    public function test_get_mid_returns_test_mid_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertEquals(self::TEST_MID, $service->getMid());
    }

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

    public function test_get_js_url_returns_test_url_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertEquals('https://stgstdpay.inicis.com/stdjs/INIStdPay.js', $service->getJsUrl());
    }

    public function test_get_js_url_returns_live_url_in_live_mode(): void
    {
        $service = $this->makeService(['is_test_mode' => false]);

        $this->assertEquals('https://stdpay.inicis.com/stdjs/INIStdPay.js', $service->getJsUrl());
    }

    public function test_get_mkey_returns_sha256_of_sign_key(): void
    {
        $service = $this->makeService();

        $expected = hash('sha256', self::TEST_SIGN_KEY);
        $this->assertEquals($expected, $service->getMKey());
    }

    public function test_generate_signature_returns_correct_sha256(): void
    {
        $service = $this->makeService();

        $oid = 'ORD-001';
        $price = 50000;
        $timestamp = '1714000000000';

        $expected = hash('sha256', 'oid=' . $oid . '&price=' . $price . '&timestamp=' . $timestamp);
        $this->assertEquals($expected, $service->generateSignature($oid, $price, $timestamp));
    }

    public function test_generate_signature_differs_for_different_amounts(): void
    {
        $service = $this->makeService();

        $sig1 = $service->generateSignature('ORD-001', 50000, '1714000000000');
        $sig2 = $service->generateSignature('ORD-001', 99999, '1714000000000');

        $this->assertNotEquals($sig1, $sig2);
    }

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
                    . self::TEST_MID
                    . 'partialRefund'
                    . $request['timestamp']
                    . $detailJson
            );

            return $request['hashData'] === $expectedHash
                && $detail['tid'] === $tid
                && $detail['price'] === (string) $cancelPrice;
        });
    }

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
                . 'Refund'
                . 'CBT'
                . $request['timestamp']
                . $request['clientIp']
                . KgInicisApiService::JAPAN_TEST_MID
                . 'CBT_TID'
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
                . 'PartialRefund'
                . 'CBT'
                . $request['timestamp']
                . $request['clientIp']
                . KgInicisApiService::JAPAN_TEST_MID
                . 'CBT_TID'
                . '300'
                . '700'
            );

            return $request['type'] === 'PartialRefund'
                && $request['price'] === '300'
                && $request['confirmPrice'] === '700'
                && $request['hashData'] === $expectedHash;
        });
    }

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
                . 'Refund'
                . 'CBT'
                . $request['timestamp']
                . $request['clientIp']
                . 'JP_LIVE_PAID'
                . 'CBT_LIVE_TID'
            );

            return $request->url() === 'https://iniapi.inicis.com/api/v1/refund'
                && $request['mid'] === 'JP_LIVE_PAID'
                && $request['hashData'] === $expectedHash;
        });
    }
}
