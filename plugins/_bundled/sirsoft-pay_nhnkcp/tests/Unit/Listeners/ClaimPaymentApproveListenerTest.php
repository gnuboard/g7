<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayNhnkcp\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 클레임 추가결제 승인 리스너 단위 테스트
 *
 * KRW 전용 게이트, 입금 대기 수단(VCNT) 사전 거절, CLI 승인 결과 매핑,
 * 환급 자격증명 메타(site_cd/is_test_mode) 보관을 검증한다.
 */
class ClaimPaymentApproveListenerTest extends PluginTestCase
{
    private function mockApiService(array $approveResponse = [], bool $expectNoApprove = false): NhnKcpApiService
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('getSiteCd')->willReturn('T0000');
        $mock->method('isTestMode')->willReturn(true);

        if ($expectNoApprove) {
            $mock->expects($this->never())->method('approvePayment');
        } else {
            $mock->method('approvePayment')->willReturn($approveResponse);
        }

        $this->app->instance(NhnKcpApiService::class, $mock);

        return $mock;
    }

    private function makePendingPayment(array $overrides = [], bool $withSnapshot = true): ClaimPayment
    {
        $attributes = array_merge([
            'pg_provider' => 'nhnkcp',
            'amount' => 3000,
        ], $overrides);

        if ($withSnapshot) {
            $attributes['order_id'] = OrderFactory::new()->create([
                'currency' => 'KRW',
                'currency_snapshot' => [
                    'base_currency' => 'KRW',
                    'order_currency' => 'KRW',
                    'exchange_rates' => [
                        'KRW' => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round'],
                    ],
                ],
            ])->id;
        }

        return ClaimPaymentFactory::new()->create($attributes);
    }

    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'paid_amount' => null, 'raw_response' => null, 'error_code' => null, 'error_message' => null];
    }

    private function pgCallback(array $overrides = []): array
    {
        return array_merge([
            'res_cd' => '0000',
            'enc_data' => 'enc-data',
            'enc_info' => 'enc-info',
            'good_mny' => 3000,
            'cust_ip' => '127.0.0.1',
        ], $overrides);
    }

    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentApproveListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.approve', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.approve']['type']);
    }

    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePendingPayment(['pg_provider' => 'tosspayments'], withSnapshot: false);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pgCallback());

        $this->assertFalse($result['handled']);
    }

    public function test_window_failure_is_rejected_with_the_pg_code(): void
    {
        $payment = $this->makePendingPayment(withSnapshot: false);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pgCallback(['res_cd' => '9999']));

        $this->assertFalse($result['success']);
        $this->assertSame('9999', $result['error_code']);
    }

    public function test_a_non_krw_payment_is_rejected_before_approval(): void
    {
        $this->mockApiService(expectNoApprove: true);
        // 결제 통화가 USD 인 주문 — KCP 는 KRW 전용이므로 승인 호출 없이 거절한다.
        $payment = $this->makePendingPayment([
            'order_id' => OrderFactory::new()->create([
                'currency' => 'USD',
                'currency_snapshot' => [
                    'base_currency' => 'KRW',
                    'order_currency' => 'USD',
                    'exchange_rates' => [
                        'KRW' => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round'],
                        'USD' => ['rate' => 0.00075, 'decimal_places' => 2, 'rounding_unit' => '0.01', 'rounding_method' => 'round'],
                    ],
                ],
            ])->id,
        ], withSnapshot: false);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pgCallback());

        $this->assertFalse($result['success']);
        $this->assertSame('CURRENCY_NOT_SUPPORTED', $result['error_code']);
    }

    public function test_a_deposit_pending_method_is_rejected_before_approval(): void
    {
        $this->mockApiService(expectNoApprove: true);
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pgCallback(['use_pay_method' => 'VCNT']));

        $this->assertFalse($result['success']);
        $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
    }

    public function test_cli_approval_returns_transaction_and_credential_meta(): void
    {
        $this->mockApiService([
            'res_cd' => '0000',
            'res_msg' => 'OK',
            'tno' => 'KCP-CLAIM-TNO-1',
        ]);
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pgCallback());

        $this->assertTrue($result['success']);
        $this->assertSame('KCP-CLAIM-TNO-1', $result['transaction_id']);
        $this->assertSame(3000, $result['paid_amount']);
        // 환급이 결제 당시 site_cd/모드로 호출되도록 보관 응답에 메타가 남아야 한다.
        $this->assertSame('T0000', $result['raw_response']['site_cd'] ?? null);
        $this->assertTrue($result['raw_response']['is_test_mode'] ?? null);
    }

    public function test_cli_refusal_returns_a_failure_result(): void
    {
        $this->mockApiService(['res_cd' => '8123', 'res_msg' => '한도초과']);
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pgCallback());

        $this->assertFalse($result['success']);
        $this->assertSame('8123', $result['error_code']);
        $this->assertSame('한도초과', $result['error_message']);
    }
}
