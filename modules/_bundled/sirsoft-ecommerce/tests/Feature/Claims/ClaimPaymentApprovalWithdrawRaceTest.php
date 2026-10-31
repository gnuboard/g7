<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 PG 결제 승인과 철회가 겹치면 승인을 망취소하고 실패로 답한다 (F5)
 *
 * 승인 확정은 「아직 결제 대기일 때만」 쓰는데, PG 승인이 오가는 사이 철회·고아 정리가 결제 건을 취소하면 확정이 빈 손으로
 * 끝났다. 그런데도 결제 건을 그대로 돌려줘 화면은 성공이었고, PG 에서 승인된 돈은 취소된 결제 건 뒤에 남았다.
 *
 * 시나리오 매니페스트: `tests/scenarios/claim-payment-approval-withdraw-race.yaml`
 */
class ClaimPaymentApprovalWithdrawRaceTest extends ModuleTestCase
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
     * 카드 결제 대기 건을 만듭니다.
     *
     * @return ClaimPayment 결제 대기 건
     */
    private function pendingCardPayment(): ClaimPayment
    {
        $order = $this->deliveredClaimOrder();
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);

        return app(ClaimPaymentService::class)->initiateForRequest($return, PaymentMethodEnum::CARD->value)->fresh();
    }

    /**
     * PG 승인 중에 결제 건이 취소되면 승인을 망취소하고, 결제 건을 성공으로 돌려주지 않는다.
     *
     * @scenario timing=withdrawn_during_approval
     *
     * @effects approval_racing_withdrawal_is_voided_and_fails
     *
     * @return void 반환값 없음
     */
    public function test_approval_racing_a_withdrawal_is_voided(): void
    {
        $payment = $this->pendingCardPayment();
        $voids = [];

        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', function (array $default, ClaimPayment $p) {
            // PG 왕복 사이에 철회가 결제 건을 취소했다
            ClaimPayment::whereKey($p->id)->update(['payment_status' => ClaimPaymentStatusEnum::CANCELLED->value]);

            return array_merge($default, [
                'handled' => true, 'success' => true, 'transaction_id' => 'TX-RACE', 'paid_amount' => 3000,
            ]);
        });
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.void_approval', function (array $default, ClaimPayment $p, array $callback, array $approve) use (&$voids) {
            $voids[] = $approve['transaction_id'];

            return ['handled' => true, 'success' => true, 'error_code' => null, 'error_message' => null];
        });

        try {
            app(ClaimPaymentService::class)->approvePgPayment($payment, ['paymentKey' => 'TX-RACE']);
            $this->fail('취소된 결제 건의 승인이 성공으로 끝났다');
        } catch (OrderClaimException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.claim_payment_withdrawn_during_approval', $e->getMessageKey());
        }

        $this->assertSame(['TX-RACE'], $voids, 'PG 에서 승인된 돈을 망취소하지 않았다');
        $this->assertSame(ClaimPaymentStatusEnum::CANCELLED, $payment->fresh()->payment_status);
    }
}
