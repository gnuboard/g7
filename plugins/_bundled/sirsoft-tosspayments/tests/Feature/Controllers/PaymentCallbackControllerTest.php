<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Support\ShopRedirectUrl;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 토스페이먼츠 결제 콜백 컨트롤러 기능 테스트
 *
 * @scenario payment_method=toss_virtual_account, use_escrow=on
 *
 * @effects vbank_account_stored_without_completing, vbank_secret_stored_for_webhook,
 *          is_escrow_snapshot_persisted
 */
class PaymentCallbackControllerTest extends PluginTestCase
{
    /**
     * 토스페이먼츠 Confirm API 성공 응답 mock 데이터
     *
     * @param  string  $paymentKey  결제 키
     * @param  string  $orderId  주문번호
     * @param  int  $amount  결제 금액
     */
    private function makeMockConfirmResponse(string $paymentKey, string $orderId, int $amount): array
    {
        return [
            'paymentKey' => $paymentKey,
            'orderId' => $orderId,
            'status' => 'DONE',
            'totalAmount' => $amount,
            'method' => '카드',
            'approvedAt' => now()->toIso8601String(),
            'card' => [
                'issuerCode' => '41',
                'acquirerCode' => '41',
                'number' => '4330-****-****-1234',
                'installmentPlanMonths' => 0,
                'approveNo' => '12345678',
                'isInterestFree' => false,
                'cardType' => '신용',
                'ownerType' => '개인',
            ],
            'easyPay' => null,
            'receipt' => [
                'url' => 'https://dashboard.tosspayments.com/receipt/test',
            ],
        ];
    }

    /**
     * 테스트용 주문 + 결제 레코드 생성
     *
     * @param  int  $totalAmount  주문 총액
     */
    /**
     * 주문번호 충돌 방지용 시퀀스.
     *
     * random_int 로 만들면 같은 스위트 내 다른 테스트가 만든 주문과 번호가 겹칠 수 있고,
     * 그때 콜백이 엉뚱한 주문을 찾아 금액 불일치(amount_mismatch)로 실패한다 —
     * 낮은 확률로만 터지므로 "간헐적 실패" 로 나타나 원인을 오해하기 쉽다.
     */
    private static int $orderSequence = 0;

    /**
     * 주문 통화 고정값.
     *
     * 팩토리 기본값은 이커머스 통화 설정에서 읽으므로 앞서 실행된 다른 스위트가 남긴
     * 설정 상태에 따라 통화가 달라진다. 더 중요한 것은 **소수 자릿수**다 —
     * `resolveSnapshotPaymentCharge` 는 스냅샷에 `decimal_places` 가 없으면 상점 통화
     * 설정에서 조회하는데, 그 설정이 비어 있으면 KRW(0자리)가 2자리로 해석되어 청구액이
     * 최소단위 환산으로 100배가 된다. 그러면 콜백 금액 검증이 `amount_mismatch` 로 갈라져
     * 이 파일의 리다이렉트 단언이 성공 분기가 아닌 실패 분기를 본다.
     * (실측: 게시판 스위트 직후 expected 5,000,000 vs 콜백 amount 50,000)
     *
     * 이 파일은 리다이렉트 주소를 검증하는 것이지 통화 환산을 검증하지 않으므로,
     * 통화와 자릿수를 스냅샷에 못박아 선행 스위트와 무관하게 결정적으로 동작하게 한다.
     */
    private const TEST_CURRENCY = 'KRW';

    /** 원화 소수 자릿수 — 스냅샷에 명시해 상점 설정 조회로 떨어지지 않게 한다. */
    private const TEST_CURRENCY_DECIMALS = 0;

