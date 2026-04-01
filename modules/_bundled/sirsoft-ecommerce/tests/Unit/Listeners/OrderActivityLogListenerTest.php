<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\ActivityLog\ChangeDetector;
use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\OrderActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderActivityLogListener 테스트
 *
 * 주문 관련 활동 로그 리스너의 모든 훅 메서드를 검증합니다.
 * - per-item 로깅: bulk 핸들러는 건별로 loggable이 지정되어야 합니다.
 * - 신규 핸들러: 구매확인, 부분취소 per-option
 * - 배송지 변경: loggable = OrderAddress
 */
class OrderActivityLogListenerTest extends ModuleTestCase
{
    private OrderActivityLogListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        // 관리자 경로 요청 설정 (resolveLogType()이 Admin 반환하도록)
        $this->app->instance('request', Request::create('/api/admin/sirsoft-ecommerce/orders'));
        $this->listener = new OrderActivityLogListener();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks
    // ═══════════════════════════════════════════

    /**
     * 훅 구독 수가 20개인지 확인 (기존 19 + after_confirm 1)
     */
    public function test_getSubscribedHooks_returns_all_20_hooks(): void
    {
        $hooks = OrderActivityLogListener::getSubscribedHooks();

        $this->assertCount(20, $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_bulk_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_bulk_status_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_bulk_shipping_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_update_shipping_address', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_send_email', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order_option.after_status_change', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order_option.after_bulk_status_change', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_cancel', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_partial_cancel', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.coupon.restore', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.mileage.restore', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order-option.after_confirm', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_payment_complete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.payment_failed', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.coupon.use', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.mileage.use', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.mileage.earn', $hooks);
    }

    // ═══════════════════════════════════════════
    // 단건 OrderService 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleOrderAfterUpdate_with_snapshot(): void
    {
        $order = Order::factory()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $snapshot = $order->toArray();

        Order::where('id', $order->id)->update(['order_status' => OrderStatusEnum::CONFIRMED->value]);
        $order->refresh();

        $this->listener->handleOrderAfterUpdate($order, $snapshot);

        $log = ActivityLog::where('loggable_type', Order::class)
            ->where('loggable_id', $order->id)
            ->where('action', 'order.update')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('sirsoft-ecommerce::activity_log.description.order_update', $log->description_key);
        $this->assertNotNull($log->changes);
    }

    public function test_handleOrderAfterUpdate_without_snapshot(): void
    {
        $order = Order::factory()->create();

        $this->listener->handleOrderAfterUpdate($order);

        $log = ActivityLog::where('loggable_type', Order::class)
            ->where('loggable_id', $order->id)
            ->where('action', 'order.update')
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->changes);
    }

    public function test_handleOrderAfterDelete_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-DEL-001']);

