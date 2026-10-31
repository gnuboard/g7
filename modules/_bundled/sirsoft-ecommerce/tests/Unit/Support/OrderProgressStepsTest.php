<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Support\OrderProgressSteps;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 진행단계 배열 단일 출처(`OrderProgressSteps`) 계약
 *
 * 관리자·회원·비회원 상세가 같은 배열로 스텝퍼를 그린다. 순서는 상태 Enum(7단계)이 정하고,
 * 이탈 상태(배송보류·취소·교환/반품)는 순서 밖의 마지막 칸으로 붙는다. 도달 지점은 현재 상태와
 * 주문이 박아 둔 시각 중 더 나아간 쪽이다 — 이탈 상태에서는 현재 상태만으로 어디까지 갔는지
 * 알 수 없다.
 *
 * @scenario order_stage=in_progress, order_stage=off_path
 *
 * 효과 요약(마커 아님 — 평문): 7단계 배열, 단계별 아이콘과 변형, 이탈 상태의 마지막 칸, 시각 기준 도달 지점.
 *   각 효과는 그 효과를 재는 메서드 docblock 에 마킹한다.
 */
class OrderProgressStepsTest extends ModuleTestCase
{
    /**
     * 배송중 주문의 진행단계가 상태 Enum 순서대로 7칸이고, 배송중 칸 하나만 현재이며 앞은 완료, 뒤는 예정인지 확인한다.
     *
     * @effects order_progress_steps_seven_stages
     *
     * @return void 반환값 없음
     */
    public function test_in_progress_order_has_seven_cells_with_one_current(): void
    {
        $order = Order::factory()->shipping()->create();

        $steps = OrderProgressSteps::for($order);

        $this->assertCount(7, $steps);
        $this->assertSame(
            array_map(fn ($case) => $case->value, OrderStatusEnum::progressSequence()),
            array_column($steps, 'value'),
        );
        $this->assertSame(
            ['done', 'done', 'done', 'done', 'current', 'upcoming', 'upcoming'],
            array_column($steps, 'state'),
        );
    }

    /**
     * 결제완료 주문의 각 칸이 자기 상태 Enum 의 아이콘, 색 변형, 라벨을 그대로 싣는지 확인한다.
     *
     * @effects order_progress_steps_carry_icon_and_variant
     *
     * @return void 반환값 없음
     */
    public function test_every_step_carries_the_icon_and_variant_of_its_status(): void
    {
        $order = Order::factory()->paid()->create();

        foreach (OrderProgressSteps::for($order) as $step) {
            $case = OrderStatusEnum::from($step['value']);

            $this->assertSame($case->icon(), $step['icon'], "{$step['value']} 칸의 아이콘은 상태 Enum 이 정한 값이어야 합니다.");
            $this->assertSame($case->variant(), $step['variant'], "{$step['value']} 칸의 색은 상태 뱃지와 같은 variant 여야 합니다.");
            $this->assertSame($case->label(), $step['label']);
            $this->assertStringStartsWith('status-', $step['icon']);
        }
    }

    /**
     * 결제 뒤 취소된 주문은 7칸 뒤에 취소 칸이 이탈 상태로 붙고, 결제완료까지만 완료로 그리며 순서 안에 현재 칸이 없는지 확인한다.
     *
     * @effects order_progress_off_path_appends_last_cell
     *
     * @return void 반환값 없음
     */
    public function test_cancelled_order_appends_an_off_path_cell_after_the_reached_stage(): void
    {
        // 결제 뒤에 취소된 주문 — paid_at 이 남아 있어 결제완료까지는 지나간 것으로 그린다.
        $order = Order::factory()->paid()->create();
        $order->update(['order_status' => OrderStatusEnum::CANCELLED, 'cancelled_at' => now()]);

        $steps = OrderProgressSteps::for($order->fresh());

        $this->assertCount(8, $steps);
        $this->assertSame('cancelled', $steps[7]['value']);
        $this->assertSame('off_path', $steps[7]['state']);
        $this->assertSame('status-cancelled', $steps[7]['icon']);
        $this->assertSame(['done', 'done', 'upcoming', 'upcoming', 'upcoming', 'upcoming', 'upcoming'], array_column(array_slice($steps, 0, 7), 'state'));
        $this->assertNotContains('current', array_column($steps, 'state'), '이탈 상태에서는 순서 안에 현재 칸이 없습니다.');
    }

    /**
     * 반품 진행 중인 주문에서 상품 행의 배송완료 시각이 도달 지점을 배송완료까지 끌어올리는지 확인한다.
     *
     * @effects order_progress_reached_index_uses_stamps
     *
     * @return void 반환값 없음
     */
    public function test_delivered_stamp_on_an_option_advances_the_reached_index(): void
    {
        // 반품 진행 중인 주문 — 상품 행의 배송완료 시각이 배송완료까지 지나갔음을 증명한다.
        $order = Order::factory()->paid()->create();
        OrderOptionFactory::new()->forOrder($order)->create(['delivered_at' => now()]);
        $order->update(['order_status' => OrderStatusEnum::RETURN_IN_PROGRESS]);
        $order = $order->fresh()->load('options');

        $steps = OrderProgressSteps::for($order);

        $this->assertSame('return_in_progress', $steps[7]['value']);
        $this->assertSame('status-return', $steps[7]['icon']);
        // 결제대기·결제완료·상품준비중·배송준비중·배송중·배송완료까지 done, 구매확정만 upcoming
        $this->assertSame(['done', 'done', 'done', 'done', 'done', 'done', 'upcoming'], array_column(array_slice($steps, 0, 7), 'state'));
    }

    /**
     * 주문대기는 순서 밖이지만 이탈이 아니다 — 첫 칸(결제대기)을 현재로 그린다.
     *
     * @effects order_progress_pending_order_is_not_off_path
     *
     * @return void 반환값 없음
     */
    public function test_pending_order_renders_first_cell_as_current(): void
    {
        $order = Order::factory()->create(['order_status' => OrderStatusEnum::PENDING_ORDER, 'paid_at' => null]);

        $steps = OrderProgressSteps::for($order);

        $this->assertCount(7, $steps);
        $this->assertSame('current', $steps[0]['state']);
    }
}
