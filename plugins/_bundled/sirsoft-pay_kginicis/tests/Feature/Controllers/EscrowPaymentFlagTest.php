<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 에스크로를 켜고 받은 KG이니시스 결제에 에스크로 표시(order_payments.is_escrow)를 남기는지 검증한다 (이커머스 전수 실측 S4 — S4-D18)
 *
 * 결함: 「에스크로 사용」 을 켜면 결제창에 useescrow 를 실어 에스크로 MID(테스트 iniescrow0)로 결제가 이뤄지는데,
 *   승인·가상계좌 발급 콜백이 결제 행에 is_escrow 를 남기지 않았다. 실측: 에스크로 가상계좌 주문 736(tid …iniescrow0…,
 *   payment_meta.mid iniescrow0)의 is_escrow=0. 그래서 마이페이지 [구매결정] 버튼·관리자 에스크로 배송 등록·구매결정
 *   확인 조회·에스크로 환불 분기가 모두 이 결제를 에스크로로 보지 못했다(이 표시로만 판정).
 * 간편결제는 결제창마다 다르다(S4-D21): PC 결제창은 간편결제에도 acceptmethod 의 useescrow 를 그대로 두고 cardonly 만
 *   붙여(gnuboard5 orderform 과 같음) 에스크로 MID 로 에스크로 결제를 하므로 에스크로로 표시한다. 모바일 결제창은
 *   P_RESERVED 에서 useescrow 를 빼므로(requestPayment.ts) 에스크로가 아니다.
 */