        $this->listener->handleOrderAfterDelete($order);

        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => Order::class,
            'loggable_id' => $order->id,
            'action' => 'order.delete',
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_delete',
        ]);
    }

    // ═══════════════════════════════════════════
    // Bulk OrderService 핸들러 — per-item 테스트
    // ═══════════════════════════════════════════

    /**
     * 일괄 수정 시 N건의 per-item 로그가 생성되는지 확인
     */
    public function test_handleOrderAfterBulkUpdate_creates_per_item_logs(): void
    {
        $orders = Order::factory()->count(3)->create();
        $ids = $orders->pluck('id')->toArray();

        $this->listener->handleOrderAfterBulkUpdate($ids, 3);

        $logs = ActivityLog::where('action', 'order.bulk_update')->get();

        $this->assertCount(3, $logs);

        foreach ($logs as $log) {
            $this->assertEquals(Order::class, $log->loggable_type);
            $this->assertContains($log->loggable_id, $ids);
            $this->assertEquals('sirsoft-ecommerce::activity_log.description.order_bulk_update', $log->description_key);
        }
    }

    /**
     * 일괄 수정 시 loggable_id가 각 주문을 정확히 가리키는지 확인
     */
    public function test_handleOrderAfterBulkUpdate_loggable_points_to_correct_order(): void
    {
        $order1 = Order::factory()->create(['order_number' => 'BULK-001']);
        $order2 = Order::factory()->create(['order_number' => 'BULK-002']);

        $this->listener->handleOrderAfterBulkUpdate([$order1->id, $order2->id], 2);

        $log1 = ActivityLog::where('action', 'order.bulk_update')
            ->where('loggable_id', $order1->id)
            ->first();
        $log2 = ActivityLog::where('action', 'order.bulk_update')
            ->where('loggable_id', $order2->id)
            ->first();

        $this->assertNotNull($log1);
        $this->assertNotNull($log2);
        $this->assertEquals($order1->id, $log1->properties['order_id']);
        $this->assertEquals($order2->id, $log2->properties['order_id']);
    }

    /**
     * 일괄 수정 시 스냅샷 전달하면 changes가 감지되는지 확인
     */
    public function test_handleOrderAfterBulkUpdate_detects_changes_with_snapshots(): void
    {
        $order = Order::factory()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $snapshot = $order->toArray();

        Order::where('id', $order->id)->update(['order_status' => OrderStatusEnum::CONFIRMED->value]);
        $order->refresh();

        $this->listener->handleOrderAfterBulkUpdate([$order->id], 1, [$order->id => $snapshot]);

        $log = ActivityLog::where('action', 'order.bulk_update')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->changes);

        $statusChange = collect($log->changes)->firstWhere('field', 'order_status');
        $this->assertNotNull($statusChange);
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT->value, $statusChange['old']);
        $this->assertEquals(OrderStatusEnum::CONFIRMED->value, $statusChange['new']);
    }

    /**
     * 일괄 수정 시 스냅샷 없으면 changes가 null인지 확인
     */
    public function test_handleOrderAfterBulkUpdate_null_changes_without_snapshots(): void
    {
        $order = Order::factory()->create();

        $this->listener->handleOrderAfterBulkUpdate([$order->id], 1);

        $log = ActivityLog::where('action', 'order.bulk_update')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->changes);
    }

    /**
     * 일괄 상태 변경 시 per-item 로그 생성 확인
     */
    public function test_handleOrderAfterBulkStatusUpdate_creates_per_item_logs(): void
    {
        $orders = Order::factory()->count(2)->create();
        $ids = $orders->pluck('id')->toArray();

        $this->listener->handleOrderAfterBulkStatusUpdate($ids, 2);

        $logs = ActivityLog::where('action', 'order.bulk_status_update')->get();
        $this->assertCount(2, $logs);

        foreach ($logs as $log) {
            $this->assertEquals(Order::class, $log->loggable_type);
            $this->assertContains($log->loggable_id, $ids);
        }
    }

    /**
     * 일괄 배송 변경 시 per-item 로그 생성 확인
     */
    public function test_handleOrderAfterBulkShippingUpdate_creates_per_item_logs(): void
    {
        $orders = Order::factory()->count(3)->create();
        $ids = $orders->pluck('id')->toArray();

        $this->listener->handleOrderAfterBulkShippingUpdate($ids, 3);

        $logs = ActivityLog::where('action', 'order.bulk_shipping_update')->get();
        $this->assertCount(3, $logs);

        foreach ($logs as $log) {
            $this->assertEquals(Order::class, $log->loggable_type);
            $this->assertContains($log->loggable_id, $ids);
            $this->assertEquals('sirsoft-ecommerce::activity_log.description.order_bulk_shipping_update', $log->description_key);
        }
    }

    /**
     * 일괄 배송 변경 시 스냅샷 전달하면 changes 감지 확인
     */
    public function test_handleOrderAfterBulkShippingUpdate_detects_changes_with_snapshots(): void
    {
        $order = Order::factory()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $snapshot = $order->toArray();

        Order::where('id', $order->id)->update(['order_status' => OrderStatusEnum::SHIPPING->value]);
        $order->refresh();

        $this->listener->handleOrderAfterBulkShippingUpdate([$order->id], 1, [$order->id => $snapshot]);

        $log = ActivityLog::where('action', 'order.bulk_shipping_update')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->changes);
    }

    // ═══════════════════════════════════════════
    // Bulk description_params :count 검증
    // ═══════════════════════════════════════════

    /**
     * 일괄 수정 시 description_params에 count가 포함되는지 확인
     */
    public function test_handleOrderAfterBulkUpdate_description_params_includes_count(): void
    {
        $orders = Order::factory()->count(3)->create();
        $ids = $orders->pluck('id')->toArray();

        $this->listener->handleOrderAfterBulkUpdate($ids, 3);

        $log = ActivityLog::where('action', 'order.bulk_update')->first();
        $this->assertNotNull($log);
        $this->assertEquals(3, $log->description_params['count']);
    }

    /**
     * 일괄 상태 변경 시 description_params에 count가 포함되는지 확인
     */
    public function test_handleOrderAfterBulkStatusUpdate_description_params_includes_count(): void
    {
        $orders = Order::factory()->count(2)->create();
        $ids = $orders->pluck('id')->toArray();

        $this->listener->handleOrderAfterBulkStatusUpdate($ids, 2);

        $log = ActivityLog::where('action', 'order.bulk_status_update')->first();
        $this->assertNotNull($log);
        $this->assertEquals(2, $log->description_params['count']);
    }

    /**
     * 일괄 배송 변경 시 description_params에 count가 포함되는지 확인
     */
    public function test_handleOrderAfterBulkShippingUpdate_description_params_includes_count(): void
    {
        $orders = Order::factory()->count(3)->create();
        $ids = $orders->pluck('id')->toArray();

        $this->listener->handleOrderAfterBulkShippingUpdate($ids, 3);

        $log = ActivityLog::where('action', 'order.bulk_shipping_update')->first();
        $this->assertNotNull($log);
        $this->assertEquals(3, $log->description_params['count']);
    }

    /**
     * 일괄 옵션 상태 변경 시 description_params에 count가 포함되는지 확인
     */
    public function test_handleOrderOptionAfterBulkStatusChange_description_params_includes_count(): void
    {
        $order = Order::factory()->create();
        $option1 = OrderOption::factory()->create(['order_id' => $order->id]);
        $option2 = OrderOption::factory()->create(['order_id' => $order->id]);

        $results = [
            ['order_option_id' => $option1->id, 'success' => true],
            ['order_option_id' => $option2->id, 'success' => true],
        ];

        $this->listener->handleOrderOptionAfterBulkStatusChange($results, OrderStatusEnum::SHIPPING);

        $log = ActivityLog::where('action', 'order_option.bulk_status_change')->first();
        $this->assertNotNull($log);
        $this->assertEquals(2, $log->description_params['count']);
    }

    // ═══════════════════════════════════════════
    // ChangeDetector enum 타입 + labelKey 검증
    // ═══════════════════════════════════════════

    /**
     * Order activityLogFields의 order_status가 enum 타입이고 ChangeDetector가 label_key를 생성하는지 확인
     */
    public function test_order_status_change_generates_label_keys(): void
    {
        $order = Order::factory()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $snapshot = $order->toArray();

        Order::where('id', $order->id)->update(['order_status' => OrderStatusEnum::SHIPPING->value]);
        $order->refresh();

        $changes = ChangeDetector::detect($order, $snapshot);

        $this->assertNotNull($changes);
        $statusChange = collect($changes)->firstWhere('field', 'order_status');
        $this->assertNotNull($statusChange);
        $this->assertEquals('enum', $statusChange['type']);
        $this->assertEquals('sirsoft-ecommerce::enums.order_status.pending_payment', $statusChange['old_label_key']);
        $this->assertEquals('sirsoft-ecommerce::enums.order_status.shipping', $statusChange['new_label_key']);
    }

    /**
     * OrderOption activityLogFields의 option_status가 enum 타입이고 ChangeDetector가 label_key를 생성하는지 확인
     */
    public function test_order_option_status_change_generates_label_keys(): void
    {
        $order = Order::factory()->create();
        $option = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        $snapshot = $option->toArray();

        OrderOption::where('id', $option->id)->update(['option_status' => OrderStatusEnum::CONFIRMED->value]);
        $option->refresh();

        $changes = ChangeDetector::detect($option, $snapshot);

        $this->assertNotNull($changes);
        $statusChange = collect($changes)->firstWhere('field', 'option_status');
        $this->assertNotNull($statusChange);
        $this->assertEquals('enum', $statusChange['type']);
        $this->assertEquals('sirsoft-ecommerce::enums.order_status.pending_order', $statusChange['old_label_key']);
        $this->assertEquals('sirsoft-ecommerce::enums.order_status.confirmed', $statusChange['new_label_key']);
    }

    /**
     * OrderStatusEnum::labelKey()가 올바른 번역 키를 반환하는지 확인
     */
    public function test_order_status_enum_label_key(): void
    {
        $this->assertEquals(
            'sirsoft-ecommerce::enums.order_status.pending_payment',
            OrderStatusEnum::PENDING_PAYMENT->labelKey()
        );
        $this->assertEquals(
            'sirsoft-ecommerce::enums.order_status.shipping',
            OrderStatusEnum::SHIPPING->labelKey()
        );
        $this->assertEquals(
            'sirsoft-ecommerce::enums.order_status.confirmed',
            OrderStatusEnum::CONFIRMED->labelKey()
        );
    }

    // ═══════════════════════════════════════════
    // 배송지 변경 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 배송지 변경 시 loggable이 OrderAddress인지 확인
     */
    public function test_handleOrderAfterUpdateShippingAddress_loggable_is_order_address(): void
    {
        $order = Order::factory()->create();
        $address = OrderAddress::factory()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'recipient_name' => '홍길동',
        ]);

        $snapshot = $address->toArray();
        $address->update(['recipient_name' => '김철수']);
        $address->refresh();

        $this->listener->handleOrderAfterUpdateShippingAddress($order, $address, $snapshot);

        $log = ActivityLog::where('action', 'order.update_shipping_address')->first();

        $this->assertNotNull($log);
        $this->assertEquals(OrderAddress::class, $log->loggable_type);
        $this->assertEquals($address->id, $log->loggable_id);
        $this->assertEquals($order->id, $log->properties['order_id']);
    }

    /**
     * 배송지 변경 시 ChangeDetector가 변경사항을 감지하는지 확인
     */
    public function test_handleOrderAfterUpdateShippingAddress_detects_changes(): void
    {
        $order = Order::factory()->create();
        $address = OrderAddress::factory()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
        ]);

        $snapshot = $address->toArray();
        $address->update(['recipient_name' => '김철수']);
        $address->refresh();

        $this->listener->handleOrderAfterUpdateShippingAddress($order, $address, $snapshot);

        $log = ActivityLog::where('action', 'order.update_shipping_address')->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->changes);

        $nameChange = collect($log->changes)->firstWhere('field', 'recipient_name');
        $this->assertNotNull($nameChange);
        $this->assertEquals('홍길동', $nameChange['old']);
        $this->assertEquals('김철수', $nameChange['new']);
    }

    /**
     * 배송지 변경 — address 없이 호출 시 fallback으로 Order에 기록
     */
    public function test_handleOrderAfterUpdateShippingAddress_fallback_to_order(): void
    {
        $order = Order::factory()->create();

        $this->listener->handleOrderAfterUpdateShippingAddress($order);

        $log = ActivityLog::where('action', 'order.update_shipping_address')->first();

        $this->assertNotNull($log);
        $this->assertEquals(Order::class, $log->loggable_type);
        $this->assertEquals($order->id, $log->loggable_id);
    }

    // ═══════════════════════════════════════════
    // OrderOptionService 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 단건 옵션 상태 변경 로그 확인
     */
    public function test_handleOrderOptionAfterStatusChange_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-OPT-001']);
        $option = OrderOption::factory()->create(['order_id' => $order->id]);

        $this->listener->handleOrderOptionAfterStatusChange($option, OrderStatusEnum::CONFIRMED, null);

        $log = ActivityLog::where('action', 'order_option.status_change')
            ->where('loggable_id', $option->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(OrderOption::class, $log->loggable_type);
        $this->assertEquals('confirmed', $log->properties['new_status']);
    }

    /**
     * 일괄 옵션 상태 변경 시 per-item 로그 생성 확인
     */
    public function test_handleOrderOptionAfterBulkStatusChange_creates_per_item_logs(): void
    {
        $order = Order::factory()->create();
        $option1 = OrderOption::factory()->create(['order_id' => $order->id]);
        $option2 = OrderOption::factory()->create(['order_id' => $order->id]);

        $results = [
            ['order_option_id' => $option1->id, 'success' => true],
            ['order_option_id' => $option2->id, 'success' => true],
        ];

        $this->listener->handleOrderOptionAfterBulkStatusChange($results, OrderStatusEnum::SHIPPING);

        $logs = ActivityLog::where('action', 'order_option.bulk_status_change')->get();
        $this->assertCount(2, $logs);

        foreach ($logs as $log) {
            $this->assertEquals(OrderOption::class, $log->loggable_type);
            $this->assertContains($log->loggable_id, [$option1->id, $option2->id]);
            $this->assertEquals('shipping', $log->properties['new_status']);
        }
    }

    /**
     * 일괄 옵션 상태 변경 시 스냅샷으로 changes 감지 확인
     */
    public function test_handleOrderOptionAfterBulkStatusChange_detects_changes_with_snapshots(): void
    {
        $order = Order::factory()->create();
        $option = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        $snapshot = $option->toArray();

        OrderOption::where('id', $option->id)->update(['option_status' => OrderStatusEnum::SHIPPING->value]);
        $option->refresh();

        $results = [['order_option_id' => $option->id, 'success' => true]];

        $this->listener->handleOrderOptionAfterBulkStatusChange(
            $results,
            OrderStatusEnum::SHIPPING,
            [$option->id => $snapshot]
        );

        $log = ActivityLog::where('action', 'order_option.bulk_status_change')
            ->where('loggable_id', $option->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->changes);
    }

    // ═══════════════════════════════════════════
    // 구매확인 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 구매확인 시 OrderOption에 로그가 기록되는지 확인
     */
    public function test_handleOrderOptionAfterConfirm_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-CONFIRM-001']);
        $option = OrderOption::factory()->create(['order_id' => $order->id]);

        $this->listener->handleOrderOptionAfterConfirm($order, $option);

        $log = ActivityLog::where('action', 'order_option.confirm')
            ->where('loggable_id', $option->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(OrderOption::class, $log->loggable_type);
        $this->assertEquals('sirsoft-ecommerce::activity_log.description.order_option_confirm', $log->description_key);
        $this->assertEquals($option->id, $log->description_params['option_id']);
        $this->assertEquals($order->id, $log->properties['order_id']);
        $this->assertEquals($option->id, $log->properties['option_id']);
        $this->assertEquals('ORD-CONFIRM-001', $log->properties['order_number']);
    }

    // ═══════════════════════════════════════════
    // OrderCancellationService 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleOrderAfterCancel_logs_activity_with_snapshot(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-CANCEL-001']);

        $cancelSnapshot = [
            'cancel_type' => 'full',
            'cancel_items' => [['order_option_id' => 100, 'cancel_quantity' => 1]],
        ];

        $this->listener->handleOrderAfterCancel($order, $cancelSnapshot);

        $log = ActivityLog::where('action', 'order.cancel')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('sirsoft-ecommerce::activity_log.description.order_cancel', $log->description_key);
        $this->assertEquals('full', $log->properties['cancel_type']);
    }

    /**
     * 부분 취소 시 Order 로그 + per-OrderOption 로그가 생성되는지 확인
     */
    public function test_handleOrderAfterPartialCancel_creates_order_and_per_option_logs(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-PC-001']);
        $option1 = OrderOption::factory()->create(['order_id' => $order->id]);
        $option2 = OrderOption::factory()->create(['order_id' => $order->id]);

        $cancelSnapshot = [
            'cancel_type' => 'partial',
            'cancel_items' => [
                ['order_option_id' => $option1->id, 'cancel_quantity' => 2],
                ['order_option_id' => $option2->id, 'cancel_quantity' => 1],
            ],
        ];

        $this->listener->handleOrderAfterPartialCancel($order, $cancelSnapshot);

        // Order 레벨 로그
        $orderLog = ActivityLog::where('action', 'order.partial_cancel')
            ->where('loggable_type', Order::class)
            ->where('loggable_id', $order->id)
            ->first();
        $this->assertNotNull($orderLog);
        $this->assertEquals('sirsoft-ecommerce::activity_log.description.order_partial_cancel', $orderLog->description_key);

        // per-OrderOption 로그
        $optionLogs = ActivityLog::where('action', 'order_option.partial_cancel')->get();
        $this->assertCount(2, $optionLogs);

        $opt1Log = $optionLogs->firstWhere('loggable_id', $option1->id);
        $this->assertNotNull($opt1Log);
        $this->assertEquals(OrderOption::class, $opt1Log->loggable_type);
        $this->assertEquals($order->id, $opt1Log->properties['order_id']);
        $this->assertEquals(2, $opt1Log->properties['cancel_quantity']);

        $opt2Log = $optionLogs->firstWhere('loggable_id', $option2->id);
        $this->assertNotNull($opt2Log);
        $this->assertEquals(1, $opt2Log->properties['cancel_quantity']);
    }

    /**
     * 부분 취소 시 cancel_items 없으면 per-option 로그 미생성
     */
    public function test_handleOrderAfterPartialCancel_without_cancel_items(): void
    {
        $order = Order::factory()->create();

        $this->listener->handleOrderAfterPartialCancel($order);

        // Order 로그만 존재
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.partial_cancel',
            'loggable_type' => Order::class,
            'loggable_id' => $order->id,
        ]);

        // per-option 로그 미생성
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'order_option.partial_cancel',
        ]);
    }

    public function test_handleCouponRestore_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-CR-001']);
        $restoredIds = [101, 102];

        $this->listener->handleCouponRestore($order, $restoredIds);

        $log = ActivityLog::where('action', 'coupon.restore')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($restoredIds, $log->properties['restored_coupon_issue_ids']);
    }

    public function test_handleMileageRestore_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-MR-001']);

        $this->listener->handleMileageRestore(5000.0, $order);

        $log = ActivityLog::where('action', 'mileage.restore')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(5000.0, $log->description_params['amount']);
    }

    // ═══════════════════════════════════════════
    // OrderProcessingService 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleOrderAfterCreate_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-NEW-001', 'total_amount' => 75000]);

        $this->listener->handleOrderAfterCreate($order);

        $log = ActivityLog::where('action', 'order.create')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('sirsoft-ecommerce::activity_log.description.order_create', $log->description_key);
        $this->assertEquals('ORD-NEW-001', $log->properties['order_number']);
    }

    public function test_handleOrderAfterPaymentComplete_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-PAY-001']);

        $this->listener->handleOrderAfterPaymentComplete($order);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.payment_complete',
            'loggable_type' => Order::class,
            'loggable_id' => $order->id,
        ]);
    }

    public function test_handleOrderAfterPaymentFailed_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-FAIL-001']);

        $this->listener->handleOrderAfterPaymentFailed($order, 'PAY_001', 'Insufficient funds');

        $log = ActivityLog::where('action', 'order.payment_failed')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('PAY_001', $log->properties['error_code']);
        $this->assertEquals('Insufficient funds', $log->properties['error_message']);
    }

    public function test_handleCouponUse_logs_activity(): void
    {
        $order = Order::factory()->create();
        $appliedCouponIds = [501, 502];

        $this->listener->handleCouponUse($appliedCouponIds, $order);

        $log = ActivityLog::where('action', 'coupon.use')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($appliedCouponIds, $log->properties['applied_coupon_ids']);
    }

    public function test_handleMileageUse_logs_activity(): void
    {
        $order = Order::factory()->create();

        $this->listener->handleMileageUse(3000.0, $order);

        $log = ActivityLog::where('action', 'mileage.use')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(3000.0, $log->description_params['amount']);
    }

    public function test_handleMileageEarn_logs_activity(): void
    {
        $order = Order::factory()->create();

        $this->listener->handleMileageEarn(1500.0, $order);

        $log = ActivityLog::where('action', 'mileage.earn')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(1500.0, $log->description_params['amount']);
    }

    // ═══════════════════════════════════════════
    // 이메일/기타 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleOrderAfterSendEmail_logs_activity(): void
    {
        $data = ['order_id' => 10, 'order_number' => 'ORD-EMAIL', 'template' => 'order_confirmation'];

        $this->listener->handleOrderAfterSendEmail($data);

        $log = ActivityLog::where('action', 'order.send_email')->first();

        $this->assertNotNull($log);
        $this->assertEquals(10, $log->properties['order_id']);
        $this->assertEquals('order_confirmation', $log->properties['template']);
    }

    public function test_handleOrderAfterSendEmail_handles_missing_keys(): void
    {
        $this->listener->handleOrderAfterSendEmail([]);

        $log = ActivityLog::where('action', 'order.send_email')->first();

        $this->assertNotNull($log);
        $this->assertNull($log->properties['order_id']);
        $this->assertNull($log->properties['template']);
    }

    // ═══════════════════════════════════════════
    // handle 기본 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handle_does_nothing(): void
    {
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true);
    }
}
