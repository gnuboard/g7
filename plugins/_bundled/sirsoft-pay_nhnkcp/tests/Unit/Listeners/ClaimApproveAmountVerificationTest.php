<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayNhnkcp\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 클레임·교환 추가결제 승인의 금액 근거가 KCP 서버 승인 응답인지 검증한다.
 *
 * 수정 전에는 승인 요청에 청구액(ordr_mony)이 실리지 않았고, 승인 응답에 금액이 없거나 0 이하면
 * 브라우저 콜백의 good_mny 를 승인액으로 넘겼다. 거래번호도 응답에 없으면 브라우저 tno 로 채웠다.
 * 이제 응답 금액이 청구액과 다르거나 쓸 수 없으면 승인분을 취소하고 AMOUNT_MISMATCH 로 거절한다.
 */
class ClaimApproveAmountVerificationTest extends PluginTestCase
{
    /**
     * KCP API 목을 바인딩합니다. 승인은 청구액을 5번째 인수(ordr_mony)로 받아야 한다.
     *
     * @param  array  $approveResponse  CLI 승인 응답
     * @param  ClaimPayment  $payment  결제 건 (결제번호 대조용)
     * @param  string|null  $expectCancelTno  취소가 한 번 있어야 하면 그 거래번호, 없어야 하면 null
     * @return NhnKcpApiService API 서비스 목
     */
    private function kcp(array $approveResponse, ClaimPayment $payment, ?string $expectCancelTno = null): NhnKcpApiService
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('getSiteCd')->willReturn('T0000');
        $mock->method('isTestMode')->willReturn(true);

        $mock->expects($this->once())
            ->method('approvePayment')
            ->with($this->anything(), $this->anything(), $payment->payment_number, $this->anything(), 3000)
            ->willReturn($approveResponse);

        if ($expectCancelTno === null) {
            $mock->expects($this->never())->method('cancelPayment');
        } else {
            $mock->expects($this->once())
                ->method('cancelPayment')
                ->with($expectCancelTno, $payment->payment_number, $this->anything(), $this->anything())
                ->willReturn(['res_cd' => '0000']);
        }

        $this->app->instance(NhnKcpApiService::class, $mock);

