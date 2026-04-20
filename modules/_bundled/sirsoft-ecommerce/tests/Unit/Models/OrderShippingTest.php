<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderShipping 모델 테스트
 */
class OrderShippingTest extends ModuleTestCase
{
    public function test_order_shipping_can_be_created(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create();

        $this->assertDatabaseHas('ecommerce_order_shippings', [
            'id' => $shipping->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_order_shipping_belongs_to_order(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create();

        $this->assertInstanceOf(Order::class, $shipping->order);
        $this->assertEquals($order->id, $shipping->order->id);
    }

    public function test_order_shipping_casts_status_to_enum(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->preparing()->create();

        $this->assertInstanceOf(ShippingStatusEnum::class, $shipping->shipping_status);
        $this->assertEquals(ShippingStatusEnum::PREPARING, $shipping->shipping_status);
    }

    public function test_order_shipping_stores_shipping_type_as_string(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'shipping_type' => 'parcel',
        ]);

        $this->assertIsString($shipping->shipping_type);
        $this->assertEquals('parcel', $shipping->shipping_type);
    }

    public function test_preparing_shipping_has_no_tracking(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->preparing()->create();

        $this->assertEquals(ShippingStatusEnum::PREPARING, $shipping->shipping_status);
        $this->assertNull($shipping->tracking_number);
        $this->assertNull($shipping->shipped_at);
    }

    public function test_in_transit_shipping_has_tracking_info(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->inTransit()->create();

        $this->assertEquals(ShippingStatusEnum::IN_TRANSIT, $shipping->shipping_status);
        $this->assertNotNull($shipping->tracking_number);
        $this->assertNotNull($shipping->shipped_at);
    }

    public function test_delivered_shipping_has_delivered_at(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->delivered()->create();

        $this->assertEquals(ShippingStatusEnum::DELIVERED, $shipping->shipping_status);
        $this->assertNotNull($shipping->delivered_at);
    }

    public function test_pickup_shipping_has_visit_info(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->pickup()->create();

        $this->assertEquals('pickup', $shipping->shipping_type);
        $this->assertNull($shipping->carrier_id);
        $this->assertNull($shipping->tracking_number);
        $this->assertNotNull($shipping->visit_date);
        $this->assertNotNull($shipping->visit_time_slot);
    }

    public function test_order_can_have_multiple_shippings(): void
    {
        $order = OrderFactory::new()->create();
        OrderShippingFactory::new()->forOrder($order)->count(3)->create();

        $this->assertCount(3, $order->fresh()->shippings);
    }

    public function test_is_shipped_returns_true_when_shipped(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->inTransit()->create();

        $this->assertTrue($shipping->isShipped());
    }

    public function test_is_shipped_returns_false_when_not_shipped(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->preparing()->create();

        $this->assertFalse($shipping->isShipped());
    }

    public function test_is_delivered_returns_true_when_delivered(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->delivered()->create();

        $this->assertTrue($shipping->isDelivered());
    }

    public function test_is_domestic_returns_true_for_domestic_shipping(): void
    {
        ShippingType::clearCodeCache();
        ShippingType::firstOrCreate(['code' => 'parcel'], [
            'name' => ['ko' => '택배', 'en' => 'Parcel'],
            'category' => 'domestic',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'shipping_type' => 'parcel',
        ]);

        $this->assertTrue($shipping->isDomestic());
    }

    public function test_is_international_returns_true_for_international_shipping(): void
    {
        ShippingType::clearCodeCache();
        ShippingType::firstOrCreate(['code' => 'international_ems'], [
            'name' => ['ko' => '국제EMS', 'en' => 'International EMS'],
            'category' => 'international',
            'is_active' => false,
            'sort_order' => 8,
        ]);

        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'shipping_type' => 'international_ems',
        ]);

        $this->assertTrue($shipping->isInternational());
    }

    public function test_is_pickup_returns_true_for_pickup_shipping(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->pickup()->create();

        $this->assertTrue($shipping->isPickup());
    }

    public function test_carrier_belongs_to_shipping_carrier(): void
    {
        $carrier = ShippingCarrier::firstOrCreate(
            ['code' => 'cj'],
            [
                'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics'],
                'type' => 'domestic',
                'tracking_url' => 'https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => $carrier->id,
            'tracking_number' => '123456789012',
        ]);

        $this->assertInstanceOf(ShippingCarrier::class, $shipping->carrier);
        $this->assertEquals($carrier->id, $shipping->carrier->id);
    }

    public function test_get_tracking_url_returns_null_when_no_tracking_number(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => 1,
            'tracking_number' => null,
        ]);

        $this->assertNull($shipping->getTrackingUrl());
    }

    public function test_get_tracking_url_returns_null_when_no_carrier_id(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => null,
            'tracking_number' => '123456789012',
        ]);

        $this->assertNull($shipping->getTrackingUrl());
    }

    public function test_get_tracking_url_returns_null_when_carrier_not_found(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => 99999,
            'tracking_number' => '123456789012',
        ]);

        $this->assertNull($shipping->getTrackingUrl());
    }

    public function test_get_tracking_url_returns_null_when_carrier_has_no_tracking_url(): void
    {
        $carrier = ShippingCarrier::firstOrCreate(
            ['code' => 'custom-no-url'],
            [
                'name' => ['ko' => '커스텀 배송사', 'en' => 'Custom Carrier'],
                'type' => 'domestic',
                'tracking_url' => null,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => $carrier->id,
            'tracking_number' => '123456789012',
        ]);

        $this->assertNull($shipping->getTrackingUrl());
    }

    public function test_get_tracking_url_returns_correct_url(): void
    {
        $carrier = ShippingCarrier::firstOrCreate(
            ['code' => 'cj'],
            [
                'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics'],
                'type' => 'domestic',
                'tracking_url' => 'https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => $carrier->id,
            'tracking_number' => '123456789012',
        ]);

        $expectedUrl = 'https://trace.cjlogistics.com/next/tracking.html?wblNo=123456789012';
        $this->assertEquals($expectedUrl, $shipping->getTrackingUrl());
    }

    public function test_get_tracking_url_returns_null_when_both_carrier_and_tracking_missing(): void
    {
        $order = OrderFactory::new()->create();
        $shipping = OrderShippingFactory::new()->forOrder($order)->create([
            'carrier_id' => null,
            'tracking_number' => null,
        ]);

        $this->assertNull($shipping->getTrackingUrl());
    }
}
