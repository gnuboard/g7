<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayNhnkcp\Listeners\ClaimPaymentRefundListener;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 클레임 추가결제 환급 리스너 단위 테스트
 *
 * 전액/부분 취소 분기와 잔액(rem_mny) 산출, 결제 당시 자격증명(site_cd) 복원을 검증한다.
 */
class ClaimPaymentRefundListenerTest extends PluginTestCase
{
    /**
     * 이 PG 로 결제완료된 클레임 결제 건을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 속성
     * @return ClaimPayment 결제 건
     */
    private function makePaidPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'nhnkcp',
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'amount' => 3000,
            'transaction_id' => 'KCP-CLAIM-TNO-9',
            'raw_response' => ['tno' => 'KCP-CLAIM-TNO-9', 'site_cd' => 'T0000', 'is_test_mode' => true],
            'paid_at' => now(),
        ], $overrides));
    }

    /**
     * 환급 필터의 기본값을 돌려줍니다.
     *
     * @return array<string, mixed> 필터 기본값
     */
    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'error_code' => null, 'error_message' => null];
    }

    /**
     * 클레임 결제 환급 필터를 필터 훅으로 구독한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=hook_subscription
     *
     * @effects kcp_claim_refund_listener_subscribes_the_refund_filter
     */
    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentRefundListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.refund', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.refund']['type']);
    }

    /**
     * 다른 PG 의 결제 건은 처리하지 않고 넘긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=other_provider
     *
     * @effects kcp_claim_refund_listener_skips_other_providers
     */
    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePaidPayment(['pg_provider' => 'kginicis']);

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertFalse($result['handled']);
    }

    /**
     * 거래번호가 없는 결제 건은 환급을 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=missing_transaction_id
     *
     * @effects kcp_claim_refund_without_tno_is_rejected
     */
    public function test_missing_tno_is_rejected(): void
    {
        $payment = $this->makePaidPayment(['transaction_id' => null]);

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertFalse($result['success']);
        $this->assertSame('MISSING_TNO', $result['error_code']);
    }

    /**
     * 전액 환급은 결제 당시 자격증명을 복원하고 전체 취소로 보낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=full_refund
     *
     * @effects kcp_claim_full_refund_restores_credentials_and_cancels_in_full
     */
    public function test_full_refund_restores_credentials_and_cancels_in_full(): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->expects($this->once())
            ->method('useStoredCredentials')
            ->with(true, 'T0000');
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with('KCP-CLAIM-TNO-9', $this->anything(), 3000, $this->anything(), false, $this->anything())
            ->willReturn(['res_cd' => '0000', 'tno' => 'KCP-CLAIM-TNO-9']);
        $this->app->instance(NhnKcpApiService::class, $mock);

        $payment = $this->makePaidPayment();

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 3000.0, '철회 환급', 'g7-claimpay-9-0-300000');

        $this->assertTrue($result['success']);
        $this->assertSame('KCP-CLAIM-TNO-9', $result['transaction_id']);
    }

    /**
     * 부분 환급은 이미 환급된 금액을 뺀 취소 가능 금액을 함께 보낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=partial_refund
     *
     * @effects kcp_claim_partial_refund_passes_remaining_balance_as_rem_mny
     */
    public function test_partial_refund_passes_the_remaining_balance_as_rem_mny(): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        // 이미 500 환급된 3,000 결제 건의 1,000 부분 환급 — 부분취소 가능 금액(rem_mny)은
        // 3000 − 500 = 2500 이다 (KCP 는 취소 후 잔액이 아니라 현재 가능 금액을 받는다).
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with('KCP-CLAIM-TNO-9', $this->anything(), 1000, $this->anything(), true, 2500)
            ->willReturn(['res_cd' => '0000', 'tno' => 'KCP-CLAIM-TNO-9']);
        $this->app->instance(NhnKcpApiService::class, $mock);

        $payment = $this->makePaidPayment(['refunded_amount' => 500]);

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 1000.0, '차액 환급', 'g7-claimpay-9-50000-100000');

        $this->assertTrue($result['success']);
    }

    /**
     * 결제대행사 호출 오류는 실패 결과로 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=pg_api_error
     *
     * @effects kcp_claim_refund_pg_api_error_returns_failure
     */
    public function test_pg_api_error_returns_a_failure_result(): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('cancelPayment')->willThrowException(new \RuntimeException('KCP cancel failed'));
        $this->app->instance(NhnKcpApiService::class, $mock);

        $payment = $this->makePaidPayment();

        $result = app(ClaimPaymentRefundListener::class)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-9-0-300000');

        $this->assertFalse($result['success']);
        $this->assertSame('PG_API_ERROR', $result['error_code']);
    }
}
