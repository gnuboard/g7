<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\DTO\ManualPaidOptions;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
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

    /**
     * 주문 옵션 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderOptionService::class);
    }

    /**
     * 취소(복원)된 단일 옵션 주문을 생성합니다.
     *
     * @param  int  $stock  상품옵션 재고
     * @param  int  $quantity  주문 수량
     * @return array{0: Order, 1: OrderOption, 2: ProductOption} 생성된 [주문, 주문옵션, 상품옵션]
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

    /**
     * 취소된 행 전량을 판매 상태로 되돌리면 재고를 다시 차감한다.
     *
     * @effects cancelled_to_sales_status_reactivation_allowed
     *
     * @return void 반환값 없음
     */
    public function test_per_line_full_revert_rededucts_stock(): void
    {
        [, $orderOption, $productOption] = $this->createCancelledOrderOption(stock: 8, quantity: 3);

        // 취소 옵션 전체(3개)를 결제완료로 되돌림
        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 3]],
            OrderStatusEnum::PAYMENT_COMPLETE,
            $orderOption->order_id,
        );

        // 재차감으로 재고 -3 (8 → 5)
        $productOption->refresh();
        $this->assertEquals(5, $productOption->stock_quantity, 'per-line 전체 되돌림 시 재고가 재차감되어야 합니다.');

        // 옵션 플래그 재차감됨
        $orderOption->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $orderOption->option_status);
        $this->assertTrue($orderOption->is_stock_deducted);
    }

    /**
     * 취소된 행의 일부 수량만 되돌리면 분할된 수량만큼만 재고를 다시 차감한다.
     *
     * @effects cancelled_to_sales_status_reactivation_allowed
     *
     * @return void 반환값 없음
     */
    public function test_per_line_partial_revert_rededucts_only_split_quantity(): void
    {
        [, $orderOption, $productOption] = $this->createCancelledOrderOption(stock: 8, quantity: 3);

        // 취소 옵션 3개 중 2개만 결제완료로 되돌림 (부분 분할)
        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 2]],
            OrderStatusEnum::PAYMENT_COMPLETE,
            $orderOption->order_id,
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

    /**
     * 재차감할 재고가 모자라면 상태 변경 전체를 되돌린다.
     *
     * @effects cancelled_to_sales_status_reactivation_allowed
     *
     * @return void 반환값 없음
     */
    public function test_per_line_insufficient_stock_rolls_back(): void
    {
        // 재고 2 < 재차감 요구량 3 → 부족
        [, $orderOption, $productOption] = $this->createCancelledOrderOption(stock: 2, quantity: 3);

        try {
            $this->service->bulkChangeStatusWithQuantity(
                [['option_id' => $orderOption->id, 'quantity' => 3]],
                OrderStatusEnum::PAYMENT_COMPLETE,
                $orderOption->order_id,
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

    /**
     * 판매 상태가 아닌 상태로 옮기는 전이는 재고를 다시 차감하지 않는다.
     *
     * @effects cancelled_to_pre_payment_status_blocked
     *
     * @return void 반환값 없음
     */
    public function test_per_line_revert_to_non_sales_status_does_not_rededuct(): void
    {
        // 취소 → 취소(판매 비대상 유지) 전이는 재차감 없음.
        // (부분취소는 별도 상태가 아니므로 비판매 상태는 CANCELLED 뿐 — 동일 상태 no-op 전이도 재차감하지 않아야 한다.)
        [, $orderOption, $productOption] = $this->createCancelledOrderOption(stock: 8, quantity: 3);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 3]],
            OrderStatusEnum::CANCELLED,
            $orderOption->order_id,
        );

        $productOption->refresh();
        $this->assertEquals(8, $productOption->stock_quantity, '판매 비대상 전이는 재차감하지 않아야 합니다.');
    }

    /**
     * 판매(결제완료)된 단일 옵션 주문을 생성합니다.
     *
     * @param  int  $stock  상품옵션 재고
     * @param  int  $quantity  주문 수량
     * @return array{0: Order, 1: OrderOption, 2: ProductOption} 생성된 [주문, 주문옵션, 상품옵션]
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

    /**
     * 판매 행 전량을 행 단위로 취소하면 재고를 복원한다.
     *
     * @effects option_bulk_full_quantity_changes_without_split
     *
     * @return void 반환값 없음
     */
    public function test_per_line_full_cancel_restores_stock(): void
    {
        // 결제완료 옵션(3개, 차감됨)을 per-line 으로 전량 취소 → 재고 복원 +3
        [, $orderOption, $productOption] = $this->createPaidOrderOption(stock: 5, quantity: 3);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 3]],
            OrderStatusEnum::CANCELLED,
            $orderOption->order_id,
        );

        // PG 환불 동반 경로가 아니어도 재고가 복원되어야 한다 (5 → 8)
        $productOption->refresh();
        $this->assertEquals(8, $productOption->stock_quantity, 'per-line 취소 전이 시 재고가 복원되어야 합니다.');

        // 취소 옵션 플래그 정리
        $orderOption->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $orderOption->option_status);
        $this->assertFalse($orderOption->is_stock_deducted);
    }

    /**
     * 판매 행의 일부 수량만 취소하면 분할된 수량만큼만 재고를 복원한다.
     *
     * @effects option_bulk_partial_quantity_splits_row
     *
     * @return void 반환값 없음
     */
    public function test_per_line_partial_cancel_restores_only_split_quantity(): void
    {
        // 결제완료 옵션(3개) 중 2개만 per-line 취소 → 취소분(2)만 복원, 잔여(1) 차감 유지
        [, $orderOption, $productOption] = $this->createPaidOrderOption(stock: 5, quantity: 3);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 2]],
            OrderStatusEnum::CANCELLED,
            $orderOption->order_id,
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

    /**
     * 취소 시 재고 복원 설정이 꺼져 있으면 복원하지 않는다.
     *
     * @effects option_bulk_full_quantity_changes_without_split
     *
     * @return void 반환값 없음
     */
    public function test_per_line_cancel_does_not_restore_when_setting_off(): void
    {
        // 설정 OFF 시 per-line 취소해도 재고 복원 안 함 (취소 모달 경로와 동일 정책)
        app(EcommerceSettingsService::class)
            ->saveSettings(['order_settings' => ['stock_restore_on_cancel' => false]]);

        [, $orderOption, $productOption] = $this->createPaidOrderOption(stock: 5, quantity: 3);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 3]],
            OrderStatusEnum::CANCELLED,
            $orderOption->order_id,
        );

        // 설정 OFF → 재고 불변 (5), 플래그도 차감 유지
        $productOption->refresh();
        $orderOption->refresh();
        $this->assertEquals(5, $productOption->stock_quantity, '설정 OFF 시 재고가 복원되지 않아야 합니다.');
        $this->assertTrue($orderOption->is_stock_deducted, '설정 OFF 시 차감 플래그가 유지되어야 합니다.');
    }

    /**
     * 확인 창에서 「재고 차감」을 끄면 취소 행을 되살려도 재고를 다시 빼지 않는다. (Q13)
     *
     * @effects unchecked_item_is_skipped_only
     *
     * @return void 반환값 없음
     */
    public function test_per_line_revert_with_stock_unchecked_skips_rededuct(): void
    {
        [, $orderOption, $productOption] = $this->createCancelledOrderOption(stock: 8, quantity: 3);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $orderOption->id, 'quantity' => 3]],
            OrderStatusEnum::PAYMENT_COMPLETE,
            $orderOption->order_id,
            [],
            new ManualPaidOptions(applyStockDeduction: false),
        );

        $this->assertEquals(8, $productOption->fresh()->stock_quantity);
        $this->assertFalse($orderOption->fresh()->is_stock_deducted);
    }

    /**
     * 재고 차감 여부가 다른 형제 행은 병합하지 않는다 — 차감하지 않은 재고가 이후 취소에서 복원되지 않게. (Q26)
     *
     * @effects merge_never_mixes_stock_deduction_flags
     *
     * @return void 반환값 없음
     */
    public function test_merge_skipped_when_stock_flags_differ(): void
    {
        [$order, $cancelled, $productOption] = $this->createCancelledOrderOption(stock: 8, quantity: 1);
        $live = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $cancelled->product_id,
            'product_option_id' => $productOption->id,
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'is_stock_deducted' => true,
        ]);
        $cancelled->forceFill(['parent_option_id' => $live->id])->save();

        $this->service->changeStatusWithQuantity($cancelled->fresh(), OrderStatusEnum::PAYMENT_COMPLETE, 1, redeductStockOnReactivation: false);

        $rows = OrderOption::query()->where('order_id', $order->id)->get();
        $this->assertCount(2, $rows, '차감 여부가 다른 두 행은 합쳐지면 안 된다');
        $this->assertEquals(8, $productOption->fresh()->stock_quantity);
    }

    /**
     * 재고를 다시 빼며 되살리면 플래그가 같아져 종전대로 병합된다.
     *
     * @effects reactivation_merges_split_rows_back
     *
     * @return void 반환값 없음
     */
    public function test_merge_happens_when_stock_flags_match(): void
    {
        [$order, $cancelled, $productOption] = $this->createCancelledOrderOption(stock: 8, quantity: 1);
        $live = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $cancelled->product_id,
            'product_option_id' => $productOption->id,
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'is_stock_deducted' => true,
        ]);
        $cancelled->forceFill(['parent_option_id' => $live->id])->save();

        $this->service->changeStatusWithQuantity($cancelled->fresh(), OrderStatusEnum::PAYMENT_COMPLETE, 1);

        $rows = OrderOption::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows->first()->quantity);
        $this->assertEquals(7, $productOption->fresh()->stock_quantity);
    }

    /**
     * 되살리는 행의 취소 수량 카운터를 되돌리고, 분할 조각은 복사된 카운터·사유를 지운다. (Q26)
     *
     * @effects reactivation_releases_cancelled_quantity_counter
     *
     * @return void 반환값 없음
     */
    public function test_reviving_rows_releases_cancelled_quantity_counter(): void
    {
        [$order, $cancelled] = $this->createCancelledOrderOption(stock: 8, quantity: 3);
        $cancelled->forceFill(['cancelled_quantity' => 3, 'cancel_reason' => 'changed_mind'])->save();

        $this->service->changeStatusWithQuantity($cancelled->fresh(), OrderStatusEnum::PAYMENT_COMPLETE, 2);

        $revived = OrderOption::query()->where('order_id', $order->id)->where('option_status', OrderStatusEnum::PAYMENT_COMPLETE->value)->first();
        $remaining = OrderOption::query()->where('order_id', $order->id)->where('option_status', OrderStatusEnum::CANCELLED->value)->first();
        $this->assertSame(0, (int) $revived->cancelled_quantity);
        $this->assertNull($revived->cancel_reason);
        $this->assertSame(1, (int) $remaining->cancelled_quantity);
    }
}
