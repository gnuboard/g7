<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class PaymentCallbackControllerTest extends PluginTestCase
{
    private const TEST_MID = 'nicepay00m';

    /**
     * 애플리케이션 로그를 spy 로 감시하되 `activity` 채널은 실제 로거로 유지합니다.
     *
     * `Log::spy()` 단독 사용 시 `Log::channel('activity')` 가 null 을 반환하고,
     * 결제 완료 훅이 태우는 활동 로그가 null 에 `info()` 를 호출해 \Error 로 죽는다.
     * (`ResolvesActivityLogType::logActivity` 의 catch 는 \Exception 만 잡아 통과시킴)
     */
    private function spyApplicationLogKeepingActivityChannel(): void
    {
        $activityChannel = Log::channel('activity');

        Log::spy();
        Log::shouldReceive('channel')->with('activity')->andReturn($activityChannel);
    }

    private const TEST_MERCHANT_KEY = 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==';

    private function makeAuthorizeResponse(string $tid, string $moid, int $amount, string $resultCode = '3001'): array
    {
        return [
            'ResultCode' => $resultCode,
            'ResultMsg' => '정상처리',
            'TID' => $tid,
            'Moid' => $moid,
            'Amt' => (string) $amount,
            'PayMethod' => 'CARD',
            'AppNo' => 'APP12345',
            'CardNum' => '4330-****-****-1234',
            'IssuCardName' => '신한카드',
            'CardQuota' => '00',
            'AuthDate' => now()->format('YmdHis'),
        ];
    }

    private function createTestOrder(
        int $totalAmount = 50000,
        PaymentMethodEnum $paymentMethod = PaymentMethodEnum::CARD,
    ): Order {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
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
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => $paymentMethod,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'card_approval_number' => null,
        ]);

        return $order;
    }

    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_merchant_key' => self::TEST_MERCHANT_KEY,
            'live_mid' => '',
            'live_merchant_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];

        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $overrides));

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }

    private function makeSignature(string $authToken, string $mid, int $amt, string $merchantKey): string
    {
        return bin2hex(hash('sha256', $authToken.$mid.(string) $amt.$merchantKey, true));
    }

    private function makeCallbackParams(string $moid, int $amt, array $overrides = []): array
    {
        $authToken = 'AUTH_TOKEN_'.uniqid();
        $signature = $this->makeSignature($authToken, self::TEST_MID, $amt, self::TEST_MERCHANT_KEY);

        return array_merge([
            'AuthResultCode' => '0000',
            'AuthResultMsg' => '성공',
            'NextAppURL' => 'https://pay.nicepay.co.kr/v1/authorize',
            'TxTid' => 'TX_TID_'.uniqid(),
            'AuthToken' => $authToken,
            'PayMethod' => 'CARD',
            'MID' => self::TEST_MID,
            'Moid' => $moid,
            'Amt' => $amt,
            'NetCancelURL' => 'https://pay.nicepay.co.kr/v1/netcancel',
            'Signature' => $signature,
        ], $overrides);
    }

    private function makeVbankNotifyPayload(string $moid, int $amt, array $overrides = []): array
    {
        return array_merge([
            'PG' => 'nicepay',
            'PayMethod' => 'VBANK',
            'MID' => self::TEST_MID,
            'MOID' => $moid,
            'TID' => 'VBANK_TID_'.uniqid(),
            'Amt' => $amt,
            'ResultCode' => '4110',
            'ResultMsg' => '입금완료',
            'AuthDate' => now()->format('YmdHis'),
            'AuthCode' => 'AUTH12345',
            'VbankNum' => '1234567890',
            'VbankName' => '국민은행',
            'VbankInputName' => '홍길동',
            'FnCd' => '004',
            'FnName' => '국민은행',
        ], $overrides);
    }

    private function markVbankIssued(Order $order, string $tid, int $amount, array $overrides = []): void
    {
        $order->payment()->update([
            'payment_method' => PaymentMethodEnum::VBANK,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'pg_provider' => 'nicepayments',
            'vbank_name' => $overrides['vbank_name'] ?? '국민은행',
            'vbank_number' => $overrides['vbank_number'] ?? '1234567890',
            'vbank_issued_at' => now(),
            'payment_meta' => array_merge([
                'result_code' => '4100',
                'pay_method' => 'VBANK',
                'mid' => self::TEST_MID,
                'vbank_tid' => $tid,
                'vbank_num' => $overrides['vbank_number'] ?? '1234567890',
                'vbank_name' => $overrides['vbank_name'] ?? '국민은행',
                'is_test_mode' => true,
            ], $overrides['payment_meta'] ?? []),
        ]);
    }

    // ===== SignData 생성 테스트 =====

    public function test_sign_data_allows_guest_pending_nicepayments_order(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'ediDate',
                'signData',
                'mid',
            ])
            ->assertJsonPath('mid', self::TEST_MID);

        $this->assertSame(64, strlen((string) $response->json('signData')));
    }

    public function test_sign_data_rejects_amount_mismatch(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 49999,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_amount'));
    }

    public function test_sign_data_rejects_buyer_mismatch(): void
    {
        $order = $this->createTestOrder(50000);
        OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
            'buyer_email' => 'attacker@example.com',
            'buyer_phone' => '01099999999',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));
    }

    public function test_sign_data_is_rate_limited_by_order_and_ip(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        RateLimiter::clear('sirsoft-pay_nicepayments:sign-data:'.sha1('127.0.0.1|'.$order->order_number));

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
                'amt' => 50000,
                'moid' => $order->order_number,
            ])->assertOk();
        }

        $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ])->assertStatus(429)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));
    }

    public function test_sign_data_rejects_unchargeable_payment_currency(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update([
            'currency' => 'USD',
            'currency_snapshot' => self::unchargeableUsdCurrencySnapshot(),
        ]);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
    }

    public function test_sign_data_rejects_non_payable_order(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update(['order_status' => OrderStatusEnum::CANCELLED]);
        $order->payment()->update(['payment_status' => PaymentStatusEnum::FAILED]);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));
    }

    public function test_sign_data_rejects_live_mode_when_live_mid_is_missing(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings([
            'is_test_mode' => false,
            'live_mid' => '',
            'live_merchant_key' => 'live_key',
        ]);

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));
    }

    public function test_sign_data_restores_retryable_failed_nicepayments_order(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update([
            'order_status' => OrderStatusEnum::CANCELLED,
            'order_meta' => [
                'payment_failure_code' => 'USER_CANCEL',
                'payment_failure_message' => '사용자가 결제창을 닫았습니다.',
                'payment_failed_at' => now()->toIso8601String(),
            ],
        ]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::FAILED,
            'payment_meta' => [
                'failure_source' => 'nicepayments',
                'failure_code' => 'USER_CANCEL',
                'failure_message' => '사용자가 결제창을 닫았습니다.',
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['ediDate', 'signData', 'mid']);

        $order->refresh();
        $payment = $order->payment->fresh();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertArrayNotHasKey('payment_failure_code', $order->order_meta);
        $this->assertEquals(1, $order->order_meta['nicepayments_retry_count'] ?? null);
        $this->assertArrayNotHasKey('failure_code', $payment->payment_meta);
        $this->assertEquals(1, $payment->payment_meta['retry_count'] ?? null);
    }

    public function test_sign_data_does_not_restore_amount_mismatch_failure(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update([
            'order_status' => OrderStatusEnum::CANCELLED,
            'order_meta' => [
                'payment_failure_code' => 'AMOUNT_MISMATCH',
                'payment_failure_message' => 'amount mismatch',
                'payment_failed_at' => now()->toIso8601String(),
            ],
        ]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::FAILED,
            'payment_meta' => [
                'failure_source' => 'nicepayments',
                'failure_code' => 'AMOUNT_MISMATCH',
                'failure_message' => 'amount mismatch',
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));

        $order->refresh();
        $payment = $order->payment->fresh();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
    }

    public function test_sign_data_rejects_non_nicepayments_payment_record(): void
    {
        $order = $this->createTestOrder(50000);
        $order->payment()->update(['pg_provider' => 'kginicis']);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));
    }

    public function test_sign_data_rejects_missing_payment_record(): void
    {
        $order = $this->createTestOrder(50000);
        $order->payment()->delete();
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));
    }

    public function test_sign_data_rejects_unknown_order(): void
    {
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => 'ORD-NOT-FOUND',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.order_not_found'));
    }

    // ===== 성공 콜백 테스트 =====

    public function test_auth_callback_redirects_to_complete_page_on_valid_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_'.uniqid();
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tid, $payment->transaction_id);
        $this->assertEquals('APP12345', $payment->card_approval_number);
    }

    public function test_auth_callback_stores_easy_pay_display_metadata(): void
    {
        app()->setLocale('ko');

        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_EASY_PAY_'.uniqid();
        $params = $this->makeCallbackParams($order->order_number, 50000, [
            'MallReserved' => 'nicepay_easy_pay_method=nicepay_kakaopay',
            'MallReserved1' => 'nicepay_kakaopay',
        ]);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();
        $meta = $payment->payment_meta;

        $this->assertSame('kakaopay', $payment->embedded_pg_provider);
        $this->assertSame('nicepay_kakaopay', $meta['nicepay_easy_pay_method'] ?? null);
        $this->assertSame('kakaopay', $meta['nicepay_easy_pay_provider'] ?? null);
        $this->assertSame('카카오페이', $meta['nicepay_easy_pay_label']['ko'] ?? null);
        $this->assertSame('KakaoPay', $meta['nicepay_easy_pay_label']['en'] ?? null);
        $this->assertSame('kakaopay', $meta['embedded_pg_provider'] ?? null);
    }

    public function test_auth_callback_stores_only_whitelisted_pg_response_fields(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_SANITIZED';
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(array_merge(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                [
                    'BuyerName' => '홍길동',
                    'BuyerEmail' => 'buyer@example.test',
                    'BuyerTel' => '01012345678',
                    'NewSensitiveField' => 'store-me-not',
                ]
            ), 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame($tid, $meta['pg_raw_response']['TID'] ?? null);
        $this->assertSame('3001', $meta['pg_raw_response']['ResultCode'] ?? null);
        $this->assertArrayNotHasKey('CardNum', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerName', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerEmail', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerTel', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('NewSensitiveField', $meta['pg_raw_response']);
    }

    public function test_vbank_issue_stores_only_whitelisted_pg_response_fields(): void
    {
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $tid = 'VBANK_ISSUE_SANITIZED';
        $params = $this->makeCallbackParams($order->order_number, 30000, [
            'PayMethod' => 'VBANK',
        ]);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(array_merge(
                $this->makeAuthorizeResponse($tid, $order->order_number, 30000, '4100'),
                [
                    'PayMethod' => 'VBANK',
                    'VbankBankCode' => '004',
                    'VbankBankName' => '국민은행',
                    'VbankNum' => '1234567890',
                    'VbankExpDate' => '20260521',
                    'VbankExpTime' => '235959',
                    'VbankInputName' => '홍길동',
                    'BuyerName' => '구매자',
                    'BuyerEmail' => 'buyer@example.test',
                    'BuyerTel' => '01012345678',
                    'NewSensitiveField' => 'store-me-not',
                ]
            ), 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame($tid, $meta['pg_raw_response']['TID'] ?? null);
        $this->assertSame('국민은행', $meta['pg_raw_response']['VbankBankName'] ?? null);
        $this->assertArrayNotHasKey('VbankNum', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('VbankInputName', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('CardNum', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerName', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerEmail', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerTel', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('NewSensitiveField', $meta['pg_raw_response']);
    }

    /**
     * 인증 단계(AuthResultCode != '0000') 실패는 사용자가 아직 결제를 끝내지 않은 상태로
     * 어떤 코드/메시지가 오든 generic 에러 toast 없이 체크아웃으로 깨끗하게 복귀해야 한다.
     *
     * 모바일에서 사용자가 취소버튼을 누르거나, PG 가 인증을 거부하거나, 타임아웃이 발생하더라도
     * 이 시점엔 결제 승인 (NextAppURL 호출) 이 일어나기 전이므로 사용자 입장에서는 "다시 시도"
     * 외에 할 수 있는 것이 없다. "결제 처리 중 오류" 같은 강한 메시지는 부적절.
     */
    public function test_auth_callback_softens_pre_authorize_failures_to_silent_redirect(): void
    {
        $this->mockPluginSettings();

        $cases = [
            ['code' => '9999', 'msg' => '사용자 취소'],                  // 표준 사용자 취소
            ['code' => '2001', 'msg' => '사용자 취소'],                  // 한국어 사용자 메시지
            ['code' => '0021', 'msg' => '결제창을 종료하셨습니다'],       // 결제창 종료 (취소 키워드 없음)
            ['code' => '8001', 'msg' => 'User cancelled'],               // 영문 cancel
            ['code' => 'F004', 'msg' => '지불 거절'],                    // 사용자/취소 키워드 없는 PG 거절
            ['code' => '5500', 'msg' => '인증 시간 초과'],                // 타임아웃
            ['code' => '0033', 'msg' => ''],                              // 메시지 없는 실패
        ];

        foreach ($cases as $case) {
            $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
                'AuthResultCode' => $case['code'],
                'AuthResultMsg' => $case['msg'],
            ]);

            $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

            $response->assertRedirect();
            $location = $response->headers->get('Location');

            $this->assertStringNotContainsString(
                'error=',
                $location,
                "AuthResultCode={$case['code']} ({$case['msg']}) 은 인증단계 실패이므로 error query 없이 체크아웃으로 복귀해야 한다. 실제 redirect: {$location}",
            );
            $this->assertStringContainsString('/shop/checkout', $location);
        }
    }

    public function test_auth_callback_keeps_pre_authorize_failure_retryable_when_amount_matches_order(): void
    {
        $order = $this->createTestOrder(50000);
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', [
            'AuthResultCode' => '0021',
            'AuthResultMsg' => '결제창을 종료하셨습니다',
            'Moid' => $order->order_number,
            'Amt' => 50000,
        ]);

        $response->assertRedirect('/shop/checkout');
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment->fresh();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertArrayNotHasKey('payment_failure_code', $order->order_meta ?? []);
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertArrayNotHasKey('failure_code', $payment->payment_meta ?? []);
        $this->assertNull($payment->cancelled_at);
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $option->refresh()->option_status);
    }

    public function test_auth_callback_redirects_to_fail_on_mid_mismatch(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000, [
            'MID' => 'WRONG_MID',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=mid_mismatch', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_signature_mismatch(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000, [
            'Signature' => 'INVALID_SIGNATURE',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=signature_mismatch', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('NON_EXISTENT_ORDER', 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse('TID_NONE', 'NON_EXISTENT_ORDER', 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_found', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_and_sends_net_cancel_on_authorize_failure(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response([
                'ResultCode' => '9999',
                'ResultMsg' => '승인 실패',
            ], 200),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->payment_status);
        $this->assertEquals('9999', $order->payment->payment_meta['failure_code'] ?? null);
    }

    public function test_auth_callback_records_authorize_exception_as_failed_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response([], 500),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment->fresh();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals('AUTHORIZE_EXCEPTION', $order->order_meta['payment_failure_code'] ?? null);
        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertEquals('AUTHORIZE_EXCEPTION', $payment->payment_meta['failure_code'] ?? null);
    }

    public function test_auth_callback_records_invalid_payment_currency_as_failed_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update([
            'currency' => 'USD',
            'currency_snapshot' => self::unchargeableUsdCurrencySnapshot(),
        ]);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse('TID_INVALID_CURRENCY', $order->order_number, 50000),
                200
            ),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_payment_currency', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment->fresh();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals('INVALID_PAYMENT_CURRENCY', $order->order_meta['payment_failure_code'] ?? null);
        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertEquals('INVALID_PAYMENT_CURRENCY', $payment->payment_meta['failure_code'] ?? null);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/netcancel'));
    }

    public function test_auth_callback_redirects_to_fail_url_on_missing_params(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', [
            'AuthResultCode' => '0000',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_custom_success_url(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings(['redirect_success_url' => '/custom/payment/{orderId}/done']);

        $tid = 'TID_CUSTOM';
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/custom/payment/{$order->order_number}/done");
    }

    public function test_auth_callback_detects_mobile_device(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse('TID_MOBILE', $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/callback',
            $params,
            ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)']
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('mobile', $payment->payment_device);
    }

    public function test_auth_callback_ignores_non_payable_order_without_authorize_request(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update(['order_status' => OrderStatusEnum::CANCELLED]);
        $order->payment()->update(['payment_status' => PaymentStatusEnum::FAILED]);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            '*' => Http::response(
                $this->makeAuthorizeResponse('TID_SHOULD_NOT_BE_SENT', $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_payable', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->payment_status);
        $this->assertNull($order->payment->transaction_id);
        Http::assertNothingSent();
    }

    // ===== 가상계좌 입금 통보 테스트 =====

    public function test_vbank_notify_returns_ok_on_successful_deposit(): void
    {
        $tid = 'VBANK_TID_001';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, $tid, 30000);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, ['TID' => $tid])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    public function test_vbank_notify_stores_only_whitelisted_pg_response_fields(): void
    {
        $tid = 'VBANK_TID_SANITIZED';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, $tid, 30000);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'TID' => $tid,
                'name' => '구매자명',
                'BuyerEmail' => 'buyer@example.test',
                'MallUserID' => 'member-001',
                'Signature' => 'signature-value',
            ])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $payment = $order->payment;
        $payment->refresh();

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame('VBANK_TID_SANITIZED', $meta['pg_raw_response']['TID'] ?? null);
        $this->assertSame('4110', $meta['pg_raw_response']['ResultCode'] ?? null);
        $this->assertArrayNotHasKey('VbankNum', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('VbankInputName', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('name', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerEmail', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('MallUserID', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('Signature', $meta['pg_raw_response']);
        $this->assertTrue($meta['vbank_notifications'][0]['raw_sanitized'] ?? false);
        $this->assertArrayNotHasKey('VbankNum', $meta['vbank_notifications'][0]['raw']);
        $this->assertArrayNotHasKey('VbankInputName', $meta['vbank_notifications'][0]['raw']);
    }

    public function test_vbank_notify_returns_ok_on_cancelled_deposit(): void
    {
        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload('ORD-TEST-CANCEL', 30000, [
                'TID' => 'VBANK_TID_002',
                'ResultCode' => '4100',
                'ResultMsg' => '계좌발급',
            ])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_vbank_notify_returns_fail_on_order_not_found(): void
    {
        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload('NON_EXISTENT_ORDER', 30000, ['TID' => 'VBANK_TID_003'])
        );

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());
    }

    public function test_vbank_notify_is_idempotent_for_same_tid(): void
    {
        $tid = 'VBANK_TID_DUPLICATE';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $order->update([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_paid_amount' => 30000,
            'total_due_amount' => 0,
        ]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => 30000,
            'paid_at' => now(),
            'transaction_id' => $tid,
            'vbank_number' => '1234567890',
            'payment_meta' => [
                'mid' => self::TEST_MID,
                'vbank_tid' => $tid,
                'vbank_num' => '1234567890',
                'is_test_mode' => true,
            ],
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, ['TID' => $tid])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    public function test_vbank_notify_rejects_mismatched_context_without_completing_order(): void
    {
        $tid = 'VBANK_TID_SECURE';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, $tid, 30000);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'TID' => 'VBANK_TID_FORGED',
                'MID' => self::TEST_MID,
                'VbankNum' => '1234567890',
            ])
        );

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertNull($order->payment->transaction_id);
    }

    public function test_vbank_notify_rejects_amount_mismatch_without_completing_order(): void
    {
        $tid = 'VBANK_TID_AMOUNT';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, $tid, 30000);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 29999, ['TID' => $tid])
        );

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    public function test_vbank_notify_success_log_does_not_include_depositor_or_full_account(): void
    {
        $this->spyApplicationLogKeepingActivityChannel();

        $tid = 'VBANK_TID_LOG_SAFE';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, $tid, 30000);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, ['TID' => $tid])
        );

        $response->assertOk();

        Log::shouldHaveReceived('info')
            ->with('NicePayments: vbank deposit confirmed', Mockery::on(function (array $context): bool {
                return ! array_key_exists('depositor', $context)
                    && ! array_key_exists('vbank_number', $context)
                    && ! array_key_exists('vbank_num', $context);
            }));
    }

    public function test_auth_callback_rolls_back_and_net_cancels_when_payment_complete_hook_throws_inside_locked_callback(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_POST_COMMIT';
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        HookManager::addAction('sirsoft-ecommerce.order.after_payment_complete', function (): void {
            throw new \RuntimeException('post commit listener failed');
        }, 999);

        try {
            $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);
        } finally {
            HookManager::clearAction('sirsoft-ecommerce.order.after_payment_complete');
        }

        $response->assertRedirect("/shop/checkout?error=authorize_failed&orderId={$order->order_number}");

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->payment_status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'netcancel'));
    }

    public function test_auth_callback_does_not_complete_again_when_order_is_paid_with_another_tid_after_authorize(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_CURRENT_AUTH';
        $params = $this->makeCallbackParams($order->order_number, 50000);
        $afterCompleteCalls = 0;

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        HookManager::addAction('sirsoft-pay_nicepayments.payment.after_authorize', function () use ($order): void {
            $order->update([
                'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'total_due_amount' => 0,
                'total_paid_amount' => 50000,
                'paid_at' => now(),
            ]);
            $order->payment()->update([
                'payment_status' => PaymentStatusEnum::PAID,
                'paid_amount_local' => 50000,
                'paid_at' => now(),
                'transaction_id' => 'TID_ALREADY_PAID',
            ]);
        }, 999);
        HookManager::addAction('sirsoft-ecommerce.order.after_payment_complete', function () use (&$afterCompleteCalls): void {
            $afterCompleteCalls++;
        }, 999);

        try {
            $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);
        } finally {
            HookManager::clearAction('sirsoft-pay_nicepayments.payment.after_authorize');
            HookManager::clearAction('sirsoft-ecommerce.order.after_payment_complete');
        }

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $this->assertSame(0, $afterCompleteCalls);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals('TID_ALREADY_PAID', $order->payment->transaction_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'netcancel'));
    }
}
