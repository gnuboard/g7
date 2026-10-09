<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 추가결제 PG 환급은 결제사가 준 환급 거래 ID 를 남긴다 (P-D11)
 *
 * 환급 결과의 `transaction_id` 를 버려, 결제사 정산·취소 내역과 대사할 근거가 우리 쪽에 없었다. 부분 환급이 여러 번이면
 * 몇 번째 환급이 어느 거래인지도 알 수 없었다.
 *
 * 시나리오 매니페스트: `tests/scenarios/claim-payment-refund-transaction-record.yaml`
 */
class ClaimPaymentRefundTransactionRecordTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화·사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 회수비 3,000원을 카드로 낸 결제 건을 만듭니다.
     *
     * @return ClaimPayment 결제 완료 건
     */
    private function cardPaidPayment(): ClaimPayment
    {
        $order = $this->deliveredClaimOrder();
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);

        $payment = app(ClaimPaymentService::class)->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $payment->forceFill([
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'transaction_id' => 'CLAIM-TXN-PD11',
            'pg_provider' => 'tosspayments',
            'paid_at' => now(),
        ])->save();

        return $payment->fresh();
    }

    /**
     * 부분 환급을 두 번 하면 환급마다 결제사 거래 ID·금액이 차례로 남는다.
     *
     * @scenario refunds=partial_twice
     *
     * @effects claim_payment_refund_keeps_the_pg_transaction_id
     *
     * @return void 반환값 없음
     */
    public function test_each_pg_refund_keeps_its_transaction_id(): void
    {
        $payment = $this->cardPaidPayment();
        $this->fakeClaimPaymentRefund(true);
        $service = app(ClaimPaymentService::class);

        $service->refundPayment($payment, 1000.0);
        $service->refundPayment($payment->fresh(), 2000.0);

        $refunds = $payment->fresh()->raw_response['refunds'] ?? [];
        $this->assertCount(2, $refunds, '환급 거래가 기록되지 않았다');
        $this->assertSame('RTXN-1', $refunds[0]['transaction_id']);
        $this->assertEquals(1000, $refunds[0]['amount']);
        $this->assertSame('RTXN-2', $refunds[1]['transaction_id']);
        $this->assertSame('CLAIM-TXN-PD11', $payment->fresh()->transaction_id, '승인 거래 ID 는 그대로다');
    }
}
