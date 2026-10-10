<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 환불이 주문 헤더의 세금 분류를 함께 내리는가 (S3-D17)
 *
 * `CashReceiptService::calculateIssuableAmount()` 는 `total_tax_amount`·`total_tax_free_amount`
 * 를 **그대로** 읽고, 그 주석이 「지금 이 주문이 얼마짜리인가만 읽으면 된다」를 계약으로
 * 명시한다. 그런데 반품·환불 경로는 그 컬럼을 갱신하지 않았다 — **전액 환불된 주문에
 * 현금영수증이 발급 가능하다고 답했다**(2026-09-16 실측: 주문 964 전액 환불 후
 * `issuable.amount = 62,000`).
 *
 * 전액취소 경로는 이미 0 으로 내린다(세션1 S1-D22). 반품·환불만 빠져 있었다 —
 * 같은 「돈을 다 돌려준 주문」인데 경로에 따라 헤더가 달랐다.
 *
 * **금액 컬럼(`total_paid_amount`)은 건드리지 않는다** — 깎으면 잔여 한도에서 같은 금액을
 * 두 번 차감한다(`RefundExecutionService::execute()` 독블록). 이 스위트는 그것도 함께 잰다.
 */
class RefundTaxColumnSyncTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClaimReasons();
    }

    /**
     * 환불액만큼 승인된 환불신청서를 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @param  float  $amount  환불액
     * @return OrderRefund 승인 상태의 신청서
     */
    private function approvedRefund(Order $order, float $amount): OrderRefund
    {
        $refund = new OrderRefund;
        $refund->order_id = $order->id;
        $refund->refund_number = 'RF'.uniqid();
        $refund->refund_status = RefundStatusEnum::REQUESTED;
        $refund->refund_amount = $amount;
        $refund->refund_points_amount = 0;
        $refund->refund_method = 'bank';
        $refund->reason_code = 'defective_product';
        $refund->save();

        // **신청 상품 행을 반드시 단다** — 이 행이 없으면 실행 경로가 「덜어낼 상품이 없다」로
        // 보고 재계산을 건너뛴다(실측: 실데이터 RF166·167 은 `refundOptions` 가 1·2건이다).
        // 행 없는 신청서로 재면 결함이 고쳐졌는데도 red 가 서서 엉뚱한 곳을 파게 된다.
        $order->loadMissing('options');

        foreach ($order->options as $option) {
            $refund->refundOptions()->create([
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => RefundStatusEnum::REQUESTED,
                'quantity' => (int) $option->quantity,
                'unit_price' => (float) $option->unit_price,
                'subtotal_amount' => (float) $option->unit_price * (int) $option->quantity,
                'refund_amount' => (float) $option->unit_price * (int) $option->quantity,
            ]);
        }

        return app(OrderRefundRequestService::class)->transition($refund, RefundStatusEnum::APPROVED);
    }

    /**
     * 전액 환불하면 **현금영수증 발급 가능액이 0 이 돼야 한다.**
     *
     * 이 축이 결함의 실제 피해다 — 돈을 다 돌려준 주문에 세금 문서를 끊을 수 있으면 안 된다.
     *
     * @scenario claim_type=refund, coverage=full
     *
     * @effects fully_refunded_order_is_not_cash_receipt_issuable
     */
    public function test_fully_refunded_order_clears_tax_columns(): void
    {
        // 현금영수증 발급 가능액은 `total_cash_equivalent_amount` 가 0 이면 그 자리에서 0 을
        // 낸다 — 픽스처는 카드 주문이라 그 값이 0 이다(실측: 주문 965 카드 `cash_eq = 0`).
        // 그래서 **세금 컬럼 자체**를 잰다. 발급 가능액은 이 컬럼들을 읽으므로, 컬럼이
        // 맞으면 무통장 주문의 발급 판정도 따라 맞는다(주문 964 실측이 그 경로다).
        $order = $this->deliveredClaimOrder(price: 29000, quantity: 1);

        $this->assertGreaterThan(
            0,
            (float) $order->total_tax_amount,
            '전제: 환불 전에는 과세액이 있어야 한다.'
        );

        app(OrderRefundRequestService::class)
            ->executeRefund($this->approvedRefund($order, (float) $order->total_paid_amount));

        $order->refresh();

        $this->assertSame(
            0.0,
            (float) $order->total_tax_amount,
            '전액 환불했는데 과세액이 남았다 — 세금계산서·현금영수증이 이 값을 읽는다 (S3-D17).'
        );

        $this->assertSame(
            0.0,
            (float) $order->total_vat_amount,
            '전액 환불했는데 부가세가 남았다 (S3-D17).'
        );
    }

    /**
     * 환불은 `total_paid_amount` 를 깎지 않아야 한다. (이중 차감 방지 대조군)
     *
     * 세금 컬럼을 맞추면서 금액 컬럼까지 건드리면, 잔여 한도
     * (`total_paid_amount - total_refunded_amount`)가 같은 금액을 두 번 뺀다.
     *
     * @scenario claim_type=refund, coverage=partial
     *
     * @effects refund_does_not_touch_paid_amount
     */
    public function test_refund_does_not_touch_paid_amount(): void
    {
        $order = $this->deliveredClaimOrder(price: 29000, quantity: 1);
        $paidBefore = (float) $order->total_paid_amount;

        app(OrderRefundRequestService::class)->executeRefund($this->approvedRefund($order, 10000));

        $order->refresh();

        $this->assertSame(
            $paidBefore,
            (float) $order->total_paid_amount,
            '환불이 total_paid_amount 를 깎으면 잔여 한도에서 같은 금액이 두 번 차감된다.'
        );
    }
}
