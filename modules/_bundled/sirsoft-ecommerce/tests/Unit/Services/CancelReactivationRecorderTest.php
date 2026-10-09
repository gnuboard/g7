<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\CancelReactivationRecorder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 재활성 수량의 취소신청서 귀속 기록 테스트 (Q29)
 *
 * 저장소는 실제 DB 를 쓴다(테스트 트랜잭션 안). 취소신청서 상품은 분할 전 원 행을 가리키므로 되살린 분할
 * 조각은 부모 행을 통해 귀속되고, 나중 취소부터 되돌린다. 반품·교환 발 환불이 만든 취소는 대상이 아니다.
 *
 * 시나리오 매니페스트: `tests/scenarios/cancel-reactivation-badge.yaml`
 */
class CancelReactivationRecorderTest extends ModuleTestCase
{
    private CancelReactivationRecorder $recorder;

    private Order $order;

    /**
     * 기록기와 주문을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = $this->app->make(CancelReactivationRecorder::class);
        $this->order = Order::factory()->create();
    }

    /**
     * 주문 행을 만듭니다.
     *
     * @param  int  $quantity  수량
     * @param  OrderStatusEnum  $status  상태
     * @param  int|null  $parentId  부모 행 ID
     * @return OrderOption 주문 행
     */
    private function row(int $quantity, OrderStatusEnum $status, ?int $parentId = null): OrderOption
    {
        return OrderOption::factory()->create([
            'order_id' => $this->order->id,
            'option_status' => $status,
            'quantity' => $quantity,
            'parent_option_id' => $parentId,
        ]);
    }

    /**
     * 완료 취소신청서와 취소 상품 하나를 만듭니다.
     *
     * @param  OrderOption  $target  취소 대상 원 행
     * @param  int  $quantity  취소 수량
     * @param  RefundSourceTypeEnum|null  $refundSource  붙일 환불의 출처 (null 이면 환불 없음)
     * @return OrderCancel 취소신청서
     */
    private function cancel(OrderOption $target, int $quantity, ?RefundSourceTypeEnum $refundSource = null): OrderCancel
    {
        $cancel = OrderCancel::create([
            'order_id' => $this->order->id,
            'cancel_number' => 'CN-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'changed_mind',
            'items_snapshot' => [['order_option_id' => $target->id, 'quantity' => $quantity]],
            'cancelled_at' => now(),
        ]);
        OrderCancelOption::create([
            'order_cancel_id' => $cancel->id,
            'order_id' => $this->order->id,
            'order_option_id' => $target->id,
            'option_status' => CancelOptionStatusEnum::COMPLETED,
            'cancel_quantity' => $quantity,
            'original_quantity' => (int) $target->quantity,
            'unit_price' => 1000,
            'subtotal_amount' => 1000 * $quantity,
        ]);

        if ($refundSource !== null) {
            OrderRefund::create([
                'order_id' => $this->order->id,
                'order_cancel_id' => $cancel->id,
                'refund_source_type' => $refundSource,
                'refund_number' => 'RF-TEST-'.uniqid(),
                'refund_status' => RefundStatusEnum::COMPLETED,
                'refund_method' => RefundMethodEnum::PG,
                'refund_amount' => 1000 * $quantity,
                'refund_points_amount' => 0,
                'refund_shipping_amount' => 0,
            ]);
        }

        return $cancel;
    }

    /**
     * 취소 행 전량을 되살리면 그 행을 가리키는 취소신청서가 전부 재활성으로 기록된다.
     *
     * @effects revival_attributes_quantity_to_cancel_documents_before_merge
     *
     * @return void 반환값 없음
     */
    public function test_full_row_revival_marks_its_cancel(): void
    {
        $row = $this->row(2, OrderStatusEnum::CANCELLED);
        $cancel = $this->cancel($row, 2);
        $at = Carbon::parse('2026-10-05 14:00:00');

        $byCancel = $this->recorder->record($row, 2, $at);

        $this->assertSame([$cancel->id => 2], $byCancel);
        $cancel->refresh();
        $this->assertSame(2, $cancel->reactivated_quantity);
        $this->assertSame('2026-10-05 14:00:00', $cancel->reactivated_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, (int) OrderCancelOption::where('order_cancel_id', $cancel->id)->value('reactivated_quantity'));
        $this->assertFalse($cancel->isPartiallyReactivated());
    }

    /**
     * 분할 조각을 되살리면 부모 행(분할 전 원 행)을 가리키는 취소신청서에 귀속된다.
     *
     * @effects revival_attributes_quantity_to_cancel_documents_before_merge
     *
     * @return void 반환값 없음
     */
    public function test_split_child_revival_is_attributed_through_parent(): void
    {
        $parent = $this->row(2, OrderStatusEnum::PAYMENT_COMPLETE);
        $child = $this->row(1, OrderStatusEnum::CANCELLED, $parent->id);
        // 취소 상품은 분할 전 원 행(부모)을 가리킨다
        $cancel = $this->cancel($parent, 1);

        $this->recorder->record($child, 1, now());

        $this->assertSame(1, $cancel->fresh()->reactivated_quantity);
    }

    /**
     * 같은 행에 취소가 둘이면 나중 취소부터 되돌린다.
     *
     * @effects latest_cancel_is_reverted_first
     *
     * @return void 반환값 없음
     */
    public function test_latest_cancel_is_reverted_first(): void
    {
        $row = $this->row(3, OrderStatusEnum::CANCELLED);
        $first = $this->cancel($row, 1);
        $second = $this->cancel($row, 2);

        $this->recorder->record($row, 2, now());

        $this->assertSame(2, $second->fresh()->reactivated_quantity);
        $this->assertSame(0, $first->fresh()->reactivated_quantity);
        $this->assertNull($first->fresh()->reactivated_at);

        // 나머지 1 은 앞선 취소로 간다
        $this->recorder->record($row, 1, now());
        $this->assertSame(1, $first->fresh()->reactivated_quantity);
    }

    /**
     * 반품·교환 발 환불이 만든 취소신청서는 재활성으로 기록하지 않는다.
     *
     * @effects return_or_exchange_origin_cancel_is_never_marked
     *
     * @return void 반환값 없음
     */
    public function test_return_origin_cancel_is_never_marked(): void
    {
        $row = $this->row(1, OrderStatusEnum::CANCELLED);
        $returnCancel = $this->cancel($row, 1, RefundSourceTypeEnum::RETURN);
        $exchangeCancel = $this->cancel($row, 1, RefundSourceTypeEnum::EXCHANGE);

        $byCancel = $this->recorder->record($row, 1, now());

        $this->assertSame([], $byCancel);
        $this->assertNull($returnCancel->fresh()->reactivated_at);
        $this->assertNull($exchangeCancel->fresh()->reactivated_at);
    }

    /**
     * 귀속할 취소신청서가 없으면 로그만 남기고 예외를 던지지 않는다.
     *
     * @effects unattributable_revival_is_logged_not_blocked
     *
     * @return void 반환값 없음
     */
    public function test_unattributable_quantity_is_logged_not_thrown(): void
    {
        $row = $this->row(2, OrderStatusEnum::CANCELLED);
        Log::spy();

        $byCancel = $this->recorder->record($row, 2, now());

        $this->assertSame([], $byCancel);
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => str_contains((string) $message, '귀속하지 못했') && $context['unattributed'] === 2)
            ->once();
    }
}