class EscrowPaymentFlagTest extends PluginTestCase
{
    /**
     * 결제 대기 주문 하나를 만듭니다.
     *
     * @return Order 주문
     */
    private function createOrder(): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-ESC-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => 50000,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => 50000,
            'total_due_amount' => 50000,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'is_escrow' => false,
        ]);

        return $order;
    }

    /**
     * 플러그인 설정을 테스트 값으로 고정합니다.
     *
     * @param  bool  $useEscrow  에스크로 사용 여부
     * @return void 없음
     */
    private function settings(bool $useEscrow): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
            'use_escrow' => $useEscrow,
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * PC 승인 응답을 꾸밉니다.
     *
     * @param  Order  $order  주문
     * @param  array<string, mixed>  $overrides  덮어쓸 응답 값
     * @return void 없음
     */
    private function fakeApproval(Order $order, array $overrides = []): void
    {
        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(array_merge([
                'resultCode' => '0000',
                'resultMsg' => '성공',
                'tid' => 'StdpayCARDiniescrow0'.uniqid(),
                'MOID' => $order->order_number,
                'TotPrice' => '50000',
                'payMethod' => 'Card',
                'applNum' => 'APP12345',
                'cardQuota' => '00',
                'applDate' => now()->format('YmdHis'),
            ], $overrides), 200),
            '*' => Http::response(['resultCode' => '0000'], 200),
        ]);
    }

    /**
     * PC 콜백 요청 본문을 만듭니다.
     *
     * @param  Order  $order  주문
     * @return array<string, mixed> 콜백 본문
     */
    private function callbackParams(Order $order): array
    {
        return [
            'resultCode' => '0000',
            'resultMsg' => '성공',
            'authToken' => 'AUTH_TOKEN_'.uniqid(),
            'authUrl' => 'https://fcstdpay.inicis.com/api/payAuth',
            'idc_name' => 'fc',
            'netCancelUrl' => 'https://stginiapi.inicis.com/api/v1/netcancel',
            'MOID' => $order->order_number,
            'TotPrice' => 50000,
        ];
    }

    /**
     * 에스크로를 켠 상점의 가상계좌 발급은 결제 행에 에스크로 표시를 남긴다.
     *
     * @scenario case=vbank_escrow_on
     *
     * @effects escrow_payment_flagged
     *
     * @return void 없음
     */
    public function test_vbank_issue_with_escrow_on_flags_payment(): void
    {
        $order = $this->createOrder();
        $this->settings(true);
        $this->fakeApproval($order, ['payMethod' => 'VBank', 'VACT_Num' => '56211992479255', 'vactBankName' => '신한(통합)은행']);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $this->callbackParams($order))
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment->fresh();
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
        $this->assertTrue((bool) $payment->is_escrow);
    }

    /**
     * 에스크로를 켠 상점의 즉시 결제(카드) 승인도 에스크로 표시를 남긴다.
     *
     * @scenario case=card_escrow_on
     *
     * @effects escrow_payment_flagged
     *
     * @return void 없음
     */
    public function test_card_approval_with_escrow_on_flags_payment(): void
    {
        $order = $this->createOrder();
        $this->settings(true);
        $this->fakeApproval($order);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $this->callbackParams($order))
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertTrue((bool) $order->payment->fresh()->is_escrow);
    }

    /**
     * 에스크로를 끈 상점의 결제는 에스크로 표시를 남기지 않는다.
     *
     * @scenario case=escrow_off
     *
     * @effects non_escrow_payment_not_flagged
     *
     * @return void 없음
     */
    public function test_escrow_off_does_not_flag_payment(): void
    {
        $order = $this->createOrder();
        $this->settings(false);
        $this->fakeApproval($order);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $this->callbackParams($order))
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertFalse((bool) $order->payment->fresh()->is_escrow);
    }

    /**
     * PC 간편결제는 결제창이 useescrow 를 그대로 두고 에스크로 MID 로 결제하므로 에스크로 표시를 남긴다.
     *
     * 표시가 없으면 관리자 결제 조회·취소가 표준 테스트 MID 자격증명으로 바뀌어 에스크로 MID 거래와 어긋난다.
     *
     * @scenario case=pc_easy_pay
     *
     * @effects escrow_payment_flagged
     *
     * @return void 없음
     */
    public function test_pc_easy_pay_with_escrow_on_flags_payment(): void
    {
        $order = $this->createOrder();
        $this->settings(true);
        $this->fakeApproval($order);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback?selectedPaymentMethod=kginicis_naverpay', $this->callbackParams($order))
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertTrue((bool) $order->payment->fresh()->is_escrow);
    }

    /**
     * 모바일 간편결제는 결제창이 useescrow 를 빼고 부르므로 에스크로 표시를 남기지 않는다.
     *
     * @scenario case=mobile_easy_pay
     *
     * @effects non_escrow_payment_not_flagged
     *
     * @return void 없음
     */
    public function test_mobile_easy_pay_does_not_flag_payment(): void
    {
        $order = $this->createOrder();
        $this->settings(true);
        $tid = 'MOBILE_TID_'.uniqid();

        Http::fake([
            'fcmobile.inicis.com/smart/payReq.ini' => Http::response(http_build_query([
                'P_STATUS' => '00',
                'P_RMESG1' => '성공',
                'P_TID' => $tid,
                'P_OID' => $order->order_number,
                'P_AMT' => '50000',
                'P_TYPE' => 'CARD',
                'P_AUTH_DT' => now()->format('YmdHis'),
                'P_APPL_NUM' => 'MAPP124',
                'P_CARD_QUOTA' => '00',
            ]), 200),
        ]);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/callback?'.http_build_query(['orderId' => $order->order_number, 'selectedPaymentMethod' => 'kginicis_naverpay']), [
            'P_STATUS' => '00',
            'P_TID' => $tid,
            'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
            'P_AMT' => '50000',
            'P_OID' => $order->order_number,
            'idc_name' => 'fc',
        ])->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertFalse((bool) $order->payment->fresh()->is_escrow);
    }

    /**
     * 모바일 승인도 에스크로를 켠 상점이면 에스크로 표시를 남긴다.
     *
     * @scenario case=mobile_escrow_on
     *
     * @effects escrow_payment_flagged
     *
     * @return void 없음
     */
    public function test_mobile_approval_with_escrow_on_flags_payment(): void
    {
        $order = $this->createOrder();
        $this->settings(true);
        $tid = 'MOBILE_TID_'.uniqid();

        Http::fake([
            'fcmobile.inicis.com/smart/payReq.ini' => Http::response(http_build_query([
                'P_STATUS' => '00',
                'P_RMESG1' => '성공',
                'P_TID' => $tid,
                'P_OID' => $order->order_number,
                'P_AMT' => '50000',
                'P_TYPE' => 'CARD',
                'P_AUTH_DT' => now()->format('YmdHis'),
                'P_APPL_NUM' => 'MAPP123',
                'P_CARD_QUOTA' => '00',
            ]), 200),
        ]);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/callback?'.http_build_query(['orderId' => $order->order_number]), [
            'P_STATUS' => '00',
            'P_TID' => $tid,
            'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
            'P_AMT' => '50000',
            'P_OID' => $order->order_number,
            'idc_name' => 'fc',
        ])->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertTrue((bool) $order->payment->fresh()->is_escrow);
    }
}
