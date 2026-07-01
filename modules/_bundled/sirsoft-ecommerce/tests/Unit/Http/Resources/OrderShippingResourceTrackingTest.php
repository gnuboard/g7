<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderShippingResource;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderShippingResource 배송조회 데이터 노출 회귀 테스트 (U21)
 *
 * Resource 가 존재하지 않는 모델 속성(carrier_code/tracking_url)을 참조해 항상 null 을
 * 노출하던 결함을 정정한 뒤, carrier?->code 와 getTrackingUrl() 값이 올바르게
 * 노출되는지 검증합니다.
 */
class OrderShippingResourceTrackingTest extends ModuleTestCase
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

    public function test_resource_exposes_tracking_url_and_carrier_code(): void
    {
        $carrier = $this->makeCarrier();
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => $carrier->id,
            'tracking_number' => '123456789012',
        ]);

        $array = (new OrderShippingResource($shipping->fresh()))->toArray(Request::create('/'));

        $this->assertSame('cj', $array['carrier_code']);
        $this->assertSame(
            'https://trace.cjlogistics.com/next/tracking.html?wblNo=123456789012',
            $array['tracking_url']
        );
    }

    public function test_resource_tracking_url_null_when_no_tracking_number(): void
    {
        $carrier = $this->makeCarrier();
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => $carrier->id,
            'tracking_number' => null,
        ]);

        $array = (new OrderShippingResource($shipping->fresh()))->toArray(Request::create('/'));

        $this->assertNull($array['tracking_url']);
        $this->assertSame('cj', $array['carrier_code']);
    }

    public function test_resource_carrier_code_null_when_no_carrier(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => null,
            'tracking_number' => '123456789012',
        ]);

        $array = (new OrderShippingResource($shipping->fresh()))->toArray(Request::create('/'));

        $this->assertNull($array['carrier_code']);
        $this->assertNull($array['tracking_url']);
    }
}
