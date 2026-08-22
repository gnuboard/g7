<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 Repository 테스트
 */
class OrderRepositoryTest extends ModuleTestCase
{
    protected OrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OrderRepository(new Order);
    }

    public function test_find_returns_order(): void
    {
        $order = OrderFactory::new()->create();

        $found = $this->repository->find($order->id);

        $this->assertNotNull($found);
        $this->assertEquals($order->id, $found->id);
    }

    public function test_find_returns_null_for_non_existent_id(): void
    {
        $found = $this->repository->find(99999);

        $this->assertNull($found);
    }

    public function test_find_with_relations_loads_all_relations(): void
    {
        $order = OrderFactory::new()->create();
        OrderAddressFactory::new()->forOrder($order)->shipping()->create();
        OrderOptionFactory::new()->forOrder($order)->create();
        OrderPaymentFactory::new()->forOrder($order)->create();
        OrderShippingFactory::new()->forOrder($order)->create();

        $found = $this->repository->findWithRelations($order->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('shippingAddress'));
        $this->assertTrue($found->relationLoaded('options'));
        $this->assertTrue($found->relationLoaded('payment'));
        $this->assertTrue($found->relationLoaded('shippings'));
    }

    public function test_find_by_order_number_returns_order(): void
    {
        $order = OrderFactory::new()->create(['order_number' => 'TEST-ORD-001']);

        $found = $this->repository->findByOrderNumber('TEST-ORD-001');

        $this->assertNotNull($found);
        $this->assertEquals('TEST-ORD-001', $found->order_number);
    }

    public function test_find_by_order_number_returns_null_for_non_existent(): void
    {
        $found = $this->repository->findByOrderNumber('NON-EXISTENT');

        $this->assertNull($found);
    }

    public function test_get_list_with_filters_returns_paginated_results(): void
    {
        OrderFactory::new()->count(15)->create();

        $result = $this->repository->getListWithFilters([], 10);

        $this->assertCount(10, $result->items());
        $this->assertEquals(15, $result->total());
    }

    public function test_get_list_filters_by_order_status(): void
    {
        OrderFactory::new()->count(3)->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT->value]);
        OrderFactory::new()->count(2)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);

        $result = $this->repository->getListWithFilters([
            'order_status' => [OrderStatusEnum::PENDING_PAYMENT->value],
        ]);

        $this->assertEquals(3, $result->total());
    }

    public function test_get_list_filters_by_multiple_order_status(): void
    {
        OrderFactory::new()->count(3)->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT->value]);
        OrderFactory::new()->count(2)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        OrderFactory::new()->count(1)->create(['order_status' => OrderStatusEnum::SHIPPING->value]);

        $result = $this->repository->getListWithFilters([
            'order_status' => [
                OrderStatusEnum::PENDING_PAYMENT->value,
                OrderStatusEnum::PAYMENT_COMPLETE->value,
            ],
        ]);

        $this->assertEquals(5, $result->total());
    }

    public function test_get_list_filters_by_date_range(): void
    {
        OrderFactory::new()->create(['ordered_at' => now()->subDays(10)]);
        OrderFactory::new()->create(['ordered_at' => now()->subDays(5)]);
        OrderFactory::new()->create(['ordered_at' => now()]);

        $result = $this->repository->getListWithFilters([
            'date_type' => 'ordered_at',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $this->assertEquals(2, $result->total());
    }

    public function test_get_list_filters_by_search_keyword(): void
    {
        $order = OrderFactory::new()->create(['order_number' => 'SEARCH-TEST-001']);
        OrderFactory::new()->create(['order_number' => 'OTHER-ORDER-002']);

        $result = $this->repository->getListWithFilters([
            'search_keyword' => 'SEARCH-TEST',
            'search_field' => 'order_number',
        ]);

        $this->assertEquals(1, $result->total());
        $this->assertEquals($order->id, $result->items()[0]->id);
    }

    public function test_get_list_filters_by_price_range(): void
    {
        OrderFactory::new()->create(['total_amount' => 10000]);
        OrderFactory::new()->create(['total_amount' => 50000]);
        OrderFactory::new()->create(['total_amount' => 100000]);

        $result = $this->repository->getListWithFilters([
            'min_amount' => 20000,
            'max_amount' => 80000,
        ]);

        $this->assertEquals(1, $result->total());
    }

    public function test_get_list_filters_by_shipping_type(): void
    {
        $order1 = OrderFactory::new()->create();
        $order2 = OrderFactory::new()->create();
        $order3 = OrderFactory::new()->create();

        OrderShippingFactory::new()->forOrder($order1)->create([
            'shipping_type' => 'parcel',
        ]);
        OrderShippingFactory::new()->forOrder($order2)->create([
            'shipping_type' => 'pickup',
        ]);
        OrderShippingFactory::new()->forOrder($order3)->create([
            'shipping_type' => 'parcel',
        ]);

        $result = $this->repository->getListWithFilters([
            'shipping_type' => ['parcel'],
        ]);

        $this->assertEquals(2, $result->total());
    }

    public function test_get_list_filters_by_country_codes(): void
    {
        $order1 = OrderFactory::new()->create();
        $order2 = OrderFactory::new()->create();
        $order3 = OrderFactory::new()->create();

        OrderAddressFactory::new()->forOrder($order1)->shipping()->create([
            'recipient_country_code' => 'KR',
        ]);
        OrderAddressFactory::new()->forOrder($order2)->shipping()->create([
            'recipient_country_code' => 'US',
        ]);
        OrderAddressFactory::new()->forOrder($order3)->shipping()->create([
            'recipient_country_code' => 'KR',
        ]);

        $result = $this->repository->getListWithFilters([
            'country_codes' => ['KR'],
        ]);

        $this->assertEquals(2, $result->total());
    }

    public function test_get_list_filters_by_order_device(): void
    {
        OrderFactory::new()->count(3)->create([
            'order_device' => DeviceTypeEnum::PC->value,
        ]);
        OrderFactory::new()->count(2)->create([
            'order_device' => DeviceTypeEnum::MOBILE->value,
        ]);

        $result = $this->repository->getListWithFilters([
            'order_device' => [DeviceTypeEnum::MOBILE->value],
        ]);

        $this->assertEquals(2, $result->total());
    }

    public function test_get_list_sorts_by_field(): void
    {
        $order1 = OrderFactory::new()->create(['total_amount' => 30000]);
        $order2 = OrderFactory::new()->create(['total_amount' => 10000]);
        $order3 = OrderFactory::new()->create(['total_amount' => 20000]);

        $result = $this->repository->getListWithFilters([
            'sort_by' => 'total_amount',
            'sort_order' => 'asc',
        ]);

        $items = $result->items();
        $this->assertEquals($order2->id, $items[0]->id);
        $this->assertEquals($order3->id, $items[1]->id);
        $this->assertEquals($order1->id, $items[2]->id);
    }

    public function test_create_creates_new_order(): void
    {
        $data = [
            'order_number' => 'NEW-ORD-001',
            'order_status' => OrderStatusEnum::PENDING_PAYMENT->value,
            'subtotal_amount' => 50000,
            'total_amount' => 50000,
            'item_count' => 1,
            'ordered_at' => now(),
        ];

        $order = $this->repository->create($data);

        $this->assertNotNull($order->id);
        $this->assertEquals('NEW-ORD-001', $order->order_number);
        $this->assertDatabaseHas('ecommerce_orders', [
            'order_number' => 'NEW-ORD-001',
        ]);
    }

    public function test_update_updates_order(): void
    {
        $order = OrderFactory::new()->create(['admin_memo' => '기존 메모']);

        $updated = $this->repository->update($order, [
            'admin_memo' => '수정된 메모',
        ]);

        $this->assertEquals('수정된 메모', $updated->admin_memo);
        $this->assertDatabaseHas('ecommerce_orders', [
            'id' => $order->id,
            'admin_memo' => '수정된 메모',
        ]);
    }

    public function test_delete_soft_deletes_order(): void
    {
        $order = OrderFactory::new()->create();

        $result = $this->repository->delete($order);

        $this->assertTrue($result);
        $this->assertSoftDeleted('ecommerce_orders', ['id' => $order->id]);
    }

    public function test_bulk_update_status_updates_multiple_orders(): void
    {
        $orders = OrderFactory::new()->count(3)->create([
            'order_status' => OrderStatusEnum::PENDING_PAYMENT->value,
        ]);

        $count = $this->repository->bulkUpdateStatus(
            $orders->pluck('id')->toArray(),
            OrderStatusEnum::PAYMENT_COMPLETE->value
        );

        $this->assertEquals(3, $count);

        foreach ($orders as $order) {
            $this->assertDatabaseHas('ecommerce_orders', [
                'id' => $order->id,
                'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
            ]);
        }
    }

    public function test_bulk_update_option_status_syncs_active_options_only(): void
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER->value,
        ]);
        $active = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PENDING_ORDER->value,
        ]);
        $cancelled = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CANCELLED->value,
        ]);

        $count = $this->repository->bulkUpdateOptionStatus(
            [$order->id],
            OrderStatusEnum::PAYMENT_COMPLETE->value
        );

        // 활성 옵션 1건만 전이, 취소 옵션은 보존(sync 제외)
        $this->assertEquals(1, $count);
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $active->fresh()->option_status);
        $this->assertEquals(OrderStatusEnum::CANCELLED, $cancelled->fresh()->option_status);
    }

    public function test_get_statistics_returns_correct_counts(): void
    {
        OrderFactory::new()->count(3)->create([
            'order_status' => OrderStatusEnum::PENDING_PAYMENT->value,
            'ordered_at' => now(),
        ]);
        OrderFactory::new()->count(2)->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
            'ordered_at' => now(),
        ]);

        $stats = $this->repository->getStatistics();

        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(5, $stats['today_count']);
        $this->assertArrayHasKey('status_counts', $stats);
        $this->assertArrayHasKey('today_revenue', $stats);
        $this->assertArrayHasKey('monthly_revenue', $stats);
    }

    public function test_get_for_export_returns_collection(): void
    {
        OrderFactory::new()->count(5)->create();

        $result = $this->repository->getForExport([]);

        $this->assertCount(5, $result);
    }

    public function test_get_for_export_with_specific_ids(): void
    {
        $orders = OrderFactory::new()->count(5)->create();
        $selectedIds = $orders->take(2)->pluck('id')->toArray();

        $result = $this->repository->getForExport([], $selectedIds);

        $this->assertCount(2, $result);
    }

    public function test_exists_by_order_number_returns_true_for_existing(): void
    {
        OrderFactory::new()->create(['order_number' => 'TEST-ORD-EXISTS']);

        $result = $this->repository->existsByOrderNumber('TEST-ORD-EXISTS');

        $this->assertTrue($result);
    }

    public function test_exists_by_order_number_returns_false_for_non_existing(): void
    {
        $result = $this->repository->existsByOrderNumber('NON-EXISTENT-ORDER');

        $this->assertFalse($result);
    }

    public function test_has_order_by_user_returns_true_for_user_with_orders(): void
    {
        $user = User::factory()->create();
        OrderFactory::new()->create(['user_id' => $user->id]);

        $result = $this->repository->hasOrderByUser($user->id);

        $this->assertTrue($result);
    }

    public function test_has_order_by_user_returns_false_for_user_without_orders(): void
    {
        $user = User::factory()->create();

        $result = $this->repository->hasOrderByUser($user->id);

        $this->assertFalse($result);
    }

    public function test_get_list_filters_by_orderer_uuid(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        // 해당 회원의 주문 2건
        OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);
        OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        // 다른 회원의 주문 1건
        OrderFactory::new()->create([
            'user_id' => $otherUser->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        $result = $this->repository->getListWithFilters([
            'orderer_uuid' => $user->uuid,
        ], 10);

        $this->assertEquals(2, $result->total());
        $result->each(function ($order) use ($user) {
            $this->assertEquals($user->id, $order->user_id);
        });
    }

    public function test_get_list_with_nonexistent_orderer_uuid_returns_empty(): void
    {
        OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        $result = $this->repository->getListWithFilters([
            'orderer_uuid' => '00000000-0000-0000-0000-000000000000',
        ], 10);

        $this->assertEquals(0, $result->total());
    }

    // ================================================================
    // MP02-U18③④: 주문내역 카운터·필터 정합화
    // ================================================================

    /**
     * getUserStatistics 의 상품준비중(preparing) 카운터는 PREPARING + SHIPPING_READY 를 합산한다.
     */
    public function test_user_statistics_preparing_sums_preparing_and_shipping_ready(): void
    {
        $user = User::factory()->create();
        OrderFactory::new()->count(2)->create(['user_id' => $user->id, 'order_status' => OrderStatusEnum::PREPARING->value]);
        OrderFactory::new()->count(3)->create(['user_id' => $user->id, 'order_status' => OrderStatusEnum::SHIPPING_READY->value]);

        $stats = $this->repository->getUserStatistics($user->id);

        $this->assertEquals(5, $stats['preparing'], '상품준비중 카운터는 PREPARING + SHIPPING_READY 합산이어야 합니다');
    }

    /**
     * 부분취소는 별도 order_status 가 아니므로 통계에 partial_cancelled 키가 없고,
     * 부분취소된 주문은 잔여 옵션 기준 진행 상태(예: payment_complete) 카운터에 집계된다 (partial_cancelled 제거).
     */
    public function test_user_statistics_has_no_partial_cancelled_key(): void
    {
        $user = User::factory()->create();
        // 부분취소 후 잔여 옵션 기준으로 payment_complete 가 유지된 주문 2건
        OrderFactory::new()->count(2)->create(['user_id' => $user->id, 'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);

        $stats = $this->repository->getUserStatistics($user->id);

        $this->assertArrayNotHasKey('partial_cancelled', $stats);
        $this->assertEquals(2, $stats['payment_complete'], '부분취소 주문은 진행 상태(payment_complete) 카운터에 집계됨');
    }

    /**
     * 주문대기(pending_order)는 통계에서 숨김 유지된다 (정책 확정, listHiddenValues).
     */
    public function test_user_statistics_hides_pending_order(): void
    {
        $user = User::factory()->create();
        OrderFactory::new()->count(2)->create(['user_id' => $user->id, 'order_status' => OrderStatusEnum::PENDING_ORDER->value]);
        OrderFactory::new()->create(['user_id' => $user->id, 'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);

        $stats = $this->repository->getUserStatistics($user->id);

        $this->assertArrayNotHasKey('pending_order', $stats, '주문대기는 카운터에 노출되면 안 됩니다');
        $this->assertEquals(1, $stats['payment_complete']);
    }

    /**
     * 상품준비중(preparing) 필터로 조회하면 PREPARING + SHIPPING_READY 가 함께 조회된다.
     *
     * 카운터(합산)와 필터 결과 수가 일치하도록 그룹 SSoT 로 확장된다.
     */
    public function test_preparing_filter_expands_to_preparing_and_shipping_ready(): void
    {
        OrderFactory::new()->count(2)->create(['order_status' => OrderStatusEnum::PREPARING->value]);
        OrderFactory::new()->count(3)->create(['order_status' => OrderStatusEnum::SHIPPING_READY->value]);
        OrderFactory::new()->create(['order_status' => OrderStatusEnum::SHIPPING->value]);

        // 단일 'preparing' 값으로 필터 → 그룹 확장으로 5건 조회(카운터와 일치)
        $result = $this->repository->getListWithFilters([
            'order_status' => 'preparing',
        ]);

        $this->assertEquals(5, $result->total(), '상품준비중 필터는 PREPARING + SHIPPING_READY 를 함께 조회해야 합니다');
    }

    /**
     * 일반 상태 값 필터는 그룹 확장의 영향을 받지 않는다(멱등).
     */
    public function test_non_group_status_filter_is_unaffected(): void
    {
        OrderFactory::new()->count(2)->create(['order_status' => OrderStatusEnum::SHIPPING->value]);
        OrderFactory::new()->count(3)->create(['order_status' => OrderStatusEnum::PREPARING->value]);

        $result = $this->repository->getListWithFilters([
            'order_status' => 'shipping',
        ]);

        $this->assertEquals(2, $result->total());
    }

    /**
     * 입금 기한이 만료된 VBANK/DBANK 결제대기 주문만 조회되고
     * BANK(계좌이체)는 제외되는지 검증한다 (주문 442 누락 회귀 차단).
     */
    public function test_get_expired_pending_payment_orders_matches_vbank_and_dbank_only(): void
    {
        $user = User::factory()->create();

        // 1) 만료 VBANK → 대상
        $vbankOrder = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);
        OrderPaymentFactory::new()->forOrder($vbankOrder)->create([
            'payment_method' => PaymentMethodEnum::VBANK,
            'vbank_due_at' => Carbon::now()->subDay(),
            'deposit_due_at' => null,
        ]);

        // 2) 만료 DBANK(무통장입금) → 대상
        $dbankOrder = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);
        OrderPaymentFactory::new()->forOrder($dbankOrder)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            'vbank_due_at' => null,
            'deposit_due_at' => Carbon::now()->subDay(),
        ]);

        // 3) 만료 BANK(계좌이체) → 제외
        $bankOrder = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);
        OrderPaymentFactory::new()->forOrder($bankOrder)->create([
            'payment_method' => PaymentMethodEnum::BANK,
            'vbank_due_at' => null,
            'deposit_due_at' => Carbon::now()->subDay(),
        ]);

        $expired = $this->repository->getExpiredPendingPaymentOrders();
        $expiredIds = $expired->pluck('id')->all();

        $this->assertContains($vbankOrder->id, $expiredIds);
        $this->assertContains($dbankOrder->id, $expiredIds);
        $this->assertNotContains($bankOrder->id, $expiredIds);
    }
}
