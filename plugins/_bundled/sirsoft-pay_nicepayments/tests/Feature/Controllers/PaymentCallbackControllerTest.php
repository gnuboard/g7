<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
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
     *
     * @return void 반환값 없음
     */
    private function spyApplicationLogKeepingActivityChannel(): void
    {
        $activityChannel = Log::channel('activity');

        Log::spy();
        Log::shouldReceive('channel')->with('activity')->andReturn($activityChannel);
    }

    private const TEST_MERCHANT_KEY = 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==';

    /**
     * PG 서버 승인 응답을 흉내 낸 배열을 만듭니다.
     *
     * @param  string  $tid  거래번호
     * @param  string  $moid  주문번호
     * @param  int  $amount  금액
     * @param  string  $resultCode  PG 결과 코드
     * @return array 구성한 배열
     */
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

    /**
     * 결제대기 주문과 결제 레코드를 만듭니다.
     *
     * @param  int  $totalAmount  주문 총액
     * @param  PaymentMethodEnum  $paymentMethod  결제수단
     * @return Order 생성한 주문
     */
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

    /**
     * 콜백 서명(AuthToken+MID+Amt+MerchantKey 의 SHA-256)을 계산합니다.
     *
     * @param  string  $authToken  인증 토큰
     * @param  string  $mid  가맹점 ID
     * @param  int  $amt  결제 금액
     * @param  string  $merchantKey  가맹점 키
     * @return string 생성한 문자열
     */
    private function makeSignature(string $authToken, string $mid, int $amt, string $merchantKey): string
    {
        return bin2hex(hash('sha256', $authToken.$mid.(string) $amt.$merchantKey, true));
    }

    /**
     * 브라우저가 보내는 결제 인증 콜백 파라미터를 만듭니다.
     *
     * @param  string  $moid  주문번호
     * @param  int  $amt  결제 금액
     * @param  array  $overrides  기본값 대신 쓸 항목
     * @return array 구성한 배열
     */
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

    /**
     * 가상계좌 입금통보 페이로드를 만듭니다.
     *
     * @param  string  $moid  주문번호
     * @param  int  $amt  결제 금액
     * @param  array  $overrides  기본값 대신 쓸 항목
     * @return array 구성한 배열
     */
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

    /**
     * 결제 레코드를 가상계좌 발급(입금대기) 상태로 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $tid  거래번호
     * @param  int  $amount  금액
     * @param  array  $overrides  기본값 대신 쓸 항목
     * @return void 반환값 없음
     */
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

    /**
     * 나이스페이 거래 조회 API 응답을 가짜로 세웁니다. (기본값: 입금이 끝나 승인으로 조회되는 거래)
     *
     * @param  string  $tid  거래번호
     * @param  array  $overrides  기본값 대신 쓸 항목
     * @return void 반환값 없음
     */
    private function fakeTransactionQuery(string $tid, array $overrides = []): void
    {
        Http::fake([
            'webapi.nicepay.co.kr/webapi/inquery/trans_status.jsp' => Http::response(array_merge([
                'ResultCode' => '0000',
                'ResultMsg' => '정상처리',
                'TID' => $tid,
                'Status' => '0',
                'AuthCode' => 'AUTH12345',
                'AuthDate' => now()->format('ymdHis'),
            ], $overrides), 200),
        ]);
    }

    // ===== SignData 생성 테스트 =====

    /**
     * 비회원 결제대기 주문도 결제창 서명을 발급받을 수 있다.
     *
     * @effects sign_data_allows_guest_pending_nicepayments_order
     *
     * @return void 반환값 없음
     */
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

    /**
     * 요청 금액이 주문 청구액과 다르면 서명을 발급하지 않는다.
     *
     * @effects sign_data_rejects_amount_mismatch
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문자 정보가 다른 요청에는 서명을 발급하지 않는다.
     *
     * @effects sign_data_rejects_buyer_mismatch
     *
     * @return void 반환값 없음
     */
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

    /**
     * 서명 발급은 주문·IP 기준으로 요청 횟수를 제한한다.
     *
     * @effects sign_data_is_rate_limited_by_order_and_ip
     *
     * @return void 반환값 없음
     */
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

    /**
     * 청구할 수 없는 통화 설정이면 서명을 발급하지 않는다.
     *
     * @effects sign_data_rejects_unchargeable_payment_currency
     *
     * @return void 반환값 없음
     */
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

    /**
     * 재결제 복구 대상이 아닌 결제 불가 주문에는 서명을 발급하지 않는다.
     *
     * @effects sign_data_rejects_non_payable_order
     *
     * @return void 반환값 없음
     */
    public function test_sign_data_rejects_non_payable_order(): void
    {
        $order = $this->createTestOrder(50000);
        // 소유자라도 재결제 복구 대상(나이스페이 실패 흔적)이 아니면 거부된다.
        Sanctum::actingAs(User::findOrFail($order->user_id));
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

    /**
     * 운영 모드에서 운영 MID 가 비어 있으면 서명을 발급하지 않는다.
     *
     * @effects sign_data_rejects_live_mode_when_live_mid_is_missing
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문 소유자가 같은 주문을 다시 결제하면 나이스페이 실패 주문을 결제대기로 되살린다.
     *
     * @effects sign_data_restores_retryable_failed_nicepayments_order
     *
     * @return void 반환값 없음
     */
    public function test_sign_data_restores_retryable_failed_nicepayments_order(): void
    {
        $order = $this->createTestOrder(50000);
        Sanctum::actingAs(User::findOrFail($order->user_id));
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

    /**
     * 결제창 닫힘으로 결제 취소(취소 이력 포함)된 주문도 소유자가 다시 결제하면 결제대기로 되살린다.
     *
     * @effects sign_data_restores_window_closed_cancelled_nicepayments_order
     *
     * @return void 반환값 없음
     */
    public function test_sign_data_restores_window_closed_cancelled_nicepayments_order(): void
    {
        $order = $this->createTestOrder(50000);
        Sanctum::actingAs(User::findOrFail($order->user_id));
        $order->update([
            'order_status' => OrderStatusEnum::CANCELLED,
            'order_meta' => [
                'payment_failure_code' => 'USER_CANCEL',
                'payment_failure_message' => '사용자가 나이스페이먼츠 결제창을 닫았습니다.',
                'payment_failed_at' => now()->toIso8601String(),
            ],
        ]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::CANCELLED,
            'cancelled_at' => now(),
            'cancel_history' => [[
                'cancel_code' => 'USER_CANCEL',
                'cancel_message' => 'nicepay-window-closed',
                'cancelled_at' => now()->toIso8601String(),
            ]],
            'payment_meta' => [
                'failure_source' => 'nicepayments',
                'failure_code' => 'USER_CANCEL',
                'failure_message' => '사용자가 나이스페이먼츠 결제창을 닫았습니다.',
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
        $this->assertNull($payment->cancelled_at);
        $this->assertEquals(1, $order->order_meta['nicepayments_retry_count'] ?? null);
    }

    /**
     * 금액 불일치로 실패한 주문은 소유자라도 결제대기로 되살리지 않는다.
     *
     * @effects sign_data_does_not_restore_amount_mismatch_failure
     *
     * @return void 반환값 없음
     */
    public function test_sign_data_does_not_restore_amount_mismatch_failure(): void
    {
        $order = $this->createTestOrder(50000);
        Sanctum::actingAs(User::findOrFail($order->user_id));
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

    /**
     * 나이스페이 결제 실패로 취소된 주문으로 만듭니다 (재결제 복구 대상).
     *
     * @param  Order  $order  대상 주문
     * @return void 반환값 없음
     */
    private function markRetryableNiceFailure(Order $order): void
    {
        $order->update([
            'order_status' => OrderStatusEnum::CANCELLED,
            'order_meta' => [
                'payment_failure_code' => 'USER_CANCEL',
                'payment_failed_at' => now()->toIso8601String(),
            ],
        ]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::FAILED,
            'payment_meta' => [
                'failure_source' => 'nicepayments',
                'failure_code' => 'USER_CANCEL',
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * 주문자 연락처를 맞춰 보내도 소유자가 아니면 취소된 주문을 되살리지 못한다 (KVE-2026-2434).
     *
     * 종전에는 연락처 대조만으로 취소 주문이 결제대기로 돌아갔다.
     *
     * @effects sign_data_does_not_restore_failed_order_for_non_owner_with_matching_contact
     *
     * @return void 반환값 없음
     */
    public function test_sign_data_does_not_restore_failed_order_for_non_owner_with_matching_contact(): void
    {
        $order = $this->createTestOrder(50000);
        $this->markRetryableNiceFailure($order);
        OrderAddressFactory::new()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->fresh()->payment_status);
    }

    /**
     * 다른 회원은 남의 실패 주문을 되살리지 못한다.
     *
     * @effects sign_data_does_not_restore_failed_order_for_other_member
     *
     * @return void 반환값 없음
     */
    public function test_sign_data_does_not_restore_failed_order_for_other_member(): void
    {
        $order = $this->createTestOrder(50000);
        $this->markRetryableNiceFailure($order);
        $this->mockPluginSettings();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ])->assertStatus(403);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 비회원은 주문 생성 때 받은 조회 토큰으로 자기 실패 주문을 다시 결제할 수 있다.
     *
     * @effects sign_data_restores_guest_failed_order_with_order_token
     *
     * @return void 반환값 없음
     */
    public function test_sign_data_restores_guest_failed_order_with_order_token(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update(['user_id' => null]);
        $this->markRetryableNiceFailure($order);
        $this->mockPluginSettings();
        $token = app(GuestOrderAuthService::class)->issueForOrder($order->fresh())['token'];

        $this->withHeader('X-Guest-Order-Token', $token)
            ->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
                'amt' => 50000,
                'moid' => $order->order_number,
            ])
            ->assertOk()
            ->assertJsonStructure(['ediDate', 'signData', 'mid']);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 다른 PG 결제 레코드의 주문에는 서명을 발급하지 않는다.
     *
     * @effects sign_data_rejects_non_nicepayments_payment_record
     *
     * @return void 반환값 없음
     */
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

    /**
     * 결제 레코드가 없는 주문에는 서명을 발급하지 않는다.
     *
     * @effects sign_data_rejects_missing_payment_record
     *
     * @return void 반환값 없음
     */
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

    /**
     * Moid 상한(64자) 초과는 SignDataRequest 가 표준 422 로 차단해야 한다.
     * (계약 변경: 종전 수동 검사 400 → FormRequest 표준 422 — 클라이언트는 `.ok` 만 검사)
     *
     * @effects sign_data_rejects_overlong_moid
     *
     * @return void 반환값 없음
     */
    public function test_sign_data_rejects_overlong_moid(): void
    {
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => str_repeat('A', 65),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['moid']);
    }

    /**
     * 비정수 amt('abc')는 SignDataRequest 의 integer 규칙이 표준 422 로 차단해야 한다.
     * (계약 변경: 종전 수동 검사 400 → FormRequest 표준 422)
     *
     * @effects sign_data_rejects_non_integer_amt
     *
     * @return void 반환값 없음
     */
    public function test_sign_data_rejects_non_integer_amt(): void
    {
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 'abc',
            'moid' => 'ORD-TEST-AMT-TYPE',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amt']);
    }

    /**
     * 존재하지 않는 주문번호에는 서명을 발급하지 않는다.
     *
     * @effects sign_data_rejects_unknown_order
     *
     * @return void 반환값 없음
     */
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

    /**
     * 간편결제 승인은 표시용 간편결제 정보를 결제 메타에 남긴다.
     *
     * @effects auth_callback_stores_easy_pay_display_metadata
     *
     * @return void 반환값 없음
     */
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

    /**
     * PG 승인 응답은 허용 목록의 필드만 저장한다 (카드번호·개인정보 제외).
     *
     * @effects auth_callback_stores_only_whitelisted_pg_response_fields
     *
     * @return void 반환값 없음
     */
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

    /**
     * 가상계좌 발급 응답은 허용 목록의 필드만 저장한다.
     *
     * @effects vbank_issue_stores_only_whitelisted_pg_response_fields
     *
     * @return void 반환값 없음
     */
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

        // VbankExpDate·VbankExpTime 은 한국 시각이다 — 저장은 UTC(앱 시간대)로 환산(20260521 235959 KST = 14:59:59 UTC).
        $this->assertSame('2026-05-21 14:59:59', $payment->vbank_due_at->copy()->utc()->format('Y-m-d H:i:s'));
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
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
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
            'pay.nicepay.co.kr/v1/authorize' => Http::response(array_merge(
                $this->makeAuthorizeResponse('VBANK_ISSUE_CART', $order->order_number, 30000, '4100'),
                [
                    'PayMethod' => 'VBANK',
                    'VbankBankCode' => '004',
                    'VbankBankName' => '국민은행',
                    'VbankNum' => '1234567891',
                    'VbankExpDate' => '20260521',
                    'VbankExpTime' => '235959',
                ]
            ), 200),
        ]);

        $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $this->makeCallbackParams($order->order_number, 30000, ['PayMethod' => 'VBANK']))
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }

    /**
     * 입금 기한 형식이 깨진 발급 응답도 발급은 기록하고 기한만 비운다(예외로 발급 처리가 끊기지 않는다).
     *
     * KCP·KG 는 기한 파싱을 예외 처리로 감싸는데 나이스만 감싸지 않아, 형식이 다른 기한이 오면 발급 기록 전체가 실패했다.
     *
     * @effects vbank_issue_tolerates_malformed_due_date
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issue_with_malformed_due_date_still_records_issue(): void
    {
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 30000, [
            'PayMethod' => 'VBANK',
        ]);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(array_merge(
                $this->makeAuthorizeResponse('VBANK_ISSUE_BAD_DUE', $order->order_number, 30000, '4100'),
                [
                    'PayMethod' => 'VBANK',
                    'VbankBankCode' => '004',
                    'VbankBankName' => '국민은행',
                    'VbankNum' => '1234567890',
                    'VbankExpDate' => '2026-05-21',
                    'VbankExpTime' => '23:59',
                ]
            ), 200),
        ]);

        $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params)
            ->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment->fresh();
        $this->assertSame('1234567890', $payment->vbank_number);
        $this->assertNull($payment->vbank_due_at);
    }

    /**
     * 가상계좌 발급 승인 응답을 흉내 낸 배열을 만듭니다.
     *
     * @param  string  $tid  거래번호
     * @param  string  $moid  주문번호
     * @param  int  $amount  발급 금액
     * @return array 구성한 배열
     */
    private function makeVbankIssueResponse(string $tid, string $moid, int $amount): array
    {
        return array_merge($this->makeAuthorizeResponse($tid, $moid, $amount, '4100'), [
            'PayMethod' => 'VBANK',
            'VbankBankCode' => '004',
            'VbankBankName' => '국민은행',
            'VbankNum' => '1234567890',
            'VbankExpDate' => '20260521',
            'VbankExpTime' => '235959',
        ]);
    }

    /**
     * 발급 금액이 주문 청구액과 다른 가상계좌는 기록하지 않고 발급 승인을 망취소한다.
     *
     * 수정 전: 발급 응답 금액을 주문 청구액과 대조하지 않아, 청구액보다 적은 금액의 계좌가 주문에 붙고
     * 그 금액을 입금하면 결제완료 경로로 이어질 수 있었다.
     *
     * @effects vbank_issue_net_cancels_when_issued_amount_differs_from_order_charge
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issue_net_cancels_when_issued_amount_differs_from_order_charge(): void
    {
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 1000, [
            'PayMethod' => 'VBANK',
        ]);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeVbankIssueResponse('VBANK_ISSUE_LOW_AMOUNT', $order->order_number, 1000),
                200
            ),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment->fresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->vbank_number);
        $this->assertArrayNotHasKey('vbank_tid', $payment->payment_meta ?? []);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/netcancel'));
    }

    /**
     * 가상계좌로 주문하지 않은 결제 건에는 발급을 기록하지 않고 발급 승인을 망취소한다.
     *
     * @effects vbank_issue_net_cancels_for_order_not_paid_by_virtual_account
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issue_net_cancels_for_order_not_paid_by_virtual_account(): void
    {
        $order = $this->createTestOrder(30000, PaymentMethodEnum::CARD);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 30000, [
            'PayMethod' => 'VBANK',
        ]);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeVbankIssueResponse('VBANK_ISSUE_CARD_ORDER', $order->order_number, 30000),
                200
            ),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_payable', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment->fresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->vbank_number);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/netcancel'));
    }

    /**
     * 인증 단계(AuthResultCode != '0000') 실패는 사용자가 아직 결제를 끝내지 않은 상태로
     * 어떤 코드/메시지가 오든 generic 에러 toast 없이 체크아웃으로 깨끗하게 복귀해야 한다.
     *
     * 모바일에서 사용자가 취소버튼을 누르거나, PG 가 인증을 거부하거나, 타임아웃이 발생하더라도
     * 이 시점엔 결제 승인 (NextAppURL 호출) 이 일어나기 전이므로 사용자 입장에서는 "다시 시도"
     * 외에 할 수 있는 것이 없다. "결제 처리 중 오류" 같은 강한 메시지는 부적절.
     *
     * @effects auth_callback_softens_pre_authorize_failures_to_silent_redirect
     *
     * @return void 반환값 없음
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

    /**
     * 승인 전 인증 단계 실패는 주문을 결제대기로 남겨 같은 주문으로 다시 결제할 수 있다.
     *
     * @effects auth_callback_keeps_pre_authorize_failure_retryable_when_amount_matches_order
     *
     * @return void 반환값 없음
     */
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

    /**
     * 가맹점 ID 가 설정과 다르면 실패 화면으로 보내고 주문을 건드리지 않는다.
     *
     * @effects auth_callback_redirects_to_fail_on_mid_mismatch
     *
     * @return void 반환값 없음
     */
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

    /**
     * 서명이 맞지 않는 콜백은 실패 화면으로 보내고 주문을 건드리지 않는다.
     *
     * @effects auth_callback_redirects_to_fail_on_signature_mismatch
     *
     * @return void 반환값 없음
     */
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
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse('TID_NONE', 'NON_EXISTENT_ORDER', 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_found', $response->headers->get('Location'));
    }

    /**
     * 승인 실패는 망취소하고 실패 화면으로 보내되 주문 상태는 바꾸지 않는다 (KVE-2026-2434).
     *
     * 이 콜백은 비인증 브라우저 POST 이고 서명이 주문번호를 묶지 않는다. 승인 실패를 근거로 실패 처리하면
     * 남의 주문번호를 붙인 요청 하나로 타인의 결제대기 주문이 취소된다.
     *
     * @effects auth_callback_redirects_to_fail_and_sends_net_cancel_on_authorize_failure
     *
     * @return void 반환값 없음
     */
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
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/netcancel'));
    }

    /**
     * 승인 API 예외도 주문 상태를 바꾸지 않는다 — 예외는 누구나 일으킬 수 있어 실패 근거가 못 된다.
     *
     * @effects auth_callback_keeps_order_pending_on_authorize_exception
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_keeps_order_pending_on_authorize_exception(): void
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
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
        $this->assertArrayNotHasKey('payment_failure_code', $order->order_meta ?? []);
    }

    /**
     * 결제 통화 설정 오류로 승인을 반영할 수 없으면 승인을 망취소하고 주문은 그대로 둔다.
     *
     * @effects auth_callback_net_cancels_invalid_payment_currency_without_changing_order
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_net_cancels_invalid_payment_currency_without_changing_order(): void
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
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/netcancel'));
    }

    /**
     * 자기 결제창에서 얻은 인증값에 남의 주문번호를 붙이고 승인 주소를 바꿔 보내도
     * 피해자 주문은 취소되지 않는다 (KVE-2026-2434 공격 재현).
     *
     * @effects auth_callback_with_foreign_order_number_and_forged_next_app_url_does_not_cancel_victim
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_with_foreign_order_number_and_forged_next_app_url_does_not_cancel_victim(): void
    {
        $victim = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        // 서명은 AuthToken+MID+Amt 만 묶으므로 공격자 인증값으로도 통과한다.
        $params = $this->makeCallbackParams($victim->order_number, 50000, [
            'NextAppURL' => 'https://evil.example/v1/authorize',
        ]);

        Http::fake([
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
            '*' => Http::response([], 500),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'));

        $victim->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $victim->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $victim->payment->payment_status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
    }

    /**
     * 승인 응답의 주문번호가 콜백 주문과 다르면(다른 주문의 인증값) 승인을 망취소하고
     * 이 주문을 결제완료로 만들지 않는다.
     *
     * @effects auth_callback_rejects_authorize_response_for_another_order
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_rejects_authorize_response_for_another_order(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse('TID_OTHER_ORDER', 'ORD-ATTACKER-OWN', 50000),
                200
            ),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
        $this->assertNull($order->payment->transaction_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/netcancel'));
    }

    /**
     * 승인 응답에 승인 금액이 없거나 숫자가 아니면 승인을 망취소하고 주문을 결제완료로 만들지 않는다.
     *
     * 수정 전: 응답 금액이 비면 대조를 건너뛰고 브라우저가 보낸 콜백 금액으로 결제를 완료했다.
     *
     * @effects auth_callback_net_cancels_when_authorize_response_has_no_approved_amount
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_net_cancels_when_authorize_response_has_no_approved_amount(): void
    {
        $this->mockPluginSettings();

        $cases = [
            'missing' => null,
            'empty' => '',
            'non_numeric' => 'N/A',
            'decimal' => '50000.0',
        ];

        // 가짜 응답은 한 번만 등록한다 — Http::fake 를 거듭 부르면 먼저 등록한 응답이 이긴다.
        $pgResponse = [];
        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => function () use (&$pgResponse) {
                return Http::response($pgResponse, 200);
            },
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        foreach ($cases as $label => $pgAmt) {
            $order = $this->createTestOrder(50000);
            $params = $this->makeCallbackParams($order->order_number, 50000);

            $pgResponse = $this->makeAuthorizeResponse('TID_NO_AMT_'.$label, $order->order_number, 50000);
            if ($pgAmt === null) {
                unset($pgResponse['Amt']);
            } else {
                $pgResponse['Amt'] = $pgAmt;
            }

            $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

            $response->assertRedirect();
            $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'), "case={$label}");

            $order->refresh();
            $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status, "case={$label}");
            $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status, "case={$label}");
            $this->assertNull($order->payment->transaction_id, "case={$label}");
            Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/netcancel')
                && $request['TID'] === $params['TxTid']);
        }
    }

    /**
     * 결제완료 반영에 넘기는 금액은 PG 승인 응답의 금액이다. (0 으로 채운 숫자 문자열도 정수로 읽는다)
     *
     * @effects auth_callback_hands_pg_approved_amount_to_complete_payment
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_hands_pg_approved_amount_to_complete_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);
        $pgResponse = array_merge(
            $this->makeAuthorizeResponse('TID_PG_AMOUNT', $order->order_number, 50000),
            ['Amt' => '000000050000'],
        );

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response($pgResponse, 200),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        $receivedAmounts = [];
        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->andReturnUsing(fn (string $orderNumber) => Order::query()->where('order_number', $orderNumber)->first());
        $orderService->shouldReceive('completePayment')
            ->once()
            ->andReturnUsing(function (Order $lockedOrder, array $paymentData, ?int $pgAmount) use (&$receivedAmounts): Order {
                $receivedAmounts[] = $pgAmount;

                return $lockedOrder;
            });
        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $this->assertSame([50000], $receivedAmounts);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/netcancel'));
    }

    /**
     * 필수 파라미터가 빠진 콜백은 실패 화면으로 보낸다.
     *
     * @effects auth_callback_redirects_to_fail_url_on_missing_params
     *
     * @return void 반환값 없음
     */
    public function test_auth_callback_redirects_to_fail_url_on_missing_params(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', [
            'AuthResultCode' => '0000',
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
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

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

    /**
     * 결제할 수 없는 주문이면 PG 승인 요청 없이 실패 화면으로 보낸다.
     *
     * @effects auth_callback_ignores_non_payable_order_without_authorize_request
     *
     * @return void 반환값 없음
     */
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

    /**
     * 정상 입금통보는 주문을 결제완료로 만들고 정상 응답한다.
     *
     * @effects vbank_notify_returns_ok_on_successful_deposit
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_returns_ok_on_successful_deposit(): void
    {
        $tid = 'VBANK_TID_001';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, $tid, 30000);
        $this->fakeTransactionQuery($tid);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, ['TID' => $tid])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        // 결제완료 전에 거래 조회로 입금을 PG 에 직접 확인했다.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'trans_status.jsp')
            && $request['TID'] === $tid);
    }

    /**
     * 입금통보 응답은 허용 목록의 필드만 저장한다.
     *
     * @effects vbank_notify_stores_only_whitelisted_pg_response_fields
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_stores_only_whitelisted_pg_response_fields(): void
    {
        $tid = 'VBANK_TID_SANITIZED';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, $tid, 30000);
        $this->fakeTransactionQuery($tid);

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

    /**
     * 입금 취소 통보에도 정상 응답한다.
     *
     * @effects vbank_notify_returns_ok_on_cancelled_deposit
     *
     * @return void 반환값 없음
     */
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

    /**
     * 존재하지 않는 주문의 입금통보에는 실패를 응답한다.
     *
     * @effects vbank_notify_returns_fail_on_order_not_found
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_returns_fail_on_order_not_found(): void
    {
        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload('NON_EXISTENT_ORDER', 30000, ['TID' => 'VBANK_TID_003'])
        );

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());
    }

    /**
     * 같은 거래번호의 입금통보를 다시 받아도 한 번만 처리한다.
     *
     * @effects vbank_notify_is_idempotent_for_same_tid
     *
     * @return void 반환값 없음
     */
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

    /**
     * 저장된 발급 정보와 다른 입금통보는 주문을 결제완료로 만들지 않는다.
     *
     * @effects vbank_notify_rejects_mismatched_context_without_completing_order
     *
     * @return void 반환값 없음
     */
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

    /**
     * 입금액이 청구액과 다르면 주문을 결제완료로 만들지 않는다.
     *
     * @effects vbank_notify_rejects_amount_mismatch_without_completing_order
     *
     * @return void 반환값 없음
     */
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

    /**
     * 거래 조회로 입금이 확인되지 않는 입금통보는 주문을 결제완료로 만들지 않고 실패를 응답한다.
     *
     * 입금통보에는 서명이 없어, 발급 정보(거래번호·계좌·금액)를 아는 쪽이 보낸 위조 통보를 통보 내용만으로는
     * 가려낼 수 없다. 수정 전: 발급 정보와 맞기만 하면 PG 확인 없이 결제완료로 바꿨다.
     *
     * @effects vbank_notify_rejects_deposit_not_confirmed_by_transaction_query
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_rejects_deposit_not_confirmed_by_transaction_query(): void
    {
        $cases = [
            'not_deposited' => ['body' => ['ResultCode' => '0000', 'Status' => '9'], 'status' => 200],
            'cancelled' => ['body' => ['ResultCode' => '0000', 'Status' => '1'], 'status' => 200],
            'query_failed' => ['body' => ['ResultCode' => 'A118', 'ResultMsg' => '조회 실패'], 'status' => 200],
            'other_tid' => ['body' => ['ResultCode' => '0000', 'Status' => '0', 'TID' => 'VBANK_TID_OTHER'], 'status' => 200],
            'amount_differs' => ['body' => ['ResultCode' => '0000', 'Status' => '0', 'Amt' => '1000'], 'status' => 200],
            'http_error' => ['body' => [], 'status' => 500],
        ];

        // 가짜 응답은 한 번만 등록한다 — Http::fake 를 거듭 부르면 먼저 등록한 응답이 이긴다.
        $queryResponse = ['body' => null, 'status' => 200];
        Http::fake([
            'webapi.nicepay.co.kr/webapi/inquery/trans_status.jsp' => function () use (&$queryResponse) {
                return Http::response($queryResponse['body'], $queryResponse['status']);
            },
        ]);

        foreach ($cases as $label => $case) {
            $tid = 'VBANK_UNCONF_'.$label; // 입금통보 TID 는 30자 이하만 받는다
            $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
            $this->markVbankIssued($order, $tid, 30000);

            $queryResponse = [
                'body' => $case['status'] === 200 ? array_merge(['TID' => $tid], $case['body']) : null,
                'status' => $case['status'],
            ];

            $response = $this->post(
                '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
                $this->makeVbankNotifyPayload($order->order_number, 30000, ['TID' => $tid])
            );

            $response->assertOk();
            $this->assertEquals('FAIL', $response->getContent(), "case={$label}");

            $order->refresh();
            $payment = $order->payment->fresh();
            $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status, "case={$label}");
            $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status, "case={$label}");
            $this->assertNull($payment->transaction_id, "case={$label}");
            Http::assertSent(fn ($request) => str_contains($request->url(), 'trans_status.jsp')
                && $request['TID'] === $tid);
        }
    }

    /**
     * 입금 확인 거래 조회는 계좌를 발급한 당시의 MID 로 서명해 보낸다.
     *
     * @effects vbank_notify_transaction_query_uses_issue_time_mid
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_transaction_query_uses_issue_time_mid(): void
    {
        $this->mockPluginSettings(['test_mid' => 'othermid0m']);

        $tid = 'VBANK_TID_ISSUE_MID';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, $tid, 30000);
        $this->fakeTransactionQuery($tid);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, ['TID' => $tid])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'trans_status.jsp')
            && $request['MID'] === self::TEST_MID);
    }

    /**
     * 입금 성공 로그에 입금자명·전체 계좌번호를 남기지 않는다.
     *
     * @effects vbank_notify_success_log_does_not_include_depositor_or_full_account
     *
     * @return void 반환값 없음
     */
    public function test_vbank_notify_success_log_does_not_include_depositor_or_full_account(): void
    {
        $this->spyApplicationLogKeepingActivityChannel();

        $tid = 'VBANK_TID_LOG_SAFE';
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, $tid, 30000);
        $this->fakeTransactionQuery($tid);

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

    /**
     * 결제완료 반영 중 예외가 나면 트랜잭션을 되돌리고 승인을 망취소한다 — 주문은 결제대기 그대로
     * 남아 같은 주문으로 다시 결제할 수 있다.
     *
     * @effects auth_callback_rolls_back_and_net_cancels_when_payment_complete_hook_throws_inside_locked_callback
     *
     * @return void 반환값 없음
     */
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
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'netcancel'));
    }

    /**
     * 승인 직후 다른 거래번호로 이미 결제된 주문은 다시 결제완료하지 않고 이번 승인을 망취소한다.
     *
     * @effects auth_callback_does_not_complete_again_when_order_is_paid_with_another_tid_after_authorize
     *
     * @return void 반환값 없음
     */
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
