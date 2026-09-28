<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 취소된 주문에 미수금이 남지 않는지 검증한다 (S1-D17)
 *
 * `total_due_amount` 는 「아직 받아야 할 돈」이다. 결제가 성사되면
 * `OrderProcessingService::completePayment()` 가 0 으로 내린다(1716행).
 *
 * 그런데 **결제 전에 취소된 주문은 그 경로를 지나지 않는다.** 무통장 미입금 주문을
 * 취소하면 돌려줄 돈이 없어 환불신청서도 만들어지지 않고(RF 0건), 취소신청서만 생긴다.
 * 그 결과 주문은 `cancelled` 인데 **미수금은 결제예정금액 그대로 남는다.**
 *
 * 실측(#599 통합실측 세션1 · 주문 20260915-1506049952): 무통장 5,000원 + 배송비 5,000원
 * = `total_due_amount 10,000` 으로 생성 → 미입금 상태에서 취소 → `order_status=cancelled` ·
 * `payment_status=cancelled` · `paid_amount_local=0` 인데 **`total_due_amount=10,000` 유지**.
 * 같은 DB 의 다른 취소 주문 9건은 전부 `due=0` 이다 — 결제를 거친 주문만 0 이 되기 때문이다.
 *
 * 계약: 전체취소로 확정되는 순간 미수금은 0 이다. 취소된 주문은 받을 돈이 없다.
 */
class CancelledOrderDueAmountTest extends ModuleTestCase
{
    private OrderCancellationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderCancellationService::class);
    }

    /**
     * 미입금 주문을 전체취소하면 미수금이 0 이 된다.
     *
     * @scenario payment_state=unpaid
     *
     * @effects cancelled_order_has_no_outstanding_due
     */
    public function test_full_cancel_of_unpaid_order_clears_due_amount(): void
    {
        $order = $this->makeUnpaidOrder();

        $this->assertSame(10000, (int) $order->total_due_amount, '전제: 미수금이 있는 주문');

        $this->cancelFully($order);

        $order->refresh();

        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertSame(
            0,
            (int) $order->total_due_amount,
            '취소된 주문에는 받을 돈이 없다 — 미수금이 남으면 장부가 계속 미결제를 말한다'
        );
    }

    /**
     * 결제를 마친 주문의 전체취소는 종전과 같다 — 과잉 변경 방지.
     *
     * @scenario payment_state=paid
     *
     * @effects cancelled_order_has_no_outstanding_due
     */
    public function test_full_cancel_of_paid_order_keeps_due_at_zero(): void
    {
        $order = $this->makeUnpaidOrder();
        $order->update([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_paid_amount' => 10000,
            'total_due_amount' => 0,
        ]);

        $this->cancelFully($order->fresh());

        $this->assertSame(0, (int) $order->fresh()->total_due_amount);
    }

    /**
     * 부분취소가 누적되어 전량 취소된 미입금 주문도 미수금이 0 이 된다.
     *
     * 전체취소 확정 블록과는 **다른 입구**다 — 잔여 활성 옵션이 0 이 되면
     * `syncParentOrderStatus` → `transitionOrderStatus` 가 `CANCELLED` 로 올린다.
     * 한 입구만 닫으면 이 경로로 들어올 때 또 미수금이 남는다.
     *
     * @scenario payment_state=unpaid_partial_accumulated
     *
     * @effects cancelled_order_has_no_outstanding_due
     */
    public function test_due_is_cleared_when_partial_cancels_accumulate_to_full(): void
    {
        $order = $this->makeUnpaidOrder();
        $option = $order->options->first();

        // 전체취소 경로를 지나지 않고 옵션만 취소로 내린 뒤 부모 상태를 파생시킨다
        $option->update(['option_status' => OrderStatusEnum::CANCELLED]);
        app(OrderOptionService::class)
            ->syncParentOrderStatus($order->id);

        $order->refresh();

        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertSame(
            0,
            (int) $order->total_due_amount,
            '부분취소 누적으로 취소된 주문도 받을 돈은 없다'
        );
    }

    /**
     * 미수금 10,000원을 가진 미입금 주문을 만듭니다.
     *
     * @return Order 주문
     */
    private function makeUnpaidOrder(): Order
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'total_paid_amount' => 0,
            'total_due_amount' => 10000,
        ]);

        OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        return $order->fresh(['options']);
    }

    /**
     * 주문을 전체취소합니다.
     *
     * @param  Order  $order  주문
     */
    private function cancelFully(Order $order): void
    {
        $this->service->cancelOrder($order, '단순 변심');
    }
}
