<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 청구액을 이미 받은 뒤 운영자가 올리면 차액을 받을 자리가 생기고, 미수납이면 완료할 수 없다 (S3)
 *
 * 「신청 시점 결제」로 회수비 3,000원을 낸 반품을 운영자가 5,000원으로 올리면 예전에는 결제 자리가 생기지 않았고
 * (수납액이 0 일 때만 정산), 미수납인 채로 완료가 통과했다.
 *
 * @scenario claim-charge-increase-after-payment
 */
class ChargeIncreaseAfterPaymentTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private AdminClaimService $admin;

    private ClaimPaymentService $payments;

    private OrderReturnService $returns;

    /**
     * 통화·사유·마일리지를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);

        $this->admin = app(AdminClaimService::class);
        $this->payments = app(ClaimPaymentService::class);
        $this->returns = app(OrderReturnService::class);
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
     * 회수비 3,000원을 마일리지로 이미 낸 반품을 만듭니다.
     *
     * @return OrderReturn 결제완료 반품
     */
    private function paidReturn(): OrderReturn
    {
        $order = $this->deliveredClaimOrder();
        $return = $this->returns->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);
        $this->grantMileage((int) $return->requested_by, 20000);

        $payment = $this->payments->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        $this->payments->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        return $return->fresh(['returnOptions']);
    }

    /**
     * 반품을 회수 완료까지 옮깁니다.
     *
     * @param  OrderReturn  $return  반품
     * @return OrderReturn 회수 완료 반품
     */
    private function collected(OrderReturn $return): OrderReturn
    {
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::APPROVED);
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::COLLECTION_READY);
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::COLLECTING, $this->claimTrackingFor(ReturnStatusEnum::COLLECTING));

        return $this->returns->transition($return->fresh(), ReturnStatusEnum::COLLECTED);
    }

    /**
     * 이미 3,000원을 낸 뒤 청구액을 5,000원으로 올리면 2,000원 결제 대기 건이 생긴다.
     *
     * @scenario charge_method=pay_on_request, stage=after_payment
     *
     * @effects charge_increase_after_payment_creates_pending_difference
     *
     * @return void 반환값 없음
     */
    public function test_increase_after_payment_creates_pending_difference(): void
    {
        $return = $this->paidReturn();

        $this->admin->updateAmounts($return, ['claim_shipping_fee' => 5000]);

        $pending = $this->payments->listForClaim($return->fresh())->first(fn ($p) => $p->isPending());
        $this->assertNotNull($pending, '차액을 낼 결제 대기 건이 없다');
        $this->assertEquals(2000, $pending->getTotalAmount());
    }

    /**
     * 승인 시점 결제로 이미 받은 반품도 청구액을 올리면 차액 결제 대기 건이 생긴다 — 승인 시도는 다시 오지 않는다.
     *
     * @scenario charge_method=pay_on_approval, stage=after_payment
     *
     * @effects charge_increase_after_payment_creates_pending_difference
     *
     * @return void 반환값 없음
     */
    public function test_increase_after_approval_payment_creates_pending_difference(): void
    {
        $return = $this->paidReturn();
        // 승인 시점 결제로 받은 건과 같은 모양 — 결제 건은 PAID, 청구 방식은 승인 시점
        $return->forceFill(['charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL])->save();

        $this->admin->updateAmounts($return->fresh(['returnOptions']), ['claim_shipping_fee' => 5000]);

        $pending = $this->payments->listForClaim($return->fresh())->first(fn ($p) => $p->isPending());
        $this->assertNotNull($pending, '승인 시점 결제 건의 차액을 낼 결제 대기 건이 없다');
        $this->assertEquals(2000, $pending->getTotalAmount());
    }

    /**
     * 미수납이 남은 반품은 완료할 수 없다.
     *
     * @scenario charge_method=pay_on_request, stage=after_payment
     *
     * @effects claim_completion_blocked_while_outstanding
     *
     * @return void 반환값 없음
     */
    public function test_completion_is_blocked_while_outstanding(): void
    {
        $return = $this->paidReturn();
        $this->admin->updateAmounts($return, ['claim_shipping_fee' => 5000]);
        $return = $this->collected($return);

        try {
            $this->admin->complete($return->fresh(), [], new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true));
            $this->fail('미수납인 채로 완료됐습니다.');
        } catch (OrderClaimException $e) {
            $this->assertSame(__('sirsoft-ecommerce::exceptions.claim_payment_outstanding'), $e->getMessage());
        }

        $this->assertNotSame(ReturnStatusEnum::COMPLETED, $return->fresh()->return_status);
    }

    /**
     * 차액을 내면 완료된다.
     *
     * @scenario charge_method=pay_on_request, stage=after_payment
     *
     * @effects claim_completion_blocked_while_outstanding
     *
     * @return void 반환값 없음
     */
    public function test_completion_succeeds_after_paying_the_difference(): void
    {
        $return = $this->paidReturn();
        $this->admin->updateAmounts($return, ['claim_shipping_fee' => 5000]);
        $pending = $this->payments->listForClaim($return->fresh())->first(fn ($p) => $p->isPending());
        $this->payments->pay($pending, PaymentMethodEnum::POINT->value, (int) $return->requested_by);
        $return = $this->collected($return);

        $completed = $this->admin->complete($return->fresh(), [], new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true));

        $this->assertSame(ReturnStatusEnum::COMPLETED, $completed->fresh()->return_status);
    }
}
