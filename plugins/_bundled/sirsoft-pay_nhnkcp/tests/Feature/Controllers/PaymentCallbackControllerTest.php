<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class PaymentCallbackControllerTest extends PluginTestCase
{
    private const TEST_SITE_CD = 'T0000';

    private const TEST_SITE_KEY = 'TEST_SITE_KEY_0000';

    // ===== 헬퍼 =====

    /**
     * KCP CLI 서버 승인 응답을 흉내 낸 배열을 만든다.
     *
     * @param  string  $tno  거래번호
     * @param  string  $ordrIdxx  주문번호
     * @param  int  $amount  승인 금액
     * @param  string  $resCd  응답 코드 (기본값 0000 은 정상 승인)
     * @return array<string, mixed> CLI 승인 응답
     */
    private function makeCliResponse(string $tno, string $ordrIdxx, int $amount, string $resCd = '0000'): array
    {
        return [
            'res_cd' => $resCd,
            'res_msg' => $resCd === '0000' ? '정상처리' : '승인실패',
            'tno' => $tno,
            'ordr_idxx' => $ordrIdxx,
            'good_mny' => $amount,
            // 서버-서버 승인 응답의 승인 금액 — 결제완료 확정의 유일한 금액 근거
            'amount' => (string) $amount,
            'app_no' => 'APP12345',
            'card_no' => '4330****1234',
            'card_name' => '신한카드',
            'quota' => '00',
            'use_pay_method' => 'CARD',
            'app_time' => now()->format('YmdHis'),
        ];
    }

    /**
     * 결제 대기 상태의 KRW 주문과 결제 준비(READY) 상태의 KCP 결제 건을 만든다.
     *
     * @param  int  $totalAmount  주문 총액
     * @param  array{taxable?: int, vat?: int, taxFree?: int}  $tax  과세·부가세·비과세 금액 (생략 시 전액 과세)
     * @param  PaymentMethodEnum  $paymentMethod  결제수단
     * @return Order 생성된 주문
     */
    private function createTestOrder(
        int $totalAmount = 50000,
        array $tax = [],
        PaymentMethodEnum $paymentMethod = PaymentMethodEnum::CARD,
    ): Order {
        $taxable = $tax['taxable'] ?? $totalAmount;
        $vat = $tax['vat'] ?? (int) round($taxable * 10 / 110);
        $taxFree = $tax['taxFree'] ?? 0;

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
            'total_tax_amount' => $taxable,
            'total_vat_amount' => $vat,
            'total_tax_free_amount' => $taxFree,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => $paymentMethod,
            'pg_provider' => 'nhnkcp',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'card_approval_number' => null,
        ]);

        return $order;
    }

    /**
     * KRW 단일 통화 주문의 통화 스냅샷을 돌려준다.
     *
     * @return array<string, mixed> 통화 스냅샷
     */
    private static function krwCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    /**
     * USD 환율이 0 이라 결제 금액을 환산할 수 없는 통화 스냅샷을 돌려준다.
     *
     * @return array<string, mixed> 통화 스냅샷
     */
    private static function invalidUsdCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1000,
                ],
                'USD' => [
                    'rate' => 0,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
                    'decimal_places' => 2,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    /**
     * 테스트 모드 site_cd 와 리다이렉트 경로를 담은 플러그인 설정을 mock 으로 주입한다.
     *
     * @param  array<string, mixed>  $overrides  기본값을 덮어쓸 설정
     * @return void 반환값 없음
     */
    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode' => true,
            'test_site_cd' => self::TEST_SITE_CD,
            'test_site_key' => self::TEST_SITE_KEY,
            'live_site_cd' => '',
            'live_site_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];

        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn(array_merge($defaults, $overrides));
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * NhnKcpApiService::approvePayment() 를 exec() 없이 mock.
     * 기존 테스트는 Http::fake()로 CLI exec를 막으려 했으나 동작하지 않음.
     *
     * @param  array<string, mixed>  $cliResponse  approvePayment 가 돌려줄 응답
     * @param  int|null  $approveCalls  기대 호출 횟수 (null 이면 횟수를 검증하지 않음)
     * @return void 반환값 없음
     */
    private function mockApiService(array $cliResponse, ?int $approveCalls = null): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $expectation = $approveCalls === null
            ? $mock->method('approvePayment')
            : $mock->expects($this->exactly($approveCalls))->method('approvePayment');
        $expectation->willReturn($cliResponse);
        $this->app->instance(NhnKcpApiService::class, $mock);
    }

    /**
     * 결제창이 인증 콜백으로 보내는 기본 파라미터를 만든다.
     *
     * @param  string  $ordrIdxx  주문번호
     * @param  int  $goodMny  결제창 금액
     * @param  array<string, mixed>  $overrides  덮어쓸 파라미터
     * @return array<string, mixed> 콜백 파라미터
     */
    private function makeCallbackParams(string $ordrIdxx, int $goodMny, array $overrides = []): array
    {
        return array_merge([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => 'KCP_TNO_'.uniqid(),
            'ordr_idxx' => $ordrIdxx,
            'good_mny' => $goodMny,
            'enc_data' => base64_encode('encrypted_payment_data'),
            'enc_info' => base64_encode('encrypted_info'),
            'use_pay_method' => 'CARD',
        ], $overrides);
    }

    /**
     * 주문의 결제 건을 KCP 가 가상계좌를 발급한 상태로 만든다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $tno  발급 거래번호
     * @param  string  $account  발급 계좌번호
     * @return void 반환값 없음
     */
    private function markVbankIssued(
        Order $order,
        string $tno = 'KCP_VBANK_TNO_ISSUED',
        string $account = 'T1234567890',
    ): void {
        $order->payment->update([
            'transaction_id' => $tno,
            'vbank_name' => '테스트은행',
            'vbank_number' => $account,
            'vbank_holder' => 'NHN KCP',
            'vbank_issued_at' => now(),
            'payment_meta' => [
                'tno' => $tno,
                'site_cd' => self::TEST_SITE_CD,
                'is_test_mode' => true,
                'pg_response_sanitized' => true,
                'pg_raw_response' => [
                    'res_cd' => 'V000',
                    'tno' => $tno,
                    'bankname' => '테스트은행',
                ],
            ],
        ]);
    }

    // ===== 성공 콜백 =====

    /**
     * 정상 카드 승인 콜백은 주문을 결제완료로 바꾸고 거래번호·승인번호를 기록한 뒤 완료 페이지로 보낸다.
     *
     * @return void 반환값 없음
     *
     * @effects normal_approval_still_completes_the_order
     */
    public function test_auth_callback_redirects_to_complete_page_on_valid_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_'.uniqid();
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 50000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tno, $payment->transaction_id);
        $this->assertEquals('APP12345', $payment->card_approval_number);
    }

    /**
     * 간편결제로 승인되면 간편결제 제공자와 표시 이름을 결제 건 메타에 남긴다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_stores_easy_pay_method_meta
     */
    public function test_auth_callback_stores_easy_pay_method_meta(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_NAVERPAY';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 50000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000, [
                'tno' => $tno,
                'param_opt_1' => 'nhnkcp_naverpay',
                'nhnkcp_easy_pay_method' => 'nhnkcp_naverpay',
            ])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();
        $meta = $payment->payment_meta;

        $this->assertSame('naverpay', $payment->embedded_pg_provider);
        $this->assertSame('nhnkcp_naverpay', $meta['nhnkcp_easy_pay_method'] ?? null);
        $this->assertSame('naverpay', $meta['nhnkcp_easy_pay_provider'] ?? null);
        $this->assertSame('네이버페이', $meta['nhnkcp_easy_pay_label']['ko'] ?? null);
        $this->assertSame('NaverPay', $meta['nhnkcp_easy_pay_label']['en'] ?? null);
    }

    /**
     * 이미 결제완료된 주문에 콜백이 다시 오면 CLI 승인을 호출하지 않고 기존 거래 기록을 유지한다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_skips_cli_approval_for_already_paid_order
     */
    public function test_auth_callback_skips_cli_approval_when_order_already_paid(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $existingTno = 'KCP_TNO_ALREADY_PAID';
        $order->update([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'paid_at' => now(),
            'total_paid_amount' => 50000,
            'total_due_amount' => 0,
        ]);
        $order->payment->update([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_at' => now(),
            'paid_amount_local' => 50000,
            'paid_amount_base' => 50000,
            'transaction_id' => $existingTno,
            'card_approval_number' => 'APP_ALREADY',
        ]);

        $this->mockApiService($this->makeCliResponse('KCP_TNO_SHOULD_NOT_APPROVE', $order->order_number, 50000), 0);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000, ['tno' => $existingTno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();
        $this->assertSame($existingTno, $payment->transaction_id);
        $this->assertSame('APP_ALREADY', $payment->card_approval_number);
    }

    /**
     * 결제창 금액이 주문 금액과 다르면 CLI 승인을 호출하지 않고 amount_mismatch 로 돌려보낸다.
     *
     * @return void 반환값 없음
     *
     * @effects pre_approval_amount_mismatch_does_not_mutate_the_order
     */
    public function test_auth_callback_rejects_browser_amount_mismatch_before_cli_approval(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->expects($this->never())->method('approvePayment');
        $this->app->instance(NhnKcpApiService::class, $mock);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 1)
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * KRW 가 아닌 주문은 CLI 승인 전에 currency_not_supported 로 거절하고 주문을 그대로 둔다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_rejects_non_krw_order_before_cli_approval
     */
    public function test_auth_callback_rejects_non_krw_order_before_cli_approval(): void
    {
        $order = $this->createTestOrder(50000);
        $order->currency = 'USD';
        $order->save();
        $this->mockPluginSettings();

        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->expects($this->never())->method('approvePayment');
        $this->app->instance(NhnKcpApiService::class, $mock);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000)
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=currency_not_supported', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 환산할 수 없는 결제 통화의 주문은 CLI 승인 전에 invalid_payment_currency 로 거절하고 주문·결제 상태를 그대로 둔다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_rejects_invalid_payment_currency_before_cli_approval
     */
    public function test_auth_callback_rejects_invalid_payment_currency_before_cli_approval(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update([
            'currency' => 'USD',
            'currency_snapshot' => self::invalidUsdCurrencySnapshot(),
        ]);
        $this->mockPluginSettings();

        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->expects($this->never())->method('approvePayment');
        $this->app->instance(NhnKcpApiService::class, $mock);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000)
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_payment_currency', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
    }

    /**
     * 승인 응답 원문은 허용된 필드만 남기고 카드번호·구매자 정보 같은 민감 필드는 저장하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_stores_only_sanitized_pg_response_fields
     */
    public function test_auth_callback_stores_only_sanitized_pg_response_fields(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_SANITIZED';
        $this->mockApiService(array_merge(
            $this->makeCliResponse($tno, $order->order_number, 50000),
            [
                'buyr_name' => '홍길동',
                'buyr_mail' => 'buyer@example.test',
                'buyr_tel1' => '01012345678',
                'account' => '1234567890123456',
                'unexpected_sensitive' => 'store-me-not',
            ]
        ));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame($tno, $meta['pg_raw_response']['tno'] ?? null);
        $this->assertSame('APP12345', $meta['pg_raw_response']['app_no'] ?? null);
        $this->assertArrayNotHasKey('card_no', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('buyr_name', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('buyr_mail', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('buyr_tel1', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('account', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('unexpected_sensitive', $meta['pg_raw_response']);
    }

    // ===== 과세/비과세 시나리오 =====

    /**
     * 전액 과세 주문의 승인 콜백이 결제완료로 끝난다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_completes_order_regardless_of_tax_composition
     */
    public function test_fully_taxable_order_payment_completes(): void
    {
        // 11,000원 = 공급가 10,000 + 부가세 1,000 (전액 과세)
        $amount = 11000;
        $vat = (int) round($amount * 10 / 110); // 1,000
        $order = $this->createTestOrder($amount, ['taxable' => $amount, 'vat' => $vat, 'taxFree' => 0]);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_TAXABLE';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, $amount));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, $amount, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tno, $payment->transaction_id);
    }

    /**
     * 전액 비과세 주문의 승인 콜백이 결제완료로 끝나고 거래번호가 기록된다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_completes_order_regardless_of_tax_composition
     */
    public function test_fully_tax_free_order_payment_completes(): void
    {
        // 10,000원 전액 비과세 (도서, 농산물, 의료 등 면세 상품)
        $amount = 10000;
        $order = $this->createTestOrder($amount, ['taxable' => 0, 'vat' => 0, 'taxFree' => $amount]);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_TAXFREE';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, $amount));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, $amount, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        // 비과세 주문도 paid_amount 가 올바르게 기록돼야 함
        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tno, $payment->transaction_id);
    }

    /**
     * 과세·비과세가 섞인 주문의 승인 콜백이 결제완료로 끝난다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_completes_order_regardless_of_tax_composition
     */
    public function test_mixed_tax_order_payment_completes(): void
    {
        // 21,000원 = 과세 11,000(공급가 10,000 + 부가세 1,000) + 비과세 10,000
        $taxable = 11000;
        $taxFree = 10000;
        $total = $taxable + $taxFree;
        $vat = (int) round($taxable * 10 / 110); // 1,000
        $order = $this->createTestOrder($total, ['taxable' => $taxable, 'vat' => $vat, 'taxFree' => $taxFree]);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_MIXED';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, $total));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, $total, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    // ===== 실패/취소 =====

    /**
     * 결제창 결과 코드가 정상이 아니면 그 코드를 오류로 실어 실패 경로로 보낸다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_redirects_to_fail_with_the_pg_result_code
     */
    public function test_auth_callback_redirects_to_fail_on_res_cd_not_0000(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'res_cd' => '8001',
            'res_msg' => '사용자 취소',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=8001', $response->headers->get('Location'));
    }

    /**
     * 사용자가 결제창을 닫은 취소 코드(3001)는 오류 안내 없이 결제 화면으로 돌려보낸다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_user_cancel_redirects_without_an_error
     */
    public function test_auth_callback_silently_redirects_on_user_cancel_code_3001(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '3001',
            'res_msg' => '사용자취소',
            'ordr_idxx' => 'ORD-TEST-CANCEL',
        ]);

        $response->assertRedirect('/shop/checkout');
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));
    }

    /**
     * 브라우저가 보낸 실패 결과만으로는 주문·결제 상태와 실패 기록을 바꾸지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects browser_result_code_alone_never_transitions_order_state
     */
    public function test_auth_callback_does_not_mutate_order_on_browser_failure_result(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '3001',
            'res_msg' => '사용자취소',
            'ordr_idxx' => $order->order_number,
            'good_mny' => 50000,
        ]);

        $response->assertRedirect('/shop/checkout');
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertNull($order->order_meta['payment_failure_code'] ?? null);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
        $this->assertNull($order->payment->cancelled_at);
        $this->assertSame('nhnkcp', $order->payment->pg_provider);
        $this->assertNull($order->payment->payment_meta['failure_source'] ?? null);
        $this->assertNull($order->payment->payment_meta['failure_code'] ?? null);
        $this->assertNull($order->payment->payment_meta['failure_stage'] ?? null);
    }

    /**
     * 결과 코드가 비어 있는 콜백은 오류 안내 없이 결제 화면으로 돌려보낸다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_empty_result_code_redirects_without_an_error
     */
    public function test_auth_callback_silently_redirects_on_empty_res_cd(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '',
            'ordr_idxx' => 'ORD-TEST-EMPTY',
        ]);

        $response->assertRedirect('/shop/checkout');
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));
    }

    /**
     * 없는 주문번호의 콜백은 order_not_found 로 실패 경로에 보낸다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_redirects_to_fail_on_order_not_found
     */
    public function test_auth_callback_redirects_to_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();
        $this->mockApiService($this->makeCliResponse('TNO_X', 'NON_EXISTENT', 50000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams('NON_EXISTENT_ORDER', 50000)
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_found', $response->headers->get('Location'));
    }

    /**
     * 서버 승인이 실패하면 실패 URL 로 돌아가되 주문 상태는 건드리지 않는다.
     *
     * 계약 변경(KVE-2026-2018): `authCallback` 은 PG 서명도 IP 증명도 없는 비인증 브라우저
     * POST 다. 주문번호는 요청자가 고른 값이라, 승인 실패를 근거로 주문을 취소하면 남의
     * 주문번호와 아무 암호문이나 실어 보내는 것만으로 그 주문을 취소시킬 수 있다.
     * 승인이 성공하지 않은 이상 이 엔드포인트는 상태를 바꾸지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects pre_approval_failure_sink_does_not_call_fail_payment
     */
    public function test_auth_callback_does_not_mutate_the_order_when_cli_approval_fails(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $this->mockApiService($this->makeCliResponse('TNO_FAIL', $order->order_number, 50000, '9999'));

        $statusBefore = $order->order_status;
        $paymentStatusBefore = $order->payment->payment_status;

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000)
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=9999', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals($statusBefore, $order->order_status, '비인증 콜백이 주문 상태를 바꿨습니다.');
        $this->assertEquals($paymentStatusBefore, $payment->payment_status, '비인증 콜백이 결제 상태를 바꿨습니다.');
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertArrayNotHasKey('failure_stage', $payment->payment_meta ?? []);
    }

    /**
     * 브라우저가 보낸 금액이 주문 금액과 다르면 승인 자체를 하지 않고, 주문도 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects pre_approval_amount_mismatch_does_not_mutate_the_order
     */
    public function test_auth_callback_does_not_mutate_the_order_on_browser_amount_mismatch(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_AMOUNT_MISMATCH';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 60000));

        $statusBefore = $order->order_status;
        $paymentStatusBefore = $order->payment->payment_status;

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 60000, ['tno' => $tno])
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals($statusBefore, $order->order_status, '비인증 콜백이 주문 상태를 바꿨습니다.');
        $this->assertEquals($paymentStatusBefore, $payment->payment_status, '비인증 콜백이 결제 상태를 바꿨습니다.');
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 무인증 위조 콜백으로 타인의 결제대기 주문을 취소할 수 없다 (KVE-2026-2018 회귀).
     *
     * 공격자는 로그인하지 않고, 피해자의 주문번호와 아무 암호문만으로 이 엔드포인트를 친다.
     * 서버 승인은 당연히 실패하는데, 그 실패를 피해 주문의 결제 실패로 오인 처리하면
     * 남의 주문이 취소된다.
     *
     * @return void 반환값 없음
     *
     * @effects unauthenticated_callback_cannot_cancel_another_users_order
     */
    public function test_forged_unauthenticated_callback_cannot_cancel_another_users_order(): void
    {
        $victimOrder = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $this->mockApiService($this->makeCliResponse('', $victimOrder->order_number, 0, '6003'));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($victimOrder->order_number, 0, [
                'enc_data' => 'FORGED_CIPHERTEXT',
                'enc_info' => 'FORGED_INFO',
            ])
        );

        $response->assertRedirect();

        $victimOrder->refresh();
        $payment = $victimOrder->payment;
        $payment->refresh();

        $this->assertEquals(
            OrderStatusEnum::PENDING_ORDER,
            $victimOrder->order_status,
            '무인증 위조 콜백이 피해자의 주문을 취소시켰습니다.'
        );
        $this->assertNotEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertArrayNotHasKey('payment_failure_code', $victimOrder->order_meta ?? []);
    }

    /**
     * 카드 주문에 use_pay_method=VCNT 를 실어 가상계좌 분기로 우회하는 통로 차단.
     *
     * 가상계좌 모바일 분기는 enc_data/enc_info 가 비면 PG 서버를 호출하지 않고 콜백 평문을
     * 그대로 승인 응답으로 취급한다. 분기 판정이 요청값을 보면 공격자가 카드 주문을 그 경로로
     * 몰아, 은행명·계좌번호를 비운 것만으로 "발급 실패" 를 만들어 남의 주문을 취소시킬 수 있다.
     *
     * @return void 반환값 없음
     *
     * @effects forged_vbank_callback_cannot_cancel_a_card_order,
     *   payment_method_branch_is_decided_by_stored_order_value_only
     */
    public function test_forged_vbank_routed_callback_cannot_cancel_a_card_order(): void
    {
        $victimOrder = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            [
                'res_cd' => '0000',
                'ordr_idxx' => $victimOrder->order_number,
                'tno' => 'FORGED',
                // 금액을 비워 승인 전 금액 검증을 건너뛴다
                // enc_data/enc_info 를 비워 CLI 호출 자체를 우회한다
                // bankname/account 를 비워 "발급 실패" 판정을 유도한다
                'use_pay_method' => 'VCNT',
            ]
        );

        $response->assertRedirect();

        $victimOrder->refresh();
        $payment = $victimOrder->payment;
        $payment->refresh();

        $this->assertEquals(
            OrderStatusEnum::PENDING_ORDER,
            $victimOrder->order_status,
            '위조 콜백이 use_pay_method=VCNT 우회로 피해자의 카드 주문을 취소시켰습니다.'
        );
        $this->assertNotEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertArrayNotHasKey('payment_failure_code', $victimOrder->order_meta ?? []);
    }

    /**
     * 분기 판정이 주문의 결제수단만 본다는 계약 (요청값으로 경로를 고를 수 없다).
     *
     * 카드 주문에 VCNT + 위조 발급정보를 실어도 가상계좌 발급으로 기록되지 않아야 한다.
     * 요청값으로 분기가 갈리면 공격자가 피해자 카드 주문에 임의 계좌번호를 심을 수 있다.
     *
     * @return void 반환값 없음
     *
     * @effects request_pay_method_cannot_route_a_card_order_into_vbank_branch,
     *   forged_vbank_callback_cannot_write_an_account_number_to_the_order
     */
    public function test_request_pay_method_cannot_route_a_card_order_into_the_vbank_branch(): void
    {
        $victimOrder = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $this->mockApiService($this->makeCliResponse('', $victimOrder->order_number, 0, '6003'));

        $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            [
                'res_cd' => '0000',
                'ordr_idxx' => $victimOrder->order_number,
                'tno' => 'FORGED',
                'use_pay_method' => 'VCNT',
                'bankname' => '공격자은행',
                'account' => '110-000-999999',
                'depositor' => '공격자',
            ]
        );

        $payment = $victimOrder->payment;
        $payment->refresh();

        $this->assertNull(
            $payment->vbank_number,
            '요청의 use_pay_method 로 카드 주문이 가상계좌 분기로 흘러 위조 계좌가 기록됐습니다.'
        );
        $this->assertNull($payment->vbank_name);
    }

    /**
     * 주문번호 같은 필수 파라미터가 빠진 콜백은 invalid_params 로 실패 경로에 보낸다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_redirects_to_fail_on_missing_params
     */
    public function test_auth_callback_redirects_to_fail_url_on_missing_params(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '0000',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

    /**
     * 설정한 성공 리다이렉트 경로에 주문번호를 채워 보낸다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_redirects_to_custom_success_url
     */
    public function test_auth_callback_redirects_to_custom_success_url(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings(['redirect_success_url' => '/custom/payment/{orderId}/done']);

        $tno = 'KCP_TNO_CUSTOM';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 50000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno])
        );

        $response->assertRedirect("/custom/payment/{$order->order_number}/done");
    }

    /**
     * 모바일 User-Agent 로 들어온 승인은 결제 기기를 mobile 로 기록한다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_auth_callback_records_mobile_payment_device
     */
    public function test_auth_callback_detects_mobile_device(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_MOBILE';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 50000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno]),
            ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)']
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('mobile', $payment->payment_device);
    }

    // ===== 가상계좌 입금 통보 (KCP 공식 webhook 페이로드) =====
    //
    // 페이로드 키 (그누보드5 settle_kcp_common.php 참고):
    //   tx_cd=TX00 + op_cd=50 (입금완료) / 01 (재전송) / 13 (망취소)
    //   tno, order_no, ipgm_mnyx, ipgm_name, remitter, bank_code, account, noti_id
    //
    // 응답: <form><input name="result" value="0000"> HTML
    //   - result=0000 → KCP 가 통보 성공으로 인정 (재시도 차단)
    //   - 그 외 → KCP 재통보 (최대 10회)

    /**
     * 입금 통보 응답이 KCP 가 성공으로 인정하는 result=0000 인지 확인한다.
     *
     * @param  TestResponse  $response  통보 응답
     * @return void 반환값 없음
     */
    private function assertKcpNotifyOk(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
        $this->assertStringContainsString('value="0000"', $response->getContent());
    }

    /**
     * 입금 통보 응답이 0000 이 아니어서 KCP 가 다시 통보하게 되는지 확인한다.
     *
     * @param  TestResponse  $response  통보 응답
     * @return void 반환값 없음
     */
    private function assertKcpNotifyRetry(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
        $this->assertStringNotContainsString('value="0000"', $response->getContent());
    }

    /**
     * KCP 공식 키로 된 가상계좌 입금 통보 페이로드를 만든다.
     *
     * @param  string  $orderNo  주문번호
     * @param  int  $amount  입금 금액
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> 통보 페이로드
     */
    private function makeVbankNotifyPayload(string $orderNo, int $amount, array $overrides = []): array
    {
        return array_merge([
            'site_cd' => 'T0000',
            'tno' => 'KCP_VBANK_TNO_'.uniqid(),
            'order_no' => $orderNo,
            'tx_cd' => 'TX00',
            'tx_tm' => now()->format('YmdHis'),
            'op_cd' => '50',
            'ipgm_mnyx' => $amount,
            'ipgm_name' => '홍길동',
            'remitter' => '홍길동',
            'bank_code' => 'BK04',
            'account' => 'T1234567890',
            'noti_id' => uniqid('NOTI_'),
        ], $overrides);
    }

    /**
     * KCP 공식 발신 IP 로 vbank-notify POST.
     * RestrictKcpIp 미들웨어가 항상 IP 검증하므로 화이트리스트 IP 필수.
     *
     * @param  array<string, mixed>  $payload  통보 페이로드
     * @param  string  $kcpIp  발신 IP (기본값은 KCP 공식 발신 IP)
     * @return TestResponse 통보 응답
     */
    private function postVbankNotify(array $payload, string $kcpIp = '203.238.36.58'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $kcpIp])
            ->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', $payload);
    }

    /**
     * 발급된 가상계좌의 입금완료 통보(op_cd=50)는 주문을 결제완료로 바꾸고 0000 으로 답한다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_completes_payment_on_deposit
     */
    public function test_vbank_notify_completes_payment_on_op_cd_50(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_TNO_50');

        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'op_cd' => '50',
                'tno' => 'KCP_VBANK_TNO_50',
            ])
        );

        $this->assertKcpNotifyOk($response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 멱등성 — op_cd=01 (재전송) 시 이미 결제완료 상태이면 0000 응답으로 차단.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_resend_is_acknowledged_idempotently
     */
    public function test_vbank_notify_idempotent_on_op_cd_01_resend(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_TNO_RESEND');

        // 첫 통보 — 입금완료
        $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'op_cd' => '50',
                'tno' => 'KCP_VBANK_TNO_RESEND',
            ])
        );

        // 두 번째 — 재전송 (op_cd=01), 동일 noti_id 가정
        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'op_cd' => '01',
                'tno' => 'KCP_VBANK_TNO_RESEND',
            ])
        );

        $this->assertKcpNotifyOk($response);
    }

    /**
     * KCP testadmin 모의입금 통보는 op_cd=18 을 발신 (KCP 공식 문서엔 명시 없음, 실제 운영 확인).
     * 그누보드5 와 동일하게 op_cd=13 (망취소) 외에는 모두 입금완료 처리.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_completes_payment_on_deposit
     */
    public function test_vbank_notify_completes_payment_on_op_cd_18_testadmin(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_TNO_TESTADMIN');

        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'tno' => 'KCP_VBANK_TNO_TESTADMIN',
                'op_cd' => '18',
                'ipgm_stat' => 'STIY',
            ])
        );

        $this->assertKcpNotifyOk($response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 망취소 — op_cd=13 시 로깅만 하고 0000 응답 (정책 미정, 자동 취소 안 함).
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_network_cancel_is_logged_without_completion
     */
    public function test_vbank_notify_logs_op_cd_13_cancel(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_TNO_CANCELLED');

        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'op_cd' => '13',
                'tno' => 'KCP_VBANK_TNO_CANCELLED',
            ])
        );

        $this->assertKcpNotifyOk($response);

        $order->refresh();
        // 망취소는 결제완료로 전환하지 않음
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * tx_cd 가 TX00 외 (예: TX01 환불, TX02 구매확인) 일 때 무시 + 0000 응답.
     * KCP 가 단일 webhook URL 로 모든 tx_cd 를 보낼 가능성에 대비.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_ignores_non_deposit_transaction_codes
     */
    public function test_vbank_notify_ignores_non_tx00(): void
    {
        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload('ANY-ORDER', 30000, ['tx_cd' => 'TX01'])
        );

        $this->assertKcpNotifyOk($response);
    }

    /**
     * 주문 없음 — 영구 실패라 재시도 의미 없음, 0000 응답으로 KCP 재통보 차단.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_acknowledges_unknown_order
     */
    public function test_vbank_notify_returns_ok_on_order_not_found(): void
    {
        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload('NON_EXISTENT_ORDER', 30000)
        );

        $this->assertKcpNotifyOk($response);
    }

    /**
     * 회귀: 그누보드5 settle_kcp_common.php 와 동일한 KCP 공식 페이로드(order_no/ipgm_mnyx/op_cd)
     * 를 사용해야 함. 옛 키(ordr_idxx/good_mny/res_cd) 사용 시 FormRequest 단계 422 차단.
     *
     * 본 테스트는 실제 KCP 가 보낼 페이로드가 정상 처리됨을 보장.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_accepts_the_official_payload_keys
     */
    public function test_vbank_notify_accepts_kcp_official_payload(): void
    {
        $order = $this->createTestOrder(50000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_TNO_REAL', 'T9876543210');

        $response = $this->postVbankNotify([
            'site_cd' => 'T0000',
            'tno' => 'KCP_TNO_REAL',
            'order_no' => $order->order_number,
            'tx_cd' => 'TX00',
            'tx_tm' => '20260514120000',
            'op_cd' => '50',
            'ipgm_mnyx' => 50000,
            'ipgm_name' => '홍길동',
            'remitter' => '실입금자',
            'bank_code' => '04',
            'account' => 'T9876543210',
            'noti_id' => '26051412000018046532',
        ]);

        $this->assertKcpNotifyOk($response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 입금 통보 원문은 허용된 필드만 남기고 입금자·계좌번호 같은 민감 필드는 저장하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_stores_only_sanitized_pg_response_fields
     */
    public function test_vbank_notify_stores_only_sanitized_pg_response_fields(): void
    {
        $order = $this->createTestOrder(50000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_TNO_SANITIZED', 'T9876543210');

        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 50000, [
                'tno' => 'KCP_VBANK_TNO_SANITIZED',
                'ipgm_name' => '실입금자',
                'remitter' => '송금자',
                'account' => 'T9876543210',
                'unexpected_sensitive' => 'store-me-not',
            ])
        );

        $this->assertKcpNotifyOk($response);

        $payment = $order->payment;
        $payment->refresh();

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame('KCP_VBANK_TNO_SANITIZED', $meta['pg_raw_response']['tno'] ?? null);
        $this->assertSame($order->order_number, $meta['pg_raw_response']['order_no'] ?? null);
        $this->assertArrayNotHasKey('ipgm_name', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('remitter', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('account', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('unexpected_sensitive', $meta['pg_raw_response']);
    }

    /**
     * KCP 발급 기록이 없는 결제 건의 입금 통보는 결제완료로 바꾸지 않고 재통보를 요청한다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_retries_when_issuance_was_not_recorded
     */
    public function test_vbank_notify_retries_when_payment_was_not_issued_by_kcp(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);

        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'tno' => 'ATTACKER_TNO_WITHOUT_ISSUE',
            ])
        );

        $this->assertKcpNotifyRetry($response);

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 발급 거래번호와 다른 거래번호의 입금 통보는 결제완료로 바꾸지 않고 재통보를 요청한다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_retries_on_tno_mismatch
     */
    public function test_vbank_notify_retries_on_tno_mismatch(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_REAL_TNO');

        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'tno' => 'ATTACKER_TNO',
            ])
        );

        $this->assertKcpNotifyRetry($response);

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 발급 계좌와 다른 계좌의 입금 통보는 결제완료로 바꾸지 않고 재통보를 요청한다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_retries_on_account_mismatch
     */
    public function test_vbank_notify_retries_on_account_mismatch(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_REAL_TNO', 'T1234567890');

        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'tno' => 'KCP_VBANK_REAL_TNO',
                'account' => 'T9999999999',
            ])
        );

        $this->assertKcpNotifyRetry($response);

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 발급 당시와 다른 site_cd 의 입금 통보는 결제완료로 바꾸지 않고 재통보를 요청한다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_retries_on_site_cd_mismatch
     */
    public function test_vbank_notify_retries_on_site_cd_mismatch(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_REAL_TNO', 'T1234567890');

        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'site_cd' => 'T9999',
                'tno' => 'KCP_VBANK_REAL_TNO',
            ])
        );

        $this->assertKcpNotifyRetry($response);

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * site_cd 가 빠진 입금 통보는 결제완료로 바꾸지 않고 재통보를 요청한다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_retries_on_missing_site_cd
     */
    public function test_vbank_notify_retries_on_missing_site_cd(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_REAL_TNO', 'T1234567890');

        $payload = $this->makeVbankNotifyPayload($order->order_number, 30000, [
            'tno' => 'KCP_VBANK_REAL_TNO',
        ]);
        unset($payload['site_cd']);

        $response = $this->postVbankNotify($payload);

        $this->assertKcpNotifyRetry($response);

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 주문 금액과 다른 입금액의 통보는 결제완료로 바꾸지 않고 재통보를 요청한다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_notify_retries_on_amount_mismatch
     */
    public function test_vbank_notify_retries_on_amount_mismatch(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_VBANK_REAL_TNO', 'T1234567890');

        $response = $this->postVbankNotify(
            $this->makeVbankNotifyPayload($order->order_number, 29999, [
                'tno' => 'KCP_VBANK_REAL_TNO',
            ])
        );

        $this->assertKcpNotifyRetry($response);

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    // ===== 가상계좌 발급 (handleVbankIssued) 성공 처리 =====

    /**
     * PC 가상계좌: KCP 가 res_cd=V000 ("가상계좌가 발급되었습니다.") + bankname/account 응답 시
     * success URL 로 리다이렉트 + vbank 컬럼 정상 저장.
     *
     * 회귀: KCP 표준결제창은 결제수단별로 다른 정상 응답 코드를 사용한다
     *      (card=0000, vbank=V000). SUCCESS_RES_CD='0000' 단일 비교만으로 검증하면
     *      정상 가상계좌 발급도 fail URL 로 처리됨 — 실제 운영 회귀 발견 (주문 20260513-0846191476).
     *      판정 기준을 res_cd 비교에서 핵심 필드(bankname/account) 존재 여부로 변경.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_pc_issuance_with_account_data_succeeds, kcp_vbank_issue_marks_payment_waiting_deposit
     */
    public function test_vbank_pc_succeeds_on_kcp_v000_with_issuance_data(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $tno = 'KCP_VBANK_V000';
        $this->mockApiService([
            'res_cd' => 'V000',
            'res_msg' => '가상계좌가 발급되었습니다.',
            'tno' => $tno,
            'bankname' => 'NH농협',
            'account' => 'T1109260001455',
            'depositor' => 'NHN KCP',
            'va_date' => '20260516235959',
            'bankcode' => 'BK11',
            'app_time' => '20260513174624',
            'amount' => '30000',
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 30000, [
                'tno' => $tno,
                'use_pay_method' => 'VCNT',
            ])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('NH농협', $payment->vbank_name);
        $this->assertEquals('T1109260001455', $payment->vbank_number);
        $this->assertEquals('NHN KCP', $payment->vbank_holder);
        $this->assertNotNull($payment->vbank_due_at);
        // KCP va_date 는 한국 시각이다 — 저장은 UTC(앱 시간대)로 환산해야 한다(20260516235959 KST = 14:59:59 UTC).
        // 종전에는 한국 시각 숫자를 그대로 UTC 로 읽어, 화면에 기한이 9시간 늦게 보이고 만료 판정도 늦었다.
        $this->assertSame('2026-05-16 14:59:59', $payment->vbank_due_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($payment->vbank_issued_at);
        // 발급이 끝난 가상계좌는 입금대기다 — KG·나이스와 같은 상태값. 결제대기(READY)로 남으면 결제창 닫힘
        // 보고가 뒤늦게 와도 결제 전 주문으로 보여 발급된 주문이 취소될 수 있었다.
        $this->assertSame(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
        $this->assertSame('nhnkcp', $payment->pg_provider);
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
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
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

        $this->mockApiService([
            'res_cd' => 'V000',
            'tno' => 'KCP_VBANK_CART',
            'bankname' => 'NH농협',
            'account' => 'T1109260001456',
            'depositor' => 'NHN KCP',
            'va_date' => '20260516235959',
            'amount' => '30000',
        ]);

        $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 30000, ['tno' => 'KCP_VBANK_CART', 'use_pay_method' => 'VCNT'])
        )->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }

    /**
     * 가상계좌 발급 응답 원문은 허용된 필드만 남기고 계좌번호·예금주·구매자 정보는 저장하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects kcp_vbank_issue_stores_only_sanitized_pg_response_fields
     */
    public function test_vbank_issue_stores_only_sanitized_pg_response_fields(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $tno = 'KCP_VBANK_ISSUE_SANITIZED';
        $this->mockApiService([
            'res_cd' => 'V000',
            'res_msg' => '가상계좌가 발급되었습니다.',
            'tno' => $tno,
            'bankname' => 'NH농협',
            'account' => 'T1109260001455',
            'depositor' => 'NHN KCP',
            'va_date' => '20260516235959',
            'bankcode' => 'BK11',
            'app_time' => '20260513174624',
            'amount' => '30000',
            'buyr_name' => '홍길동',
            'buyr_mail' => 'buyer@example.test',
            'card_no' => '4330****1234',
            'unexpected_sensitive' => 'store-me-not',
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 30000, [
                'tno' => $tno,
                'use_pay_method' => 'VCNT',
            ])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame($tno, $meta['pg_raw_response']['tno'] ?? null);
        $this->assertSame('NH농협', $meta['pg_raw_response']['bankname'] ?? null);
        $this->assertArrayNotHasKey('account', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('depositor', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('buyr_name', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('buyr_mail', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('card_no', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('unexpected_sensitive', $meta['pg_raw_response']);
    }

    // ===== 가상계좌 발급 (handleVbankIssued) 실패 처리 =====

    /**
     * PC 가상계좌: CLI 가 res_cd != 정상 + bankname/account 결락 응답 시 (예: 9502 연동 모듈 호출 오류)
     * fail URL 로 리다이렉트 + payment_status 가 결제 완료로 전환되지 않아야 함.
     *
     * 회귀: 기존 코드는 "계좌 발급 자체는 성공" 가정하에 success URL 로 보내
     *      사용자에게 빈 가상계좌 정보의 complete 페이지를 노출했음 (운영 사고).
     *
     * @return void 반환값 없음
     *
     * @effects vbank_save_failure_mutates_only_on_pc_server_confirmed_issuance
     */
    public function test_vbank_pc_redirects_to_fail_on_cli_non_0000(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();
        $this->mockApiService($this->makeCliResponse('KCP_VBANK_FAIL', $order->order_number, 30000, '9502'));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 30000, ['use_pay_method' => 'VCNT'])
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=9502', $response->headers->get('Location'));
        $this->assertStringNotContainsString('/complete', $response->headers->get('Location'));

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        // "완료되지 않았다" 만으로는 부족하다 — 이 콜백은 비인증 브라우저 경로이므로,
        // 발급 실패를 근거로 주문을 취소하면 주문번호만 아는 제3자가 남의 주문을
        // 취소시킬 수 있다 (KVE-2026-2018 동일 계열). 취소되지 않았음까지 단언한다.
        $this->assertNotEquals(
            OrderStatusEnum::CANCELLED,
            $order->order_status,
            '승인 증거 없는 가상계좌 콜백이 주문을 취소시켰습니다.'
        );

        $payment = $order->payment;
        $payment->refresh();
        $this->assertNull($payment->vbank_name);
        $this->assertNull($payment->vbank_number);
        $this->assertNotEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
    }

    /**
     * PC 가상계좌: CLI 호출 자체가 예외(approvePayment throws) → fail URL.
     *
     * @return void 반환값 없음
     *
     * @effects pc_vbank_cli_exception_does_not_mutate_the_order
     */
    public function test_vbank_pc_redirects_to_fail_on_cli_exception(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('approvePayment')->willThrowException(new \RuntimeException('CLI exec failed'));
        $this->app->instance(NhnKcpApiService::class, $mock);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 30000, ['use_pay_method' => 'VCNT'])
        );

        $response->assertRedirect();
        $this->assertStringNotContainsString('/complete', $response->headers->get('Location'));

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        // "완료되지 않았다" 만으로는 부족하다 — 이 콜백은 비인증 브라우저 경로이므로,
        // 발급 실패를 근거로 주문을 취소하면 주문번호만 아는 제3자가 남의 주문을
        // 취소시킬 수 있다 (KVE-2026-2018 동일 계열). 취소되지 않았음까지 단언한다.
        $this->assertNotEquals(
            OrderStatusEnum::CANCELLED,
            $order->order_status,
            '승인 증거 없는 가상계좌 콜백이 주문을 취소시켰습니다.'
        );

        $payment = $order->payment;
        $payment->refresh();
        $this->assertNull($payment->vbank_name);
        $this->assertNull($payment->vbank_number);
        $this->assertNotEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
    }

    /**
     * 가상계좌 발급 정보가 없고 PG 문구도 없으면, 실패 주소의 안내 문구는 화면 언어를 따른다(한국어 고정 아님).
     *
     * @effects vbank_issuance_failure_message_follows_locale
     *
     * @return void 반환값 없음
     */
    public function test_vbank_issuance_failure_message_follows_locale(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();
        // 모바일 가상계좌 세션 결속 nonce — 결속을 통과해야 발급 정보 판정까지 간다.
        $nonce = bin2hex(random_bytes(16));
        $order->payment->update(['payment_meta' => ['mobile_vbank_state' => ['nonce' => $nonce, 'issued_at' => now()->toIso8601String()]]]);

        $response = $this->withHeader('Accept-Language', 'en')->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '0000',
            'res_msg' => '',
            'ordr_idxx' => $order->order_number,
            'good_mny' => 30000,
            'tno' => 'KCP_VBANK_MOBILE_FAIL_EN',
            'use_pay_method' => 'VCNT',
            'param_opt_2' => $nonce,
        ]);

        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('[vbank_issuance_incomplete] '.__('sirsoft-pay_nhnkcp::messages.errors.vbank_issuance_incomplete', [], 'en'), $query['message'] ?? null);
        $this->assertStringNotContainsString('가상계좌', (string) ($query['message'] ?? ''));
    }

    /**
     * 모바일 가상계좌: 콜백 POST 의 평문 필드를 그대로 응답으로 취급하는 경로에서
     * res_cd != 0000 시 fail URL 로 리다이렉트.
     *
     * 모바일은 enc_data/enc_info 가 없어 CLI 미호출이므로, 콜백 res_cd 자체가 권위.
     *
     * @return void 반환값 없음
     *
     * @effects mobile_vbank_issuance_failure_does_not_mutate_the_order
     */
    public function test_vbank_mobile_redirects_to_fail_on_callback_res_cd_non_0000(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        // 모바일: enc_data/enc_info 없이 res_cd 가 비-0000.
        // 단 authCallback() 의 1단계 res_cd 가드를 통과해야 handleVbankIssued() 가 호출되므로
        // 시나리오 재현은 1단계 가드가 vbank 비-0000 도 동일하게 처리해야 한다는 의미.
        // 여기서는 res_cd=0000 으로 진입하되 handleVbankIssued 모바일 분기에서
        // 평문 필드 누락으로 발급 실패(bankname/account 모두 null)인 케이스를 검증.
        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            [
                'res_cd' => '0000',
                'ordr_idxx' => $order->order_number,
                'good_mny' => 30000,
                'tno' => 'KCP_VBANK_MOBILE_FAIL',
                'use_pay_method' => 'VCNT',
                // bankname / account / depositor / va_date 모두 누락 → 발급 정보 없음
            ]
        );

        $response->assertRedirect();
        $this->assertStringNotContainsString('/complete', $response->headers->get('Location'));

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        // "완료되지 않았다" 만으로는 부족하다 — 이 콜백은 비인증 브라우저 경로이므로,
        // 발급 실패를 근거로 주문을 취소하면 주문번호만 아는 제3자가 남의 주문을
        // 취소시킬 수 있다 (KVE-2026-2018 동일 계열). 취소되지 않았음까지 단언한다.
        $this->assertNotEquals(
            OrderStatusEnum::CANCELLED,
            $order->order_status,
            '승인 증거 없는 가상계좌 콜백이 주문을 취소시켰습니다.'
        );

        $payment = $order->payment;
        $payment->refresh();
        $this->assertNull($payment->vbank_name);
        $this->assertNull($payment->vbank_number);
        $this->assertNotEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
    }
}
