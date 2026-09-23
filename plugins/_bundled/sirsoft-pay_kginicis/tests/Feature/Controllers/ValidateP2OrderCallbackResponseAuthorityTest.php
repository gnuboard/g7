<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * KG 이니시스 **주문** 결제 콜백이 승인 금액·결제수단을 PG 승인 응답으로 판정하는지 (#599 validate P2)
 *
 * 클레임 경로와 같은 규칙을 주문 경로에도 적용한다.
 * - PC 표준결제: 콜백 `TotPrice` 를 승인 응답보다 먼저 썼다 — 결제창 금액을 바꾼 요청이 청구액을 적어
 *   보내면 대조가 항등식이 되어 통과했다.
 * - 모바일: 승인 응답에 `P_AMT` 가 없으면 콜백 `P_AMT` 로 채웠다(같은 항등식).
 * - CBT(엔화): 편의점(CVS) 판정이 응답에 결제수단이 비면 콜백 paymethod 에 기댔다 — 콜백이 CARD 라고
 *   말하면 입금 전 편의점 결제번호 발급이 결제완료로 확정됐다.
 */
class ValidateP2OrderCallbackResponseAuthorityTest extends PluginTestCase
{
    /**
     * 테스트 DB 를 다시 만들지 않고 트랜잭션으로만 격리합니다. (DatabaseTransactions 와 같은 격리 —
     * 공유 테스트 DB 를 migrate:fresh 로 지우지 않는다)
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 플러그인 설정을 테스트 모드 고정값으로 대체합니다.
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
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ]);

        $this->app->instance(PluginSettingsService::class, $settings);
    }

    /**
     * 결제 대기 주문을 만듭니다. (카드 결제 준비 상태)
     *
     * @param  string  $currency  주문 통화 (KRW / JPY)
     * @param  int  $amount  결제 예정액
     * @return Order 주문
     */
    private function createPendingOrder(string $currency, int $amount): Order
    {
        $snapshot = $currency === 'JPY' ? self::jpyCurrencySnapshot() : self::krwCurrencySnapshot();

        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-P2RESP-'.random_int(100000, 999999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => $currency,
            'currency_snapshot' => $snapshot,
            'subtotal_amount' => $amount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $amount,
            'total_due_amount' => $amount,
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
            'card_approval_number' => null,
            'currency' => $currency,
        ]);

        return $order->fresh('payment');
    }

    /**
     * PC 표준결제 인증 콜백 파라미터를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  int  $callbackTotPrice  브라우저 콜백이 실어 보낸 금액
     * @return array 콜백 파라미터
     */
    private function pcCallback(Order $order, int $callbackTotPrice): array
    {
        return [
            'resultCode' => '0000',
            'resultMsg' => '성공',
            'authToken' => 'AUTH_TOKEN_'.uniqid(),
            'authUrl' => 'https://fcstdpay.inicis.com/api/payAuth',
            'idc_name' => 'fc',
            'netCancelUrl' => 'https://fcstdpay.inicis.com/api/netCancel',
            'MOID' => $order->order_number,
            'TotPrice' => $callbackTotPrice,
        ];
    }

    /**
     * 주문이 결제완료로 확정되지 않았는지 단언합니다.
     *
     * @param  Order  $order  주문
     * @return void 반환값 없음
     */
    private function assertNotPaid(Order $order): void
    {
        $order->refresh();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertNotEquals(PaymentStatusEnum::PAID, $order->payment()->first()->payment_status);
    }

    /**
     * PC 승인 응답 금액이 청구액과 다르면 콜백 금액이 청구액과 같아도 망취소하고 실패로 돌려보낸다.
     *
     * 수정 전: 콜백 `TotPrice`(50000)를 먼저 써서 청구액과 대조가 통과하고, 응답이 10000 원을 승인했는데도
     * 주문이 결제완료가 됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=order_path_response_authority
     *
     * @effects inicis_order_pc_amount_comes_only_from_the_response
     */
    public function test_pc_order_callback_trusts_the_response_amount_over_the_callback(): void
    {
        $order = $this->createPendingOrder('KRW', 50000);
        $this->mockPluginSettings();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response([
                'resultCode' => '0000', 'tid' => 'TID-P2-PC-1', 'MOID' => $order->order_number,
                'TotPrice' => '10000', 'payMethod' => 'Card', 'applNum' => 'APP1',
            ], 200),
            '*' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $this->pcCallback($order, 50000));

        $this->assertStringContainsString('error=amount_mismatch', (string) $response->headers->get('Location'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'netCancel'));
        $this->assertNotPaid($order);
    }

    /**
     * PC 승인 응답에 금액이 없으면 콜백 금액으로 채우지 않고 망취소한 뒤 실패로 돌려보낸다.
     *
     * 수정 전: 콜백 `TotPrice` 가 그대로 승인액이 되어 결제완료로 확정됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=order_path_response_authority
     *
     * @effects inicis_order_pc_amount_comes_only_from_the_response
     */
    public function test_pc_order_callback_without_a_response_amount_is_net_cancelled(): void
    {
        $order = $this->createPendingOrder('KRW', 50000);
        $this->mockPluginSettings();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response([
                'resultCode' => '0000', 'tid' => 'TID-P2-PC-2', 'MOID' => $order->order_number,
                'payMethod' => 'Card', 'applNum' => 'APP2',
            ], 200),
            '*' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $this->pcCallback($order, 50000));

        $this->assertStringContainsString('error=amount_mismatch', (string) $response->headers->get('Location'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'netCancel'));
        $this->assertNotPaid($order);
    }

    /**
     * 모바일 승인 응답에 `P_AMT` 가 없으면 콜백 `P_AMT` 로 채우지 않고 취소한 뒤 실패로 돌려보낸다.
     *
     * 수정 전: `응답 P_AMT ?? 콜백 P_AMT` 로 채워 결제완료로 확정됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=order_path_response_authority
     *
     * @effects inicis_order_mobile_amount_comes_only_from_the_response
     */
    public function test_mobile_order_callback_without_a_response_amount_is_cancelled(): void
    {
        $order = $this->createPendingOrder('KRW', 50000);
        $this->mockPluginSettings();

        Http::fake([
            'fcmobile.inicis.com/smart/payReq.ini' => Http::response(http_build_query([
                'P_STATUS' => '00', 'P_TID' => 'MTID-P2-1', 'P_OID' => $order->order_number, 'P_TYPE' => 'CARD',
            ]), 200),
            '*' => Http::response(['resultCode' => '00'], 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/callback?'.http_build_query([
            'orderId' => $order->order_number,
        ]), [
            'P_STATUS' => '00',
            'P_TID' => 'MTID-P2-1',
            'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
            'P_AMT' => '50000',
            'P_OID' => $order->order_number,
            'idc_name' => 'fc',
        ]);

        $this->assertStringContainsString('error=amount_mismatch', (string) $response->headers->get('Location'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v2/pg/refund'));
        $this->assertNotPaid($order);
    }

    /**
     * CBT 승인 응답에 결제수단이 없어도 편의점(CVS) 발급 필드가 있으면 CVS 로 판정한다 — 콜백이 CARD 라고
     * 말해도 결제완료로 확정하지 않는다.
     *
     * 수정 전: 결제수단을 콜백 paymethod(CARD)로 채워 `completePayment()` 가 불렸다(입금 전 결제완료).
     *
     * @return void 반환값 없음
     *
     * @scenario concern=order_path_response_authority
     *
     * @effects inicis_order_cbt_cvs_is_judged_by_the_response
     */
    public function test_cbt_order_cvs_issue_hidden_by_the_callback_is_not_completed(): void
    {
        $order = $this->createPendingOrder('JPY', 100);
        $completed = false;

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')->with($order->order_number)->andReturn($order);
        $orderService->shouldReceive('completePayment')->andReturnUsing(function () use (&$completed, $order) {
            $completed = true;

            return $order;
        });

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('refundCbtPayment')->andReturn(['resultCode' => '00']);
        $apiService->shouldReceive('approveCbtPayment')->with('SID-P2-CVS')->andReturn([
            'resultCode' => 'OK',
            'tid' => 'CBT-P2-CVS-TID',
            'amount' => 100,
            'convenience' => '00007',
            'confNo' => '999999999999999999',
            'receiptNo' => '1634795292905',
            'paymentTerm' => '20260930235959',
        ]);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'.http_build_query([
            'oid' => $order->order_number,
            'sid' => 'SID-P2-CVS',
            'resultCode' => 'OK',
            'mid' => KgInicisApiService::JAPAN_TEST_MID,
            'paymethod' => 'CARD',
        ]));

        $this->assertFalse($completed, '응답이 편의점 발급인데 결제완료로 확정했다 — 콜백 paymethod 를 믿었다.');
    }
}