    private function createTestOrder(int $totalAmount = 50000): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-'.(++self::$orderSequence),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => self::TEST_CURRENCY,
            'currency_snapshot' => [
                'base_currency' => self::TEST_CURRENCY,
                'order_currency' => self::TEST_CURRENCY,
                'exchange_rate' => 1.0,
                // 환율을 단순 float 로 두면 `resolveSnapshotPaymentCharge` 가 자릿수를
                // 상점 설정에서 조회한다. 배열 형태로 `decimal_places` 를 명시해야
                // 설정 상태와 무관하게 원화 0 자리로 고정된다.
                'exchange_rates' => [
                    self::TEST_CURRENCY => [
                        'rate' => 1.0,
                        'decimal_places' => self::TEST_CURRENCY_DECIMALS,
                        'rounding_unit' => '1',
                        'rounding_method' => 'round',
                    ],
                ],
                'snapshot_at' => now()->toIso8601String(),
            ],
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
            'pg_provider' => 'tosspayments',
            'currency' => self::TEST_CURRENCY,
            'currency_snapshot' => [self::TEST_CURRENCY => 1.0],
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'card_approval_number' => null,
        ]);

        return $order;
    }

    /**
     * 플러그인 설정을 mock합니다.
     *
     * @param  array  $overrides  기본 설정을 덮어쓸 값
     */
    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_mock_key',
            'test_client_key' => 'test_ck_mock_key',
            'redirect_success_url' => ShopRedirectUrl::DEFAULT_SUCCESS_URL,
            'redirect_fail_url' => ShopRedirectUrl::DEFAULT_FAIL_URL,
        ];

        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $overrides));

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }

    /**
     * 상점 주소를 바꾼 상점에서도 결제 완료 이동 주소가 그 주소를 따르는지 확인 (공개 #85)
     *
     * 기본값이 `/shop/...` 리터럴이던 시절에는 주소를 바꾼 상점의 구매자가 결제를 마치고
     * 존재하지 않는 페이지로 떨어졌다. 리다이렉트라 예외도 404 로그도 남지 않는다.
     *
     * @scenario case=payment_redirect_follows_shop_route_path
     *
     * @effects payment_redirect_follows_route_path
     */
    public function test_success_redirect_follows_shop_route_path_setting(): void
    {
        Config::set(
            'g7_settings.modules.sirsoft-ecommerce.basic_info',
            ['route_path' => 'store']
        );

        $order = $this->createTestOrder(50000);
        $paymentKey = 'pk_test_route_path';

        $this->mockPluginSettings();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response(
                $this->makeMockConfirmResponse($paymentKey, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $order->order_number,
            'amount' => 50000,
        ]));

        $response->assertRedirect("/store/orders/{$order->order_number}/complete");
    }

    // ===== Success 콜백 테스트 =====

    /**
     * 결제 성공 시 주문 완료 페이지로 리다이렉트하는지 확인
     */
    public function test_success_redirects_to_complete_page_on_valid_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $paymentKey = 'pk_test_abc123';

        $this->mockPluginSettings();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response(
                $this->makeMockConfirmResponse($paymentKey, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $order->order_number,
            'amount' => 50000,
        ]));

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        // 주문 상태가 PAYMENT_COMPLETE로 변경되었는지 확인
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        // 결제 정보가 업데이트되었는지 확인
        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($paymentKey, $payment->transaction_id);
        $this->assertEquals('12345678', $payment->card_approval_number);
        $this->assertEquals('4330-****-****-1234', $payment->card_number_masked);
        $this->assertEquals('신한', $payment->card_name);
    }

    /**
     * 존재하지 않는 주문번호로 요청 시 체크아웃으로 리다이렉트
     */
    public function test_success_redirects_to_checkout_on_order_not_found(): void
    {
        $this->mockPluginSettings();

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => 'pk_test_xxx',
            'orderId' => 'NON_EXISTENT_ORDER',
            'amount' => 50000,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_found', $response->headers->get('Location'));
    }

    /**
     * TossPayments Confirm API 실패 시 체크아웃으로 리다이렉트
     */
    public function test_success_redirects_to_checkout_on_confirm_api_failure(): void
    {
        $order = $this->createTestOrder(50000);

        $this->mockPluginSettings();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'code' => 'ALREADY_PROCESSED_PAYMENT',
                'message' => '이미 처리된 결제입니다.',
            ], 400),
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => 'pk_test_fail',
            'orderId' => $order->order_number,
            'amount' => 50000,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=confirm_failed', $response->headers->get('Location'));

        // 주문 상태가 변경되지 않았는지 확인
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * PG 금액 불일치 시 체크아웃으로 리다이렉트
     */
    public function test_success_redirects_to_checkout_on_amount_mismatch(): void
    {
        $order = $this->createTestOrder(50000);

        $this->mockPluginSettings();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response(
                $this->makeMockConfirmResponse('pk_test_mismatch', $order->order_number, 99999),
                200
            ),
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => 'pk_test_mismatch',
            'orderId' => $order->order_number,
            'amount' => 99999,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));
    }

    // ===== Fail 콜백 테스트 =====

    /**
     * 결제 실패 시 체크아웃으로 리다이렉트하는지 확인
     */
    public function test_fail_redirects_to_checkout_with_error_params(): void
    {
        $response = $this->get('/plugins/sirsoft-tosspayments/payment/fail?'.http_build_query([
            'code' => 'USER_CANCEL',
            'message' => '사용자가 취소했습니다.',
            'orderId' => 'ORD-TEST-12345',
        ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('error=USER_CANCEL', $location);
        $this->assertStringContainsString('orderId=ORD-TEST-12345', $location);
    }

    /**
     * 결제 실패 시 주문이 존재하면 failPayment 처리되는지 확인
     */
    /**
     * 실패 콜백은 주문 상태를 바꾸지 않는다.
     *
     * 계약 변경(KVE-2026-2018 형제): 이 엔드포인트는 인증도 서명도 없는 GET 이고
     * `orderId`·`code` 가 전부 쿼리스트링에서 온다. 실패 처리를 수행하면 링크 하나로
     * 남의 결제대기 주문을 취소시킬 수 있다. 결제 성립은 `success()` 의 서버 confirm 이,
     * 결제완료 후 취소는 서명 검증된 웹훅이 담당한다.
     */
    public function test_fail_does_not_mutate_the_order(): void
    {
        $order = $this->createTestOrder(30000);
        $statusBefore = $order->order_status;

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/fail?'.http_build_query([
            'code' => 'PAY_PROCESS_CANCELED',
            'message' => '결제가 취소되었습니다.',
            'orderId' => $order->order_number,
        ]));

        $response->assertRedirect();

        $order->refresh();
        $this->assertEquals($statusBefore, $order->order_status, '비인증 실패 콜백이 주문 상태를 바꿨습니다.');
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertArrayNotHasKey('payment_failure_code', $order->order_meta ?? []);
    }

    /**
     * 제3자가 링크 하나로 타인의 결제대기 주문을 취소할 수 없다 (KVE-2026-2018 형제 회귀).
     */
    public function test_unauthenticated_fail_link_cannot_cancel_another_users_order(): void
    {
        $victimOrder = $this->createTestOrder(30000);

        // 공격자는 로그인하지 않고 피해자의 주문번호만 실어 이 URL 을 연다.
        $response = $this->get('/plugins/sirsoft-tosspayments/payment/fail?'.http_build_query([
            'code' => 'PAY_PROCESS_CANCELED',
            'message' => 'forged',
            'orderId' => $victimOrder->order_number,
        ]));

        $response->assertRedirect();

        $victimOrder->refresh();
        $this->assertEquals(
            OrderStatusEnum::PENDING_ORDER,
            $victimOrder->order_status,
            '무인증 GET 하나로 피해자의 주문이 취소되었습니다.'
        );
        $this->assertNotEquals(PaymentStatusEnum::FAILED, $victimOrder->payment->refresh()->payment_status);
    }

    /**
     * 결제 실패 시 orderId 없이도 에러 없이 처리되는지 확인
     */
    public function test_fail_handles_missing_order_id_gracefully(): void
    {
        $response = $this->get('/plugins/sirsoft-tosspayments/payment/fail?'.http_build_query([
            'code' => 'UNKNOWN_ERROR',
            'message' => '알 수 없는 오류',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=UNKNOWN_ERROR', $response->headers->get('Location'));
    }

    // ===== 커스텀 리다이렉트 URL 테스트 =====

    /**
     * 커스텀 성공 URL 설정 시 해당 URL로 리다이렉트하는지 확인
     */
    public function test_success_redirects_to_custom_success_url(): void
    {
        $order = $this->createTestOrder(50000);
        $paymentKey = 'pk_test_custom';

        $this->mockPluginSettings([
            'redirect_success_url' => '/custom/payment/{orderId}/done',
        ]);

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response(
                $this->makeMockConfirmResponse($paymentKey, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $order->order_number,
            'amount' => 50000,
        ]));

        $response->assertRedirect("/custom/payment/{$order->order_number}/done");
    }

    /**
     * 커스텀 실패 URL 설정 시 해당 URL로 리다이렉트하는지 확인
     */
    public function test_fail_redirects_to_custom_fail_url(): void
    {
        $this->mockPluginSettings([
            'redirect_fail_url' => '/custom/checkout/error',
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/fail?'.http_build_query([
            'code' => 'USER_CANCEL',
            'message' => '사용자가 취소했습니다.',
            'orderId' => 'ORD-TEST-99999',
        ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/custom/checkout/error?', $location);
        $this->assertStringContainsString('error=USER_CANCEL', $location);
        $this->assertStringContainsString('orderId=ORD-TEST-99999', $location);
    }

    /**
     * 커스텀 실패 URL 설정 시 성공 콜백 내 오류에서도 해당 URL로 리다이렉트하는지 확인
     */
    public function test_success_redirects_to_custom_fail_url_on_order_not_found(): void
    {
        $this->mockPluginSettings([
            'redirect_fail_url' => '/custom/checkout/error',
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => 'pk_test_xxx',
            'orderId' => 'NON_EXISTENT_ORDER',
            'amount' => 50000,
        ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/custom/checkout/error?', $location);
        $this->assertStringContainsString('error=order_not_found', $location);
    }

    /**
     * 전체 URL + 기존 쿼리 파라미터가 있는 실패 URL에서 & 구분자로 연결되는지 확인
     */
    public function test_fail_redirects_to_full_url_with_existing_query_params(): void
    {
        $this->mockPluginSettings([
            'redirect_fail_url' => 'https://example.com/checkout?ref=toss',
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/fail?'.http_build_query([
            'code' => 'NETWORK_ERROR',
            'message' => '네트워크 오류',
            'orderId' => 'ORD-TEST-88888',
        ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://example.com/checkout?ref=toss&', $location);
        $this->assertStringContainsString('error=NETWORK_ERROR', $location);
        $this->assertStringContainsString('orderId=ORD-TEST-88888', $location);
    }

    // ===== FormRequest 검증 테스트 =====

    /**
     * 성공 콜백에 필수 파라미터 누락 시 실패 페이지로 리다이렉트하는지 확인
     */
    public function test_success_redirects_to_fail_url_on_missing_params(): void
    {
        $this->mockPluginSettings();

        // paymentKey 누락
        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'orderId' => 'ORD-TEST-12345',
            'amount' => 50000,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

    /**
     * 성공 콜백에 금액이 0 이하일 때 실패 페이지로 리다이렉트하는지 확인
     */
    public function test_success_redirects_to_fail_url_on_invalid_amount(): void
    {
        $this->mockPluginSettings();

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => 'pk_test_xxx',
            'orderId' => 'ORD-TEST-12345',
            'amount' => 0,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

    /**
     * 성공 콜백에 파라미터가 모두 없으면 실패 페이지로 리다이렉트하는지 확인
     */
    public function test_success_redirects_to_fail_url_on_empty_params(): void
    {
        $this->mockPluginSettings();

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success');

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

    // ===== 디바이스 감지 테스트 =====

    /**
     * 모바일 User-Agent로 요청 시 payment_device가 mobile인지 확인
     */
    public function test_success_detects_mobile_device(): void
    {
        $order = $this->createTestOrder(50000);
        $paymentKey = 'pk_test_mobile';

        $this->mockPluginSettings();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response(
                $this->makeMockConfirmResponse($paymentKey, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->get(
            '/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
                'paymentKey' => $paymentKey,
                'orderId' => $order->order_number,
                'amount' => 50000,
            ]),
            ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)']
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('mobile', $payment->payment_device);
    }

    // ===== 가상계좌(WAITING_FOR_DEPOSIT) 분기 =====

    /**
     * confirm 응답이 WAITING_FOR_DEPOSIT 이면 completePayment 하지 않고 계좌 정보만 저장한다.
     */
    public function test_success_virtual_account_stores_vbank_without_completing(): void
    {
        $order = $this->createTestOrder(50000);
        $paymentKey = 'pk_test_vbank';
        $this->mockPluginSettings();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => $paymentKey,
                'orderId' => $order->order_number,
                'status' => 'WAITING_FOR_DEPOSIT',
                'method' => '가상계좌',
                'totalAmount' => 50000,
                'secret' => 'wsec_confirm_secret',
                'virtualAccount' => [
                    'accountNumber' => '12345678901234',
                    'bankCode' => '20',
                    'bank' => '우리은행',
                    'customerName' => '홍길동',
                    'dueDate' => now()->addDay()->toIso8601String(),
                    'useEscrow' => false,
                ],
            ], 200),
        ]);

        $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $order->order_number,
            'amount' => 50000,
        ]));

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $payment = $order->payment;

        // 결제완료로 전이되지 않음
        $this->assertNotSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(PaymentStatusEnum::WAITING_DEPOSIT->value, $payment->payment_status->value);
        // 계좌 정보 저장
        $this->assertSame('12345678901234', $payment->vbank_number);
        $this->assertSame('20', $payment->vbank_code);
        // 웹훅 secret 저장 (대조용)
        $this->assertSame('wsec_confirm_secret', $payment->payment_meta['toss_secret']);
    }

    /**
     * 가상계좌 응답의 useEscrow 가 is_escrow 스냅샷으로 저장된다.
     */
    public function test_success_virtual_account_persists_escrow_flag(): void
    {
        $order = $this->createTestOrder(50000);
        $paymentKey = 'pk_test_vbank_escrow';
        $this->mockPluginSettings();

        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response([
                'paymentKey' => $paymentKey,
                'orderId' => $order->order_number,
                'status' => 'WAITING_FOR_DEPOSIT',
                'method' => '가상계좌',
                'totalAmount' => 50000,
                'secret' => 'wsec_x',
                'virtualAccount' => [
                    'accountNumber' => '999',
                    'bankCode' => '20',
                    'useEscrow' => true,
                ],
            ], 200),
        ]);

        $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
            'paymentKey' => $paymentKey,
            'orderId' => $order->order_number,
            'amount' => 50000,
        ]))->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $this->assertTrue((bool) $order->payment->is_escrow);
    }
}
