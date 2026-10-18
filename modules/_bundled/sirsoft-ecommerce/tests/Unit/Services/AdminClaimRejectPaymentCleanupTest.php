<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 반려가 대기 결제행을 정리하는지 단위 테스트
 *
 * **종결은 돈을 닫는 자리다.** 철회(`UserClaimService::withdraw()`)는 종결 직후
 * `refundPaymentsForClaim()` 으로 대기 건을 취소하고 낸 돈을 환급한다. 반려도 같은 종결인데
 * 그 정리를 하지 않으면 **이미 반려된 신청서에 구매자가 돈을 낼 수 있다** — 결제 가드가 보는
 * 것은 결제행의 `isPending()` 뿐이고 신청서 상태가 아니기 때문이다(`ClaimPaymentService::pay():250`).
 * 그렇게 들어온 돈은 되돌릴 경로도 없다. 반려는 이미 끝난 전이라 환급을 부를 자리가 없다.
 *
 * 고아 정리 배치도 이 정리를 전제로 적혀 있다 —
 * `CancelOrphanClaimPaymentsCommand` 헤더: 「**종결 경로는 대기 건을 함께 취소하므로**
 * (`cancelPendingPayments()`), 이 배치의 대상은 그러지 못한 채 끝난 건(프로세스 중단 등)이다」.
 * 반려가 정리하지 않으면 그 전제가 깨지고, 배치의 보존 기간 동안 결제가 열린 채로 남는다.
 *
 * 회귀 배경 (#599 3차 실측 F-9, 2026-09-22): 관리자 화면에서 교환을 반려한 직후 구매자 상세에
 * [결제하기]가 그대로 서 있었고, 눌렀더니 **토스 결제창이 열렸다**.
 */
class AdminClaimRejectPaymentCleanupTest extends ModuleTestCase
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
     * 청구가 있는 반품 신청서를 결제대기까지 밀어 결제 대기행을 만듭니다.
     *
     * @return ClaimPayment 결제 대기 건
     */
    protected function pendingPayment(): ClaimPayment
    {
        $order = $this->deliveredClaimOrder();

        $return = $this->returns->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);

        return $this->payments->initiateForRequest($return, PaymentMethodEnum::CARD->value);
    }

    /**
     * 반려가 대기 결제행을 함께 취소하는지 검증합니다.
     *
     * 남겨 두면 반려된 신청서에 결제창이 열리고, 들어온 돈을 되돌릴 경로가 없다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_reject_cancels_the_pending_payment
     */
    public function test_반려는_대기_결제행을_함께_취소한다(): void
    {
        $payment = $this->pendingPayment();
        $return = $payment->payable;

        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);

        $this->admin->transition($return, ReturnStatusEnum::REJECTED->value, [
            'reject_reason' => '재고 부족',
        ]);

        $this->assertSame(ReturnStatusEnum::REJECTED, $return->fresh()->return_status);
        $this->assertSame(
            ClaimPaymentStatusEnum::CANCELLED,
            $payment->fresh()->payment_status,
            '반려된 신청서에 결제 대기 건이 살아남았다 — 구매자가 그 건으로 결제할 수 있다',
        );
    }

    /**
     * 반려 뒤에는 결제를 시작할 수 없는지 검증합니다.
     *
     * 위 테스트가 상태만 본다면 이 테스트는 **결과**를 본다 — 상태를 고쳐도 결제가 열리면
     * 아무것도 막지 못한 것이다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_reject_closes_the_payment_entry
     */
    public function test_반려_뒤에는_결제를_시작할_수_없다(): void
    {
        $payment = $this->pendingPayment();
        $return = $payment->payable;

        $this->admin->transition($return, ReturnStatusEnum::REJECTED->value, [
            'reject_reason' => '재고 부족',
        ]);

        try {
            $this->payments->pay($payment->fresh(), PaymentMethodEnum::CARD->value, $return->order->user_id);
            $this->fail('반려된 신청서의 결제가 열렸다 — 구매자가 낸 돈을 되돌릴 경로가 없다');
        } catch (OrderClaimException $e) {
            $this->assertStringContainsString('claim_payment_not_pending', $e->getMessageKey());
        }
    }
}
