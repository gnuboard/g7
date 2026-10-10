<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 옵션 행 병합이 클레임 신청상품을 잃지 않는지 검증한다 (S1-D6)
 *
 * 부분 수량 전이는 행을 분할하고, 같은 상태의 형제 행이 있으면 다시 **병합**한다.
 * 병합은 피흡수 행을 삭제하는데, 클레임 신청상품 4종(환불·취소·반품·교환)은 모두
 * `order_option_id` 에 `cascadeOnDelete()` 가 걸려 있어 이전하지 않으면 함께 지워진다.
 *
 * 실측(주문 20260915-0604289654): 순차 부분환불 5회 중 **4건의 신청상품이 0행**이 되어
 * 회원 클레임 상세가 「신청 상품이 없습니다.」, 관리자 클레임 목록 신청상품 칸이 `-` 로 떴다.
 * 금액·상태는 신청서 헤더에 남아 화면 어디에도 오류가 뜨지 않는다 — 조용히 사라진다.
 */
class OrderOptionMergeClaimOptionsTest extends ModuleTestCase
{
    private OrderOptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderOptionService::class);
    }

    /**
     * 병합으로 피흡수되는 행의 환불·취소 신청상품이 생존 행으로 이전된다.
     *
     * @scenario claim_kind=refund
     *
     * @effects claim_option_rows_survive_option_merge
     */
    public function test_merge_transfers_claim_option_rows(): void
    {
        $order = OrderFactory::new()->paid()->create();

        // 같은 상품옵션의 두 행 — 하나는 생존, 하나는 병합으로 흡수될 행
        $survivor = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::DELIVERED,
        ]);
        $consumed = OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $survivor->product_id,
            'product_option_id' => $survivor->product_option_id,
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            // 분할 가족으로 묶어야 findMergeCandidate() 가 형제로 인식해 병합이 일어난다
            'parent_option_id' => $survivor->id,
        ]);

        // 피흡수 행을 가리키는 클레임 신청상품
        $refundOption = OrderRefundOption::query()->create([
            'order_refund_id' => $this->makeRefundId($order->id),
            'order_id' => $order->id,
            'order_option_id' => $consumed->id,
            'option_status' => 'requested',
            'quantity' => 1,
            'unit_price' => 1000,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'refund_amount' => 1000,
        ]);

        // 병합 발생 — 피흡수 행을 생존 행과 같은 상태로 전이
        $this->service->changeStatusWithQuantity(
            $consumed->fresh(),
            OrderStatusEnum::DELIVERED,
            1,
        );

        // 피흡수 행이 사라졌어도 신청상품은 살아 있어야 한다
        $this->assertDatabaseHas((new OrderRefundOption)->getTable(), [
            'id' => $refundOption->id,
        ]);

        $pointsTo = (int) OrderRefundOption::query()->find($refundOption->id)?->order_option_id;
        $this->assertGreaterThan(0, $pointsTo, '신청상품이 남아 있어야 한다');
        $this->assertNotNull(
            OrderOption::query()->find($pointsTo),
            '신청상품이 가리키는 옵션 행이 살아 있어야 한다 (병합으로 지워진 행을 가리키면 안 된다)'
        );
    }

    /**
     * 환불 헤더 1건을 만들어 id 를 돌려준다 (신청상품 FK 충족용).
     */
    private function makeRefundId(int $orderId): int
    {
        return (int) OrderRefund::query()->create([
            'order_id' => $orderId,
            'refund_number' => 'RF-TEST-'.uniqid(),
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'reason_code' => 'changed_mind',
            'refund_amount' => 1000,
            'refund_points_amount' => 0,
        ])->id;
    }

    /**
     * 취소 신청상품도 같은 규칙으로 이전된다.
     *
     * @scenario claim_kind=cancel
     *
     * @effects claim_option_rows_survive_option_merge
     */
    public function test_merge_transfers_cancel_option_rows(): void
    {
        $order = OrderFactory::new()->paid()->create();

        $survivor = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::DELIVERED,
        ]);
        $consumed = OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $survivor->product_id,
            'product_option_id' => $survivor->product_option_id,
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'parent_option_id' => $survivor->id,
        ]);

        $cancelId = (int) OrderCancel::query()->create([
            'order_id' => $order->id,
            'cancel_number' => 'CN-TEST-'.uniqid(),
            'cancel_status' => 'completed',
            'cancel_type' => 'partial',
            'cancel_reason_type' => 'changed_mind',
            'reason_code' => 'changed_mind',
            'items_snapshot' => [],
        ])->id;

        $cancelOption = OrderCancelOption::query()->create([
            'order_cancel_id' => $cancelId,
            'order_id' => $order->id,
            'order_option_id' => $consumed->id,
            'option_status' => 'completed',
            'quantity' => 1,
            'cancel_quantity' => 1,
            'original_quantity' => 1,
            'unit_price' => 1000,
            'subtotal_amount' => 1000,
            'cancel_amount' => 1000,
        ]);

        $this->service->changeStatusWithQuantity(
            $consumed->fresh(),
            OrderStatusEnum::DELIVERED,
            1,
        );

        $pointsTo = (int) OrderCancelOption::query()->find($cancelOption->id)?->order_option_id;
        $this->assertGreaterThan(0, $pointsTo, '신청상품이 남아 있어야 한다');
        $this->assertNotNull(
            OrderOption::query()->find($pointsTo),
            '신청상품이 가리키는 옵션 행이 살아 있어야 한다'
        );
    }
}
