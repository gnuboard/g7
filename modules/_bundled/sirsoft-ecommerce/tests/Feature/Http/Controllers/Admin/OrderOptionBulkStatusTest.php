<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 옵션 일괄 상태 변경 API 테스트
 *
 * 수량 분할을 포함한 일괄 상태 변경 기능을 검증합니다.
 */
class OrderOptionBulkStatusTest extends ModuleTestCase
{
    private \App\Models\User $adminUser;

    private ShippingCarrier $carrier;

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
     */
    private function bulkStatusUrl(string $orderNumber): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/orders/{$orderNumber}/options/bulk-status";
    }

    // ========== 전체 수량 상태 변경 ==========

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

        // When: 남은 3개를 cancelled로 전체 변경
        $option->refresh();
        $this->assertEquals(3, $option->quantity);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->bulkStatusUrl($order->order_number), [
                'items' => [['option_id' => $option->id, 'quantity' => 3]],
                'status' => 'cancelled',
            ]);

        // Then
        $response->assertOk();
        $option->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $option->option_status);
        $this->assertEquals(3, $option->quantity);

        // 분할 레코드는 shipping 유지
        $splitOption = OrderOption::where('parent_option_id', $option->id)->first();
        $this->assertEquals(OrderStatusEnum::SHIPPING, $splitOption->option_status);
        $this->assertEquals(2, $splitOption->quantity);
    }

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

    // ========== 부모 주문 상태 동기화 ==========

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
}
