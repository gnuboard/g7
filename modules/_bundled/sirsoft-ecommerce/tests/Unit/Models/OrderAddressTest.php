<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderAddress 모델 테스트
 */
class OrderAddressTest extends ModuleTestCase
{
    public function test_order_address_can_be_created(): void
    {
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'orderer_name' => '홍길동',
            'recipient_name' => '김철수',
        ]);

        $this->assertDatabaseHas('ecommerce_order_addresses', [
            'id' => $address->id,
            'orderer_name' => '홍길동',
            'recipient_name' => '김철수',
        ]);
    }

    public function test_order_address_belongs_to_order(): void
    {
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->forOrder($order)->shipping()->create();

        $this->assertInstanceOf(Order::class, $address->order);
        $this->assertEquals($order->id, $address->order->id);
    }

    public function test_shipping_address_type(): void
    {
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->forOrder($order)->shipping()->create();

        $this->assertEquals('shipping', $address->address_type);
    }

    public function test_billing_address_type(): void
    {
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->forOrder($order)->billing()->create();

        $this->assertEquals('billing', $address->address_type);
    }

    public function test_order_address_contains_full_address_info(): void
    {
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'zipcode' => '12345',
            'address' => '서울시 강남구',
            'address_detail' => '테헤란로 123',
            'recipient_phone' => '010-1234-5678',
        ]);

        $this->assertEquals('12345', $address->zipcode);
        $this->assertEquals('서울시 강남구', $address->address);
        $this->assertEquals('테헤란로 123', $address->address_detail);
        $this->assertEquals('010-1234-5678', $address->recipient_phone);
    }

    public function test_order_can_have_multiple_addresses(): void
    {
        $order = OrderFactory::new()->create();
        OrderAddressFactory::new()->forOrder($order)->shipping()->create();
        OrderAddressFactory::new()->forOrder($order)->billing()->create();

        $this->assertCount(2, $order->fresh()->addresses);
    }

    public function test_order_address_has_orderer_info(): void
    {
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'orderer_name' => '홍길동',
            'orderer_phone' => '010-1111-2222',
            'orderer_email' => 'hong@test.com',
        ]);

        $this->assertEquals('홍길동', $address->orderer_name);
        $this->assertEquals('010-1111-2222', $address->orderer_phone);
        $this->assertEquals('hong@test.com', $address->orderer_email);
    }

    public function test_order_address_has_recipient_info(): void
    {
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'recipient_name' => '김철수',
            'recipient_phone' => '010-3333-4444',
        ]);

        $this->assertEquals('김철수', $address->recipient_name);
        $this->assertEquals('010-3333-4444', $address->recipient_phone);
    }
}
