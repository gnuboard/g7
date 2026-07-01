<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * per-line(주문 옵션 단위) 상태 변경 시 취소 → 판매 상태 복원 재고 재차감 테스트
 *
 * 관리자 주문 상세의 옵션 단위 상태 에디터(OrderOptionService::bulkChangeStatusWithQuantity /
 * changeStatusWithQuantity) 경로에서 취소된 옵션을 판매 상태로 되돌릴 때 재고 재차감이
 * 수행되는지 검증한다. (검수 발견 갭 #3 — 목록 일괄변경/단건 update 와 달리 per-line 경로는
 * rededuct 미호출이던 결함의 회귀 가드)
 */
class OrderOptionServiceStockRedeductTest extends ModuleTestCase
{
    protected OrderOptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderOptionService::class);
    }

    /**
     * 취소(복원)된 단일 옵션 주문을 생성합니다.
     *
     * @return array{0: Order, 1: OrderOption, 2: ProductOption}
     */
    protected function createCancelledOrderOption(int $stock, int $quantity): array
    {
        $product = Product::factory()->create(['stock_quantity' => $stock]);
        $productOption = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => $stock,
        ]);

        $order = Order::factory()->create([
            'order_status' => OrderStatusEnum::CANCELLED,
        ]);

        $orderOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_option_id' => $productOption->id,
            'quantity' => $quantity,
            'option_status' => OrderStatusEnum::CANCELLED,
            'is_stock_deducted' => false, // 취소로 복원된 상태
        ]);

        return [$order->fresh('options'), $orderOption->fresh(), $productOption];
    }

    public function test_per_line_full_revert_rededucts_stock(): void
    {
        [, $orderOption, $productOption] = $this->createCancelledOrderOption(stock: 8, quantity: 3);

        // 취소 옵션 전체(3개)를 결제완료로 되돌림
        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 3]],
            OrderStatusEnum::PAYMENT_COMPLETE
        );

        // 재차감으로 재고 -3 (8 → 5)
        $productOption->refresh();
        $this->assertEquals(5, $productOption->stock_quantity, 'per-line 전체 되돌림 시 재고가 재차감되어야 합니다.');

        // 옵션 플래그 재차감됨
        $orderOption->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $orderOption->option_status);
        $this->assertTrue($orderOption->is_stock_deducted);
    }

    public function test_per_line_partial_revert_rededucts_only_split_quantity(): void
    {
        [, $orderOption, $productOption] = $this->createCancelledOrderOption(stock: 8, quantity: 3);

        // 취소 옵션 3개 중 2개만 결제완료로 되돌림 (부분 분할)
        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 2]],
            OrderStatusEnum::PAYMENT_COMPLETE
        );

        // 되돌린 수량(2)만 재차감 (8 → 6)
        $productOption->refresh();
        $this->assertEquals(6, $productOption->stock_quantity, '부분 되돌림 시 되돌린 수량만 재차감되어야 합니다.');

        // 분할: 판매 전이분(2) 차감 플래그 true, 잔여 취소분(1) 복원 상태 false 유지
        $reactivated = OrderOption::where('product_option_id', $productOption->id)
            ->where('option_status', OrderStatusEnum::PAYMENT_COMPLETE->value)
            ->first();
        $remaining = OrderOption::where('product_option_id', $productOption->id)
            ->where('option_status', OrderStatusEnum::CANCELLED->value)
            ->first();

        $this->assertNotNull($reactivated);
        $this->assertEquals(2, $reactivated->quantity);
        $this->assertTrue($reactivated->is_stock_deducted);

        $this->assertNotNull($remaining);
        $this->assertEquals(1, $remaining->quantity);
        $this->assertFalse($remaining->is_stock_deducted);
    }

    public function test_per_line_insufficient_stock_rolls_back(): void
    {
        // 재고 2 < 재차감 요구량 3 → 부족
        [, $orderOption, $productOption] = $this->createCancelledOrderOption(stock: 2, quantity: 3);

        try {
            $this->service->bulkChangeStatusWithQuantity(
                [['option_id' => $orderOption->id, 'quantity' => 3]],
                OrderStatusEnum::PAYMENT_COMPLETE
            );
            $this->fail('재고 부족 시 InsufficientStockException 이 발생해야 합니다.');
        } catch (InsufficientStockException $e) {
            // 기대된 예외
        }

        // 상태·재고·플래그 모두 불변 (롤백)
        $productOption->refresh();
        $orderOption->refresh();
        $this->assertEquals(2, $productOption->stock_quantity, '부족 시 재고가 불변이어야 합니다(음수 금지).');
        $this->assertEquals(OrderStatusEnum::CANCELLED, $orderOption->option_status, '부족 시 옵션 상태가 취소로 유지되어야 합니다.');
        $this->assertFalse($orderOption->is_stock_deducted);
    }

    public function test_per_line_revert_to_non_sales_status_does_not_rededuct(): void
    {
        // 취소 → 취소(판매 비대상 유지) 전이는 재차감 없음.
        // (부분취소는 별도 상태가 아니므로 비판매 상태는 CANCELLED 뿐 — 동일 상태 no-op 전이도 재차감하지 않아야 한다.)
        [, $orderOption, $productOption] = $this->createCancelledOrderOption(stock: 8, quantity: 3);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 3]],
            OrderStatusEnum::CANCELLED
        );

        $productOption->refresh();
        $this->assertEquals(8, $productOption->stock_quantity, '판매 비대상 전이는 재차감하지 않아야 합니다.');
    }

    /**
     * 판매(결제완료)된 단일 옵션 주문을 생성합니다.
     *
     * @return array{0: Order, 1: OrderOption, 2: ProductOption}
     */
    protected function createPaidOrderOption(int $stock, int $quantity): array
    {
        $product = Product::factory()->create(['stock_quantity' => $stock]);
        $productOption = ProductOption::factory()->create([
            'product_id' => $product->id,
            'stock_quantity' => $stock,
        ]);

        $order = Order::factory()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $orderOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_option_id' => $productOption->id,
            'quantity' => $quantity,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'is_stock_deducted' => true, // 결제완료 = 재고 차감됨
        ]);

        return [$order->fresh('options'), $orderOption->fresh(), $productOption];
    }

    public function test_per_line_full_cancel_restores_stock(): void
    {
        // 결제완료 옵션(3개, 차감됨)을 per-line 으로 전량 취소 → 재고 복원 +3
        [, $orderOption, $productOption] = $this->createPaidOrderOption(stock: 5, quantity: 3);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 3]],
            OrderStatusEnum::CANCELLED
        );

        // PG 환불 동반 경로가 아니어도 재고가 복원되어야 한다 (5 → 8)
        $productOption->refresh();
        $this->assertEquals(8, $productOption->stock_quantity, 'per-line 취소 전이 시 재고가 복원되어야 합니다.');

        // 취소 옵션 플래그 정리
        $orderOption->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $orderOption->option_status);
        $this->assertFalse($orderOption->is_stock_deducted);
    }

    public function test_per_line_partial_cancel_restores_only_split_quantity(): void
    {
        // 결제완료 옵션(3개) 중 2개만 per-line 취소 → 취소분(2)만 복원, 잔여(1) 차감 유지
        [, $orderOption, $productOption] = $this->createPaidOrderOption(stock: 5, quantity: 3);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 2]],
            OrderStatusEnum::CANCELLED
        );

        // 취소 수량(2)만 복원 (5 → 7)
        $productOption->refresh();
        $this->assertEquals(7, $productOption->stock_quantity, '부분 취소 시 취소 수량만 복원되어야 합니다.');

        // 취소 split(2) 차감 false, 잔여 원본(1) 판매 유지 + 차감 true
        $cancelled = OrderOption::where('product_option_id', $productOption->id)
            ->where('option_status', OrderStatusEnum::CANCELLED->value)->first();
        $remaining = OrderOption::where('product_option_id', $productOption->id)
            ->where('option_status', OrderStatusEnum::PAYMENT_COMPLETE->value)->first();

        $this->assertNotNull($cancelled);
        $this->assertEquals(2, $cancelled->quantity);
        $this->assertFalse($cancelled->is_stock_deducted);

        $this->assertNotNull($remaining);
        $this->assertEquals(1, $remaining->quantity);
        $this->assertTrue($remaining->is_stock_deducted);
    }

    public function test_per_line_cancel_does_not_restore_when_setting_off(): void
    {
        // 설정 OFF 시 per-line 취소해도 재고 복원 안 함 (취소 모달 경로와 동일 정책)
        app(\Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService::class)
            ->saveSettings(['order_settings' => ['stock_restore_on_cancel' => false]]);

        [, $orderOption, $productOption] = $this->createPaidOrderOption(stock: 5, quantity: 3);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 3]],
            OrderStatusEnum::CANCELLED
        );

        // 설정 OFF → 재고 불변 (5), 플래그도 차감 유지
        $productOption->refresh();
        $orderOption->refresh();
        $this->assertEquals(5, $productOption->stock_quantity, '설정 OFF 시 재고가 복원되지 않아야 합니다.');
        $this->assertTrue($orderOption->is_stock_deducted, '설정 OFF 시 차감 플래그가 유지되어야 합니다.');
    }
}
