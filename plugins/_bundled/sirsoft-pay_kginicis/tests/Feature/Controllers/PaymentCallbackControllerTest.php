<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class PaymentCallbackControllerTest extends PluginTestCase
{
    private const TEST_MID = 'INIpayTest';

    private const TEST_SIGN_KEY = 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS';

    /**
     * 애플리케이션 로그를 spy 로 감시하되 `activity` 채널은 실제 로거로 유지합니다.
     *
     * `Log::spy()` 단독 사용 시 `Log::channel('activity')` 가 null 을 반환하고,
     * 결제 완료 훅이 태우는 활동 로그가 null 에 `info()` 를 호출해 \Error 로 죽는다.
     * (`ResolvesActivityLogType::logActivity` 의 catch 는 \Exception 만 잡아 통과시킴)
     *
     * @return void 반환값 없음
     */
    private function spyApplicationLogKeepingActivityChannel(): void
    {
        $activityChannel = Log::channel('activity');

        Log::spy();
        Log::shouldReceive('channel')->with('activity')->andReturn($activityChannel);
    }

    /**
     * PG 서버 승인 응답을 흉내 낸 배열을 만듭니다.
     *
     * @param  string  $tid  거래번호
     * @param  string  $moid  주문번호
     * @param  int  $amount  금액
     * @param  string  $resultCode  PG 결과 코드
     * @param  array  $overrides  기본값 대신 쓸 항목
     * @return array 구성한 배열
     */
    private function makeAuthorizeResponse(string $tid, string $moid, int $amount, string $resultCode = '0000', array $overrides = []): array
    {
        return array_merge([
            'resultCode' => $resultCode,
            'resultMsg' => '성공',
            'tid' => $tid,
            'MOID' => $moid,
            'TotPrice' => (string) $amount,
            'payMethod' => 'Card',
            'applNum' => 'APP12345',
            'cardNum' => '4330-****-****-1234',
            'cardName' => '신한카드',
            'cardQuota' => '00',
            'applDate' => now()->format('YmdHis'),
            'buyerName' => '홍길동',
            'buyerEmail' => 'buyer@example.com',
            'buyerTel' => '01012345678',
        ], $overrides);
    }

    /**
     * 결제대기 주문과 결제 레코드를 만듭니다.
     *
     * @param  int  $totalAmount  주문 총액
     * @return Order 생성한 주문
     */
    private function createTestOrder(int $totalAmount = 50000): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => $totalAmount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'total_due_amount' => $totalAmount,
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
        ]);

        return $order;
    }

    /**
     * 플러그인 설정 서비스를 테스트 설정으로 대체합니다.
     *
     * @param  array  $overrides  기본값 대신 쓸 항목
     * @return void 반환값 없음
     */
    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_sign_key' => self::TEST_SIGN_KEY,
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
            'live_mid' => '',
            'live_sign_key' => '',
            'live_iniapi_key' => '',
            'live_iniapi_iv' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];

        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $overrides));

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }

    /**
     * 브라우저가 보내는 결제 인증 콜백 파라미터를 만듭니다.
     *
     * @param  string  $moid  주문번호
     * @param  int  $totPrice  결제 금액
     * @param  array  $overrides  기본값 대신 쓸 항목
     * @return array 구성한 배열
     */
    private function makeCallbackParams(string $moid, int $totPrice, array $overrides = []): array
    {
        return array_merge([
            'resultCode' => '0000',
            'resultMsg' => '성공',
            'authToken' => 'AUTH_TOKEN_'.uniqid(),
            'authUrl' => 'https://fcstdpay.inicis.com/api/payAuth',
            // idc_name 은 KG 이니시스가 success 콜백에 항상 포함시키는 IDC 식별자.
            // 컨트롤러가 isValidIdcAuthUrl 화이트리스트 검증에 사용 (SSRF 방어).
            'idc_name' => 'fc',
            'netCancelUrl' => 'https://stginiapi.inicis.com/api/v1/netcancel',
            'MOID' => $moid,
            'TotPrice' => $totPrice,
        ], $overrides);
    }

    // ===== 성공 콜백 테스트 =====

    /**
     * 정상 승인은 주문을 결제완료로 만들고 완료 화면으로 보낸다.
     *
     * @effects auth_callback_redirects_to_complete_page_on_valid_payment
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_redirects_to_complete_page_on_valid_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_'.uniqid();
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tid, $payment->transaction_id);
        $this->assertEquals('APP12345', $payment->card_approval_number);

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame($tid, $meta['pg_raw_response']['tid'] ?? null);
        $this->assertArrayNotHasKey('cardNum', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('buyerName', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('buyerEmail', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('buyerTel', $meta['pg_raw_response']);
    }

    /**
     * 고른 간편결제 수단을 결제 메타에 남긴다.
     *
     * @effects auth_callback_stores_selected_easy_pay_context
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_stores_selected_easy_pay_context(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_EASYPAY_'.uniqid();
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_kginicis/payment/callback?selectedPaymentMethod=kginicis_naverpay',
            $params,
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();

        $this->assertSame('naverpay', $payment->embedded_pg_provider);

        $meta = $payment->payment_meta;
        $this->assertSame('kginicis_naverpay', $meta['selected_payment_method'] ?? null);
        $this->assertSame('naverpay', $meta['embedded_pg_provider'] ?? null);
        $this->assertSame('네이버페이', $meta['embedded_pg_provider_label'] ?? null);
    }

    /**
     * 사용자 취소 코드는 오류 정보 없이 체크아웃으로 조용히 돌려보낸다.
     *
     * @effects auth_callback_user_cancel_2001_redirects_without_error_query
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_user_cancel_2001_redirects_without_error_query(): void
    {
        // 사용자가 결제창을 X 또는 '취소' 버튼으로 종료한 경우 — 에러 토스트 노출 X.
        // NHN KCP 의 CANCEL_RES_CODES 패턴과 동일하게 조용히 체크아웃으로 복귀.
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'resultCode' => '2001',
            'resultMsg' => '사용자가 취소를 요청하였습니다.',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('error=', $location, 'user cancel must not append error query');
        $this->assertStringNotContainsString('message=', $location, 'user cancel must not append message query');
    }

    /**
     * 취소 문구가 담긴 결과는 사용자 취소로 보고 오류 정보 없이 돌려보낸다.
     *
     * @effects auth_callback_user_cancel_message_keyword_redirects_without_error_query
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_user_cancel_message_keyword_redirects_without_error_query(): void
    {
        // resultCode 가 표준 cancel 코드가 아니지만 resultMsg 에 '취소' 키워드가
        // 포함된 경우도 사용자 취소로 간주 (KG 이니시스 버전별 코드 차이 대응).
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'resultCode' => '9998',
            'resultMsg' => '사용자가 결제를 취소하였습니다.',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));
    }

    /**
     * 사용자 취소가 아닌 실제 실패는 오류 정보를 실어 실패 화면으로 보낸다.
     *
     * @effects auth_callback_real_failure_still_redirects_with_error_query
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_real_failure_still_redirects_with_error_query(): void
    {
        // 사용자 취소가 아닌 실제 결제 실패는 기존대로 error query 포함하여
        // 사용자에게 에러 토스트 노출.
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'resultCode' => '9999',
            'resultMsg' => 'PG 시스템 오류',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=9999', $response->headers->get('Location'));
    }

    /**
     * 존재하지 않는 주문번호의 콜백은 실패 화면으로 보낸다.
     *
     * @effects auth_callback_redirects_to_fail_on_order_not_found
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_redirects_to_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('NON_EXISTENT_ORDER', 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_NONE', 'NON_EXISTENT_ORDER', 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_found', $response->headers->get('Location'));
    }

    /**
     * PG 승인 결과가 성공이 아니면 주문 상태를 바꾸지 않고 실패 화면으로 보낸다.
     *
     * @effects auth_callback_redirects_to_fail_on_pg_result_code_not_0000
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_redirects_to_fail_on_pg_result_code_not_0000(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response([
                'resultCode' => '9999',
                'resultMsg' => '승인 실패',
            ], 200),
        ]);

        $statusBefore = $order->order_status;

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=9999', $response->headers->get('Location'));

        // 계약 변경(KVE-2026-2018 형제): 승인이 성립하지 않은 비인증 브라우저 콜백은
        // 주문 상태를 바꾸지 않는다. 정당한 결제창 닫힘은 close-report 가 기록한다.
        $order->refresh();
        $this->assertEquals($statusBefore, $order->order_status, '비인증 콜백이 주문 상태를 바꿨습니다.');
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 무인증 위조 콜백으로 타인의 결제대기 주문을 취소할 수 없다.
     *
     * 공격자는 로그인하지 않고 피해자의 주문번호(moid)와 위조 authToken 만으로 이 엔드포인트를
     * 친다. 서버 승인은 당연히 실패하는데, 그 실패를 주문 실패로 오인 처리하면 남의 주문이 취소된다.
     *
     * @effects forged_unauthenticated_callback_cannot_cancel_another_users_order
     *
     * @return void 반환값 없음
     */
    public function test_forged_unauthenticated_callback_cannot_cancel_another_users_order(): void
    {
        $victimOrder = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($victimOrder->order_number, 50000);
        $params['authToken'] = 'FORGED_AUTH_TOKEN';

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response([
                'resultCode' => '9999',
                'resultMsg' => '인증정보가 올바르지 않습니다',
            ], 200),
            '*' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();

        $victimOrder->refresh();
        $this->assertEquals(
            OrderStatusEnum::PENDING_ORDER,
            $victimOrder->order_status,
            '무인증 위조 콜백이 피해자의 주문을 취소시켰습니다.'
        );
        $this->assertArrayNotHasKey('payment_failure_code', $victimOrder->order_meta ?? []);
    }

    /**
     * 승인 API 가 HTTP 오류면 망취소하고 실패 화면으로 보낸다.
     *
     * @effects auth_callback_sends_net_cancel_and_redirects_to_fail_on_authorize_http_error
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_sends_net_cancel_and_redirects_to_fail_on_authorize_http_error(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(null, 500),
            'stginiapi.inicis.com/api/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'));
    }

    /**
     * 예외 전에 이미 결제완료가 기록됐으면 망취소하지 않고 완료 화면으로 보낸다.
     *
     * @effects auth_callback_skips_net_cancel_when_local_payment_was_already_completed_before_exception
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_skips_net_cancel_when_local_payment_was_already_completed_before_exception(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_POST_COMMIT_'.uniqid();
        $params = $this->makeCallbackParams($order->order_number, 50000);

        $orderService = $this->createMock(OrderProcessingService::class);
        $orderService->method('findByOrderNumber')
            ->with($order->order_number)
            ->willReturn($order);
        $orderService->expects($this->once())
            ->method('completePayment')
            ->willReturnCallback(function (Order $callbackOrder, array $paymentData, ?int $paidAmount) use ($tid): void {
                DB::transaction(function () use ($callbackOrder, $paymentData, $paidAmount, $tid): void {
                    $callbackOrder->forceFill([
                        'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                        'total_paid_amount' => $paidAmount,
                        'total_due_amount' => 0,
                        'paid_at' => now(),
                    ])->save();

                    $callbackOrder->payment()->update([
                        'payment_status' => PaymentStatusEnum::PAID,
                        'transaction_id' => $tid,
                        'card_approval_number' => $paymentData['card_approval_number'] ?? null,
                        'payment_meta' => $paymentData['payment_meta'] ?? [],
                        'paid_amount_local' => $paidAmount,
                        'paid_at' => now(),
                    ]);
                });

                throw new \RuntimeException('post commit hook failed');
            });
        $this->app->instance(OrderProcessingService::class, $orderService);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
            'stginiapi.inicis.com/api/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        Http::assertNotSent(static fn ($request): bool => str_contains((string) $request->url(), 'netcancel'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment->fresh();
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame($tid, $payment->transaction_id);
    }

    /**
     * 필수 파라미터가 빠진 콜백은 실패 화면으로 보낸다.
     *
     * @effects auth_callback_redirects_to_fail_on_missing_params
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_redirects_to_fail_on_missing_params(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', [
            'resultCode' => '0000',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

    /**
     * 운영자가 지정한 성공 주소로 이동한다.
     *
     * @effects auth_callback_redirects_to_custom_success_url
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_redirects_to_custom_success_url(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings(['redirect_success_url' => '/custom/payment/{orderId}/done']);

        $tid = 'TID_CUSTOM';
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect("/custom/payment/{$order->order_number}/done");
    }

    /**
     * 모바일 브라우저에서 들어온 콜백은 결제 기기를 모바일로 기록한다.
     *
     * @effects auth_callback_detects_mobile_device
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_detects_mobile_device(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_MOBILE', $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_kginicis/payment/callback',
            $params,
            ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)']
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('mobile', $payment->payment_device);
    }

    // ===== 가상계좌 입금 통보 테스트 =====

    /**
     * 정상 입금통보는 주문을 결제완료로 만들고 정상 응답한다.
     *
     * @effects vbank_notify_returns_ok_on_successful_deposit
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_returns_ok_on_successful_deposit(): void
    {
        $order = $this->createTestOrder(30000);
        $this->markVbankIssued($order, 'VBANK_TID_SUCCESS', '1234567890');
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $this->makeVbankNotifyPayload([
            'no_tid' => 'VBANK_TID_SUCCESS',
            'no_oid' => $order->order_number,
            'amt_input' => '30000',
        ]));

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame('1234567890', $meta['vbank_num'] ?? null);
        $this->assertSame('홍길동', $meta['depositor_name'] ?? null);
        $this->assertArrayHasKey('no_tid', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('no_vacct', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('nm_input', $meta['pg_raw_response']);
    }

    /**
     * 발급 기록이 없거나 계좌 정보가 다른 입금통보는 거부한다.
     *
     * @effects vbank_notify_rejects_unissued_or_mismatched_account_context
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_rejects_unissued_or_mismatched_account_context(): void
    {
        $order = $this->createTestOrder(30000);
        $this->markVbankIssued($order, 'VBANK_TID_ISSUED', '1234567890');
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $this->makeVbankNotifyPayload([
            'no_tid' => 'VBANK_TID_ATTACKER',
            'no_oid' => $order->order_number,
            'no_vacct' => '9999999999',
            'amt_input' => '30000',
        ]));

        $response->assertOk();
        $this->assertSame('FAIL', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $order->payment->fresh()->payment_status);
    }

    /**
     * 가상계좌 입금통보는 입금자명을 애플리케이션 로그에 남기지 않는다.
     *
     * @effects vbank_notify_does_not_write_depositor_name_to_application_log
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_does_not_write_depositor_name_to_application_log(): void
    {
        $order = $this->createTestOrder(30000);
        $this->markVbankIssued($order, 'VBANK_TID_LOG', '1234567890');
        $this->mockPluginSettings();

        $this->spyApplicationLogKeepingActivityChannel();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $this->makeVbankNotifyPayload([
            'no_tid' => 'VBANK_TID_LOG',
            'no_oid' => $order->order_number,
            'amt_input' => '30000',
            'nm_input' => '홍길동',
        ]));

        $response->assertOk();

        Log::shouldHaveReceived('info')
            ->with('KG Inicis: PC vbank deposit notify received', \Mockery::on(function (array $context): bool {
                return ! array_key_exists('depositor', $context);
            }))
            ->once();
    }

    /**
     * 모바일 승인 응답은 개인정보를 뺀 허용 필드만 저장한다.
     *
     * @effects mobile_callback_stores_sanitized_pg_response_only
     *
     * @return void 반환값 없음
     */
    public function test_mobile_callback_stores_sanitized_pg_response_only(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

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
                'P_CARD_NUM' => '4330-****-****-1234',
                'P_CARD_ISSUER_NAME' => '신한카드',
                'P_CARD_QUOTA' => '00',
                'P_UNAME' => '홍길동',
                'P_EMAIL' => 'buyer@example.com',
                'P_MOBILE' => '01012345678',
            ]), 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/callback?'.http_build_query([
            'orderId' => $order->order_number,
            'selectedPaymentMethod' => 'kginicis_naverpay',
        ]), [
            'P_STATUS' => '00',
            'P_TID' => $tid,
            'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
            'P_AMT' => '50000',
            'P_OID' => $order->order_number,
            'idc_name' => 'fc',
        ]);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals($tid, $payment->transaction_id);
        $this->assertEquals('MAPP123', $payment->card_approval_number);
        $this->assertEquals('4330-****-****-1234', $payment->card_number_masked);
        $this->assertSame('naverpay', $payment->embedded_pg_provider);

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame('kginicis_naverpay', $meta['selected_payment_method'] ?? null);
        $this->assertSame('naverpay', $meta['embedded_pg_provider'] ?? null);
        $this->assertSame('네이버페이', $meta['embedded_pg_provider_label'] ?? null);
        $this->assertSame($tid, $meta['pg_raw_response']['P_TID'] ?? null);
        $this->assertArrayNotHasKey('P_CARD_NUM', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('P_UNAME', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('P_EMAIL', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('P_MOBILE', $meta['pg_raw_response']);
    }

    /**
     * 예외 전에 이미 결제완료가 기록됐으면 모바일 자동취소를 건너뛴다.
     *
     * @effects mobile_callback_skips_auto_cancel_when_local_payment_was_already_completed_before_exception
     *
     * @return void 반환값 없음
     */
    public function test_mobile_callback_skips_auto_cancel_when_local_payment_was_already_completed_before_exception(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'MOBILE_TID_POST_COMMIT_'.uniqid();

        $orderService = $this->createMock(OrderProcessingService::class);
        $orderService->method('findByOrderNumber')
            ->with($order->order_number)
            ->willReturn($order);
        $orderService->expects($this->once())
            ->method('completePayment')
            ->willReturnCallback(function (Order $callbackOrder, array $paymentData, ?int $paidAmount) use ($tid): void {
                DB::transaction(function () use ($callbackOrder, $paymentData, $paidAmount, $tid): void {
                    $callbackOrder->forceFill([
                        'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                        'total_paid_amount' => $paidAmount,
                        'total_due_amount' => 0,
                        'paid_at' => now(),
                    ])->save();

                    $callbackOrder->payment()->update([
                        'payment_status' => PaymentStatusEnum::PAID,
                        'transaction_id' => $tid,
                        'card_approval_number' => $paymentData['card_approval_number'] ?? null,
                        'payment_meta' => $paymentData['payment_meta'] ?? [],
                        'paid_amount_local' => $paidAmount,
                        'paid_at' => now(),
                    ]);
                });

                throw new \RuntimeException('mobile post commit hook failed');
            });
        $this->app->instance(OrderProcessingService::class, $orderService);

        Http::fake([
            'fcmobile.inicis.com/smart/payReq.ini' => Http::response(http_build_query([
                'P_STATUS' => '00',
                'P_RMESG1' => '성공',
                'P_TID' => $tid,
                'P_OID' => $order->order_number,
                'P_AMT' => '50000',
                'P_TYPE' => 'CARD',
                'P_AUTH_DT' => now()->format('YmdHis'),
                'P_APPL_NUM' => 'MAPP999',
            ]), 200),
            'stginiapi.inicis.com/api/v1/refund' => Http::response(['resultCode' => '00'], 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/callback', [
            'P_STATUS' => '00',
            'P_TID' => $tid,
            'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
            'P_AMT' => '50000',
            'P_OID' => $order->order_number,
            'idc_name' => 'fc',
        ]);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        Http::assertNotSent(static fn ($request): bool => str_contains((string) $request->url(), '/v2/pg/refund'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment->fresh();
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame($tid, $payment->transaction_id);
    }

    /**
     * 발급 기록이 없거나 계좌 정보가 다른 모바일 입금통보는 거부한다.
     *
     * @effects mobile_vbank_notify_rejects_unissued_or_mismatched_account_context
     *
     * @return void 반환값 없음
     */
    public function test_mobile_vbank_notify_rejects_unissued_or_mismatched_account_context(): void
    {
        $order = $this->createTestOrder(30000);
        $this->markVbankIssued($order, 'MOBILE_VBANK_TID_ISSUED', '1234567890');
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify', [
            'P_STATUS' => '02',
            'P_TYPE' => 'VBANK',
            'P_TID' => 'MOBILE_VBANK_TID_ATTACKER',
            'P_MID' => self::TEST_MID,
            'P_OID' => $order->order_number,
            'P_AMT' => '30000',
            'P_AUTH_DT' => now()->format('YmdHis'),
            'P_FN_CD1' => '04',
            'P_FN_NM' => '국민은행',
            'P_RMESG1' => '9999999999|20260630235959',
            'P_UNAME' => '홍길동',
        ]);

        $response->assertOk();
        $this->assertSame('FAIL', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $order->payment->fresh()->payment_status);
    }

    /**
     * 모바일 가상계좌 입금통보는 입금자명을 애플리케이션 로그에 남기지 않는다.
     *
     * @effects mobile_vbank_notify_does_not_write_depositor_name_to_application_log
     *
     * @return void 반환값 없음
     */
    public function test_mobile_vbank_notify_does_not_write_depositor_name_to_application_log(): void
    {
        $order = $this->createTestOrder(30000);
        $this->markVbankIssued($order, 'MOBILE_VBANK_TID_LOG', '1234567890');
        $this->mockPluginSettings();

        $this->spyApplicationLogKeepingActivityChannel();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify', [
            'P_STATUS' => '02',
            'P_TYPE' => 'VBANK',
            'P_TID' => 'MOBILE_VBANK_TID_LOG',
            'P_MID' => self::TEST_MID,
            'P_OID' => $order->order_number,
            'P_AMT' => '30000',
            'P_AUTH_DT' => now()->format('YmdHis'),
            'P_FN_CD1' => '04',
            'P_FN_NM' => '국민은행',
            'P_RMESG1' => '1234567890|20260630235959',
            'P_UNAME' => '홍길동',
        ]);

        $response->assertOk();

        Log::shouldHaveReceived('info')
            ->with('KG Inicis: mobile vbank deposit notify received', \Mockery::on(function (array $context): bool {
                return ! array_key_exists('depositor', $context);
            }))
            ->once();
    }

    /**
     * 동일 TID 가 두 번 통보되어도 중복 결제 처리하지 않고 OK 반환 (replay 방어).
     *
     * @effects vbank_notify_is_idempotent_on_duplicate_tid
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_is_idempotent_on_duplicate_tid(): void
    {
        $order = $this->createTestOrder(30000);
        $this->markVbankIssued($order, 'VBANK_TID_DUP', '1234567890');
        $this->mockPluginSettings();

        $payload = $this->makeVbankNotifyPayload([
            'no_tid' => 'VBANK_TID_DUP',
            'no_oid' => $order->order_number,
            'amt_input' => '30000',
        ]);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $payload)->assertOk();
        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $payload);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent(), '재처리 요청도 OK 반환 (replay 방어)');
    }

    /**
     * 존재하지 않는 주문의 입금통보에는 실패를 응답한다.
     *
     * @effects vbank_notify_returns_fail_on_order_not_found
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_returns_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $this->makeVbankNotifyPayload([
            'no_oid' => 'NON_EXISTENT_ORDER',
        ]));

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());
    }

    /**
     * KG 이니시스 PC 가상계좌 입금통보 페이로드 빌더.
     *
     * 공식 매뉴얼 https://manual.inicis.com/pay/etc-noti.html#pc 의 필드명을 사용한다.
     * 테스트마다 일부 필드만 override 하기 위한 헬퍼.
     *
     * @param  array  $overrides  필드 override
     * @return array 입금통보 페이로드
     */
    // ===== 승인-주문 결속 (KVE-2026-2434 전수조사) =====

    /**
     * 싼 주문의 승인 토큰에 비싼 주문번호와 그 금액을 붙여 보내도 비싼 주문은 결제완료되지 않는다.
     *
     * 종전에는 콜백의 MOID·TotPrice(브라우저 값)만 보고 승인 응답의 주문번호·금액을 대조하지 않아,
     * 1,000원 승인으로 50,000원 주문이 결제완료됐다. 승인은 망취소한다.
     *
     * @effects auth_callback_rejects_approval_issued_for_another_order
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_rejects_approval_issued_for_another_order(): void
    {
        $victim = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_CHEAP_'.uniqid(), 'ORD-ATTACKER-CHEAP', 1000),
                200
            ),
            '*' => Http::response(['resultCode' => '0000'], 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback',
            $this->makeCallbackParams($victim->order_number, 50000));

        $response->assertRedirect();
        $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'));

        $victim->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $victim->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $victim->payment->payment_status);
        $this->assertNull($victim->payment->transaction_id);
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'payAuth'));
    }

    /**
     * 승인 응답에 주문번호가 없어도 금액은 PG 가 승인한 값이 기준이다 — 브라우저 금액으로 부풀려도
     * 주문은 결제완료되지 않는다.
     *
     * @effects auth_callback_uses_pg_approved_amount_not_browser_amount
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_uses_pg_approved_amount_not_browser_amount(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_LOW_'.uniqid(), $order->order_number, 1000, '0000', ['MOID' => null]),
                200
            ),
            '*' => Http::response(['resultCode' => '0000'], 200),
        ]);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000))->assertRedirect();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertNull($order->payment->transaction_id);
    }

    // ===== 결제 전 주문에만 승인 반영 =====

    /**
     * 주문 처리 서비스를 대체해 주문 조회는 주어진 주문을 돌려주고 결제완료 처리는 한 번도 불리지 않게 합니다.
     *
     * @param  Order  $order  조회 결과로 돌려줄 주문
     * @return void 반환값 없음
     */
    private function mockOrderServiceExpectingNoCompletion(Order $order): void
    {
        $orderService = $this->createMock(OrderProcessingService::class);
        $orderService->method('findByOrderNumber')
            ->with($order->order_number)
            ->willReturn($order);
        $orderService->expects($this->never())->method('completePayment');

        $this->app->instance(OrderProcessingService::class, $orderService);
    }

    /**
     * 이미 결제완료된 주문에 새 카드 승인이 도착하면 망취소하고 주문·결제 레코드를 바꾸지 않는다.
     *
     * 종전에는 주문 상태를 보지 않고 결제완료 처리로 넘겨, 결제완료 처리는 조용히 건너뛰어지고 승인된 돈은
     * PG 에 남은 채 결제 레코드의 PG 사 표기만 이 플러그인으로 바뀌었다.
     *
     * @effects auth_callback_net_cancels_approval_for_already_paid_order
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_net_cancels_approval_for_already_paid_order(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::PAID,
            'transaction_id' => 'TID_ORIGINAL_PAID',
            'pg_provider' => 'nhnkcp',
            'paid_at' => now(),
        ]);
        $this->mockPluginSettings();
        $this->mockOrderServiceExpectingNoCompletion($order);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_NEW_'.uniqid(), $order->order_number, 50000),
                200
            ),
            '*' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000));

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_payable', $response->headers->get('Location'));
        Http::assertSent(static fn ($request): bool => str_contains((string) $request->url(), 'netCancel'));

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
        $payment = $order->payment()->first();
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame('TID_ORIGINAL_PAID', $payment->transaction_id);
        $this->assertSame('nhnkcp', $payment->pg_provider);
    }

    /**
     * 취소된 주문에 카드 승인이 도착하면 망취소하고 주문을 결제완료로 되살리지 않는다.
     *
     * @effects auth_callback_net_cancels_approval_for_cancelled_order
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_net_cancels_approval_for_cancelled_order(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update(['order_status' => OrderStatusEnum::CANCELLED]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::CANCELLED,
            'pg_provider' => 'nhnkcp',
        ]);
        $this->mockPluginSettings();
        $this->mockOrderServiceExpectingNoCompletion($order);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_LATE_'.uniqid(), $order->order_number, 50000),
                200
            ),
            '*' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000));

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_payable', $response->headers->get('Location'));
        Http::assertSent(static fn ($request): bool => str_contains((string) $request->url(), 'netCancel'));

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
        $payment = $order->payment()->first();
        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertNull($payment->transaction_id);
        $this->assertSame('nhnkcp', $payment->pg_provider);
    }

    /**
     * 같은 거래번호의 승인 콜백이 다시 도착하면 망취소하지 않고 성공 화면으로 보낸다 (재전송 방어 유지).
     *
     * 결제 전 주문 판정보다 재전송 판정이 먼저여야 한다 — 첫 콜백으로 결제완료된 주문을 "결제 불가" 로 보고
     * 망취소하면 정상 결제가 PG 에서 취소된다.
     *
     * @effects auth_callback_replay_of_same_tid_still_succeeds_without_net_cancel
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_replay_of_same_tid_still_succeeds_without_net_cancel(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $tid = 'TID_REPLAY_'.uniqid();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
            '*' => Http::response('OK', 200),
        ]);

        $params = $this->makeCallbackParams($order->order_number, 50000);
        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params)
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params)
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        Http::assertNotSent(static fn ($request): bool => str_contains((string) $request->url(), 'netCancel'));

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
        $payment = $order->payment()->first();
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame($tid, $payment->transaction_id);
    }

    /**
     * 가상계좌 발급 승인 응답에 금액이 없으면 발급을 기록하지 않고 망취소한다.
     *
     * 종전에는 응답 금액이 없으면 대조 자체를 건너뛰어, 청구액과 무관한 발급이 결제대기 주문에 기록됐다.
     *
     * @effects vbank_issue_callback_rejects_missing_approved_amount
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issue_callback_rejects_missing_approved_amount(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_VBANK_NOAMT_'.uniqid(), $order->order_number, 50000, '0000', [
                    'payMethod' => 'VBank',
                    'TotPrice' => null,
                    'VACT_Num' => '12345678901',
                    'vactBankName' => '국민은행',
                ]),
                200
            ),
            '*' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000));

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_payable', $response->headers->get('Location'));
        Http::assertSent(static fn ($request): bool => str_contains((string) $request->url(), 'netCancel'));

        $payment = $order->payment()->first();
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->transaction_id);
        $this->assertNull($payment->vbank_number);
    }

    /**
     * 가상계좌 발급 승인 금액이 주문 청구액과 다르면 발급을 기록하지 않고 망취소한다.
     *
     * @effects vbank_issue_callback_rejects_mismatched_approved_amount
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issue_callback_rejects_mismatched_approved_amount(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_VBANK_LOW_'.uniqid(), $order->order_number, 1000, '0000', [
                    'payMethod' => 'VBank',
                    'VACT_Num' => '12345678901',
                    'vactBankName' => '국민은행',
                ]),
                200
            ),
            '*' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000));

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_payable', $response->headers->get('Location'));
        Http::assertSent(static fn ($request): bool => str_contains((string) $request->url(), 'netCancel'));

        $payment = $order->payment()->first();
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->transaction_id);
        $this->assertNull($payment->vbank_number);
    }

    /**
     * 모바일 가상계좌 발급 승인 결과에 `P_AMT` 가 없으면 콜백 금액으로 채우지 않고 발급을 거절한 뒤 취소한다.
     *
     * @effects mobile_vbank_issue_callback_rejects_missing_approved_amount
     *
     * @return void 반환값 없음
     */
    public function test_mobile_vbank_issue_callback_rejects_missing_approved_amount(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $tid = 'MOBILE_VBANK_NOAMT_'.uniqid();

        Http::fake([
            'fcmobile.inicis.com/smart/payReq.ini' => Http::response(http_build_query([
                'P_STATUS' => '00',
                'P_RMESG1' => '성공',
                'P_TID' => $tid,
                'P_OID' => $order->order_number,
                'P_TYPE' => 'VBank',
                'P_VACT_NUM' => '12345678901',
                'P_VACT_BANK_NAME' => '국민은행',
            ]), 200),
            '*' => Http::response(['resultCode' => '00'], 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/callback?'.http_build_query([
            'orderId' => $order->order_number,
        ]), [
            'P_STATUS' => '00',
            'P_TID' => $tid,
            'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
            'P_AMT' => '50000',
            'P_OID' => $order->order_number,
            'idc_name' => 'fc',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_payable', $response->headers->get('Location'));
        Http::assertSent(static fn ($request): bool => str_contains((string) $request->url(), '/v2/pg/refund'));

        $payment = $order->payment()->first();
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->transaction_id);
        $this->assertNull($payment->vbank_number);
    }

    /**
     * 가상계좌 발급 콜백은 이미 결제된 주문의 결제 레코드를 덮어쓰지 않는다.
     *
     * 종전에는 주문 상태를 보지 않고 결제 상태·거래번호·계좌를 덮어써, 결제완료 주문의 환불 대상
     * 거래번호가 남의 가상계좌로 바뀔 수 있었다. 발급은 망취소한다.
     *
     * @effects vbank_issue_callback_does_not_overwrite_paid_order
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issue_callback_does_not_overwrite_paid_order(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::PAID,
            'transaction_id' => 'TID_ORIGINAL_PAID',
            'paid_at' => now(),
        ]);
        $this->mockPluginSettings();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_ATTACKER_VBANK', $order->order_number, 50000, '0000', [
                    'payMethod' => 'VBank',
                    'VACT_Num' => '99990000111',
                    'vactBankName' => '공격은행',
                ]),
                200
            ),
            '*' => Http::response(['resultCode' => '0000'], 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000));

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_payable', $response->headers->get('Location'));

        $payment = $order->payment->fresh();
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame('TID_ORIGINAL_PAID', $payment->transaction_id);
        $this->assertNotSame('99990000111', $payment->vbank_number);
    }

    /**
     * 결제대기 주문의 정상 가상계좌 발급은 그대로 기록되고, 같은 발급의 중복 콜백은 성공으로만 처리한다.
     *
     * @effects vbank_issue_callback_records_pending_order_and_tolerates_replay
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issue_callback_records_pending_order_and_tolerates_replay(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $tid = 'TID_VBANK_'.uniqid();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000, '0000', [
                    'payMethod' => 'VBank',
                    'VACT_Num' => '12345678901',
                    'vactBankName' => '국민은행',
                ]),
                200
            ),
            '*' => Http::response(['resultCode' => '0000'], 200),
        ]);

        $params = $this->makeCallbackParams($order->order_number, 50000);
        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params)
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params)
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment->fresh();
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
        $this->assertSame($tid, $payment->transaction_id);
        $this->assertSame('12345678901', $payment->vbank_number);
    }

    /**
     * 가상계좌 계좌가 발급되면 입금 전이라도 주문한 장바구니 항목을 정리한다 — 재고 차감이 입금 완료 시점이라
     * 장바구니도 입금 때까지 남아, 주문을 마친 고객의 장바구니에 같은 상품이 그대로 보였다.
     *
     * @effects vbank_issue_clears_ordered_cart_items
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issue_clears_ordered_cart_items(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);
        $cart = Cart::create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
        ]);
        $order->update([
            'order_meta' => array_merge($order->order_meta ?? [], ['cart_items' => [['cart_id' => $cart->id, 'quantity' => 1]]]),
        ]);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_VBANK_CART_'.uniqid(), $order->order_number, 50000, '0000', [
                    'payMethod' => 'VBank',
                    'VACT_Num' => '12345678902',
                    'vactBankName' => '국민은행',
                ]),
                200
            ),
            '*' => Http::response(['resultCode' => '0000'], 200),
        ]);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $this->makeCallbackParams($order->order_number, 50000))
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $order->payment->fresh()->payment_status);
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }

    /**
     * 가상계좌 입금 기한(VACT_Date·VACT_Time)은 한국 시각이다 — 저장은 UTC(앱 시간대)로 환산한다.
     *
     * 종전에는 한국 시각 숫자를 그대로 UTC 로 읽어, 주문완료·주문 상세에 기한이 9시간 늦게 보이고
     * 기한 만료 판정도 그만큼 늦었다(20260521 235959 KST 는 14:59:59 UTC).
     *
     * @effects vbank_issue_due_at_stored_as_utc_from_kst
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issue_due_at_is_converted_from_kst_to_utc(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_VBANK_DUE_'.uniqid(), $order->order_number, 50000, '0000', [
                    'payMethod' => 'VBank',
                    'VACT_Num' => '12345678901',
                    'vactBankName' => '국민은행',
                    'VACT_Date' => '20260521',
                    'VACT_Time' => '235959',
                ]),
                200
            ),
            '*' => Http::response(['resultCode' => '0000'], 200),
        ]);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $this->makeCallbackParams($order->order_number, 50000))
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment->fresh();
        $this->assertSame('2026-05-21 14:59:59', $payment->vbank_due_at->copy()->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * 모바일 가상계좌 입금 기한(P_VACT_DATE·P_VACT_TIME)도 한국 시각을 UTC 로 환산해 저장한다.
     *
     * @effects mobile_vbank_issue_due_at_stored_as_utc_from_kst
     *
     * @return void 반환값 없음
     */
    public function test_mobile_vbank_issue_due_at_is_converted_from_kst_to_utc(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $tid = 'MOBILE_VBANK_DUE_'.uniqid();

        Http::fake([
            'fcmobile.inicis.com/smart/payReq.ini' => Http::response(http_build_query([
                'P_STATUS' => '00',
                'P_RMESG1' => '성공',
                'P_TID' => $tid,
                'P_OID' => $order->order_number,
                'P_TYPE' => 'VBank',
                'P_AMT' => '50000',
                'P_VACT_NUM' => '12345678901',
                'P_VACT_BANK_NAME' => '국민은행',
                'P_VACT_DATE' => '20260521',
                'P_VACT_TIME' => '235959',
            ]), 200),
            '*' => Http::response(['resultCode' => '00'], 200),
        ]);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/callback?'.http_build_query([
            'orderId' => $order->order_number,
        ]), [
            'P_STATUS' => '00',
            'P_TID' => $tid,
            'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
            'P_AMT' => '50000',
            'P_OID' => $order->order_number,
            'idc_name' => 'fc',
        ])->assertRedirect();

        $payment = $order->payment->fresh();
        $this->assertSame('12345678901', $payment->vbank_number);
        $this->assertSame('2026-05-21 14:59:59', $payment->vbank_due_at->copy()->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * 모바일 승인 결과의 주문번호가 콜백 주문번호와 다르면 승인을 취소하고 주문을 결제완료로 만들지 않는다.
     *
     * @effects mobile_callback_rejects_approval_for_another_order
     *
     * @return void 반환값 없음
     */
    public function test_mobile_callback_rejects_approval_for_another_order(): void
    {
        $victim = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $tid = 'MOBILE_TID_'.uniqid();

        Http::fake([
            'fcmobile.inicis.com/smart/payReq.ini' => Http::response(http_build_query([
                'P_STATUS' => '00',
                'P_RMESG1' => '성공',
                'P_TID' => $tid,
                'P_OID' => 'ORD-ATTACKER-OWN',
                'P_AMT' => '50000',
                'P_TYPE' => 'CARD',
            ]), 200),
            '*' => Http::response(['resultCode' => '00'], 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/mobile/callback?'.http_build_query([
            'orderId' => $victim->order_number,
        ]), [
            'P_STATUS' => '00',
            'P_TID' => $tid,
            'P_REQ_URL' => 'https://fcmobile.inicis.com/smart/payReq.ini',
            'P_AMT' => '50000',
            'idc_name' => 'fc',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('error=approve_failed', $response->headers->get('Location'));

        $victim->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $victim->order_status);
        $this->assertNull($victim->payment->transaction_id);
    }

    /**
     * 가상계좌 입금통보 페이로드를 만듭니다.
     *
     * @param  array  $overrides  기본값 대신 쓸 항목
     * @return array 구성한 배열
     */
    private function makeVbankNotifyPayload(array $overrides = []): array
    {
        return array_merge([
            'no_tid' => 'VBANK_TID_'.uniqid(),
            'no_oid' => 'ORD-TEST-VBANK',
            'id_merchant' => 'INIpayTest',
            'dt_trans' => now()->format('Ymd'),
            'tm_trans' => now()->format('His'),
            'cd_bank' => '04',
            'no_vacct' => '1234567890',
            'amt_input' => '30000',
            'nm_inputbank' => '국민은행',
            'nm_input' => '홍길동',
        ], $overrides);
    }

    /**
     * 결제 레코드를 가상계좌 발급(입금대기) 상태로 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $tid  거래번호
     * @param  string  $vbankNumber  가상계좌 번호
     * @return void 반환값 없음
     */
    private function markVbankIssued(Order $order, string $tid, string $vbankNumber): void
    {
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'transaction_id' => $tid,
            'vbank_number' => $vbankNumber,
            'payment_meta' => [
                'pay_method' => 'VBank',
                'mid' => self::TEST_MID,
                'is_test_mode' => true,
            ],
        ]);
    }
}
