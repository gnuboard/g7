<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 반려·철회와 추가결제 환급은 한 트랜잭션 — 환급이 거절되면 반려·철회도 되돌린다 (S6 · D2)
 *
 * 예전에는 종결 전이를 먼저 커밋한 뒤 트랜잭션 밖에서 환급을 불렀다. 카드 회수비를 낸 반품을 반려했는데 PG 가 환급을
 * 거절하면 신청서는 반려로 굳고 결제 건은 PAID 로 남아, 다시 시도할 길이 없었다(반려는 이미 끝난 전이다).
 *
 * 시나리오 매니페스트: `tests/scenarios/claim-terminate-refund-atomicity.yaml`
 */
class TerminateRollsBackOnChargeRefundFailureTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private AdminClaimService $admin;

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
        $this->admin = app(AdminClaimService::class);
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
     * 회수비 3,000원을 카드로 낸 반품을 만듭니다.
     *
     * @return array{0: OrderReturn, 1: ClaimPayment} [반품, 결제 건]
     */
    private function cardPaidReturn(): array
    {
        $order = $this->deliveredClaimOrder();
        $returns = app(OrderReturnService::class);
        $return = $returns->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);

        $payment = app(ClaimPaymentService::class)->initiateForRequest($return, PaymentMethodEnum::CARD->value);
        $payment->forceFill([
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'transaction_id' => 'CLAIM-TXN-S6',
            'pg_provider' => 'tosspayments',
            'paid_at' => now(),
        ])->save();
        $return->forceFill(['return_status' => ReturnStatusEnum::REQUESTED])->save();

        return [$return->fresh(), $payment->fresh()];
    }

    /**
     * 환급이 거절되면 반려를 되돌려 신청서와 결제 건이 그대로 남는다(다시 시도할 수 있다).
     *
     * @scenario refund_result=declined
     *
     * @effects terminate_rolls_back_when_charge_refund_fails
     *
     * @return void 반환값 없음
     */
    public function test_reject_rolls_back_when_charge_refund_is_declined(): void
    {
        [$return, $payment] = $this->cardPaidReturn();
        $this->fakeClaimPaymentRefund(false);

        try {
            $this->admin->transition($return, ReturnStatusEnum::REJECTED->value, ['reject_reason' => '재고 없음']);
            $this->fail('환급이 거절됐는데 반려가 완료됐습니다.');
        } catch (\Throwable) {
            // 기대한 중단
        }

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $payment->fresh()->payment_status);
    }

    /**
     * 재시도에서 환급이 되면 반려되고 결제 건은 환급 완료다.
     *
     * @scenario refund_result=accepted
     *
     * @effects terminate_rolls_back_when_charge_refund_fails
     *
     * @return void 반환값 없음
     */
    public function test_reject_retry_succeeds_after_refund_is_accepted(): void
    {
        [$return, $payment] = $this->cardPaidReturn();
        $this->fakeClaimPaymentRefund(true);

        $this->admin->transition($return, ReturnStatusEnum::REJECTED->value, ['reject_reason' => '재고 없음']);

        $this->assertSame(ReturnStatusEnum::REJECTED, $return->fresh()->return_status);
        $this->assertSame(ClaimPaymentStatusEnum::REFUNDED, $payment->fresh()->payment_status);
    }
}
