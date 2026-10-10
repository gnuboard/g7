<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Listeners;

use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayKginicis\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Support\CbtClaimApproveToken;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * CBT(엔화) 클레임 추가결제 승인이 sid 를 **이 결제 건**에 묶어 확인하는지 (#599 validate C1)
 *
 * CBT 승인 재료는 sid 하나다. 승인 응답에는 금액·주문번호가 없다(매뉴얼상 미사용 — 이력
 * 20260629). 그래서 싼 결제 건(CP-A)으로 연 결제창의 sid 를 비싼 결제 건(CP-B)의 승인에 실어
 * 보내면, KG 는 CP-A 의 금액으로 승인하고 모듈은 CP-B 를 결제완료로 확정했다.
 *
 * 막는 층은 둘이다.
 *   ① 승인 재료(sid)는 **서버 콜백 컨트롤러만** 넘길 수 있다 — 결제 건 번호와 sid 를 서버 키로
 *      서명한 토큰이 함께 와야 한다. 클라이언트 승인 API 로 sid 를 보내면 서명이 없어 거절된다.
 *   ② 응답에 주문번호·MID·통화·결제수단·금액이 실려 오면 주문 경로와 같게 대조한다.
 */
class CbtClaimApproveBindingTest extends PluginTestCase
{
    private static int $sequence = 0;

    /**
     * 엔화 결제 대기 클레임 결제 건을 만듭니다.
     *
     * @return ClaimPayment 결제 건 (청구 500 JPY)
     */
    private function pendingJpyClaimPayment(): ClaimPayment
    {
        $seq = ++self::$sequence;

        $order = OrderFactory::new()->create([
            'order_number' => 'ORD-CBTBIND-'.$seq,
            'currency' => 'JPY',
            'currency_snapshot' => [
                'base_currency' => 'JPY',
                'order_currency' => 'JPY',
                'exchange_rates' => [
                    'JPY' => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round'],
                ],
            ],
        ]);

        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-CBTBIND-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 500,
        ]);

        return ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-CBTBIND-'.$seq,
            'pg_provider' => 'kginicis',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'currency_code' => 'JPY',
            'amount' => 500,
        ]);
    }

    /**
     * 일본 CBT API 목을 바인딩합니다.
     *
     * @return Mockery\MockInterface&KgInicisApiService API 서비스 목
     */
    private function japanApi(): Mockery\MockInterface
    {
        $api = Mockery::mock(KgInicisApiService::class)->makePartial();
        $api->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $api->shouldReceive('isTestMode')->andReturn(true);
        $this->app->instance(KgInicisApiService::class, $api);

        return $api;
    }

    /**
     * 서버 콜백 컨트롤러가 넘기는 모양의 콜백을 만듭니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  string  $sid  CBT 세션 ID
     * @param  array  $extra  추가 파라미터
     * @return array 콜백
     */
    private function serverCallback(ClaimPayment $payment, string $sid, array $extra = []): array
    {
        return ['cbt_sid' => $sid, 'cbt_token' => CbtClaimApproveToken::sign($payment->payment_number, $sid)] + $extra;
    }

    /**
     * 승인 리스너를 부릅니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $callback  콜백
     * @return array 승인 결과
     */
    private function approve(ClaimPayment $payment, array $callback): array
    {
        return app(ClaimPaymentApproveListener::class)->processApprove(['handled' => false], $payment, $callback);
    }

    /**
     * 서명 없이 sid 만 실어 보낸 승인(클라이언트 승인 API 경로)은 PG 를 부르지 않고 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario caller=client_approve_api, response=clean
     *
     * @effects cbt_claim_approve_requires_the_server_callback_signature
     */
    public function test_a_sid_without_the_server_signature_is_refused_before_calling_the_pg(): void
    {
        $payment = $this->pendingJpyClaimPayment();
        $api = $this->japanApi();
        $api->shouldNotReceive('approveCbtPayment');

        $result = $this->approve($payment, ['cbt_sid' => 'SID-FROM-A-CHEAP-PAYMENT']);

        $this->assertFalse($result['success']);
        $this->assertSame('CBT_APPROVE_UNVERIFIED', $result['error_code']);
    }

    /**
     * 다른 결제 건에 발급된 서명은 이 결제 건의 승인에 쓸 수 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario caller=forged_signature, response=clean
     *
     * @effects cbt_claim_approve_requires_the_server_callback_signature
     */
    public function test_a_signature_issued_for_another_payment_is_refused(): void
    {
        $payment = $this->pendingJpyClaimPayment();
        $api = $this->japanApi();
        $api->shouldNotReceive('approveCbtPayment');

        $result = $this->approve($payment, [
            'cbt_sid' => 'SID-X',
            'cbt_token' => CbtClaimApproveToken::sign('CP-SOMEONE-ELSE', 'SID-X'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('CBT_APPROVE_UNVERIFIED', $result['error_code']);
    }

    /**
     * 응답의 주문번호가 이 결제 건이 아니면 승인분을 환불로 되돌리고 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario caller=server_callback, response=order_id_mismatch
     *
     * @effects cbt_claim_approve_matches_the_response_to_this_payment
     */
    public function test_a_response_for_another_order_id_is_refunded_and_refused(): void
    {
        $payment = $this->pendingJpyClaimPayment();
        $api = $this->japanApi();
        $api->shouldReceive('approveCbtPayment')->once()->andReturn([
            'resultCode' => 'OK', 'tid' => 'TID-1', 'paymethod' => 'CARD', 'orderId' => 'CP-OTHER',
        ]);
        $api->shouldReceive('refundCbtPayment')->once()->with('TID-1', null, Mockery::any())->andReturn(['resultCode' => '00']);

        $result = $this->approve($payment, $this->serverCallback($payment, 'SID-1'));

        $this->assertFalse($result['success']);
        $this->assertSame('CBT_APPROVE_MISMATCH', $result['error_code']);
    }

    /**
     * 응답의 MID·통화가 일본 가맹점·엔화가 아니면 환불로 되돌리고 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario caller=server_callback, response=mid_or_currency_mismatch
     *
     * @effects cbt_claim_approve_matches_the_response_to_this_payment
     */
    public function test_a_response_with_a_foreign_mid_or_currency_is_refunded_and_refused(): void
    {
        foreach ([['mid' => 'OTHERMID01'], ['currencyCd' => 'KRW']] as $mismatch) {
            $payment = $this->pendingJpyClaimPayment();
            $api = $this->japanApi();
            $api->shouldReceive('approveCbtPayment')->once()->andReturn(
                ['resultCode' => 'OK', 'tid' => 'TID-M', 'paymethod' => 'CARD'] + $mismatch
            );
            $api->shouldReceive('refundCbtPayment')->once()->andReturn(['resultCode' => '00']);

            $result = $this->approve($payment, $this->serverCallback($payment, 'SID-M'));

            $this->assertFalse($result['success'], json_encode($mismatch));
            $this->assertSame('CBT_APPROVE_MISMATCH', $result['error_code']);
        }
    }

    /**
     * 응답에 금액이 실려 오면 그 금액을 `paid_amount` 로 넘긴다 — 모듈이 청구액과 대조한다.
     *
     * @return void 반환값 없음
     *
     * @scenario caller=server_callback, response=amount_present
     *
     * @effects cbt_claim_approve_hands_the_response_amount_to_the_module
     */
    public function test_a_response_amount_is_handed_to_the_module_for_comparison(): void
    {
        $payment = $this->pendingJpyClaimPayment();
        $api = $this->japanApi();
        $api->shouldReceive('approveCbtPayment')->once()->andReturn([
            'resultCode' => 'OK', 'tid' => 'TID-A', 'paymethod' => 'PAYPAY', 'bokuApplPrice' => '500',
        ]);

        $result = $this->approve($payment, $this->serverCallback($payment, 'SID-A'));

        // 청구액(500)과 다른 응답 금액은 리스너가 환불로 되돌리고 거절한다(ValidateP2ClaimApproveTest).
        $this->assertTrue($result['success']);
        $this->assertSame(500, $result['paid_amount']);
    }

    /**
     * 응답에 결제수단이 없어도 콜백이 편의점(CVS)을 말하면 거절한다 — 어느 쪽이든 CVS 면 입금 대기다.
     *
     * @return void 반환값 없음
     *
     * @scenario caller=server_callback, response=paymethod_absent
     *
     * @effects cbt_claim_approve_refuses_cvs_from_either_source
     */
    public function test_cvs_is_refused_when_only_the_callback_says_so(): void
    {
        $payment = $this->pendingJpyClaimPayment();
        $api = $this->japanApi();
        $api->shouldReceive('approveCbtPayment')->once()->andReturn(['resultCode' => 'OK', 'tid' => 'TID-C']);
        $api->shouldReceive('refundCbtPayment')->once()->andReturn(['resultCode' => '00']);

        $result = $this->approve($payment, $this->serverCallback($payment, 'SID-C', ['paymethod' => 'CVS']));

        $this->assertFalse($result['success']);
        $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
    }

    /**
     * 서명이 맞고 응답이 깨끗하면 승인된다(비회귀). 응답에 금액이 없고 해시 발급 기록도 없으면 주문 경로와
     * 같게 결제 예정액을 승인액으로 보고한다 — 모듈이 `paid_amount` 부재를 불일치로 거절하기 때문이다.
     *
     * @return void 반환값 없음
     *
     * @scenario caller=server_callback, response=clean
     *
     * @effects cbt_claim_approve_still_succeeds_for_a_clean_response
     */
    public function test_a_signed_callback_with_a_clean_response_is_approved(): void
    {
        $payment = $this->pendingJpyClaimPayment();
        $api = $this->japanApi();
        $api->shouldReceive('approveCbtPayment')->once()->with('SID-OK')->andReturn([
            'resultCode' => 'OK', 'tid' => 'TID-OK', 'paymethod' => 'CARD',
        ]);
        $api->shouldNotReceive('refundCbtPayment');

        $result = $this->approve($payment, $this->serverCallback($payment, 'SID-OK'));

        $this->assertTrue($result['success']);
        $this->assertSame('TID-OK', $result['transaction_id']);
        $this->assertSame(500, $result['paid_amount']);
    }
}
