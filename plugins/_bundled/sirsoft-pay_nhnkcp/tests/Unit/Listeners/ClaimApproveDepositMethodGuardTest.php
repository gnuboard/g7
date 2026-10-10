<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayNhnkcp\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * KCP 클레임 추가결제의 가상계좌 판정이 클라이언트 값에 기대지 않는지 (#599 validate C2)
 *
 * 클레임 결제는 입금 대기 상태가 없어 가상계좌를 받지 않는다. 그런데 거절 판정이 **브라우저가
 * 보낸** `use_pay_method` 하나였다 — 그 값을 빼거나 바꿔 보내면 계좌만 발급됐는데(입금 전)
 * KCP 승인 응답이 0000 이라 결제 건이 결제완료로 확정됐다. 주문 경로는 KVE-2026-2018 때문에
 * 저장된 결제수단으로만 판정한다.
 *
 * 판정 층:
 *   ① 승인 전 — 결제 건에 **저장된** 결제수단이 가상계좌면 거절한다.
 *   ② 승인 뒤 — KCP 응답의 결제수단이 가상계좌면 발급분을 취소하고 거절한다.
 */
class ClaimApproveDepositMethodGuardTest extends PluginTestCase
{
    /**
     * KCP API 목을 바인딩합니다.
     *
     * @param  array  $approveResponse  승인 응답
     * @param  bool  $expectNoApprove  승인 호출이 없어야 하면 true
     * @param  bool  $expectCancel  발급분 취소가 한 번 있어야 하면 true
     * @return NhnKcpApiService API 서비스 목
     */
    private function kcp(array $approveResponse = [], bool $expectNoApprove = false, bool $expectCancel = false): NhnKcpApiService
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('getSiteCd')->willReturn('T0000');
        $mock->method('isTestMode')->willReturn(true);

        if ($expectNoApprove) {
            $mock->expects($this->never())->method('approvePayment');
        } else {
            $mock->method('approvePayment')->willReturn($approveResponse);
        }

        $mock->expects($expectCancel ? $this->once() : $this->never())->method('cancelPayment')->willReturn(['res_cd' => '0000']);

        $this->app->instance(NhnKcpApiService::class, $mock);

        return $mock;
    }

    /**
     * KRW 결제 대기 건을 만듭니다.
     *
     * @param  array  $overrides  덮어쓸 속성
     * @return ClaimPayment 결제 건
     */
    private function pendingKrwPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'nhnkcp',
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
        ], $overrides));
    }

    /**
     * 결제창 콜백을 만듭니다. (클라이언트가 결제수단 값을 빼고 보낸 모양)
     *
     * @param  array  $overrides  덮어쓸 값
     * @return array 콜백
     */
    private function windowCallback(array $overrides = []): array
    {
        return array_merge(['res_cd' => '0000', 'enc_data' => 'e', 'enc_info' => 'i', 'good_mny' => 3000, 'cust_ip' => '127.0.0.1'], $overrides);
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
     * 결제 건에 저장된 결제수단이 가상계좌면 콜백이 무엇을 말하든 승인 전에 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario judged_by=stored_method, callback_method=omitted
     *
     * @effects kcp_claim_deposit_method_is_judged_by_the_stored_method
     */
    public function test_a_stored_virtual_account_method_is_refused_before_approval(): void
    {
        $this->kcp(expectNoApprove: true);
        $payment = $this->pendingKrwPayment(['payment_method' => 'vbank']);

        $result = $this->approve($payment, $this->windowCallback());

        $this->assertFalse($result['success']);
        $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
    }

    /**
     * 콜백이 결제수단을 숨겨도 KCP 응답이 가상계좌를 말하면 발급분을 취소하고 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario judged_by=pg_response, callback_method=omitted
     *
     * @effects kcp_claim_deposit_method_is_rechecked_on_the_pg_response
     */
    public function test_a_virtual_account_in_the_pg_response_is_cancelled_and_refused(): void
    {
        foreach (['VCNT', '001000000000'] as $code) {
            $this->kcp(['res_cd' => '0000', 'tno' => 'TNO-V', 'use_pay_method' => $code], expectCancel: true);
            $payment = $this->pendingKrwPayment(['payment_method' => 'card']);

            $result = $this->approve($payment, $this->windowCallback());

            $this->assertFalse($result['success'], $code);
            $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
        }
    }

    /**
     * 응답에 금액이 실려 오면 브라우저 값(good_mny)보다 그 값으로 판정한다.
     *
     * 브라우저 값은 청구액과 같아도(3000) 응답 금액(2500)이 다르면 승인분을 취소하고 거절한다 —
     * 대조를 모듈에 넘기면 그 거절의 보상은 서버 콜백 경로에만 있어 클라이언트 승인 API 경로에서는
     * 청구된 채 남는다(#599 validate 07 #8 로 판정을 리스너로 옮겼다).
     *
     * @return void 반환값 없음
     *
     * @scenario judged_by=pg_response, callback_method=card
     *
     * @effects kcp_claim_paid_amount_prefers_the_pg_response
     */
    public function test_the_pg_response_amount_is_preferred_over_the_browser_value(): void
    {
        $this->kcp(['res_cd' => '0000', 'tno' => 'TNO-A', 'use_pay_method' => 'CARD', 'amount' => '2500'], expectCancel: true);
        $payment = $this->pendingKrwPayment(['payment_method' => 'card']);

        $result = $this->approve($payment, $this->windowCallback(['use_pay_method' => 'CARD', 'good_mny' => 3000]));

        $this->assertFalse($result['success'], '브라우저가 보낸 good_mny 로 대조하면 결제창 금액 변조를 못 잡는다');
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
    }

    /**
     * 카드 결제는 그대로 승인된다(비회귀).
     *
     * @return void 반환값 없음
     *
     * @scenario judged_by=pg_response, callback_method=card
     *
     * @effects kcp_claim_card_approval_is_unchanged
     */
    public function test_a_card_approval_is_unchanged(): void
    {
        $this->kcp(['res_cd' => '0000', 'tno' => 'TNO-C', 'use_pay_method' => 'CARD', 'amount' => '3000']);
        $payment = $this->pendingKrwPayment(['payment_method' => 'card']);

        $result = $this->approve($payment, $this->windowCallback(['use_pay_method' => 'CARD']));

        $this->assertTrue($result['success']);
        $this->assertSame('TNO-C', $result['transaction_id']);
    }
}
