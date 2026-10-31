<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderOptionResource;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderOptionResource 상품 행 배송비 표시 테스트 (S-Q26-b)
 *
 * 병합 이전분으로 한 주문 행에 배송 행이 여럿일 수 있다. 재계산은 그중 id 가 가장 작은 행에만 몫을 쓰고
 * 나머지는 0 으로 둔다. 첫 행만 읽으면 0 행이 앞에 올 때 몫이 사라지므로 행들의 합을 표시한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/partial-cancel-row-allocation.yaml`
 */
class OrderOptionResourceShippingAmountTest extends ModuleTestCase
{
    /**
     * 상품 행의 배송비는 그 행의 배송 행 합이다 — 0 인 행이 앞서도 몫이 사라지지 않는다.
     *
     * @effects merged_row_extra_shipping_rows_are_zero
     *
     * @return void 반환값 없음
     */
    public function test_shipping_amount_sums_all_shipping_rows(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id]);
        OrderShippingFactory::new()->forOrderOption($option)->create([
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'total_shipping_amount' => 0,
        ]);
        OrderShippingFactory::new()->forOrderOption($option)->create([
            'base_shipping_amount' => 2500,
            'extra_shipping_amount' => 0,
            'total_shipping_amount' => 2500,
        ]);

        $array = (new OrderOptionResource($option->fresh()->load('shippings')))
            ->toArray(Request::create('/'));

        $this->assertEquals(2500, $array['shipping_amount']);
    }
}
