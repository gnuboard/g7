<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 옵션 일괄 상태 변경 API 테스트
 *
 * 수량 분할을 포함한 일괄 상태 변경 기능을 검증합니다.
 */
class OrderOptionBulkStatusTest extends ModuleTestCase
{
    private User $adminUser;

    private ShippingCarrier $carrier;

    /**
     * 주문 상태 변경 권한을 가진 관리자와 택배사를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->carrier = ShippingCarrier::create([
            'code' => 'test_carrier',
            'name' => json_encode(['ko' => '테스트 택배사', 'en' => 'Test Carrier']),
            'type' => 'domestic',
            'is_active' => true,
        ]);
    }

    /**
     * API 엔드포인트 URL 생성 (order_number 기반)
     *
     * @param  string  $orderNumber  주문번호
     * @return string 일괄 상태 변경 URL
     */
    private function bulkStatusUrl(string $orderNumber): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/orders/{$orderNumber}/options/bulk-status";
    }

    // ========== 전체 수량 상태 변경 ==========

    /**
     * 전체 수량을 지정하면 행이 쪼개지지 않고 상태만 바뀐다 (split_count=0)
     *
     * @effects option_bulk_full_quantity_changes_without_split
     *
     * @return void 반환값 없음
     */
    public function test_can_change_full_quantity_status(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 3],
                ],
                'status' => 'shipping',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TEST123456',
            ]);

        // Then
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.changed_count', 1)
            ->assertJsonPath('data.split_count', 0);

        $option->refresh();
        $this->assertEquals(OrderStatusEnum::SHIPPING, $option->option_status);
        $this->assertEquals(3, $option->quantity);
    }

    // ========== 부분 수량 변경 → 레코드 분할 ==========

    /**
     * 부분 수량 변경은 행을 쪼갠다 — 원본이 잔량, 분할 행이 변경분을 갖는다
     *
     * @effects option_bulk_partial_quantity_splits_row
     *
     * @return void 반환값 없음
     */
    public function test_can_split_option_on_partial_quantity_change(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 5,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'unit_price' => 10000,
            'subtotal_price' => 50000,
        ]);

        // When
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 2],
                ],
                'status' => 'shipping',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TEST123456',
            ]);

        // Then
        $response->assertOk()
            ->assertJsonPath('data.changed_count', 1)
            ->assertJsonPath('data.split_count', 1);

        // 원본 레코드: 남은 수량 3
        $option->refresh();
        $this->assertEquals(3, $option->quantity);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $option->option_status);

        // 분할 레코드: 변경 수량 2
        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertNotNull($splitOption);
        $this->assertEquals(2, $splitOption->quantity);
        $this->assertEquals(OrderStatusEnum::SHIPPING, $splitOption->option_status);
    }

    /**
     * 분할 행의 parent_option_id 와 parentOption·splitOptions 관계가 원본을 가리킨다
     *
     * @effects split_row_points_to_parent_and_is_marked_split
     *
     * @return void 반환값 없음
     */
    public function test_split_option_has_correct_parent_reference(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 4,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 1],
                ],
                'status' => 'preparing',
            ]);

        // Then
        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertNotNull($splitOption);
        $this->assertEquals($option->id, $splitOption->parent_option_id);

        // parentOption 관계 확인
        $this->assertEquals($option->id, $splitOption->parentOption->id);

        // splitOptions 관계 확인
        $option->refresh();
        $this->assertCount(1, $option->splitOptions);
    }

    /**
     * 분할 행의 source_type 이 SPLIT 으로 표시된다
     *
     * @effects split_row_points_to_parent_and_is_marked_split
     *
     * @return void 반환값 없음
     */
    public function test_split_option_has_correct_source_type(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 1],
                ],
                'status' => 'shipping',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TEST123456',
            ]);

        // Then
        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertEquals(OrderOptionSourceTypeEnum::SPLIT, $splitOption->source_type);
    }

    // ========== 수량 검증 ==========

    /**
     * 보유 수량을 넘는 수량은 422 로 막힌다
     *
     * @effects option_bulk_quantity_out_of_range_rejected
     *
     * @return void 반환값 없음
     */
    public function test_cannot_exceed_available_quantity(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 비배송 상태로 수량 검증 분리
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 10],
                ],
                'status' => 'preparing',
            ]);

        // Then
        $response->assertUnprocessable();
    }

    /**
     * 수량 0 은 422 로 막힌다
     *
     * @effects option_bulk_quantity_out_of_range_rejected
     *
     * @return void 반환값 없음
     */
    public function test_cannot_change_with_zero_quantity(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 비배송 상태로 수량 검증 분리
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 0],
                ],
                'status' => 'preparing',
            ]);

        // Then
        $response->assertUnprocessable();
    }

    // ========== 일괄 변경 (여러 옵션) ==========

    /**
     * 한 요청에 부분·전체가 섞여도 각 행이 제 몫대로 쪼개지거나 통째로 바뀐다
     *
     * @effects option_bulk_partial_quantity_splits_row, option_bulk_full_quantity_changes_without_split
     *
     * @return void 반환값 없음
     */
    public function test_bulk_change_with_mixed_quantities(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option1 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option2 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: option1은 부분 변경(분할), option2는 전체 변경
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option1->id, 'quantity' => 1],
                    ['option_id' => $option2->id, 'quantity' => 2],
                ],
                'status' => 'shipping',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TEST123456',
            ]);

        // Then
        $response->assertOk()
            ->assertJsonPath('data.changed_count', 2)
            ->assertJsonPath('data.split_count', 1);

        // option1: 분할됨 (남은 2 + 분할 1)
        $option1->refresh();
        $this->assertEquals(2, $option1->quantity);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $option1->option_status);

        $splitOption = OrderOption::where('parent_option_id', $option1->id)->first();
        $this->assertNotNull($splitOption);
        $this->assertEquals(1, $splitOption->quantity);
        $this->assertEquals(OrderStatusEnum::SHIPPING, $splitOption->option_status);

        // option2: 전체 변경
        $option2->refresh();
        $this->assertEquals(2, $option2->quantity);
        $this->assertEquals(OrderStatusEnum::SHIPPING, $option2->option_status);
    }

    // ========== 권한 검증 ==========

    /**
     * orders.update 권한이 없으면 403
     *
     * @effects option_bulk_requires_orders_update_permission
     *
     * @return void 반환값 없음
     */
    public function test_unauthorized_user_cannot_bulk_change(): void
    {
        // Given
        $user = $this->createUser();
        $order = OrderFactory::new()->create();

        // When
        $response = $this->actingAs($user)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => 1, 'quantity' => 1]],
                'status' => 'shipping',
            ]);

        // Then
        $response->assertForbidden();
    }

    // ========== 요청 검증 ==========

    /**
     * items 가 없으면 422 + items 검증 오류
     *
     * @effects option_bulk_requires_items_and_valid_status
     *
     * @return void 반환값 없음
     */
    public function test_requires_items_array(): void
    {
        // Given
        $order = OrderFactory::new()->create();

        // When
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'status' => 'shipping',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    // ========== 배송 정보 필수 검증 ==========

    /**
     * 배송중으로 옮길 때 택배사·송장번호가 둘 다 없으면 422
     *
     * @effects shipping_status_requires_carrier_and_tracking
     *
     * @return void 반환값 없음
     */
    public function test_shipping_status_requires_carrier_and_tracking(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 배송 상태로 변경하면서 택배사/송장번호 누락
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 3],
                ],
                'status' => 'shipping',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['carrier_id', 'tracking_number']);
    }

    /**
     * 배송준비중으로 옮길 때 택배사만 있고 송장번호가 없으면 422
     *
     * @effects shipping_status_requires_carrier_and_tracking
     *
     * @return void 반환값 없음
     */
    public function test_shipping_status_requires_tracking_number(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 택배사만 있고 송장번호 누락
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 3],
                ],
                'status' => 'shipping_ready',
                'carrier_id' => $this->carrier->id,
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tracking_number']);
    }

    /**
     * 배송 계열이 아닌 상태(준비중)는 택배사·송장번호 없이 통과한다
     *
     * @effects shipping_status_requires_carrier_and_tracking
     *
     * @return void 반환값 없음
     */
    public function test_non_shipping_status_does_not_require_carrier(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 비배송 상태는 택배사/송장번호 불필요
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 3],
                ],
                'status' => 'preparing',
            ]);

        // Then
        $response->assertOk();
    }

    /**
     * 어휘 밖 상태 문자열은 422 + status 검증 오류
     *
     * @effects option_bulk_requires_items_and_valid_status
     *
     * @return void 반환값 없음
     */
    public function test_requires_valid_status(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create();

        // When
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'invalid_status',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    // ========== 금액 분할 계산 정확성 ==========

    /**
     * 분할 행과 잔량 행의 소계·할인이 수량 비율로 나뉜다 (5000 × 2/5 = 2000)
     *
     * @effects split_row_amounts_are_prorated_by_quantity
     *
     * @return void 반환값 없음
     */
    public function test_split_amount_calculation_is_correct(): void
    {
        // Given: 할인 5000원, 수량 5개
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 5,
            'unit_price' => 10000,
            'subtotal_price' => 50000,
            'subtotal_discount_amount' => 5000,
            'coupon_discount_amount' => 0,
            'code_discount_amount' => 0,
            'subtotal_points_used_amount' => 0,
            'subtotal_deposit_used_amount' => 0,
            'subtotal_paid_amount' => 45000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 2개만 분할
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 2]],
                'status' => 'preparing',
            ]);

        // Then: 분할 할인 = 5000 × (2/5) = 2000, 원본 할인 = 5000 - 2000 = 3000
        $response->assertOk();

        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertNotNull($splitOption);
        $this->assertEquals(2000, $splitOption->subtotal_discount_amount);
        $this->assertEquals(20000, $splitOption->subtotal_price); // 10000 × 2

        $option->refresh();
        $this->assertEquals(3000, $option->subtotal_discount_amount);
        $this->assertEquals(30000, $option->subtotal_price); // 10000 × 3
    }

    /**
     * 분할 후에도 결제액이 소계 − 할인 − 마일리지 − 예치금 항등식을 지킨다
     *
     * @effects split_row_amounts_are_prorated_by_quantity
     *
     * @return void 반환값 없음
     */
    public function test_split_subtotal_paid_amount_is_consistent(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 4,
            'unit_price' => 20000,
            'subtotal_price' => 80000,
            'subtotal_discount_amount' => 4000,
            'coupon_discount_amount' => 0,
            'code_discount_amount' => 0,
            'subtotal_points_used_amount' => 2000,
            'subtotal_deposit_used_amount' => 1000,
            'subtotal_paid_amount' => 73000, // 80000 - 4000 - 2000 - 1000
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 1개 분할
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'preparing',
            ]);

        // Then: paid = price - discount - points - deposit
        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $expectedPaid = $splitOption->subtotal_price
            - $splitOption->subtotal_discount_amount
            - $splitOption->subtotal_points_used_amount
            - $splitOption->subtotal_deposit_used_amount;
        $this->assertEquals($expectedPaid, $splitOption->subtotal_paid_amount);

        $option->refresh();
        $expectedOrigPaid = $option->subtotal_price
            - $option->subtotal_discount_amount
            - $option->subtotal_points_used_amount
            - $option->subtotal_deposit_used_amount;
        $this->assertEquals($expectedOrigPaid, $option->subtotal_paid_amount);
    }

    /**
     * 분할 행과 잔량 행의 무게·부피가 수량 비율로 나뉜다
     *
     * @effects split_row_weight_and_volume_are_prorated
     *
     * @return void 반환값 없음
     */
    public function test_split_weight_and_volume_proportional(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 4,
            'unit_weight' => 0.5,
            'unit_volume' => 0.02,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 1개 분할
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'preparing',
            ]);

        // Then
        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertEquals(0.5, $splitOption->subtotal_weight); // 0.5 × 1
        $this->assertEquals(0.02, $splitOption->subtotal_volume); // 0.02 × 1

        $option->refresh();
        $this->assertEquals(1.5, $option->subtotal_weight); // 0.5 × 3
        $this->assertEquals(0.06, $option->subtotal_volume); // 0.02 × 3
    }

    /**
     * 분할 행이 product_id·product_option_id·order_id·sku 를 그대로 승계한다
     *
     * @effects split_row_carries_over_product_and_snapshot_columns
     *
     * @return void 반환값 없음
     */
    public function test_split_preserves_product_references(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'preparing',
            ]);

        // Then: product_id, product_option_id, order_id, sku 동일
        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertEquals($option->product_id, $splitOption->product_id);
        $this->assertEquals($option->product_option_id, $splitOption->product_option_id);
        $this->assertEquals($option->order_id, $splitOption->order_id);
        $this->assertEquals($option->sku, $splitOption->sku);
    }

    /**
     * 분할 행이 상품·옵션·프로모션 스냅샷을 그대로 승계한다
     *
     * @effects split_row_carries_over_product_and_snapshot_columns
     *
     * @return void 반환값 없음
     */
    public function test_split_preserves_snapshot_columns(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'preparing',
            ]);

        // Then: 스냅샷 동일
        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertEquals($option->product_snapshot, $splitOption->product_snapshot);
        $this->assertEquals($option->option_snapshot, $splitOption->option_snapshot);
        $this->assertEquals($option->promotions_applied_snapshot, $splitOption->promotions_applied_snapshot);
    }

    // ========== 병합 테스트 ==========

    /**
     * 잔량까지 같은 상태가 되면 두 행이 한 행으로 합쳐지고 피흡수 행은 사라진다
     *
     * @effects same_status_rows_merge_back_into_one
     *
     * @return void 반환값 없음
     */
    public function test_merge_when_remaining_changed_to_same_status(): void
    {
        // Given: qty=3 → 2개 분할(shipping)
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'unit_price' => 10000,
            'subtotal_price' => 30000,
            'subtotal_discount_amount' => 0,
            'coupon_discount_amount' => 0,
            'code_discount_amount' => 0,
            'subtotal_points_used_amount' => 0,
            'subtotal_deposit_used_amount' => 0,
            'subtotal_paid_amount' => 30000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 2]],
                'status' => 'shipping',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TEST111',
            ]);

        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertNotNull($splitOption);

        // When: 남은 1개도 shipping으로 변경 → 병합
        $option->refresh();
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'shipping',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TEST222',
            ]);

        // Then: 병합되어 1레코드(qty=3)
        $response->assertOk();
        $splitOption->refresh();
        $this->assertEquals(3, $splitOption->quantity);
        $this->assertEquals(OrderStatusEnum::SHIPPING, $splitOption->option_status);

        // 원본(피흡수)은 삭제됨
        $this->assertNull(OrderOption::find($option->id));
    }

    /**
     * 병합 행의 수량·소계·할인이 분할 전 값으로 다시 합산된다
     *
     * @effects merged_row_sums_amounts_and_quantity
     *
     * @return void 반환값 없음
     */
    public function test_merge_combines_amounts_correctly(): void
    {
        // Given: 분할 후 금액이 나뉜 상태
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 4,
            'unit_price' => 10000,
            'subtotal_price' => 40000,
            'subtotal_discount_amount' => 4000,
            'coupon_discount_amount' => 0,
            'code_discount_amount' => 0,
            'subtotal_points_used_amount' => 0,
            'subtotal_deposit_used_amount' => 0,
            'subtotal_paid_amount' => 36000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $originalTotalPrice = 40000;
        $originalTotalDiscount = 4000;

        // 2개 분할
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 2]],
                'status' => 'preparing',
            ]);

        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $option->refresh();

        // When: 남은 2개도 preparing으로 → 병합
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 2]],
                'status' => 'preparing',
            ]);

        // Then: 병합 후 원본 금액 합산 일치
        $splitOption->refresh();
        $this->assertEquals(4, $splitOption->quantity);
        $this->assertEquals($originalTotalPrice, $splitOption->subtotal_price);
        $this->assertEquals($originalTotalDiscount, $splitOption->subtotal_discount_amount);
    }

    /**
     * 수량 분할로 생긴 레코드도 자기 배송 행을 가져야 한다 (S3-D5 회귀).
     *
     * `ClaimShippingFeeService` 는 옵션의 배송 행에서 배송정책을 얻는다. 분할 행에 배송 행이
     * 없으면 정책 그룹이 비어 `no_shipping_policy` 로 떨어지고, **고객 귀책 반품·교환인데도
     * 회수비·왕복 배송비가 0원**으로 청구된다. 교환까지 가면 `replicateShippingRow()` 가 원 행의
     * 배송 행을 못 찾아 건너뛰므로 후속 행도 배송 행 없이 이어진다.
     *
     * 실측(주문 106): 분할로 생긴 opt774 와 그 후속 opt775 만 배송 행이 없었고,
     * 그 행을 교환하자 `shipping_fee.fee = 0` · `reason = "no_shipping_policy"` 가 나왔다
     * (정책1 고객 귀책 교환이므로 왕복 6,000 이어야 한다).
     *
     * @scenario from_status=payment_complete,to_status=preparing,transition_path=option_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects split_row_gets_its_own_shipping_record
     *
     * @return void 반환값 없음
     */
    public function test_split_option_gets_its_own_shipping_record(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $origin = OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'pending',
            'shipping_type' => 'parcel',
            'shipping_policy_id' => 1,
        ]);

        // 2개 중 1개만 전이 → 행 분할
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => OrderStatusEnum::PREPARING->value,
            ])
            ->assertOk();

        $split = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertNotNull($split, '분할 레코드가 생성되지 않았습니다.');

        $splitShipping = OrderShipping::where('order_option_id', $split->id)->first();

        $this->assertNotNull(
            $splitShipping,
            '분할 레코드에 배송 행이 없습니다 — 클레임 배송비가 0원(no_shipping_policy)이 됩니다.'
        );
        $this->assertSame(
            (int) $origin->shipping_policy_id,
            (int) $splitShipping->shipping_policy_id,
            '분할 레코드가 원 행의 배송정책을 승계하지 않았습니다.'
        );

        // 원 행의 배송 행은 그대로 남는다(이전이 아니라 복제).
        $this->assertNotNull(OrderShipping::where('order_option_id', $option->id)->first());
    }

    /**
     * 수량 분할로 생긴 배송 행은 배송정책만 승계하고 배송비는 0 이다 (S3-D9, 2026-10-07 실측).
     *
     * 복제본이 원 행의 배송비까지 들고 가, 2개 중 1개씩 나눠 보낸 주문(Q10·Q12)의 고객 「배송 현황」에 같은
     * 2,500원이 두 줄로 보였다(실제 배송비 2,500원). 주문 헤더 합계는 행 수로 세지 않아 변하지 않았지만,
     * 행 금액을 그대로 그리는 화면은 배송비를 두 번 보인다.
     *
     * @scenario from_status=payment_complete,to_status=preparing,transition_path=option_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects split_row_gets_its_own_shipping_record
     *
     * @return void 반환값 없음
     */
    public function test_split_shipping_row_carries_no_shipping_amount(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'pending',
            'shipping_type' => 'parcel',
            'shipping_policy_id' => 1,
            'base_shipping_amount' => 3000,
            'extra_shipping_amount' => 500,
            'total_shipping_amount' => 3500,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => OrderStatusEnum::PREPARING->value,
            ])
            ->assertOk();

        $split = OrderOption::where('parent_option_id', $option->id)->first();
        $splitShipping = OrderShipping::where('order_option_id', $split->id)->first();

        $this->assertSame(1, (int) $splitShipping->shipping_policy_id, '분할 행이 배송정책을 승계하지 않았습니다.');
        $this->assertEquals(0.0, (float) $splitShipping->base_shipping_amount, '분할 행이 기본 배송비를 복제했습니다.');
        $this->assertEquals(0.0, (float) $splitShipping->extra_shipping_amount, '분할 행이 추가 배송비를 복제했습니다.');
        $this->assertEquals(0.0, (float) $splitShipping->total_shipping_amount, '분할 행이 배송비 합계를 복제했습니다 — 고객 화면에 배송비가 두 번 보입니다.');
        $this->assertEquals(3500.0, (float) OrderShipping::where('order_option_id', $option->id)->value('total_shipping_amount'));
    }

    /**
     * 배송 행 복제가 배송비를 이중 계상하지 않아야 한다 (S3-D5 경계).
     *
     * 분할로 배송 행이 하나 늘어나므로, 금액을 배송 행 수로 세는 구현이었다면 주문 배송비가
     * 두 배가 된다. 배송비는 **옵션 단위 정책 조회**로 쓰일 뿐 행 수로 합산되지 않아야 한다
     * — 같은 정책·국가의 행은 한 그룹으로 접힌다.
     *
     * @scenario from_status=payment_complete,to_status=preparing,transition_path=option_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects split_row_gets_its_own_shipping_record
     *
     * @return void 반환값 없음
     */
    public function test_split_shipping_replication_does_not_double_count_order_shipping(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'pending',
            'shipping_type' => 'parcel',
            'shipping_policy_id' => 1,
            'total_shipping_amount' => 3000,
        ]);

        $orderShippingBefore = (float) $order->total_shipping_amount;

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => OrderStatusEnum::PREPARING->value,
            ])
            ->assertOk();

        $order->refresh();

        // 주문 헤더의 배송비 총액은 분할로 변하지 않는다 — 분할은 회계 사실이 아니라 상태 분리다.
        $this->assertEquals(
            $orderShippingBefore,
            (float) $order->total_shipping_amount,
            '분할이 주문 배송비 총액을 바꿨습니다 — 이중 계상 위험.'
        );

        // 배송 행은 늘지만(정책 조회용) 금액은 원 행에만 남는다 — 복제본은 정책 승계가 목적이다.
        $originShipping = OrderShipping::where('order_option_id', $option->id)->first();
        $this->assertEquals(
            3000.0,
            (float) $originShipping->total_shipping_amount,
            '원 행의 배송비가 분할로 바뀌었습니다.'
        );

        $split = OrderOption::where('parent_option_id', $option->id)->first();
        $splitShipping = OrderShipping::where('order_option_id', $split->id)->first();
        $this->assertNotNull($splitShipping);
        $this->assertSame(
            (int) $originShipping->shipping_policy_id,
            (int) $splitShipping->shipping_policy_id,
            '복제본이 배송정책을 승계하지 않았습니다 — 클레임 배송비 조회가 실패합니다.'
        );
    }

    /**
     * 병합 시 피흡수 행의 배송 레코드가 생존 행으로 옮겨진다 (cascade 삭제 방지)
     *
     * @effects merge_reassigns_dependent_shipping_and_review_rows
     *
     * @return void 반환값 없음
     */
    public function test_merge_reassigns_shipping_records(): void
    {
        // Given: 분할 후 원본에 배송 레코드 존재
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // 배송 레코드 생성
        $shipping = OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'pending',
            'shipping_type' => 'parcel',
        ]);

        // 2개 분할
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 2]],
                'status' => 'preparing',
            ]);

        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $option->refresh();

        // When: 남은 1개도 preparing으로 → 병합 (option이 피흡수됨)
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'preparing',
            ]);

        // Then: 배송 레코드가 생존 레코드로 이전됨 (cascade 삭제 방지)
        $shipping->refresh();
        $this->assertEquals($splitOption->id, $shipping->order_option_id);
    }

    /**
     * 병합 시 피흡수 행의 리뷰 레코드가 생존 행으로 옮겨진다
     *
     * @effects merge_reassigns_dependent_shipping_and_review_rows
     *
     * @return void 반환값 없음
     */
    public function test_merge_reassigns_review_records(): void
    {
        // Given: 분할 후 원본에 리뷰 레코드 존재
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $review = ProductReview::create([
            'product_id' => $option->product_id,
            'order_option_id' => $option->id,
            'user_id' => $this->adminUser->id,
            'rating' => 5,
            'content' => json_encode(['ko' => '좋습니다']),
            'content_mode' => 'text',
            'status' => 'visible',
        ]);

        // 2개 분할
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 2]],
                'status' => 'preparing',
            ]);

        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $option->refresh();

        // When: 남은 1개도 preparing으로 → 병합
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'preparing',
            ]);

        // Then: 리뷰가 생존 레코드로 이전됨
        $review->refresh();
        $this->assertEquals($splitOption->id, $review->order_option_id);
    }

    // ========== 연속 분할 / 일괄 변경 ==========

    /**
     * 연속 분할이 서로 다른 상태의 독립한 행으로 쌓인다 (원본 3 · 준비중 1 · 배송중 1)
     *
     * @effects sequential_splits_accumulate_independent_rows
     *
     * @return void 반환값 없음
     */
    public function test_multiple_sequential_splits(): void
    {
        // Given: qty=5
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 5,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 1개 분할 (preparing)
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'preparing',
            ]);

        // 또 1개 분할 (shipping)
        $option->refresh();
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'shipping',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TEST999',
            ]);

        // Then: 3개 레코드 (원본 3, 분할1 1, 분할2 1)
        $option->refresh();
        $this->assertEquals(3, $option->quantity);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $option->option_status);

        $splits = OrderOption::where('parent_option_id', $option->id)->get();
        $this->assertCount(2, $splits);

        $preparingSplit = $splits->firstWhere('option_status', OrderStatusEnum::PREPARING);
        $this->assertEquals(1, $preparingSplit->quantity);

        $shippingSplit = $splits->firstWhere('option_status', OrderStatusEnum::SHIPPING);
        $this->assertEquals(1, $shippingSplit->quantity);
    }

    /**
     * 세 행 모두 전체 수량이면 split_count=0
     *
     * @effects option_bulk_full_quantity_changes_without_split
     *
     * @return void 반환값 없음
     */
    public function test_bulk_all_full_quantity_no_split(): void
    {
        // Given: 3개 옵션
        $order = OrderFactory::new()->create();
        $option1 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option2 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option3 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 모두 전체 수량 변경
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option1->id, 'quantity' => 2],
                    ['option_id' => $option2->id, 'quantity' => 3],
                    ['option_id' => $option3->id, 'quantity' => 1],
                ],
                'status' => 'preparing',
            ]);

        // Then: split_count = 0
        $response->assertOk()
            ->assertJsonPath('data.changed_count', 3)
            ->assertJsonPath('data.split_count', 0);
    }

    /**
     * 세 행 모두 부분 수량이면 split_count=3
     *
     * @effects option_bulk_partial_quantity_splits_row
     *
     * @return void 반환값 없음
     */
    public function test_bulk_all_partial_quantity_all_split(): void
    {
        // Given: 3개 옵션
        $order = OrderFactory::new()->create();
        $option1 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option2 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 4,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option3 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 모두 부분 수량 변경
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option1->id, 'quantity' => 1],
                    ['option_id' => $option2->id, 'quantity' => 2],
                    ['option_id' => $option3->id, 'quantity' => 1],
                ],
                'status' => 'preparing',
            ]);

        // Then: split_count = 3
        $response->assertOk()
            ->assertJsonPath('data.changed_count', 3)
            ->assertJsonPath('data.split_count', 3);
    }

    /**
     * 분할 뒤 잔량을 다른 상태로 옮겨도 분할 행은 제 상태를 유지한다. 일괄 어휘에 취소는 없다(422)
     *
     * @effects option_bulk_cancelled_is_not_in_admin_vocabulary, sequential_splits_accumulate_independent_rows
     *
     * @return void 반환값 없음
     */
    public function test_split_then_full_change_remaining(): void
    {
        // Given: 부분 분할 후 남은 전체 수량을 다른 상태로 변경
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 5,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // 2개 shipping 분할
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 2]],
                'status' => 'shipping',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TEST333',
            ]);

        // When: 남은 3개를 다른 상태로 전체 변경
        $option->refresh();
        $this->assertEquals(3, $option->quantity);

        // 일괄 변경 어휘에는 `cancelled` 가 없다 — 취소는 [주문취소] 모달(환불신청서 경로)만이
        // 만든다(1-O · U6 · `OrderStatusEnum::adminAssignableStatuses()`). 예전 이 테스트는
        // 일괄 변경으로 취소를 걸어 200 을 기대했는데, 그 경로가 열려 있으면 환불 없이 상품만
        // 취소되는 장부 어긋남이 생긴다. 계약을 여기서 못 박는다.
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 3]],
                'status' => 'cancelled',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 3]],
                'status' => 'preparing',
            ]);

        // Then
        $response->assertOk();
        $option->refresh();
        $this->assertEquals(OrderStatusEnum::PREPARING, $option->option_status);
        $this->assertEquals(3, $option->quantity);

        // 분할 레코드는 shipping 유지
        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertEquals(OrderStatusEnum::SHIPPING, $splitOption->option_status);
        $this->assertEquals(2, $splitOption->quantity);
    }

    /**
     * 분할이 먼저 난 뒤 부모가 같은 상태가 되면 분할 행 쪽으로 합쳐진다
     *
     * @effects same_status_rows_merge_back_into_one, merged_row_sums_amounts_and_quantity
     *
     * @return void 반환값 없음
     */
    public function test_merge_back_into_parent(): void
    {
        // Given: 분할 후 부모를 같은 상태로 변경 시 부모로 병합
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 4,
            'unit_price' => 10000,
            'subtotal_price' => 40000,
            'subtotal_discount_amount' => 0,
            'coupon_discount_amount' => 0,
            'code_discount_amount' => 0,
            'subtotal_points_used_amount' => 0,
            'subtotal_deposit_used_amount' => 0,
            'subtotal_paid_amount' => 40000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // 3개 분할 (preparing)
        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 3]],
                'status' => 'preparing',
            ]);

        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertNotNull($splitOption);
        $this->assertEquals(3, $splitOption->quantity);

        // When: 남은 1개도 preparing으로 변경
        $option->refresh();
        $this->assertEquals(1, $option->quantity);

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => 'preparing',
            ]);

        // Then: 병합되어 splitOption에 수량 4
        $splitOption->refresh();
        $this->assertEquals(4, $splitOption->quantity);
        $this->assertEquals(40000, $splitOption->subtotal_price);
        $this->assertNull(OrderOption::find($option->id));
    }

    // ========== 배송완료 상태 변경 — 운송장 선택사항 ==========

    /**
     * 배송완료는 택배사·송장번호 없이도 통과한다
     *
     * @effects delivered_status_treats_carrier_and_tracking_as_optional
     *
     * @return void 반환값 없음
     */
    public function test_delivered_status_does_not_require_carrier_and_tracking(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);

        // When: 배송완료 상태 변경 시 택배사/송장번호 없이
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 2],
                ],
                'status' => 'delivered',
            ]);

        // Then: 성공 — delivered는 배송정보 필수가 아님
        $response->assertOk();

        $option->refresh();
        $this->assertEquals(OrderStatusEnum::DELIVERED, $option->option_status);
    }

    /**
     * 배송완료에 택배사·송장번호를 함께 넣어도 통과한다
     *
     * @effects delivered_status_treats_carrier_and_tracking_as_optional
     *
     * @return void 반환값 없음
     */
    public function test_delivered_status_with_optional_carrier_and_tracking(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);

        // When: 배송완료 + 택배사/송장번호 선택 입력
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 2],
                ],
                'status' => 'delivered',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TRACK123',
            ]);

        // Then: 성공
        $response->assertOk();

        $option->refresh();
        $this->assertEquals(OrderStatusEnum::DELIVERED, $option->option_status);
    }

    // ========== 상태 전이 규칙 (A30) ==========

    /**
     * 옵션일괄: 역방향 전이(배송중 → 결제완료)는 422 로 차단되고 분할/병합·option_status 미발생.
     *
     * @scenario transition_path=option_bulk, from_status=shipping, to_status=payment_complete, classification=reverse_not_whitelisted
     *
     * @effects option_bulk_reverse_transition_blocked_no_split
     *
     * @return void 반환값 없음
     */
    public function test_bulk_change_blocks_reverse_transition(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 3],
                ],
                'status' => 'payment_complete',
            ]);

        // Then: 422 + 항목별 에러키
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.status']);

        // 분할/병합·option_status 미발생
        $option->refresh();
        $this->assertEquals(OrderStatusEnum::SHIPPING, $option->option_status);
        $this->assertEquals(3, $option->quantity);
        $this->assertNull(OrderOption::where('parent_option_id', $option->id)->first(), '차단 시 분할 미발생');
    }

    /**
     * 옵션일괄: 여러 항목 중 1건이라도 역방향이면 전체 422 + 정상 항목도 미변경(all-or-nothing).
     *
     * @scenario transition_path=option_bulk, mix=1_forward_1_reverse, to_status=delivered, classification=all_or_nothing
     *
     * @effects option_bulk_all_or_nothing_blocks
     *
     * @return void 반환값 없음
     */
    public function test_bulk_change_blocks_all_when_one_reverse(): void
    {
        $order = OrderFactory::new()->create();
        // forward 가능: SHIPPING → DELIVERED
        $okOption = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);
        // 역방향: CONFIRMED → DELIVERED (되돌림 금지)
        $violator = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::CONFIRMED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $okOption->id, 'quantity' => 2],
                    ['option_id' => $violator->id, 'quantity' => 1],
                ],
                'status' => 'delivered',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.status']);

        // all-or-nothing: 정상 항목도 미변경
        $this->assertEquals(OrderStatusEnum::SHIPPING, $okOption->fresh()->option_status);
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $violator->fresh()->option_status);
    }

    /**
     * 옵션일괄: forward 전이는 정상 통과(회귀 보호).
     *
     * @scenario transition_path=option_bulk, from_status=payment_complete, to_status=preparing, classification=forward
     *
     * @effects option_bulk_forward_transition_allowed
     *
     * @return void 반환값 없음
     */
    public function test_bulk_change_allows_forward_transition(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 2],
                ],
                'status' => 'preparing',
            ]);

        $response->assertOk();
        $this->assertEquals(OrderStatusEnum::PREPARING, $option->fresh()->option_status);
    }

    // ========== 부모 주문 상태 동기화 ==========

    /**
     * 모든 옵션이 같은 상태가 되면 부모 주문도 그 상태로 따라간다
     *
     * @effects parent_order_status_syncs_to_lowest_option_stage
     *
     * @return void 반환값 없음
     */
    public function test_parent_order_syncs_when_all_options_same_status(): void
    {
        // Given: 주문 1개, 옵션 2개 (결제완료)
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option1 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option2 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 모든 옵션을 배송완료로 변경
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option1->id, 'quantity' => 1],
                    ['option_id' => $option2->id, 'quantity' => 1],
                ],
                'status' => 'delivered',
            ]);

        // Then: 부모 주문도 배송완료
        $response->assertOk();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::DELIVERED, $order->order_status);
    }

    /**
     * 옵션 상태가 섞이면 부모 주문은 가장 낮은 진행 단계를 따른다
     *
     * @effects parent_order_status_syncs_to_lowest_option_stage
     *
     * @return void 반환값 없음
     */
    public function test_parent_order_syncs_to_lowest_status_when_mixed(): void
    {
        // Given: 주문 1개, 옵션 2개 (결제완료)
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option1 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option2 = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: option1만 배송완료로 변경 (option2는 결제완료 유지)
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option1->id, 'quantity' => 1],
                ],
                'status' => 'delivered',
            ]);

        // Then: 혼합 상태 → 가장 낮은 진행 단계(결제완료)
        $response->assertOk();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    // ========== carrier_id 키 정합성 ==========

    /**
     * 요청 키가 carrier 가 아니라 carrier_id 로 받아진다
     *
     * @effects shipping_status_requires_carrier_and_tracking
     *
     * @return void 반환값 없음
     */
    public function test_carrier_id_key_is_accepted_in_request(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: carrier_id 키로 전송 (carrier 아님)
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 1],
                ],
                'status' => 'shipping',
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'TEST123',
            ]);

        // Then: 성공
        $response->assertOk();
    }

    // ========== results 구조 검증 ==========

    /**
     * 응답 results 의 각 행에 order_option_id 가 실린다
     *
     * @effects option_bulk_response_carries_result_row_ids
     *
     * @return void 반환값 없음
     */
    public function test_results_contain_order_option_id_key(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 2],
                ],
                'status' => 'preparing',
            ]);

        // Then: results에 order_option_id 키 존재
        $response->assertOk();
        $results = $response->json('data.results');
        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('order_option_id', $results[0]);
        $this->assertEquals($option->id, $results[0]['order_option_id']);
    }

    /**
     * 분할이 난 행의 results 에 split_order_option_id 가 실리고 그 id 로 DB 행을 찾을 수 있다
     *
     * @effects option_bulk_response_carries_result_row_ids
     *
     * @return void 반환값 없음
     */
    public function test_split_results_contain_split_order_option_id(): void
    {
        // Given
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // When: 부분 수량 분할
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 1],
                ],
                'status' => 'preparing',
            ]);

        // Then: split_order_option_id 존재
        $response->assertOk();
        $results = $response->json('data.results');
        $this->assertNotNull($results[0]['split_order_option_id']);

        // 분할 옵션 DB에 존재
        $splitOption = OrderOption::find($results[0]['split_order_option_id']);
        $this->assertNotNull($splitOption);
        $this->assertEquals(OrderStatusEnum::PREPARING, $splitOption->option_status);
    }
    // ========== 클레임 코스 상태 차단 (D15 후단) ==========

    /**
     * 클레임 진행 중인 상품의 상태 변경은 화면 경로에서 운영자 안내와 함께 422 로 막힌다
     *
     * @effects claim_course_option_status_change_blocked_with_422_guidance
     *
     * @return void 반환값 없음
     */
    public function test_claim_course_item_is_blocked_with_a_guidance_message(): void
    {
        // 화면 경로 — FormRequest 가 items.N.status 에 운영자 안내를 싣는다.
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::RETURN_IN_PROGRESS,
        ]);

        // 전이 게이트 자체는 이 전이를 허용한다(반려·철회 복귀용) — 가드가 유일한 차단 지점이다.
        $this->assertTrue(OrderStatusEnum::RETURN_IN_PROGRESS->canTransitionTo(OrderStatusEnum::DELIVERED));

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 1],
                ],
                'status' => OrderStatusEnum::DELIVERED->value,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.status']);
        $this->assertEquals(OrderStatusEnum::RETURN_IN_PROGRESS, $option->fresh()->option_status);
    }

    /**
     * 요청 검증을 거치지 않는 서비스 경로에서도 같은 안내로 422 가 난다
     *
     * @effects claim_course_option_status_change_blocked_with_422_guidance
     *
     * @return void 반환값 없음
     */
    public function test_claim_course_block_from_the_service_path_keeps_the_same_guidance(): void
    {
        // FormRequest 를 우회하는 내부·훅 호출이 서비스 2차 방어에 걸린 경우.
        // 도메인 실패이므로 화면 경로와 같은 422 안내여야 한다 — 광역 catch 로 흘러 500 이 되면
        // 운영자는 스스로 고칠 수 있는 상황을 서버 장애로 읽는다. docs/api/orders.md 도 422 로 적혀 있다.
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);

        // 라벨은 예외 생성 시점에 치환 파라미터로 굳는다 — 같은 값을 기대값에도 써서
        // 테스트 본문과 요청 처리의 로케일 차이가 단언을 흔들지 않게 한다.
        $courseLabel = OrderStatusEnum::RETURN_IN_PROGRESS->label();

        $this->mock(OrderOptionService::class, function ($mock) use ($courseLabel) {
            $mock->shouldReceive('bulkChangeStatusWithQuantity')
                ->andThrow(OrderClaimException::optionInClaimCourseStatusChange($courseLabel));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 1],
                ],
                'status' => OrderStatusEnum::DELIVERED->value,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_option_in_course_status_change', [
            'status' => $courseLabel,
        ]));
    }

    /**
     * 도메인 예외가 아닌 기반 장애는 입력 오류로 위장되지 않고 500 으로 남는다
     *
     * @effects option_bulk_infrastructure_failure_returns_500
     *
     * @return void 반환값 없음
     */
    public function test_infrastructure_failure_of_bulk_status_still_returns_500(): void
    {
        // 대조군 — 도메인 예외가 아닌 실패는 계속 500 이어야 한다(장애가 입력 오류로 위장되지 않음).
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);

        $this->mock(OrderOptionService::class, function ($mock) {
            $mock->shouldReceive('bulkChangeStatusWithQuantity')
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error'));
            $mock->shouldReceive()->andReturnNull();
        });

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 1],
                ],
                'status' => OrderStatusEnum::DELIVERED->value,
            ]);

        $response->assertStatus(500);
    }

    // ========== 배송 레코드 상태 동기화 (S3-D2 회귀) ==========

    /**
     * 배송중 전이는 배송 레코드를 `shipped` 로 옮기고 발송 시각을 남겨야 한다.
     *
     * 종전에는 택배사·송장번호만 기록하고 `shipping_status` 를 건드리지 않아, 배송이 시작된
     * 주문의 배송 레코드가 `pending` 에 머물렀다. 회원 주문상세의 「배송 현황」 카드는 이
     * 레코드를 읽으므로, 운영자가 송장을 넣고 배송중으로 옮겨도 고객 화면에는
     * 「배송대기」가 계속 보였다.
     *
     * 같은 사실을 넣는 다른 경로(주문 목록 일괄 운송장 입력 · `bulkUpdateShipping`)는
     * 처음부터 `shipped` + `shipped_at` 을 함께 기록하고 있었다 — 두 경로가 갈라져 있었다.
     *
     * @scenario from_status=preparing,to_status=shipping,transition_path=option_bulk,classification=forward_jump,verify_stage=db_status
     *
     * @effects shipping_record_follows_option_status
     *
     * @return void 반환값 없음
     */
    public function test_shipping_transition_marks_shipping_record_shipped(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PREPARING,
        ]);
        $shipping = OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'pending',
            'shipping_type' => 'parcel',
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => OrderStatusEnum::SHIPPING->value,
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'MEASSHIP001',
            ])
            ->assertOk();

        $shipping->refresh();

        $this->assertSame(
            'shipped',
            $shipping->shipping_status->value,
            '배송중 전이 후에도 배송 레코드가 pending 입니다 — 고객 배송현황이 「배송대기」로 남습니다.'
        );
        $this->assertNotNull($shipping->shipped_at, '발송 시각이 기록되지 않았습니다.');
        $this->assertSame('MEASSHIP001', $shipping->tracking_number);
    }

    /**
     * 배송완료 전이는 배송 레코드를 `delivered` 로 옮기고 도착 시각을 남겨야 한다.
     *
     * @scenario from_status=shipping,to_status=delivered,transition_path=option_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects shipping_record_follows_option_status
     *
     * @return void 반환값 없음
     */
    public function test_delivered_transition_marks_shipping_record_delivered(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);
        $shipping = OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'pending',
            'shipping_type' => 'parcel',
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => OrderStatusEnum::DELIVERED->value,
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'MEASSHIP002',
            ])
            ->assertOk();

        $shipping->refresh();

        $this->assertSame(
            'delivered',
            $shipping->shipping_status->value,
            '배송완료 전이 후에도 배송 레코드가 옮겨지지 않았습니다.'
        );
        $this->assertNotNull($shipping->delivered_at, '도착 시각이 기록되지 않았습니다.');
    }

    /**
     * 송장 없이 배송완료로 올려도 배송 레코드가 `delivered` 로 옮겨지고 도착 시각이 남는다 (S3-D5, 2026-10-07 실측).
     *
     * 송장은 배송중으로 보낼 때 한 번 넣고, 배송완료는 상태만 바꾸는 것이 보통의 운영이다. 그런데 상태 전이를
     * 배송 레코드로 옮기는 코드가 택배사·송장이 함께 올 때만 돌아, 배송완료된 주문의 레코드가 `shipped`·
     * `delivered_at=null` 로 남고 고객 「배송 현황」이 배송중으로 보였다(S2 실측: 주문 240·269).
     * 이미 찍힌 발송 시각과 송장은 그대로 둔다.
     *
     * @scenario from_status=shipping,to_status=delivered,transition_path=option_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects shipping_record_follows_option_status
     *
     * @return void 반환값 없음
     */
    public function test_delivered_transition_without_tracking_marks_shipping_record_delivered(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);
        $shippedAt = now()->subDay()->startOfSecond();
        $shipping = OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'shipped',
            'shipping_type' => 'parcel',
            'carrier_id' => $this->carrier->id,
            'tracking_number' => 'MEASSHIP010',
            'shipped_at' => $shippedAt,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => OrderStatusEnum::DELIVERED->value,
            ])
            ->assertOk();

        $shipping->refresh();

        $this->assertSame('delivered', $shipping->shipping_status->value, '송장 없이 배송완료로 올리면 배송 레코드가 shipped 에 남습니다.');
        $this->assertNotNull($shipping->delivered_at, '도착 시각이 기록되지 않았습니다.');
        $this->assertSame('MEASSHIP010', $shipping->tracking_number, '송장이 지워졌습니다.');
        $this->assertTrue($shippedAt->equalTo($shipping->shipped_at), '발송 시각이 덮였습니다.');
    }

    /**
     * 주문 목록 일괄 변경(`PATCH admin/orders/bulk`)으로 배송완료로 올려도 배송 레코드가 따라간다 (S3-D5).
     *
     * 목록 일괄 경로는 주문·상품 상태를 raw update 로 옮기고, 배송 레코드는 송장을 함께 넣을 때만 `shipped` 로
     * 썼다 — 배송완료로 올린 주문의 레코드가 `shipped`·`delivered_at=null` 에 남았다(S2 실측: 주문 240·269).
     *
     * @scenario from_status=shipping,to_status=delivered,transition_path=order_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects shipping_record_follows_option_status
     *
     * @return void 반환값 없음
     */
    public function test_order_list_bulk_delivered_marks_shipping_record_delivered(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::SHIPPING]);
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);
        $shippedAt = now()->subDay()->startOfSecond();
        $shipping = OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'shipped',
            'shipping_type' => 'parcel',
            'carrier_id' => $this->carrier->id,
            'tracking_number' => 'MEASSHIP011',
            'shipped_at' => $shippedAt,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'ids' => [$order->id],
                'order_status' => OrderStatusEnum::DELIVERED->value,
            ])
            ->assertOk();

        $shipping->refresh();

        $this->assertSame('delivered', $shipping->shipping_status->value, '목록 일괄 배송완료 후 배송 레코드가 shipped 에 남습니다.');
        $this->assertNotNull($shipping->delivered_at);
        $this->assertSame('MEASSHIP011', $shipping->tracking_number);
        $this->assertTrue($shippedAt->equalTo($shipping->shipped_at));
    }

    /**
     * 수량을 나눠 보낸 상품(배송 행 2개 — 송장 2개)을 배송완료로 올리면 두 배송 행이 모두 따라간다 (S3-D5 보강, 2026-10-07 실측).
     *
     * 저장소가 상품의 **첫 배송 행**만 고쳐, 나눠 보낸 주문(Q12 — 송장 S3SHIP0001·0002)을 목록 일괄로 배송완료하자
     * 한 행만 delivered 가 되고 다른 행은 shipped·delivered_at=null 로 남았다. 상세 일괄변경도 같은 저장소를 쓴다.
     *
     * @scenario from_status=shipping,to_status=delivered,transition_path=order_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects shipping_record_follows_option_status
     *
     * @return void 반환값 없음
     */
    public function test_order_list_bulk_delivered_marks_every_shipping_row_of_the_option(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::SHIPPING]);
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);
        $rows = collect(['MEASSHIP021', 'MEASSHIP022'])->map(fn ($tracking) => OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'shipped',
            'shipping_type' => 'parcel',
            'carrier_id' => $this->carrier->id,
            'tracking_number' => $tracking,
            'shipped_at' => now()->subDay(),
        ]));

        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'ids' => [$order->id],
                'order_status' => OrderStatusEnum::DELIVERED->value,
            ])
            ->assertOk();

        foreach ($rows as $row) {
            $row->refresh();
            $this->assertSame('delivered', $row->shipping_status->value, "송장 {$row->tracking_number} 행이 배송완료로 옮겨지지 않았습니다.");
            $this->assertNotNull($row->delivered_at);
        }
        $this->assertSame(['MEASSHIP021', 'MEASSHIP022'], $rows->map(fn ($r) => $r->tracking_number)->all(), '송장이 바뀌었습니다.');
    }

    /**
     * 상세 일괄변경으로 송장 없이 배송완료해도 같은 상품의 배송 행이 모두 따라간다 (S3-D5 보강).
     *
     * @scenario from_status=shipping,to_status=delivered,transition_path=option_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects shipping_record_follows_option_status
     *
     * @return void 반환값 없음
     */
    public function test_delivered_transition_without_tracking_marks_every_shipping_row_of_the_option(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'option_status' => OrderStatusEnum::SHIPPING,
        ]);
        $rows = collect(['MEASSHIP031', 'MEASSHIP032'])->map(fn ($tracking) => OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'shipped',
            'shipping_type' => 'parcel',
            'carrier_id' => $this->carrier->id,
            'tracking_number' => $tracking,
            'shipped_at' => now()->subDay(),
        ]));

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 2]],
                'status' => OrderStatusEnum::DELIVERED->value,
            ])
            ->assertOk();

        foreach ($rows as $row) {
            $row->refresh();
            $this->assertSame('delivered', $row->shipping_status->value, "송장 {$row->tracking_number} 행이 배송완료로 옮겨지지 않았습니다.");
        }
    }

    /**
     * 송장 없는 상태 전이 중 배송과 무관한 단계(상품준비중)는 배송 레코드를 건드리지 않는다.
     *
     * @scenario from_status=payment_complete,to_status=preparing,transition_path=option_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects shipping_record_follows_option_status
     *
     * @return void 반환값 없음
     */
    public function test_preparing_transition_without_tracking_keeps_shipping_record(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $shipping = OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'pending',
            'shipping_type' => 'parcel',
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => OrderStatusEnum::PREPARING->value,
            ])
            ->assertOk();

        $shipping->refresh();

        $this->assertSame('pending', $shipping->shipping_status->value);
        $this->assertNull($shipping->shipped_at);
        $this->assertNull($shipping->delivered_at);
    }

    /**
     * 배송준비중 전이는 송장을 기록하되 아직 발송이 아니므로 `pending` 을 유지한다.
     *
     * 전이를 따라 무조건 앞으로 미는 수정이면 이 경계가 깨진다.
     *
     * @scenario from_status=preparing,to_status=shipping_ready,transition_path=option_bulk,classification=forward_adjacent,verify_stage=db_status
     *
     * @effects shipping_record_follows_option_status
     *
     * @return void 반환값 없음
     */
    public function test_shipping_ready_transition_keeps_shipping_record_pending(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'option_status' => OrderStatusEnum::PREPARING,
        ]);
        $shipping = OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'pending',
            'shipping_type' => 'parcel',
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 1]],
                'status' => OrderStatusEnum::SHIPPING_READY->value,
                'carrier_id' => $this->carrier->id,
                'tracking_number' => 'MEASSHIP003',
            ])
            ->assertOk();

        $shipping->refresh();

        $this->assertSame('pending', $shipping->shipping_status->value);
        $this->assertNull($shipping->shipped_at);
        $this->assertSame('MEASSHIP003', $shipping->tracking_number);
    }
}
