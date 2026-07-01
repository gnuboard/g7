<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 취소 → 판매상태 복원 시 재고 재차감 테스트 (A4/P0-C 증상 ③)
 *
 * 실제 DB 기반으로 OrderService::update()/bulkUpdate()/bulkUpdateStatus() 의
 * 재차감 전이 처리와 재고 부족 롤백을 검증한다.
 */
class OrderServiceStockRedeductTest extends ModuleTestCase
{
    protected OrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderService::class);
    }

    /**
     * 취소된 주문 + 복원된(is_stock_deducted=false) CANCELLED 옵션을 생성합니다.
     *
     * @return array{0: Order, 1: ProductOption}
     */
    protected function createCancelledOrderWithRestoredStock(int $stock, int $quantity): array
    {
        $product = Product::factory()->create(['stock_quantity' => $stock]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => $stock,
        ]);

        $order = Order::factory()->create([
            'order_status' => OrderStatusEnum::CANCELLED,
        ]);

        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => $quantity,
            'option_status' => OrderStatusEnum::CANCELLED,
            'is_stock_deducted' => false, // 취소로 복원된 상태
        ]);

        return [$order->fresh('options'), $option];
    }

    public function test_update_rededucts_stock_on_revert_to_payment_complete(): void
    {
        [$order, $option] = $this->createCancelledOrderWithRestoredStock(stock: 8, quantity: 3);

        $this->service->update($order, ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);

        // 재차감으로 재고 -3 (8 → 5)
        $option->refresh();
        $this->assertEquals(5, $option->stock_quantity);

        // 주문 상태 복원됨
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        // 옵션 플래그 재차감됨
        $this->assertTrue($order->options()->first()->is_stock_deducted);
    }

    public function test_update_insufficient_stock_rolls_back_status_and_stock(): void
    {
        // 재고 2 < 재차감 요구량 3 → 부족
        [$order, $option] = $this->createCancelledOrderWithRestoredStock(stock: 2, quantity: 3);

        try {
            $this->service->update($order, ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
            $this->fail('재고 부족 시 InsufficientStockException 이 발생해야 합니다.');
        } catch (InsufficientStockException $e) {
            // 기대된 예외
        }

        // 상태·재고 모두 불변 (롤백)
        $order->refresh();
        $option->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(2, $option->stock_quantity);
        $this->assertFalse($order->options()->first()->is_stock_deducted);
    }

    public function test_update_to_non_sales_status_is_blocked_by_transition_rule(): void
    {
        // 전이 규칙 도입(A30) 이후: 취소 → 결제 전 상태(결제대기) 복귀는 전이 게이트가 차단한다.
        // (취소 복원은 판매 반영 상태로만 허용 — PO 2026-06-23.)
        // 차단되므로 update 가 OrderProcessingException 을 던지고, 재차감은 물론 상태/재고 모두 불변.
        [$order, $option] = $this->createCancelledOrderWithRestoredStock(stock: 8, quantity: 3);

        try {
            $this->service->update($order, ['order_status' => OrderStatusEnum::PENDING_PAYMENT->value]);
            $this->fail('취소 → 결제대기 복귀는 전이 규칙으로 차단되어야 합니다.');
        } catch (OrderProcessingException $e) {
            // 기대된 차단
        }

        $order->refresh();
        $option->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status); // 상태 불변
        $this->assertEquals(8, $option->stock_quantity); // 재고 불변(재차감 없음)
    }

    public function test_bulk_update_rededucts_on_revert(): void
    {
        [$order, $option] = $this->createCancelledOrderWithRestoredStock(stock: 8, quantity: 3);

        $this->service->bulkUpdate([
            'ids' => [$order->id],
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        $option->refresh();
        $this->assertEquals(5, $option->stock_quantity);
        $this->assertTrue($order->options()->first()->is_stock_deducted);
    }

    public function test_bulk_update_status_insufficient_rolls_back(): void
    {
        [$order, $option] = $this->createCancelledOrderWithRestoredStock(stock: 2, quantity: 3);

        try {
            $this->service->bulkUpdateStatus([$order->id], OrderStatusEnum::PAYMENT_COMPLETE->value);
            $this->fail('재고 부족 시 InsufficientStockException 이 발생해야 합니다.');
        } catch (InsufficientStockException $e) {
            // 기대된 예외
        }

        $order->refresh();
        $option->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(2, $option->stock_quantity);
    }
}
