<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderOption 모델 테스트
 */
class OrderOptionTest extends ModuleTestCase
{
    public function test_order_option_can_be_created(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'product_name' => '테스트 상품',
            'sku' => 'TEST-SKU-001',
        ]);

        $this->assertDatabaseHas('ecommerce_order_options', [
            'id' => $option->id,
            'product_name' => '테스트 상품',
            'sku' => 'TEST-SKU-001',
        ]);
    }

    public function test_order_option_belongs_to_order(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create();

        $this->assertInstanceOf(Order::class, $option->order);
        $this->assertEquals($order->id, $option->order->id);
    }

    public function test_order_option_casts_status_to_enum(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PENDING_ORDER->value,
        ]);

        $this->assertInstanceOf(OrderStatusEnum::class, $option->option_status);
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $option->option_status);
    }

    public function test_order_option_casts_amounts_to_decimal(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'unit_price' => 10000.50,
            'subtotal_discount_amount' => 1000.00,
            'subtotal_paid_amount' => 9000.50,
        ]);

        $this->assertEquals('10000.50', $option->unit_price);
        $this->assertEquals('1000.00', $option->subtotal_discount_amount);
        $this->assertEquals('9000.50', $option->subtotal_paid_amount);
    }

    public function test_order_option_casts_quantity_to_integer(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 5,
        ]);

        $this->assertIsInt($option->quantity);
        $this->assertEquals(5, $option->quantity);
    }

    public function test_order_option_casts_json_fields_to_array(): void
    {
        $order = OrderFactory::new()->create();
        $productSnapshot = ['name' => '테스트 상품', 'price' => 10000];
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'product_snapshot' => $productSnapshot,
        ]);

        $this->assertIsArray($option->product_snapshot);
        $this->assertEquals('테스트 상품', $option->product_snapshot['name']);
    }

    public function test_order_option_has_various_statuses(): void
    {
        $order = OrderFactory::new()->create();

        // 결제 완료 상태
        $paymentComplete = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $paymentComplete->option_status);

        // 배송 완료 상태
        $delivered = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::DELIVERED,
        ]);
        $this->assertEquals(OrderStatusEnum::DELIVERED, $delivered->option_status);

        // 취소 상태
        $cancelled = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CANCELLED,
        ]);
        $this->assertEquals(OrderStatusEnum::CANCELLED, $cancelled->option_status);
    }
}
