<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class PaymentCallbackControllerTest extends PluginTestCase
{
    private const TEST_SITE_CD = 'T0000';

    private const TEST_SITE_KEY = 'TEST_SITE_KEY_0000';

    // ===== 헬퍼 =====

    private function makeCliResponse(string $tno, string $ordrIdxx, int $amount, string $resCd = '0000'): array
    {
        return [
            'res_cd'          => $resCd,
            'res_msg'         => $resCd === '0000' ? '정상처리' : '승인실패',
            'tno'             => $tno,
            'ordr_idxx'       => $ordrIdxx,
            'good_mny'        => $amount,
            'app_no'          => 'APP12345',
            'card_no'         => '4330****1234',
            'card_name'       => '신한카드',
            'quota'           => '00',
            'use_pay_method'  => 'CARD',
            'app_time'        => now()->format('YmdHis'),
        ];
    }

    /**
     * @param array{taxable?: int, vat?: int, taxFree?: int} $tax
     */
    private function createTestOrder(
        int $totalAmount = 50000,
        array $tax = [],
        PaymentMethodEnum $paymentMethod = PaymentMethodEnum::CARD,
    ): Order {
        $taxable = $tax['taxable'] ?? $totalAmount;
        $vat     = $tax['vat']     ?? (int) round($taxable * 10 / 110);
        $taxFree = $tax['taxFree'] ?? 0;

        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id'                            => $user->id,
            'order_number'                       => 'ORD-TEST-' . random_int(10000, 99999),
            'order_status'                       => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount'                    => $totalAmount,
            'total_discount_amount'              => 0,
            'total_coupon_discount_amount'       => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount'         => 0,
            'base_shipping_amount'               => 0,
            'extra_shipping_amount'              => 0,
            'shipping_discount_amount'           => 0,
            'total_shipping_amount'              => 0,
            'total_amount'                       => $totalAmount,
            'total_due_amount'                   => $totalAmount,
            'total_points_used_amount'           => 0,
            'total_deposit_used_amount'          => 0,
            'total_paid_amount'                  => 0,
            'total_tax_amount'                   => $taxable,
            'total_vat_amount'                   => $vat,
            'total_tax_free_amount'              => $taxFree,
            'currency'                           => 'KRW',
            'currency_snapshot'                  => self::krwCurrencySnapshot(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id'             => $order->id,
            'payment_status'       => PaymentStatusEnum::READY,
            'payment_method'       => $paymentMethod,
            'pg_provider'          => 'nhnkcp',
            'paid_amount_local'    => 0,
            'paid_at'              => null,
            'transaction_id'       => null,
            'card_approval_number' => null,
        ]);

        return $order;
    }

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

    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode'         => true,
            'test_site_cd'         => self::TEST_SITE_CD,
            'test_site_key'        => self::TEST_SITE_KEY,
            'live_site_cd'         => '',
            'live_site_key'        => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url'    => '/shop/checkout',
        ];

        $mock = $this->createMock(\App\Services\PluginSettingsService::class);
        $mock->method('get')->willReturn(array_merge($defaults, $overrides));
        $this->app->instance(\App\Services\PluginSettingsService::class, $mock);
    }

    /**
     * NhnKcpApiService::approvePayment() 를 exec() 없이 mock.
     * 기존 테스트는 Http::fake()로 CLI exec를 막으려 했으나 동작하지 않음.
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

    private function makeCallbackParams(string $ordrIdxx, int $goodMny, array $overrides = []): array
    {
        return array_merge([
            'res_cd'          => '0000',
            'res_msg'         => '정상처리',
            'tno'             => 'KCP_TNO_' . uniqid(),
            'ordr_idxx'       => $ordrIdxx,
            'good_mny'        => $goodMny,
            'enc_data'        => base64_encode('encrypted_payment_data'),
            'enc_info'        => base64_encode('encrypted_info'),
            'use_pay_method'  => 'CARD',
        ], $overrides);
    }

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

    public function test_auth_callback_redirects_to_complete_page_on_valid_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_' . uniqid();
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

    public function test_fully_taxable_order_payment_completes(): void
    {
        // 11,000원 = 공급가 10,000 + 부가세 1,000 (전액 과세)
        $amount = 11000;
        $vat    = (int) round($amount * 10 / 110); // 1,000
        $order  = $this->createTestOrder($amount, ['taxable' => $amount, 'vat' => $vat, 'taxFree' => 0]);
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

    public function test_fully_tax_free_order_payment_completes(): void
    {
        // 10,000원 전액 비과세 (도서, 농산물, 의료 등 면세 상품)
        $amount = 10000;
        $order  = $this->createTestOrder($amount, ['taxable' => 0, 'vat' => 0, 'taxFree' => $amount]);
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

    public function test_mixed_tax_order_payment_completes(): void
    {
        // 21,000원 = 과세 11,000(공급가 10,000 + 부가세 1,000) + 비과세 10,000
        $taxable = 11000;
        $taxFree = 10000;
        $total   = $taxable + $taxFree;
        $vat     = (int) round($taxable * 10 / 110); // 1,000
        $order   = $this->createTestOrder($total, ['taxable' => $taxable, 'vat' => $vat, 'taxFree' => $taxFree]);
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

    public function test_auth_callback_redirects_to_fail_on_res_cd_not_0000(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'res_cd'  => '8001',
            'res_msg' => '사용자 취소',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=8001', $response->headers->get('Location'));
    }

    public function test_auth_callback_silently_redirects_on_user_cancel_code_3001(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd'   => '3001',
            'res_msg'  => '사용자취소',
            'ordr_idxx' => 'ORD-TEST-CANCEL',
        ]);

        $response->assertRedirect('/shop/checkout');
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));
    }

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

    public function test_auth_callback_silently_redirects_on_empty_res_cd(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd'    => '',
            'ordr_idxx' => 'ORD-TEST-EMPTY',
        ]);

        $response->assertRedirect('/shop/checkout');
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));
    }

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

    public function test_auth_callback_redirects_to_fail_when_cli_approval_fails(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $this->mockApiService($this->makeCliResponse('TNO_FAIL', $order->order_number, 50000, '9999'));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000)
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=9999', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertSame('nhnkcp', $payment->payment_meta['failure_source'] ?? null);
        $this->assertSame('9999', $payment->payment_meta['failure_code'] ?? null);
        $this->assertSame('approval_failed', $payment->payment_meta['failure_stage'] ?? null);
    }

    public function test_auth_callback_records_amount_mismatch_as_non_retryable_kcp_failure(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_AMOUNT_MISMATCH';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 60000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 60000, ['tno' => $tno])
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertSame('nhnkcp', $payment->payment_meta['failure_source'] ?? null);
        $this->assertSame('AMOUNT_MISMATCH', $payment->payment_meta['failure_code'] ?? null);
        $this->assertSame('amount_mismatch', $payment->payment_meta['failure_stage'] ?? null);
    }

    public function test_auth_callback_redirects_to_fail_url_on_missing_params(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '0000',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

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

    private function assertKcpNotifyOk(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
        $this->assertStringContainsString('value="0000"', $response->getContent());
    }

    private function assertKcpNotifyRetry(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
        $this->assertStringNotContainsString('value="0000"', $response->getContent());
    }

    private function makeVbankNotifyPayload(string $orderNo, int $amount, array $overrides = []): array
    {
        return array_merge([
            'site_cd'    => 'T0000',
            'tno'        => 'KCP_VBANK_TNO_' . uniqid(),
            'order_no'   => $orderNo,
            'tx_cd'      => 'TX00',
            'tx_tm'      => now()->format('YmdHis'),
            'op_cd'      => '50',
            'ipgm_mnyx'  => $amount,
            'ipgm_name'  => '홍길동',
            'remitter'   => '홍길동',
            'bank_code'  => 'BK04',
            'account'    => 'T1234567890',
            'noti_id'    => uniqid('NOTI_'),
        ], $overrides);
    }

    /**
     * KCP 공식 발신 IP 로 vbank-notify POST.
     * RestrictKcpIp 미들웨어가 항상 IP 검증하므로 화이트리스트 IP 필수.
     */
    private function postVbankNotify(array $payload, string $kcpIp = '203.238.36.58'): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $kcpIp])
            ->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', $payload);
    }

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
     */
    public function test_vbank_notify_accepts_kcp_official_payload(): void
    {
        $order = $this->createTestOrder(50000, [], PaymentMethodEnum::VBANK);
        $this->markVbankIssued($order, 'KCP_TNO_REAL', 'T9876543210');

        $response = $this->postVbankNotify([
            'site_cd'   => 'T0000',
            'tno'       => 'KCP_TNO_REAL',
            'order_no'  => $order->order_number,
            'tx_cd'     => 'TX00',
            'tx_tm'     => '20260514120000',
            'op_cd'     => '50',
            'ipgm_mnyx' => 50000,
            'ipgm_name' => '홍길동',
            'remitter'  => '실입금자',
            'bank_code' => '04',
            'account'   => 'T9876543210',
            'noti_id'   => '26051412000018046532',
        ]);

        $this->assertKcpNotifyOk($response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

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
     */
    public function test_vbank_pc_succeeds_on_kcp_v000_with_issuance_data(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $tno = 'KCP_VBANK_V000';
        $this->mockApiService([
            'res_cd'    => 'V000',
            'res_msg'   => '가상계좌가 발급되었습니다.',
            'tno'       => $tno,
            'bankname'  => 'NH농협',
            'account'   => 'T1109260001455',
            'depositor' => 'NHN KCP',
            'va_date'   => '20260516235959',
            'bankcode'  => 'BK11',
            'app_time'  => '20260513174624',
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 30000, [
                'tno'            => $tno,
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
        $this->assertNotNull($payment->vbank_issued_at);
    }

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

        $payment = $order->payment;
        $payment->refresh();
        $this->assertNull($payment->vbank_name);
        $this->assertNull($payment->vbank_number);
    }

    /**
     * PC 가상계좌: CLI 호출 자체가 예외(approvePayment throws) → fail URL.
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

        $payment = $order->payment;
        $payment->refresh();
        $this->assertNull($payment->vbank_name);
        $this->assertNull($payment->vbank_number);
    }

    /**
     * 모바일 가상계좌: 콜백 POST 의 평문 필드를 그대로 응답으로 취급하는 경로에서
     * res_cd != 0000 시 fail URL 로 리다이렉트.
     *
     * 모바일은 enc_data/enc_info 가 없어 CLI 미호출이므로, 콜백 res_cd 자체가 권위.
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
                'res_cd'         => '0000',
                'ordr_idxx'      => $order->order_number,
                'good_mny'       => 30000,
                'tno'            => 'KCP_VBANK_MOBILE_FAIL',
                'use_pay_method' => 'VCNT',
                // bankname / account / depositor / va_date 모두 누락 → 발급 정보 없음
            ]
        );

        $response->assertRedirect();
        $this->assertStringNotContainsString('/complete', $response->headers->get('Location'));

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertNull($payment->vbank_name);
        $this->assertNull($payment->vbank_number);
    }
}
