<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 재고 관리 서비스 테스트
 */
class StockServiceTest extends ModuleTestCase
{
    protected StockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockService::class);
    }

    public function test_validate_stock_returns_true_for_sufficient(): void
    {
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);

        $result = $this->service->validateStock([
            ['product_option_id' => $option->id, 'quantity' => 5],
        ]);

        $this->assertTrue($result);
    }

    public function test_validate_stock_returns_true_for_exact_amount(): void
    {
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);

        $result = $this->service->validateStock([
            ['product_option_id' => $option->id, 'quantity' => 5],
        ]);

        $this->assertTrue($result);
    }

    public function test_validate_stock_throws_for_insufficient(): void
    {
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 3,
        ]);

        $this->expectException(InsufficientStockException::class);

        $this->service->validateStock([
            ['product_option_id' => $option->id, 'quantity' => 5],
        ]);
    }

    public function test_validate_stock_throws_for_nonexistent_option(): void
    {
        $this->expectException(InsufficientStockException::class);

        $this->service->validateStock([
            ['product_option_id' => 999999, 'quantity' => 1],
        ]);
    }

    public function test_validate_stock_multiple_options(): void
    {
        $product = Product::factory()->create();
        $option1 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);
        $option2 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 20,
        ]);

        $result = $this->service->validateStock([
            ['product_option_id' => $option1->id, 'quantity' => 5],
            ['product_option_id' => $option2->id, 'quantity' => 10],
        ]);

        $this->assertTrue($result);
    }

    public function test_deduct_option_stock_reduces_quantity(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);

        $result = $this->service->deductOptionStock($option->id, 3);

        $this->assertTrue($result);
        $option->refresh();
        $this->assertEquals(7, $option->stock_quantity);
    }

    public function test_deduct_option_stock_to_zero(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);

        $result = $this->service->deductOptionStock($option->id, 5);

        $this->assertTrue($result);
        $option->refresh();
        $this->assertEquals(0, $option->stock_quantity);
    }

    public function test_deduct_option_stock_throws_for_insufficient(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 3]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 3,
        ]);

        $this->expectException(InsufficientStockException::class);

        $this->service->deductOptionStock($option->id, 5);
    }

    public function test_deduct_option_stock_throws_for_nonexistent(): void
    {
        $this->expectException(InsufficientStockException::class);

        $this->service->deductOptionStock(999999, 1);
    }

    public function test_restore_option_stock_increases_quantity(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);

        $result = $this->service->restoreOptionStock($option->id, 3);

        $this->assertTrue($result);
        $option->refresh();
        $this->assertEquals(8, $option->stock_quantity);
    }

    public function test_restore_option_stock_from_zero(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 0]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 0,
        ]);

        $result = $this->service->restoreOptionStock($option->id, 10);

        $this->assertTrue($result);
        $option->refresh();
        $this->assertEquals(10, $option->stock_quantity);
    }

    public function test_deduct_stock_for_order(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 20]);
        $option1 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);
        $option2 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);

        $order = Order::factory()->create();
        $orderOption1 = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option1->id,
            'quantity' => 3,
        ]);
        $orderOption2 = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option2->id,
            'quantity' => 5,
        ]);

        $order->load('options');
        $this->service->deductStock($order);

        $option1->refresh();
        $option2->refresh();
        $this->assertEquals(7, $option1->stock_quantity);
        $this->assertEquals(5, $option2->stock_quantity);

        // is_stock_deducted 플래그 확인
        $orderOption1->refresh();
        $orderOption2->refresh();
        $this->assertTrue($orderOption1->is_stock_deducted);
        $this->assertTrue($orderOption2->is_stock_deducted);
    }

    public function test_deduct_stock_throws_for_insufficient_order(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 3]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 3,
        ]);

        $order = Order::factory()->create();
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 10,
        ]);

        $order->load('options');

        $this->expectException(InsufficientStockException::class);

        $this->service->deductStock($order);
    }

    public function test_restore_stock_for_order(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);

        $order = Order::factory()->create();
        $orderOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
            'is_stock_deducted' => true,
        ]);

        $order->load('options');
        $this->service->restoreStock($order);

        $option->refresh();
        $this->assertEquals(8, $option->stock_quantity);

        // is_stock_deducted 플래그가 false로 리셋 확인
        $orderOption->refresh();
        $this->assertFalse($orderOption->is_stock_deducted);
    }

    public function test_restore_stock_for_order_with_multiple_options(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $option1 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);
        $option2 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);

        $order = Order::factory()->create();
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option1->id,
            'quantity' => 3,
            'is_stock_deducted' => true,
        ]);
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option2->id,
            'quantity' => 5,
            'is_stock_deducted' => true,
        ]);

        $order->load('options');
        $this->service->restoreStock($order);

        $option1->refresh();
        $option2->refresh();
        $this->assertEquals(8, $option1->stock_quantity);
        $this->assertEquals(10, $option2->stock_quantity);
    }

    // ===== is_stock_deducted 멱등성 테스트 =====

    public function test_deduct_stock_skips_already_deducted_options(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 20]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);

        $order = Order::factory()->create();
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
            'is_stock_deducted' => true, // 이미 차감됨
        ]);

        $order->load('options');
        $this->service->deductStock($order);

        // 이미 차감된 옵션은 스킵 → 재고 변동 없음
        $option->refresh();
        $this->assertEquals(10, $option->stock_quantity);
    }

    public function test_deduct_stock_partial_idempotency(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 20]);
        $option1 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);
        $option2 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);

        $order = Order::factory()->create();
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option1->id,
            'quantity' => 3,
            'is_stock_deducted' => true, // 이미 차감됨
        ]);
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option2->id,
            'quantity' => 5,
            'is_stock_deducted' => false, // 미차감
        ]);

        $order->load('options');
        $this->service->deductStock($order);

        // option1: 이미 차감 → 스킵 (재고 유지)
        $option1->refresh();
        $this->assertEquals(10, $option1->stock_quantity);

        // option2: 미차감 → 차감 실행
        $option2->refresh();
        $this->assertEquals(5, $option2->stock_quantity);
    }

    public function test_restore_stock_skips_non_deducted_options(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);

        $order = Order::factory()->create();
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
            'is_stock_deducted' => false, // 차감되지 않음
        ]);

        $order->load('options');
        $this->service->restoreStock($order);

        // 차감되지 않은 옵션은 복원 스킵 → 재고 변동 없음
        $option->refresh();
        $this->assertEquals(5, $option->stock_quantity);
    }
}
