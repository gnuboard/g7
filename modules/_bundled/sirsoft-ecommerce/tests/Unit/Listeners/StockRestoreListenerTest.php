<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\StockRestoreListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 재고 복원 리스너 테스트
 */
class StockRestoreListenerTest extends ModuleTestCase
{
    protected StockRestoreListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(StockRestoreListener::class);
    }

    public function test_listener_registers_for_order_cancel_hook(): void
    {
        $hooks = StockRestoreListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_cancel', $hooks);
        $this->assertEquals('restoreStock', $hooks['sirsoft-ecommerce.order.after_cancel']['method']);
    }

    public function test_restore_stock_on_order_cancelled(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 100]);

        // 상품 옵션 생성 (재고 5)
        $productOption = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);

        // 주문 생성
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::CANCELLED,
        ]);

        // 주문 옵션 생성 (수량 3, 차감됨)
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_option_id' => $productOption->id,
            'quantity' => 3,
            'is_stock_deducted' => true,
        ]);

        // 훅 실행
        $this->listener->restoreStock($order);

        // 재고가 복원되어야 함 (5 + 3 = 8)
        $productOption->refresh();
        $this->assertEquals(8, $productOption->stock_quantity);
    }

    public function test_restore_stock_for_multiple_options(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 100]);

        $option1 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);
        $option2 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 20,
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::CANCELLED,
        ]);

        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_option_id' => $option1->id,
            'quantity' => 2,
            'is_stock_deducted' => true,
        ]);

        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_option_id' => $option2->id,
            'quantity' => 5,
            'is_stock_deducted' => true,
        ]);

        $this->listener->restoreStock($order);

        $option1->refresh();
        $option2->refresh();

        $this->assertEquals(12, $option1->stock_quantity);
        $this->assertEquals(25, $option2->stock_quantity);
    }

    public function test_restore_skips_non_deducted_options(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 100]);

        $option1 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);
        $option2 = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 20,
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::CANCELLED,
        ]);

        // option1: 차감됨 → 복원 대상
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_option_id' => $option1->id,
            'quantity' => 2,
            'is_stock_deducted' => true,
        ]);

        // option2: 미차감 → 복원 스킵
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_option_id' => $option2->id,
            'quantity' => 5,
            'is_stock_deducted' => false,
        ]);

        $this->listener->restoreStock($order);

        $option1->refresh();
        $option2->refresh();

        $this->assertEquals(12, $option1->stock_quantity); // 복원됨
        $this->assertEquals(20, $option2->stock_quantity); // 변동 없음
    }

    public function test_does_not_throw_for_deleted_product_option(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 100]);
        $productOption = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ]);
        $productOptionId = $productOption->id;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::CANCELLED,
        ]);

        // 주문 옵션 생성 (차감됨)
        OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_option_id' => $productOptionId,
            'quantity' => 3,
            'is_stock_deducted' => true,
        ]);

        // FK 체크 비활성화 후 상품 옵션 삭제 (삭제된 상품 시나리오 시뮬레이션)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $productOption->forceDelete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // 예외 없이 실행되어야 함 (삭제된 옵션은 무시)
        $this->listener->restoreStock($order);

        $this->assertTrue(true); // 예외 없이 통과
    }

    public function test_listener_hook_priority(): void
    {
        $hooks = StockRestoreListener::getSubscribedHooks();

        // 우선순위 5여야 함 (높은 우선순위로 빠르게 처리)
        $this->assertEquals(5, $hooks['sirsoft-ecommerce.order.after_cancel']['priority']);
    }
}
