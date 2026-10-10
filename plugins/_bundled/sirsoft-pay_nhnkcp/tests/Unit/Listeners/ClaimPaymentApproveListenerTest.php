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
    /**
     * site_cd·테스트 모드와 승인 응답을 고정한 NhnKcpApiService mock 을 주입한다.
     *
     * @param  array<string, mixed>  $approveResponse  approvePayment 가 돌려줄 응답
     * @param  bool  $expectNoApprove  승인 호출이 없어야 하는지 여부
     * @return NhnKcpApiService 주입한 mock
     */
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

    /**
     * KCP 결제 대기 클레임 결제 건을 만든다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     * @param  bool  $withSnapshot  KRW 통화 스냅샷을 가진 부모 주문을 함께 만들지 여부
     * @return ClaimPayment 생성된 클레임 결제 건
     */
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

    /**
     * 승인 필터가 처음 받는 기본 결과 값을 돌려준다. (아직 처리되지 않은 상태)
     *
     * @return array<string, mixed> 필터 초기값
     */
    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'paid_amount' => null, 'raw_response' => null, 'error_code' => null, 'error_message' => null];
    }

    /**
     * KCP 결제창이 승인 단계로 넘기는 콜백 값을 만든다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 콜백 값
     */
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

    /**
     * 리스너가 클레임 결제 승인 훅을 필터로 구독한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=hook_subscription
     *
     * @effects kcp_claim_approve_listener_subscribes_the_approve_filter
     */
    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentApproveListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.approve', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.approve']['type']);
    }

    /**
     * 다른 PG 의 결제 건은 처리하지 않고 넘긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=other_provider
     *
     * @effects kcp_claim_approve_listener_skips_other_providers
     */
    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePendingPayment(['pg_provider' => 'tosspayments'], withSnapshot: false);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pgCallback());

        $this->assertFalse($result['handled']);
    }

    /**
     * 결제창 단계 실패는 PG 결과 코드를 실어 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=window_failure
     *
     * @effects kcp_claim_window_failure_is_rejected_with_the_pg_code
     */
    public function test_window_failure_is_rejected_with_the_pg_code(): void
    {
        $payment = $this->makePendingPayment(withSnapshot: false);

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pgCallback(['res_cd' => '9999']));

        $this->assertFalse($result['success']);
        $this->assertSame('9999', $result['error_code']);
    }

    /**
     * KRW 가 아닌 주문의 클레임 결제는 승인 호출 없이 CURRENCY_NOT_SUPPORTED 로 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=non_krw
     *
     * @effects kcp_claim_non_krw_payment_is_rejected_before_approval
     */
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

    /**
     * 콜백 결제수단이 입금 대기 수단(VCNT)이면 승인 호출 없이 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=deposit_method
     *
     * @effects kcp_claim_deposit_pending_method_is_rejected_before_approval
     */
    public function test_a_deposit_pending_method_is_rejected_before_approval(): void
    {
        $this->mockApiService(expectNoApprove: true);
        $payment = $this->makePendingPayment();

        $result = (new ClaimPaymentApproveListener)->processApprove($this->defaultFilterValue(), $payment, $this->pgCallback(['use_pay_method' => 'VCNT']));

        $this->assertFalse($result['success']);
        $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
    }

    /**
     * CLI 승인이 성공하면 거래번호·결제 금액과 환급용 site_cd·모드 메타를 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cli_approved
     *
     * @effects kcp_claim_cli_approval_returns_transaction_and_credential_meta
     */
    public function test_cli_approval_returns_transaction_and_credential_meta(): void
    {
        $this->mockApiService([
            'res_cd' => '0000',
            'res_msg' => 'OK',
            'tno' => 'KCP-CLAIM-TNO-1',
            'amount' => '3000',
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

    /**
     * CLI 가 승인을 거절하면 PG 코드와 메시지를 담은 실패 결과를 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cli_refused
     *
     * @effects kcp_claim_cli_refusal_returns_a_failure_result
     */
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
