<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class MobileCallbackRouteTest extends PluginTestCase
{
    /**
     * KG 이니시스 모바일 표준결제는 P_NEXT_URL 로 POST 콜백을 보낸다.
     * 라우트가 GET 만 허용하면 405 Method Not Allowed 회귀가 발생한다.
     * 가상계좌 결제 시 운영 검증으로 발견된 회귀.
     */
    public function test_mobile_callback_route_accepts_post(): void
    {
        $response = $this->withoutMiddleware()->post(
            '/plugins/sirsoft-pay_kginicis/payment/mobile/callback',
            [
                'P_STATUS'  => '99',
                'P_RMESG1'  => 'test',
                'P_OID'     => 'TEST-OID-NOT-EXIST',
                'P_TID'     => 'TEST-TID',
                'P_REQ_URL' => 'https://stgstdpay.inicis.com/api/payAuth',
                'P_AMT'     => '1000',
                'idc_name'  => 'fc',
            ]
        );

        $this->assertNotSame(405, $response->getStatusCode(), 'POST callback must not return 405 Method Not Allowed');
    }

    public function test_mobile_callback_route_accepts_get(): void
    {
        $response = $this->withoutMiddleware()->get(
            '/plugins/sirsoft-pay_kginicis/payment/mobile/callback?P_STATUS=99&P_OID=TEST-OID-NOT-EXIST'
        );

        $this->assertNotSame(405, $response->getStatusCode(), 'GET callback should remain supported');
    }

    /**
     * 사용자 취소 시 KG 이니시스가 P_STATUS=01 + P_RMESG1='사용자가 결제를 취소하셨습니다.' 를 보낸다.
     * 이 경우 오류 모달을 띄우지 않고 체크아웃으로 조용히 복귀해야 한다.
     * Playwright 운영 재현으로 확인된 패턴.
     */
    public function test_mobile_callback_user_cancel_returns_to_checkout_without_error(): void
    {
        $response = $this->withoutMiddleware()->post(
            '/plugins/sirsoft-pay_kginicis/payment/mobile/callback?orderId=TEST-CANCEL',
            [
                'P_STATUS'  => '01',
                'P_RMESG1'  => '사용자가 결제를 취소하셨습니다.',
                'P_OID'     => 'TEST-CANCEL',
                'P_TID'     => '',
                'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
                'P_AMT'     => '1000',
                'idc_name'  => 'fc',
            ]
        );

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/shop/checkout', $location);
        $this->assertStringNotContainsString('error=', $location, '사용자 취소는 error 쿼리 미부착으로 모달 미노출');
        $this->assertStringNotContainsString('message=', $location, '사용자 취소는 message 쿼리 미부착');
    }

    /**
     * 사용자 취소 외 일반 실패 (예: MX1006) 는 종전대로 error/message 쿼리 부착하여 모달 노출.
     */
    public function test_mobile_callback_general_failure_preserves_error_query(): void
    {
        $response = $this->withoutMiddleware()->post(
            '/plugins/sirsoft-pay_kginicis/payment/mobile/callback?orderId=TEST-FAIL',
            [
                'P_STATUS'  => '01',
                'P_RMESG1'  => '일시적으로 오류로 결제시도가 정상적으로 처리되지 않았습니다.(MX1006)',
                'P_OID'     => 'TEST-FAIL',
                'P_TID'     => '',
                'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
                'P_AMT'     => '1000',
                'idc_name'  => 'fc',
            ]
        );

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('error=', $location);
        $this->assertStringContainsString('MX1006', $location);
    }
}
