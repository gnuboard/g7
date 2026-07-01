<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderOptionResource;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderOptionResource 배송조회 링크 노출 회귀 테스트 (U21 관리자 주문상세)
 *
 * 유저 주문상세는 OrderShippingResource 가 tracking_url 을 노출하나, 관리자 주문상세
 * DataGrid 는 OrderOptionResource(order.data.options[]) 를 소비하면서 tracking_url 이
 * 누락되어 운송장 번호 클릭 시 배송조회 링크가 동작하지 않던 결함을 정정한 뒤,
 * shippings 관계의 getTrackingUrl() 값이 옵션 레벨에서 노출되는지 검증합니다.
 */
class OrderOptionResourceTrackingTest extends ModuleTestCase
{
    private function makeCarrier(): ShippingCarrier
    {
        return ShippingCarrier::firstOrCreate(
            ['code' => 'cj'],
            [
                'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics'],
                'type' => 'domestic',
                'tracking_url' => 'https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
    }

    public function test_option_resource_exposes_tracking_url_from_shipping(): void
    {
        $carrier = $this->makeCarrier();
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id]);
        OrderShippingFactory::new()->forOrderOption($option)->create([
            'carrier_id' => $carrier->id,
            'tracking_number' => '123456789012',
        ]);

        $array = (new OrderOptionResource($option->fresh()->load('shippings.carrier')))
            ->toArray(Request::create('/'));

        $this->assertSame('123456789012', $array['tracking_number']);
        $this->assertSame(
            'https://trace.cjlogistics.com/next/tracking.html?wblNo=123456789012',
            $array['tracking_url']
        );
    }

    public function test_option_resource_tracking_url_null_when_no_tracking_number(): void
    {
        $carrier = $this->makeCarrier();
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id]);
        OrderShippingFactory::new()->forOrderOption($option)->create([
            'carrier_id' => $carrier->id,
            'tracking_number' => null,
        ]);

        $array = (new OrderOptionResource($option->fresh()->load('shippings.carrier')))
            ->toArray(Request::create('/'));

        $this->assertNull($array['tracking_url']);
    }

    public function test_option_resource_tracking_url_missing_when_shippings_not_loaded(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id]);

        // shippings 관계 미로드 → whenLoaded 가 MissingValue 반환 (직렬화 시 키 제거)
        $array = (new OrderOptionResource($option->fresh()))->toArray(Request::create('/'));

        $this->assertInstanceOf(MissingValue::class, $array['tracking_url']);
    }
}
