<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 금액 수정이 **아직 결제하지 않은** 청구를 따라가는지 단위 테스트
 *
 * 운영자가 클레임 상세에서 청구액을 고치면 화면의 총 청구액은 바뀌는데 **결제행은 그대로 남았다.**
 * 그래서 두 가지가 동시에 벌어졌다(2026-09-22 #599 3차 실측 L-6·L-7):
 *
 * - **깎았는데 옛 결제창이 남는다** — 회수비 3,000원 청구행을 만든 뒤 운영자가 0원으로 낮춰도
 *   구매자 화면에는 3,000원 결제 버튼이 그대로 있었다. 안 받기로 한 돈을 받을 수 있었다.
 * - **올렸는데 낼 자리가 없다** — 청구 0원 신청서의 배송비를 4,000원으로 올리면 화면은
 *   「미수납 4,000원」이라고 적는데 `claim_payments` 에는 행이 하나도 없어 결제가 불가능했다.
 *
 * 원인은 관리자 경로에 차액 정산이 아예 연결돼 있지 않은 것이다 —
 * `ClaimPaymentService::settleChargeDifference()` 를 부르는 곳은 구매자 수정
 * (`UserClaimService::update()`) 뿐이었고, `AdminClaimService::updateAmounts()` 는 금액 컬럼만
 * 갱신하고 활동로그 훅을 쏜 뒤 끝났다.
 *
 * **이미 결제한 건은 손대지 않는다**(운영 결정 2026-09-23). 초과 수납이 생겨도 자동 환급하지 않고
 * 운영이 처리한다 — 관리자 금액 수정에 자동 환급을 하기로 한 합의가 없었고(`modules/_bundled/sirsoft-ecommerce/docs/api/admin-claims.md`
 * 의 `updateAmounts` 응답에 환급 기술이 없다), 운영자가 금액을 고치는 것은 대개 운영상의 판단이기
 * 때문이다. 그래서 정산은 **수납액이 0 일 때만** 부른다. 그 조건에서는 차액이 음수가 될 수 없어
 * (`difference = total − 0`) 환급 경로 자체를 타지 않는다.
 */
class AdminClaimAmountSyncsUnpaidPaymentTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected AdminClaimService $admin;

    protected ClaimPaymentService $payments;

    protected OrderReturnService $returns;

    /**
     * 서비스와 테스트 통화·사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();

        $this->admin = app(AdminClaimService::class);
        $this->payments = app(ClaimPaymentService::class);
        $this->returns = app(OrderReturnService::class);
    }

    /**
     * 회수비가 청구된 반품 신청서를 결제대기까지 밀어 **미결제** 대기행을 만듭니다.
     *
     * @param  float  $fee  청구할 회수 배송비
     * @return ClaimPayment 결제 대기 건
     */
    protected function pendingPayment(int $fee = 3000): ClaimPayment
    {
        $order = $this->deliveredClaimOrder(returnFee: $fee);

        $return = $this->returns->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);

        return $this->payments->initiateForRequest($return, PaymentMethodEnum::CARD->value);
    }

    /**
     * 청구액을 0 으로 낮추면 아직 안 낸 결제행이 닫히는지 검증합니다.
     *
     * 남겨 두면 **안 받기로 한 돈을 구매자가 낼 수 있다.**
     *
     * @return void 반환값 없음
     *
     * @scenario direction=down_to_zero, payment_state=unpaid
     *
     * @effects claim_amount_edit_cancels_the_unpaid_payment_when_charge_drops_to_zero
     */
    public function test_청구액을_0_으로_낮추면_미결제_결제행이_취소된다(): void
    {
        $payment = $this->pendingPayment(3000);
        $return = $payment->payable;

        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);

        $this->admin->updateAmounts($return, ['claim_shipping_fee' => 0]);

        $this->assertSame(
            0.0,
            (float) $return->fresh()->total_charge_amount,
            '총 청구액이 0 으로 내려가지 않았다',
        );
        $this->assertSame(
            ClaimPaymentStatusEnum::CANCELLED,
            $payment->fresh()->payment_status,
            '안 받기로 한 금액의 결제행이 살아남았다 — 구매자가 그 건으로 결제할 수 있다',
        );
    }

    /**
     * 청구액을 내리면 아직 안 낸 결제행의 금액도 따라 내려가는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario direction=down, payment_state=unpaid
     *
     * @effects claim_amount_edit_moves_the_unpaid_payment_with_the_charge
     */
    public function test_청구액을_내리면_미결제_결제행_금액도_따라_내려간다(): void
    {
        $payment = $this->pendingPayment(3000);
        $return = $payment->payable;

        $this->admin->updateAmounts($return, ['claim_shipping_fee' => 1000]);

        $this->assertSame(
            1000.0,
            (float) $this->payments->outstandingAmount($return->fresh()),
            '미수납액이 새 청구액과 어긋난다',
        );
        $this->assertSame(
            1000.0,
            (float) $payment->fresh()->getTotalAmount(),
            '결제행이 옛 금액을 들고 있다 — 구매자가 고치기 전 금액을 내게 된다',
        );
    }

    /**
     * 청구 0원 신청서의 금액을 올리면 낼 결제행이 생기는지 검증합니다.
     *
     * 화면은 「미수납 N원」이라고 적는데 결제행이 없으면 **낼 자리가 없다.**
     *
     * @return void 반환값 없음
     *
     * @scenario direction=up_from_zero, payment_state=unpaid
     *
     * @effects claim_amount_edit_opens_a_payment_when_the_charge_rises_from_zero
     */
    public function test_청구액을_올리면_낼_결제행이_생긴다(): void
    {
        $order = $this->deliveredClaimOrder(returnFee: 0);

        $return = $this->returns->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'requested_by' => $order->user_id,
        ]);

        $this->assertSame(0, ClaimPayment::where('payable_id', $return->id)->count());

        $this->admin->updateAmounts($return, [
            'claim_shipping_fee' => 4000,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST->value,
        ]);

        $created = ClaimPayment::where('payable_id', $return->id)
            ->where('payable_type', 'return')
            ->first();

        $this->assertNotNull($created, '미수납이 생겼는데 낼 결제행이 만들어지지 않았다');
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $created->payment_status);
        $this->assertSame(4000.0, (float) $created->getTotalAmount());
    }

    /**
     * **이미 결제한 건**은 금액을 고쳐도 결제행을 건드리지 않는지 검증합니다.
     *
     * 운영 결정 2026-09-23 — 초과 수납은 자동 환급하지 않고 운영이 처리한다. 이 테스트가 없으면
     * 위 세 테스트를 만족시키려다 결제 완료 건까지 정산 경로로 끌어들이기 쉽다.
     *
     * @return void 반환값 없음
     *
     * @scenario direction=down, payment_state=paid
     *
     * @effects claim_amount_edit_leaves_the_paid_payment_untouched
     */
    public function test_이미_결제한_건은_금액을_고쳐도_결제행을_건드리지_않는다(): void
    {
        $payment = $this->pendingPayment(3000);
        $return = $payment->payable;

        // 마일리지로 완납시킨다(PG 왕복 없이 paid 상태를 만든다). 잔액을 먼저 채운다.
        MileageTransaction::create([
            'user_id' => $return->order->user_id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 10000,
            'remaining_amount' => 10000,
            'balance_after' => 10000,
        ]);

        $paid = $this->payments->payWithMileage($payment->fresh(), (int) $return->order->user_id);
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $paid->fresh()->payment_status);

        $this->admin->updateAmounts($return, ['claim_shipping_fee' => 1000]);

        $after = $paid->fresh();
        $this->assertSame(
            ClaimPaymentStatusEnum::PAID,
            $after->payment_status,
            '결제 완료 건의 상태가 바뀌었다 — 운영으로 풀기로 한 영역이다',
        );
        $this->assertSame(
            0.0,
            (float) $after->refunded_amount,
            '자동 환급이 일어났다 — 합의된 동작이 아니다',
        );
    }
}
