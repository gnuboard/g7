<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderOptionResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderResource / OrderOptionResource 필드 테스트
 *
 * 주문상세 합계행 수정 및 다통화 표시 추가 관련 필드 검증
 */
class OrderResourceFieldsTest extends ModuleTestCase
{
    /**
     * OrderOptionResource에 final_amount, final_amount_formatted 필드가 포함되는지 확인
     */
    public function test_order_option_resource_includes_final_amount_fields(): void
    {
        // Given: 주문 옵션 생성 (subtotal_price=30000, discount=1000, points=0, deposit=0)
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'unit_price' => 10000,
            'quantity' => 3,
            'subtotal_price' => 30000,
            'subtotal_discount_amount' => 1000,
            'subtotal_points_used_amount' => 0,
            'subtotal_deposit_used_amount' => 0,
        ]);

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: final_amount = 30000 - 1000 - 0 - 0 = 29000
        $this->assertEquals(29000, $resource['final_amount']);
        $this->assertEquals('29,000원', $resource['final_amount_formatted']);
    }

    /**
     * OrderOptionResource에 list_price 필드가 option_snapshot에서 추출되는지 확인
     */
    public function test_order_option_resource_includes_list_price_from_snapshot(): void
    {
        // Given: option_snapshot에 list_price=15000, unit_price=10000
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'unit_price' => 10000,
            'option_snapshot' => [
                'list_price' => 15000,
                'selling_price' => 10000,
            ],
        ]);

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: list_price가 스냅샷에서 추출
        $this->assertEquals(15000, $resource['list_price']);
        $this->assertEquals('15,000원', $resource['list_price_formatted']);
    }

    /**
     * OrderOptionResource에서 mc_subtotal_discount_amount 필드가 제거되었는지 확인
     */
    public function test_order_option_resource_does_not_include_mc_subtotal_discount_amount(): void
    {
        // Given: 주문 옵션 생성
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create();

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: mc_subtotal_discount_amount 필드 미존재 (버그 수정)
        $this->assertArrayNotHasKey('mc_subtotal_discount_amount', $resource);
    }

    /**
     * OrderResource에 total_quantity 필드가 옵션 수량 합계를 반환하는지 확인
     */
    public function test_order_resource_includes_total_quantity(): void
    {
        // Given: 주문에 옵션 3개 (수량 2, 3, 5)
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create(['quantity' => 2, 'unit_price' => 10000, 'subtotal_price' => 20000]);
        OrderOptionFactory::new()->forOrder($order)->create(['quantity' => 3, 'unit_price' => 10000, 'subtotal_price' => 30000]);
        OrderOptionFactory::new()->forOrder($order)->create(['quantity' => 5, 'unit_price' => 10000, 'subtotal_price' => 50000]);

        // When: 리소스 변환 (options 관계 로드 필요)
        $order->load('options');
        $resource = (new OrderResource($order))->resolve();

        // Then: total_quantity = 2 + 3 + 5 = 10
        $this->assertEquals(10, $resource['total_quantity']);
    }

    /**
     * OrderResource에 total_list_price 필드가 스냅샷의 list_price * quantity 합계를 반환하는지 확인
     */
    public function test_order_resource_includes_total_list_price(): void
    {
        // Given: 주문에 옵션 2개
        $order = OrderFactory::new()->create();
        // 옵션1: list_price=15000, quantity=2 → 30000
        OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal_price' => 20000,
            'option_snapshot' => ['list_price' => 15000, 'selling_price' => 10000],
        ]);
        // 옵션2: list_price=20000, quantity=1 → 20000
        OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 18000,
            'subtotal_price' => 18000,
            'option_snapshot' => ['list_price' => 20000, 'selling_price' => 18000],
        ]);

        // When: 리소스 변환
        $order->load('options');
        $resource = (new OrderResource($order))->resolve();

        // Then: total_list_price = 30000 + 20000 = 50000
        $this->assertEquals(50000, $resource['total_list_price']);
        $this->assertEquals('50,000원', $resource['total_list_price_formatted']);
    }

    /**
     * OrderResource의 total_list_price가 list_price 없을 때 unit_price로 폴백하는지 확인
     */
    public function test_order_resource_total_list_price_falls_back_to_unit_price(): void
    {
        // Given: option_snapshot에 list_price가 없는 경우
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal_price' => 20000,
            'option_snapshot' => ['selling_price' => 10000],
            'product_snapshot' => ['selling_price' => 10000],
        ]);

        // When: 리소스 변환
        $order->load('options');
        $resource = (new OrderResource($order))->resolve();

        // Then: unit_price 폴백 → 10000 * 2 = 20000
        $this->assertEquals(20000, $resource['total_list_price']);
    }

    /**
     * OrderOptionResource의 final_amount가 할인+마일리지+예치금 모두 차감하는지 확인
     */
    public function test_order_option_resource_final_amount_deducts_all(): void
    {
        // Given: 할인+마일리지+예치금 모두 있는 경우
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'unit_price' => 50000,
            'quantity' => 1,
            'subtotal_price' => 50000,
            'subtotal_discount_amount' => 5000,
            'subtotal_points_used_amount' => 2000,
            'subtotal_deposit_used_amount' => 1000,
        ]);

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: final_amount = 50000 - 5000 - 2000 - 1000 = 42000
        $this->assertEquals(42000, $resource['final_amount']);
        $this->assertEquals('42,000원', $resource['final_amount_formatted']);
    }
}
