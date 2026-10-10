<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 주문 결제 승인·가상계좌 발급의 금액 근거가 서버-서버 승인 응답인지 검증한다.
 *
 * 수정 전에는 승인 요청에 청구액(ordr_mony)이 실리지 않았고, 승인 응답의 금액(amount)도 읽지
 * 않았다. 결제완료 금액은 `good_mny > 0 ? good_mny : 주문 청구액` 이라 브라우저가 보낸 값이
 * 곧 근거였고, 거래번호도 응답에 없으면 브라우저 tno 로 채웠다. PC 가상계좌 발급은 금액 대조
 * 없이 계좌를 기록했다.
 *
 * 이제 승인·발급 요청에 청구액을 싣고, 응답 금액이 청구액과 다르거나 비어 있으면 승인분(발급분)을
 * 취소하고 결제완료·계좌 기록을 하지 않는다.
 */
class PaymentApprovalAmountVerificationTest extends PluginTestCase
{
    private const CALLBACK_URL = '/plugins/sirsoft-pay_nhnkcp/payment/callback';

    /**
     * 결제대기 주문과 결제 행을 만듭니다.
     *
     * @param  int  $totalAmount  주문 청구액
     * @param  PaymentMethodEnum  $paymentMethod  결제수단
     * @return Order 주문
     */
    private function createOrder(int $totalAmount, PaymentMethodEnum $paymentMethod = PaymentMethodEnum::CARD): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-AMT-'.random_int(10000, 99999),
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
            'total_tax_amount' => $totalAmount,
            'total_vat_amount' => (int) round($totalAmount * 10 / 110),
            'total_tax_free_amount' => 0,
            'currency' => 'KRW',
            'currency_snapshot' => [
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
            ],
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
     * 플러그인 설정 목을 바인딩합니다.
     *
     * @return void 반환값 없음
     */
    private function mockPluginSettings(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_site_cd' => 'T0000',
            'test_site_key' => 'TEST_SITE_KEY_0000',
            'live_site_cd' => '',
            'live_site_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * KCP API 목을 바인딩합니다. 승인은 청구액을 5번째 인수(ordr_mony)로 받아야 한다.
     *
     * @param  array  $approveResponse  CLI 승인 응답
     * @param  string  $orderNumber  주문번호
     * @param  int  $expectedOrdrMony  승인 요청에 실려야 하는 청구액
     * @param  string|null  $expectCancelTno  취소가 한 번 있어야 하면 그 거래번호, 없어야 하면 null
     * @return NhnKcpApiService&MockObject API 서비스 목
     */
    private function mockKcp(
        array $approveResponse,
        string $orderNumber,
        int $expectedOrdrMony,
        ?string $expectCancelTno = null,
    ): NhnKcpApiService {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('getSiteCd')->willReturn('T0000');
        $mock->method('isTestMode')->willReturn(true);

        $mock->expects($this->once())
            ->method('approvePayment')
            ->with($this->anything(), $this->anything(), $orderNumber, $this->anything(), $expectedOrdrMony)
            ->willReturn($approveResponse);

        if ($expectCancelTno === null) {
            $mock->expects($this->never())->method('cancelPayment');
        } else {
            $mock->expects($this->once())
                ->method('cancelPayment')
                ->with($expectCancelTno, $orderNumber, $this->anything(), $this->anything())
                ->willReturn(['res_cd' => '0000']);
        }

        $this->app->instance(NhnKcpApiService::class, $mock);

        return $mock;
    }

    /**
     * 카드 승인 성공 응답을 만듭니다.
     *
     * @param  string  $tno  KCP 거래번호
     * @param  array  $overrides  덮어쓸 값 (null 값은 키 제거)
     * @return array CLI 응답
     */
    private function cardApproveResponse(string $tno, array $overrides = []): array
    {
        $response = array_merge([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => $tno,
            'app_no' => 'APP-AMT-1',
            'card_name' => '신한카드',
            'quota' => '00',
            'use_pay_method' => '100000000000',
            'app_time' => now()->format('YmdHis'),
        ], $overrides);

        return array_filter($response, static fn ($value) => $value !== null);
    }

    /**
     * PC 가상계좌 발급 성공 응답을 만듭니다.
     *
     * @param  string  $tno  KCP 거래번호
     * @param  array  $overrides  덮어쓸 값 (null 값은 키 제거)
     * @return array CLI 응답
     */
    private function vbankIssueResponse(string $tno, array $overrides = []): array
    {
        $response = array_merge([
            'res_cd' => 'V000',
            'res_msg' => '가상계좌가 발급되었습니다.',
            'tno' => $tno,
            'bankname' => 'NH농협',
            'account' => 'T1109260009999',
            'depositor' => 'NHN KCP',
            'va_date' => '20260516235959',
            'bankcode' => 'BK11',
            'app_time' => '20260513174624',
        ], $overrides);

        return array_filter($response, static fn ($value) => $value !== null);
    }

    /**
     * 결제창 콜백 파라미터를 만듭니다. (PC — enc_data/enc_info 동봉)
     *
     * @param  string  $orderNumber  주문번호
     * @param  array  $overrides  덮어쓸 값 (null 값은 키 제거)
     * @return array 콜백 파라미터
     */
    private function callbackParams(string $orderNumber, array $overrides = []): array
    {
        $params = array_merge([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'ordr_idxx' => $orderNumber,
            'enc_data' => base64_encode('encrypted_payment_data'),
            'enc_info' => base64_encode('encrypted_info'),
            'use_pay_method' => 'CARD',
        ], $overrides);

        return array_filter($params, static fn ($value) => $value !== null);
    }

    /**
     * 주문이 결제완료로 확정되지 않았는지 단언합니다.
     *
     * @param  Order  $order  주문
     * @return void 반환값 없음
     */
    private function assertOrderNotPaid(Order $order): void
    {
        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertNotEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertEquals(0, (int) $payment->paid_amount_local);
        $this->assertNull($payment->paid_at);
    }

    /**
     * 카드 승인 요청에 청구액을 ordr_mony 로 싣고, 응답 금액이 청구액과 같으면 그 금액으로 확정한다.
     *
     * 브라우저 콜백에 good_mny 가 없어도 승인은 응답 금액만으로 확정된다 — 브라우저 값은 근거가 아니다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=order_card, pg_amount=matches
     *
     * @effects kcp_approval_request_carries_the_order_amount_as_ordr_mony, kcp_approval_matching_amount_confirms_with_the_pg_amount
     */
    public function test_order_approval_sends_ordr_mony_and_confirms_with_the_pg_amount(): void
    {
        $order = $this->createOrder(50000);
        $this->mockPluginSettings();
        $this->mockKcp($this->cardApproveResponse('KCP-AMT-OK', ['amount' => '50000']), $order->order_number, 50000);

        $response = $this->post(self::CALLBACK_URL, $this->callbackParams($order->order_number));

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame('KCP-AMT-OK', $payment->transaction_id);
        $this->assertEquals(50000, (int) $payment->paid_amount_local);
        $this->assertSame('50000', $payment->payment_meta['pg_raw_response']['amount'] ?? null);
    }

    /**
     * 승인 응답 금액이 청구액과 다르면 승인분을 취소하고 결제완료로 확정하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=order_card, pg_amount=differs
     *
     * @effects kcp_approval_amount_mismatch_cancels_the_pg_approval, kcp_approval_amount_mismatch_leaves_the_payment_unconfirmed
     */
    public function test_order_approval_with_a_different_response_amount_is_cancelled_and_not_paid(): void
    {
        $order = $this->createOrder(50000);
        $this->mockPluginSettings();
        $this->mockKcp(
            $this->cardApproveResponse('KCP-AMT-DIFF', ['amount' => '45000']),
            $order->order_number,
            50000,
            expectCancelTno: 'KCP-AMT-DIFF',
        );

        $response = $this->post(self::CALLBACK_URL, $this->callbackParams($order->order_number));

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));
        $this->assertOrderNotPaid($order);
    }

    /**
     * 쓸 수 없는 승인 응답 금액 변종을 돌려줍니다. (null 은 키 자체가 없는 응답)
     *
     * @return array<string, array{string|null}> 변종별 응답 금액
     */
    public static function unusableResponseAmountProvider(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'zero' => ['0'],
            'text' => ['abc'],
            'negative' => ['-50000'],
            'decimal' => ['50000.5'],
        ];
    }

    /**
     * 승인 응답에 금액이 없거나(키 부재·빈 값) 숫자가 아니거나 0 이하이면 불일치와 같이 취소한다.
     *
     * 수정 전에는 응답 금액을 읽지 않고 브라우저 값 또는 주문 청구액으로 확정했다.
     *
     * @param  string|null  $amount  승인 응답의 금액 (null 이면 키 없음)
     * @return void 반환값 없음
     *
     * @scenario path=order_card, pg_amount=missing
     *
     * @effects kcp_approval_missing_amount_is_treated_as_mismatch, kcp_approval_amount_mismatch_cancels_the_pg_approval, kcp_approval_amount_mismatch_leaves_the_payment_unconfirmed
     */
    #[DataProvider('unusableResponseAmountProvider')]
    public function test_order_approval_without_a_usable_response_amount_is_cancelled_and_not_paid(?string $amount): void
    {
        $order = $this->createOrder(50000);
        $this->mockPluginSettings();
        $this->mockKcp(
            $this->cardApproveResponse('KCP-AMT-MISSING', ['amount' => $amount]),
            $order->order_number,
            50000,
            expectCancelTno: 'KCP-AMT-MISSING',
        );

        $response = $this->post(self::CALLBACK_URL, $this->callbackParams($order->order_number, ['good_mny' => 50000]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));
        $this->assertOrderNotPaid($order);
    }

    /**
     * 브라우저가 청구액과 같은 good_mny 를 보내도 승인 응답 금액이 다르면 취소한다.
     *
     * 수정 전 `$approvedAmt = good_mny > 0 ? good_mny : 청구액` 은 이 경우 브라우저 값(50000)으로
     * 결제완료를 확정했다 — PG 가 실제로 승인한 금액(30000)과 무관하게.
     *
     * @return void 반환값 없음
     *
     * @scenario path=order_card, pg_amount=differs
     *
     * @effects kcp_approval_browser_amount_is_never_the_basis
     */
    public function test_a_browser_good_mny_equal_to_the_charge_does_not_override_the_pg_amount(): void
    {
        $order = $this->createOrder(50000);
        $this->mockPluginSettings();
        $this->mockKcp(
            $this->cardApproveResponse('KCP-AMT-BROWSER', ['amount' => '30000']),
            $order->order_number,
            50000,
            expectCancelTno: 'KCP-AMT-BROWSER',
        );

        $response = $this->post(self::CALLBACK_URL, $this->callbackParams($order->order_number, ['good_mny' => 50000]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));
        $this->assertOrderNotPaid($order);
    }

    /**
     * 승인 성공 응답에 거래번호가 없으면 브라우저 tno 로 채우지 않고 확정하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=order_card, pg_amount=matches
     *
     * @effects kcp_approval_tno_comes_only_from_the_pg_response
     */
    public function test_order_approval_without_a_pg_tno_is_not_confirmed_with_the_browser_tno(): void
    {
        $order = $this->createOrder(50000);
        $this->mockPluginSettings();
        $this->mockKcp(
            $this->cardApproveResponse('', ['tno' => null, 'amount' => '50000']),
            $order->order_number,
            50000,
        );

        $response = $this->post(self::CALLBACK_URL, $this->callbackParams($order->order_number, [
            'tno' => 'BROWSER-SUPPLIED-TNO',
            'good_mny' => 50000,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=confirm_failed', $response->headers->get('Location'));
        $this->assertOrderNotPaid($order);

        $payment = $order->payment()->first();
        $this->assertNotSame('BROWSER-SUPPLIED-TNO', $payment->transaction_id);
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->fresh()->order_status);
    }

    /**
     * PC 가상계좌 발급 요청에도 청구액을 싣고, 발급 응답 금액이 청구액과 같으면 계좌를 기록한다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=order_vbank_pc, pg_amount=matches
     *
     * @effects kcp_approval_request_carries_the_order_amount_as_ordr_mony, kcp_approval_matching_amount_confirms_with_the_pg_amount
     */
    public function test_pc_vbank_issue_sends_ordr_mony_and_records_the_account_when_the_amount_matches(): void
    {
        $order = $this->createOrder(30000, PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();
        $this->mockKcp($this->vbankIssueResponse('KCP-VB-OK', ['amount' => '30000']), $order->order_number, 30000);

        $response = $this->post(self::CALLBACK_URL, $this->callbackParams($order->order_number, ['use_pay_method' => 'VCNT']));

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment()->first();
        $this->assertSame('KCP-VB-OK', $payment->transaction_id);
        $this->assertSame('T1109260009999', $payment->vbank_number);
        $this->assertNotEquals(PaymentStatusEnum::PAID, $payment->payment_status);
    }

    /**
     * PC 가상계좌 발급 응답 금액이 청구액과 다르면 발급분을 취소하고 계좌·주문 상태를 기록하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=order_vbank_pc, pg_amount=differs
     *
     * @effects kcp_vbank_issue_amount_mismatch_records_no_account, kcp_approval_amount_mismatch_cancels_the_pg_approval, kcp_approval_amount_mismatch_leaves_the_payment_unconfirmed
     */
    public function test_pc_vbank_issue_with_a_different_amount_is_cancelled_and_records_nothing(): void
    {
        $order = $this->createOrder(30000, PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();
        $this->mockKcp(
            $this->vbankIssueResponse('KCP-VB-DIFF', ['amount' => '1000']),
            $order->order_number,
            30000,
            expectCancelTno: 'KCP-VB-DIFF',
        );

        $response = $this->post(self::CALLBACK_URL, $this->callbackParams($order->order_number, [
            'use_pay_method' => 'VCNT',
            'good_mny' => 30000,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));
        $this->assertStringNotContainsString('/complete', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment()->first();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->transaction_id);
        $this->assertNull($payment->vbank_number);
        $this->assertNull($payment->vbank_issued_at);
    }

    /**
     * PC 가상계좌 발급 응답에 금액이 없으면 불일치와 같이 발급분을 취소하고 아무것도 기록하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=order_vbank_pc, pg_amount=missing
     *
     * @effects kcp_vbank_issue_amount_mismatch_records_no_account, kcp_approval_missing_amount_is_treated_as_mismatch
     */
    public function test_pc_vbank_issue_without_a_response_amount_is_cancelled_and_records_nothing(): void
    {
        $order = $this->createOrder(30000, PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();
        $this->mockKcp(
            $this->vbankIssueResponse('KCP-VB-MISSING'),
            $order->order_number,
            30000,
            expectCancelTno: 'KCP-VB-MISSING',
        );

        $response = $this->post(self::CALLBACK_URL, $this->callbackParams($order->order_number, [
            'use_pay_method' => 'VCNT',
            'good_mny' => 30000,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=amount_mismatch', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment()->first();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertNull($payment->transaction_id);
        $this->assertNull($payment->vbank_number);
        $this->assertNull($payment->vbank_issued_at);
    }
}
