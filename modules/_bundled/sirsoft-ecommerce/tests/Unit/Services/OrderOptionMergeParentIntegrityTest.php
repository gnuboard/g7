<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 부분 수량 발송 뒤 남은 수량을 보낼 때의 병합 무결성 (실측 2026-10-03)
 *
 * 3개 중 2개를 먼저 배송중으로 보내 행을 나눈 뒤 남은 1개를 보내면, 원본 행이 분할 행에
 * 흡수되어 지워진다. 그때 부모 주문이 배송중으로 따라가야 하고, 생존 행의 부모가 자기
 * 자신이 되어서는 안 된다.
 */
class OrderOptionMergeParentIntegrityTest extends ModuleTestCase
{
    private OrderOptionService $service;

    /**
     * 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderOptionService::class);
    }

    /**
     * 남은 수량을 보내 원본이 흡수되면, 부모 주문이 배송중으로 동기화되고
     * 생존 행의 부모가 자기 자신이 되지 않는다.
     *
     * @scenario shipping_sequence=partial_then_rest
     *
     * @effects merge_after_partial_shipping_syncs_parent_order_status, merge_never_makes_an_option_its_own_parent
     *
     * @return void 반환값 없음
     */
    public function test_shipping_the_rest_after_a_partial_shipment_syncs_order_and_keeps_parent_sane(): void
    {
        $order = OrderFactory::new()->paid()->create(['order_status' => OrderStatusEnum::PREPARING]);
        $original = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'unit_price' => 4000,
            'subtotal_price' => 12000,
            'option_status' => OrderStatusEnum::PREPARING,
            'parent_option_id' => null,
        ]);

        // 2개 먼저 발송 — 분할
        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $original->id, 'quantity' => 2]],
            OrderStatusEnum::SHIPPING,
            $order->id,
        );
        $this->assertEquals(OrderStatusEnum::PREPARING, $order->fresh()->order_status, '1개가 남았으니 주문은 아직 상품준비중이다.');

        // 남은 1개 발송 — 원본이 분할 행에 흡수된다
        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $original->id, 'quantity' => 1]],
            OrderStatusEnum::SHIPPING,
            $order->id,
        );

        $options = OrderOption::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $options, '같은 상태의 두 행은 하나로 합쳐진다.');
        $survivor = $options->first();
        $this->assertSame(3, (int) $survivor->quantity);
        $this->assertEquals(OrderStatusEnum::SHIPPING, $survivor->option_status);
        $this->assertNotSame((int) $survivor->id, (int) $survivor->parent_option_id, '생존 행이 자기 자신을 부모로 가리킵니다.');
        $this->assertNull($survivor->parent_option_id, '원본의 부모(없음)를 이어야 한다.');

        $this->assertEquals(OrderStatusEnum::SHIPPING, $order->fresh()->order_status, '모든 상품이 배송중인데 주문이 따라오지 않았습니다.');
    }
}
