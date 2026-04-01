<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 토스페이먼츠 결제 콜백 컨트롤러 기능 테스트
 */
class PaymentCallbackControllerTest extends PluginTestCase
{
    /**
     * 토스페이먼츠 Confirm API 성공 응답 mock 데이터
     *
     * @param string $paymentKey 결제 키
     * @param string $orderId 주문번호
     * @param int $amount 결제 금액
     * @return array
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
     * @param int $totalAmount 주문 총액
     * @return Order
     */
    private function createTestOrder(int $totalAmount = 50000): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-' . random_int(10000, 99999),
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
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'tosspayments',
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
     * @param array $overrides 기본 설정을 덮어쓸 값
     */
    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_mock_key',
            'test_client_key' => 'test_ck_mock_key',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];

        $settingsMock = $this->createMock(\App\Services\PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $overrides));

        $this->app->instance(\App\Services\PluginSettingsService::class, $settingsMock);
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

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/success?" . http_build_query([
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

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/success?" . http_build_query([
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

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/success?" . http_build_query([
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

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/success?" . http_build_query([
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
        $response = $this->get("/plugins/sirsoft-tosspayments/payment/fail?" . http_build_query([
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
    public function test_fail_calls_fail_payment_when_order_exists(): void
    {
        $order = $this->createTestOrder(30000);

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/fail?" . http_build_query([
            'code' => 'PAY_PROCESS_CANCELED',
            'message' => '결제가 취소되었습니다.',
            'orderId' => $order->order_number,
        ]));

        $response->assertRedirect();

        // 주문 상태가 CANCELLED로 변경되었는지 확인
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);

        // 주문 메타에 실패 정보가 저장되었는지 확인
        $meta = $order->order_meta;
        $this->assertEquals('PAY_PROCESS_CANCELED', $meta['payment_failure_code']);
    }

    /**
     * 결제 실패 시 orderId 없이도 에러 없이 처리되는지 확인
     */
    public function test_fail_handles_missing_order_id_gracefully(): void
    {
        $response = $this->get("/plugins/sirsoft-tosspayments/payment/fail?" . http_build_query([
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

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/success?" . http_build_query([
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

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/fail?" . http_build_query([
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

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/success?" . http_build_query([
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

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/fail?" . http_build_query([
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
        $response = $this->get("/plugins/sirsoft-tosspayments/payment/success?" . http_build_query([
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

        $response = $this->get("/plugins/sirsoft-tosspayments/payment/success?" . http_build_query([
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
            "/plugins/sirsoft-tosspayments/payment/success?" . http_build_query([
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
}