        return $mock;
    }

    /**
     * 청구액 3000원의 KRW 카드 결제 대기 건을 만듭니다.
     *
     * @return ClaimPayment 결제 건
     */
    private function pendingKrwPayment(): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create([
            'pg_provider' => 'nhnkcp',
            'payment_method' => 'card',
            'amount' => 3000,
            'order_id' => OrderFactory::new()->create([
                'currency' => 'KRW',
                'currency_snapshot' => [
                    'base_currency' => 'KRW',
                    'order_currency' => 'KRW',
                    'exchange_rates' => [
                        'KRW' => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round'],
                    ],
                ],
            ])->id,
        ]);
    }

    /**
     * 카드 승인 성공 응답을 만듭니다.
     *
     * @param  array  $overrides  덮어쓸 값 (null 값은 키 제거)
     * @return array CLI 응답
     */
    private function approveResponse(array $overrides = []): array
    {
        $response = array_merge([
            'res_cd' => '0000',
            'res_msg' => 'OK',
            'tno' => 'KCP-CLAIM-AMT',
            'use_pay_method' => '100000000000',
        ], $overrides);

        return array_filter($response, static fn ($value) => $value !== null);
    }

    /**
     * 결제창 콜백을 만듭니다. (브라우저 good_mny 는 청구액과 같은 3000)
     *
     * @param  array  $overrides  덮어쓸 값
     * @return array 콜백
     */
    private function windowCallback(array $overrides = []): array
    {
        return array_merge([
            'res_cd' => '0000',
            'enc_data' => 'enc-data',
            'enc_info' => 'enc-info',
            'good_mny' => 3000,
            'use_pay_method' => 'CARD',
            'cust_ip' => '127.0.0.1',
        ], $overrides);
    }

    /**
     * 승인을 부릅니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $callback  콜백
     * @return array 승인 결과
     */
    private function approve(ClaimPayment $payment, array $callback): array
    {
        return (new ClaimPaymentApproveListener)->processApprove(['handled' => false, 'success' => false], $payment, $callback);
    }

    /**
     * 승인 요청에 청구액을 ordr_mony 로 싣고, 응답 금액이 청구액과 같으면 그 금액을 승인액으로 넘긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=claim, pg_amount=matches
     *
     * @effects kcp_approval_request_carries_the_order_amount_as_ordr_mony, kcp_approval_matching_amount_confirms_with_the_pg_amount
     */
    public function test_claim_approval_sends_ordr_mony_and_reports_the_pg_amount(): void
    {
        $payment = $this->pendingKrwPayment();
        $this->kcp($this->approveResponse(['amount' => '3000']), $payment);

        $result = $this->approve($payment, $this->windowCallback());

        $this->assertTrue($result['success']);
        $this->assertSame('KCP-CLAIM-AMT', $result['transaction_id']);
        $this->assertSame(3000, $result['paid_amount']);
        $this->assertSame('3000', $result['raw_response']['amount'] ?? null);
    }

    /**
     * 응답 금액이 청구액과 다르면 승인분을 취소하고 거절한다. (브라우저 good_mny 는 청구액과 같아도)
     *
     * @return void 반환값 없음
     *
     * @scenario path=claim, pg_amount=differs
     *
     * @effects kcp_approval_amount_mismatch_cancels_the_pg_approval, kcp_approval_amount_mismatch_leaves_the_payment_unconfirmed, kcp_approval_browser_amount_is_never_the_basis
     */
    public function test_claim_approval_with_a_different_response_amount_is_cancelled_and_refused(): void
    {
        $payment = $this->pendingKrwPayment();
        $this->kcp($this->approveResponse(['amount' => '2000']), $payment, expectCancelTno: 'KCP-CLAIM-AMT');

        $result = $this->approve($payment, $this->windowCallback());

        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
        $this->assertNull($result['transaction_id']);
        $this->assertNull($result['paid_amount']);
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
            'negative' => ['-3000'],
            'text' => ['abc'],
        ];
    }

    /**
     * 응답 금액이 없거나 0 이하이거나 숫자가 아니면 브라우저 good_mny 로 대신하지 않고 취소·거절한다.
     *
     * 수정 전에는 이 경우 청구액과 같은 브라우저 good_mny(3000)를 승인액으로 넘겨 확정됐다.
     *
     * @param  string|null  $amount  승인 응답의 금액 (null 이면 키 없음)
     * @return void 반환값 없음
     *
     * @scenario path=claim, pg_amount=missing
     *
     * @effects kcp_approval_missing_amount_is_treated_as_mismatch, kcp_approval_amount_mismatch_cancels_the_pg_approval, kcp_approval_browser_amount_is_never_the_basis
     */
    #[DataProvider('unusableResponseAmountProvider')]
    public function test_claim_approval_without_a_usable_response_amount_is_cancelled_and_refused(?string $amount): void
    {
        $payment = $this->pendingKrwPayment();
        $this->kcp($this->approveResponse(['amount' => $amount]), $payment, expectCancelTno: 'KCP-CLAIM-AMT');

        $result = $this->approve($payment, $this->windowCallback());

        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
        $this->assertNull($result['paid_amount']);
    }

    /**
     * 승인 성공 응답에 거래번호가 없으면 브라우저 tno 로 채우지 않고 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=claim, pg_amount=matches
     *
     * @effects kcp_approval_tno_comes_only_from_the_pg_response
     */
    public function test_claim_approval_without_a_pg_tno_is_refused_instead_of_using_the_browser_tno(): void
    {
        $payment = $this->pendingKrwPayment();
        $this->kcp($this->approveResponse(['tno' => null, 'amount' => '3000']), $payment);

        $result = $this->approve($payment, $this->windowCallback(['tno' => 'BROWSER-SUPPLIED-TNO']));

        $this->assertFalse($result['success']);
        $this->assertSame('MISSING_TNO', $result['error_code']);
        $this->assertNull($result['transaction_id']);
    }
}
