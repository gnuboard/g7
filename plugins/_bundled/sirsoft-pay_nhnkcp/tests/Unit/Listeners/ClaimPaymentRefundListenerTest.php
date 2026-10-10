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

    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'error_code' => null, 'error_message' => null];
    }

    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentRefundListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.refund', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.refund']['type']);
    }

    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePaidPayment(['pg_provider' => 'kginicis']);

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertFalse($result['handled']);
    }

    public function test_missing_tno_is_rejected(): void
    {
        $payment = $this->makePaidPayment(['transaction_id' => null]);

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertFalse($result['success']);
        $this->assertSame('MISSING_TNO', $result['error_code']);
    }

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

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, '철회 환급', 'g7-claimpay-9-0-300000');

        $this->assertTrue($result['success']);
        $this->assertSame('KCP-CLAIM-TNO-9', $result['transaction_id']);
    }

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

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 1000.0, '차액 환급', 'g7-claimpay-9-50000-100000');

        $this->assertTrue($result['success']);
    }

    public function test_pg_api_error_returns_a_failure_result(): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('cancelPayment')->willThrowException(new \RuntimeException('KCP cancel failed'));
        $this->app->instance(NhnKcpApiService::class, $mock);

        $payment = $this->makePaidPayment();

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-9-0-300000');

        $this->assertFalse($result['success']);
        $this->assertSame('PG_API_ERROR', $result['error_code']);
    }
}
