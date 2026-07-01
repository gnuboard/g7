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

    // ===== restoreOptionStockForOrderOption (복원 + 플래그 정리) =====

    public function test_restore_option_for_order_option_increases_stock_and_resets_flag(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);

        $order = Order::factory()->create();
        // 전체취소 시나리오: CANCELLED 상태 + is_stock_deducted=true 인 옵션
        $orderOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
            'option_status' => \Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum::CANCELLED,
            'is_stock_deducted' => true,
        ]);

        $this->service->restoreOptionStockForOrderOption($orderOption, 3);

        // 재고 복원 (5 + 3 = 8)
        $option->refresh();
        $this->assertEquals(8, $option->stock_quantity);

        // CANCELLED 옵션 플래그 false 로 정리
        $orderOption->refresh();
        $this->assertFalse($orderOption->is_stock_deducted);
    }

    public function test_restore_option_for_order_option_keeps_flag_on_remaining_active_option(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);

        $order = Order::factory()->create();

        // 부분취소 분할 결과 모사: 동일 product_option_id 에 대해
        // 잔여(active) 행 + 취소(CANCELLED) 행이 공존
        $activeOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 2,
            'option_status' => \Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum::PAYMENT_COMPLETE,
            'is_stock_deducted' => true,
        ]);
        $cancelledOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
            'option_status' => \Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum::CANCELLED,
            'is_stock_deducted' => true,
        ]);

        // 취소 수량 3 복원 (원본 행 컨텍스트로 호출 — product_option_id 기준 CANCELLED 행 정리)
        $this->service->restoreOptionStockForOrderOption($cancelledOption, 3);

        $option->refresh();
        $this->assertEquals(8, $option->stock_quantity); // 5 + 3

        // CANCELLED 행만 플래그 정리, 잔여 active 행은 유지
        $cancelledOption->refresh();
        $activeOption->refresh();
        $this->assertFalse($cancelledOption->is_stock_deducted);
        $this->assertTrue($activeOption->is_stock_deducted);
    }

    // ===== redeductForReactivation (재활성 재차감) =====

    public function test_rededuct_for_reactivation_rededucts_restored_stock(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 8]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 8, // 취소로 복원된 상태(원래 5에서 +3)
        ]);

        $order = Order::factory()->create();
        // 취소로 복원된 옵션 (is_stock_deducted=false)
        $orderOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
            'option_status' => \Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum::CANCELLED,
            'is_stock_deducted' => false,
        ]);

        $order->load('options');
        $this->service->redeductForReactivation($order);

        // 재차감으로 재고 -3 (8 → 5)
        $option->refresh();
        $this->assertEquals(5, $option->stock_quantity);

        // 재차감 후 플래그 true
        $orderOption->refresh();
        $this->assertTrue($orderOption->is_stock_deducted);
    }

    public function test_rededuct_for_reactivation_skips_already_deducted_via_idempotency(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);

        $order = Order::factory()->create();
        // 복원되지 않았던 옵션 (OFF 등으로 여전히 차감 상태) → 멱등 스킵
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
            'is_stock_deducted' => true,
        ]);

        $order->load('options');
        $this->service->redeductForReactivation($order);

        // 이미 차감된 옵션은 멱등 스킵 → 재고 불변
        $option->refresh();
        $this->assertEquals(10, $option->stock_quantity);
    }

    public function test_rededuct_for_reactivation_throws_on_insufficient_stock(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 2]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 2, // 재차감 요구량(3)보다 적음
        ]);

        $order = Order::factory()->create();
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
            'is_stock_deducted' => false,
        ]);

        $order->load('options');

        $this->expectException(InsufficientStockException::class);
        $this->service->redeductForReactivation($order);
    }
}
