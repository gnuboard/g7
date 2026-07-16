<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class MobileApprovalControllerTest extends PluginTestCase
{
    private const APPROVAL_KEY_ENDPOINT = '/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key';

    private const TEST_PAY_URL = 'https://testpay.kcp.co.kr/php/mobile/mc_pay_form.php';

    // ===== 헬퍼 =====

    /**
     * @param array{taxable?: int, vat?: int, taxFree?: int} $tax
     */
    private function createOrder(int $totalAmount = 10000, array $tax = []): Order
    {
        $taxable = $tax['taxable'] ?? $totalAmount;
        $vat     = $tax['vat']     ?? (int) round($taxable * 10 / 110);
        $taxFree = $tax['taxFree'] ?? 0;

        $user  = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id'              => $user->id,
            'order_number'         => 'ORD-MOB-' . random_int(10000, 99999),
            'order_status'         => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount'      => $totalAmount,
            'total_amount'         => $totalAmount,
            'total_due_amount'     => $totalAmount,
            'total_paid_amount'    => 0,
            'total_tax_amount'     => $taxable,
            'total_vat_amount'     => $vat,
            'total_tax_free_amount'=> $taxFree,
            'currency'             => 'KRW',
            'currency_snapshot'    => self::krwCurrencySnapshot(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id'       => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider'    => 'nhnkcp',
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

    private function mockSoapService(string $approvalKey = 'TEST_APPROVAL_KEY'): void
    {
        $mock = $this->createMock(KcpSoapService::class);
        $mock->method('getApprovalKey')->willReturn([
            'approval_key' => $approvalKey,
            'pay_url'      => self::TEST_PAY_URL,
        ]);
        $mock->method('getSiteCd')->willReturn('T0000');
        $mock->method('getEscrowSiteCd')->willReturn('T0000');
        $this->app->instance(KcpSoapService::class, $mock);
    }

    private function expectSoapServiceNotCalled(): void
    {
        $mock = $this->createMock(KcpSoapService::class);
        $mock->expects($this->never())->method('getApprovalKey');
        $this->app->instance(KcpSoapService::class, $mock);
    }

    private function mockPluginSettings(): void
    {
        $mock = $this->createMock(\App\Services\PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'is_test_mode'  => true,
            'test_site_cd'  => 'T0000',
            'test_site_key' => 'TEST_KEY',
        ]);
        $this->app->instance(\App\Services\PluginSettingsService::class, $mock);
    }

    private function approvalKeyPayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'order_number' => $order->order_number,
            'amount'       => (int) $order->total_due_amount,
            'good_name'    => '테스트 상품',
            'pay_method'   => 'card',
            'buyr_name'    => '홍길동',
            'buyr_mail'    => 'test@test.com',
            'buyr_tel1'    => '01012345678',
            'ret_url'      => 'https://example.com/callback',
        ], $overrides);
    }

    // ===== 기본 승인키 획득 =====

    public function test_get_approval_key_returns_pay_url_and_fields(): void
    {
        $order = $this->createOrder(10000);
        $this->mockSoapService('APPROVAL_001');
        $this->mockPluginSettings();
        $user = $order->user;

        $response = $this->actingAs($user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pay_url', self::TEST_PAY_URL)
            ->assertJsonPath('data.fields.approval_key', 'APPROVAL_001')
            ->assertJsonPath('data.fields.ordr_idxx', $order->order_number)
            ->assertJsonPath('data.fields.good_mny', (string) (int) $order->total_due_amount);
    }

    public function test_get_approval_key_restores_retryable_cancelled_order(): void
    {
        $order = $this->createOrder(10000);
        $order->update([
            'order_status' => OrderStatusEnum::CANCELLED,
            'order_meta' => [
                'payment_failure_code' => 'USER_CANCEL',
                'payment_failure_message' => '사용자 취소',
                'payment_failed_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);
        $order->payment->update([
            'payment_status' => PaymentStatusEnum::CANCELLED,
            'cancelled_at' => now()->subMinute(),
            'paid_at' => null,
            'transaction_id' => null,
            'card_approval_number' => null,
            'payment_meta' => [
                'failure_source' => 'nhnkcp',
                'failure_code' => 'USER_CANCEL',
                'failure_message' => '사용자 취소',
                'failure_stage' => 'window_closed',
                'failed_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $this->mockSoapService('APPROVAL_RETRY');
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk()
            ->assertJsonPath('data.fields.approval_key', 'APPROVAL_RETRY');

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->cancelled_at);
    }

    public function test_get_approval_key_returns_422_on_soap_failure(): void
    {
        $order = $this->createOrder(10000);
        $this->mockPluginSettings();

        $mock = $this->createMock(KcpSoapService::class);
        $mock->method('getApprovalKey')
            ->willThrowException(new \Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException('KCP SOAP 오류'));
        $mock->method('getSiteCd')->willReturn('T0000');
        $this->app->instance(KcpSoapService::class, $mock);

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_get_approval_key_requires_authentication(): void
    {
        $order = $this->createOrder(10000);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertUnauthorized();
    }

    public function test_get_approval_key_rejects_other_users_order(): void
    {
        $order = $this->createOrder(10000);
        $otherUser = User::factory()->create();
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $response = $this->actingAs($otherUser)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_get_approval_key_rejects_amount_mismatch(): void
    {
        $order = $this->createOrder(10000);
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['amount' => 9900]));

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_get_approval_key_rejects_invalid_payment_currency_without_server_error(): void
    {
        $order = $this->createOrder(10000);
        $order->update([
            'currency' => 'USD',
            'currency_snapshot' => self::invalidUsdCurrencySnapshot(),
        ]);
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['currency' => 'USD']));

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Payment currency is not chargeable.');
    }

    public function test_get_approval_key_rejects_non_krw_order(): void
    {
        $order = $this->createOrder(10000);
        $order->currency = 'USD';
        $order->save();
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['currency' => 'USD']));

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_get_approval_key_validates_required_fields(): void
    {
        $user = User::factory()->create();
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, []);

        $response->assertUnprocessable();
    }

    // ===== 간편결제 direct 파라미터 =====

    public function test_naverpay_easy_pay_includes_direct_field(): void
    {
        $order = $this->createOrder(10000);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['pay_method' => 'nhnkcp_naverpay']));

        $response->assertOk()
            ->assertJsonPath('data.fields.naverpay_direct', 'Y')
            ->assertJsonPath('data.fields.param_opt_1', 'nhnkcp_naverpay')
            ->assertJsonPath('data.fields.nhnkcp_easy_pay_method', 'nhnkcp_naverpay');
    }

    public function test_kakaopay_easy_pay_includes_direct_field(): void
    {
        $order = $this->createOrder(10000);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order, ['pay_method' => 'nhnkcp_kakaopay']));

        $response->assertOk()
            ->assertJsonPath('data.fields.kakaopay_direct', 'Y');
    }

    // ===== 과세/비과세 분할 필드 =====

    public function test_fully_taxable_order_does_not_include_tax_flag(): void
    {
        // 전액 과세 → 복합과세 필드 불필요. KCP가 내부 계산
        $amount = 11000;
        $vat    = (int) round($amount * 10 / 110);
        $order  = $this->createOrder($amount, ['taxable' => $amount, 'vat' => $vat, 'taxFree' => 0]);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk();
        $fields = $response->json('data.fields');

        $this->assertArrayNotHasKey('tax_flag', $fields, '전액 과세 시 tax_flag 포함 불필요');
        $this->assertArrayNotHasKey('comm_tax_mny', $fields);
        $this->assertArrayNotHasKey('comm_free_mny', $fields);
    }

    public function test_fully_tax_free_order_includes_comm_free_mny(): void
    {
        // 10,000원 전액 비과세 (면세 상품)
        $amount = 10000;
        $order  = $this->createOrder($amount, ['taxable' => 0, 'vat' => 0, 'taxFree' => $amount]);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk();
        $fields = $response->json('data.fields');

        $this->assertArrayHasKey('tax_flag', $fields);
        $this->assertEquals('TG03', $fields['tax_flag']);
        $this->assertEquals('0', $fields['comm_tax_mny'], '공급가액은 0');
        $this->assertEquals('0', $fields['comm_vat_mny'], '부가세는 0');
        $this->assertEquals((string) $amount, $fields['comm_free_mny'], '비과세 금액 일치');
    }

    public function test_mixed_tax_order_includes_all_tax_fields(): void
    {
        // 21,000원 = 과세 11,000(공급가 10,000 + 부가세 1,000) + 비과세 10,000
        $taxable = 11000;
        $taxFree = 10000;
        $total   = $taxable + $taxFree;
        $vat     = (int) round($taxable * 10 / 110); // 1,000
        $supplyAmt = $taxable - $vat;               // 10,000

        $order = $this->createOrder($total, ['taxable' => $taxable, 'vat' => $vat, 'taxFree' => $taxFree]);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk();
        $fields = $response->json('data.fields');

        $this->assertEquals('TG03', $fields['tax_flag']);
        $this->assertEquals((string) $supplyAmt, $fields['comm_tax_mny'], '공급가액 일치');
        $this->assertEquals((string) $vat,        $fields['comm_vat_mny'], '부가세 일치');
        $this->assertEquals((string) $taxFree,     $fields['comm_free_mny'], '비과세 금액 일치');

        // 금액 검증: good_mny = comm_tax_mny + comm_vat_mny + comm_free_mny
        $this->assertEquals(
            (string) $total,
            $fields['good_mny'],
            'good_mny = 공급가액 + 부가세 + 비과세'
        );
    }

    public function test_mixed_tax_fields_are_rebalanced_to_payment_amount(): void
    {
        // 원 상품 과세/비과세 합계 21,000원에서 쿠폰/마일리지 등으로 실결제액이 19,000원이 된 상황.
        $taxable = 11000;
        $taxFree = 10000;
        $paymentAmount = 19000;
        $vat = 1000;

        $order = $this->createOrder($paymentAmount, ['taxable' => $taxable, 'vat' => $vat, 'taxFree' => $taxFree]);
        $this->mockSoapService();
        $this->mockPluginSettings();

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->approvalKeyPayload($order));

        $response->assertOk();
        $fields = $response->json('data.fields');

        $this->assertEquals('TG03', $fields['tax_flag']);
        $this->assertEquals('8182', $fields['comm_tax_mny']);
        $this->assertEquals('818', $fields['comm_vat_mny']);
        $this->assertEquals('10000', $fields['comm_free_mny']);
        $this->assertSame(
            (int) $fields['good_mny'],
            (int) $fields['comm_tax_mny'] + (int) $fields['comm_vat_mny'] + (int) $fields['comm_free_mny'],
            'KCP 복합과세 분할합은 good_mny와 반드시 일치해야 함'
        );
    }

    public function test_get_approval_key_rejects_missing_order(): void
    {
        $order = $this->createOrder(10000);
        $this->expectSoapServiceNotCalled();
        $this->mockPluginSettings();

        $payload = $this->approvalKeyPayload($order, ['order_number' => 'NON-EXISTENT-ORDER']);

        $response = $this->actingAs($order->user)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $payload);

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
