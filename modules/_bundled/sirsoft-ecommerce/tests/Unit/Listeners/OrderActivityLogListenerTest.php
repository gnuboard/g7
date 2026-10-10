<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\ActivityLog\ChangeDetector;
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

    /**
     * 관리자 경로 요청을 심고 활동 로그 리스너 인스턴스를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 관리자 경로 요청 설정 (resolveLogType()이 Admin 반환하도록)
        $this->app->instance('request', Request::create('/api/admin/sirsoft-ecommerce/orders'));
        $this->listener = app(OrderActivityLogListener::class);
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks
    // ═══════════════════════════════════════════

    /**
     * 훅 구독 수가 26개인지 확인
     *
     * 기존 21 + `order.after_status_sync`(파생 전이 이력) 1 +
     * `order.tax_invoice_stale_after_cancel` · `order_option.after_sync_status` ·
     * `order_shipping.tracking_updated` 3 + `order.after_deposit_recorded`(입금 기록) 1 = 26.
     *
     * 뒤의 3건은 구독이 늘어날 때 이 개수가 함께 갱신되지 않아 이 테스트가 오래 red 였다
     * (#599 통합실측 세션2 에서 발견 — 실제 구독 25 vs 기대 22). 개수만 고치면 같은 일이
     * 반복되므로 **세 훅의 키 확인을 아래 목록에 함께 넣어** 무엇이 늘었는지가 남게 한다.
     *
     * @scenario listener_subscription=hook_set
     *
     * @effects order_activity_log_listener_subscribes_the_expected_hook_set
     *
     * @return void 반환값 없음
     */
    public function test_get_subscribed_hooks_returns_all_27_hooks(): void
    {
        $hooks = OrderActivityLogListener::getSubscribedHooks();

        $this->assertCount(27, $hooks);
        // 수동 결제 처리 세 항목·재활성 복원 결과 — 27번째 구독 (Q13·Q26)
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_manual_paid_effects', $hooks);
        // 입금 기록(결제 이후 단계 주문의 입금만 기록) — 26번째 구독
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_deposit_recorded', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.tax_invoice_stale_after_cancel', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order_option.after_sync_status', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order_shipping.tracking_updated', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_status_sync', $hooks);
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
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_reset_guest_password', $hooks);
    }

    // ═══════════════════════════════════════════
    // 단건 OrderService 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 주문 수정 훅이 수정 전 스냅샷과 함께 오면 주문 수정 활동 로그를 남긴다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_update_with_snapshot(): void
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

    /**
     * 스냅샷 없이 주문 수정 훅이 오면 변경 내역(changes) 없이 활동 로그만 남긴다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_update_without_snapshot(): void
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

    /**
     * 주문 삭제 훅이 오면 주문 삭제 활동 로그를 남긴다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_delete_logs_activity(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_update_creates_per_item_logs(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_update_loggable_points_to_correct_order(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_update_detects_changes_with_snapshots(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_update_null_changes_without_snapshots(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_status_update_creates_per_item_logs(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_shipping_update_creates_per_item_logs(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_shipping_update_detects_changes_with_snapshots(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_update_description_params_includes_count(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_status_update_description_params_includes_count(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_bulk_shipping_update_description_params_includes_count(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_option_after_bulk_status_change_description_params_includes_count(): void
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
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
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
     *
     * @scenario log_handler=order_option
     *
     * @effects order_option_events_log_status_transitions_with_label_keys
     *
     * @return void 반환값 없음
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
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
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
     *
     * @scenario log_handler=order_shipping_address
     *
     * @effects shipping_address_change_logs_against_order_address
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_update_shipping_address_loggable_is_order_address(): void
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
     *
     * @scenario log_handler=order_shipping_address
     *
     * @effects shipping_address_change_logs_against_order_address
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_update_shipping_address_detects_changes(): void
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
     *
     * @scenario log_handler=order_shipping_address
     *
     * @effects shipping_address_change_logs_against_order_address
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_update_shipping_address_fallback_to_order(): void
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
     *
     * @scenario log_handler=order_option
     *
     * @effects order_option_events_log_status_transitions_with_label_keys
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_option_after_status_change_logs_activity(): void
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
        $this->assertNull($log->changes, '스냅샷이 없으면 전/후 표를 만들 근거가 없습니다.');
    }

    /**
     * 단건 옵션 상태 변경에 변경 전 스냅샷이 실리면 전/후 표(changes)가 남는다.
     *
     * 종전에는 "변경 후" 만 properties 에 적혀 활동 로그 화면에 전/후 표가 서지 않았다 —
     * 일괄 변경 경로와 같은 계약(스냅샷 → ChangeDetector)으로 맞춘다.
     *
     * @effects order_option_status_change_log_carries_before_and_after_with_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_option_after_status_change_records_before_and_after_with_snapshot(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-OPT-002']);
        $option = OrderOption::factory()->create(['order_id' => $order->id, 'option_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $snapshot = $option->attributesToArray();

        OrderOption::where('id', $option->id)->update(['option_status' => OrderStatusEnum::SHIPPING->value]);
        $option->refresh();

        $this->listener->handleOrderOptionAfterStatusChange($option, OrderStatusEnum::SHIPPING, null, $snapshot);

        $log = ActivityLog::where('action', 'order_option.status_change')->where('loggable_id', $option->id)->firstOrFail();

        $this->assertEquals('payment_complete', $log->properties['previous_status']);
        $statusChange = collect($log->changes)->firstWhere('field', 'option_status');
        $this->assertNotNull($statusChange, '전/후 표에 option_status 항목이 있어야 합니다.');
        $this->assertEquals('payment_complete', $statusChange['old']);
        $this->assertEquals('shipping', $statusChange['new']);
        $this->assertEquals('sirsoft-ecommerce::enums.order_status.payment_complete', $statusChange['old_label_key']);
        $this->assertEquals('sirsoft-ecommerce::enums.order_status.shipping', $statusChange['new_label_key']);
    }

    /**
     * 상품 상태에 맞춰 부모 주문 상태가 자동으로 따라 바뀐 전이는 별도 줄(전/후 포함)로 남는다.
     *
     * @effects order_status_sync_log_carries_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_status_sync_records_the_derived_transition_with_before_and_after(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-SYNC-001', 'order_status' => OrderStatusEnum::SHIPPING]);

        $this->listener->handleOrderStatusSync($order, OrderStatusEnum::PAYMENT_COMPLETE->value, OrderStatusEnum::SHIPPING->value);

        $log = ActivityLog::where('action', 'order.status_sync')->where('loggable_id', $order->id)->firstOrFail();

        $this->assertEquals(Order::class, $log->loggable_type);
        $this->assertEquals('sirsoft-ecommerce::activity_log.description.order_status_sync', $log->description_key);
        $this->assertEquals('ORD-SYNC-001', $log->description_params['order_number']);
        $this->assertEquals('payment_complete', $log->properties['previous_status']);
        $this->assertEquals('shipping', $log->properties['new_status']);
        $this->assertEquals('option_status', $log->properties['trigger']);
        $this->assertEquals('order_status', $log->changes[0]['field']);
        $this->assertEquals('sirsoft-ecommerce::enums.order_status.payment_complete', $log->changes[0]['old_label_key']);
        $this->assertEquals('sirsoft-ecommerce::enums.order_status.shipping', $log->changes[0]['new_label_key']);
        $this->assertNotSame('activity_log.action.status_sync', $log->action_label, '액션 라벨 다국어 키가 해석되어야 합니다.');
    }

    /**
     * 전/후가 같으면 파생 전이 줄을 남기지 않는다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_status_sync_skips_when_nothing_changed(): void
    {
        $order = Order::factory()->create(['order_status' => OrderStatusEnum::SHIPPING]);

        $this->listener->handleOrderStatusSync($order, OrderStatusEnum::SHIPPING->value, OrderStatusEnum::SHIPPING->value);

        $this->assertSame(0, ActivityLog::where('action', 'order.status_sync')->count());
    }

    /**
     * 일괄 옵션 상태 변경 시 per-item 로그 생성 확인
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_option_after_bulk_status_change_creates_per_item_logs(): void
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
     *
     * @scenario log_handler=order_bulk
     *
     * @effects bulk_events_log_per_item_with_correct_loggable_and_count
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_option_after_bulk_status_change_detects_changes_with_snapshots(): void
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
     *
     * @scenario log_handler=order_option
     *
     * @effects order_option_events_log_status_transitions_with_label_keys
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_option_after_confirm_logs_activity(): void
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

    /**
     * 주문 취소 훅이 취소 스냅샷과 함께 오면 주문 취소 활동 로그를 남긴다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_cancel_logs_activity_with_snapshot(): void
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
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_partial_cancel_creates_order_and_per_option_logs(): void
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
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_partial_cancel_without_cancel_items(): void
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

    /**
     * 쿠폰 복원 훅이 오면 복원된 쿠폰 발급 ID 목록을 활동 로그 속성에 남긴다.
     *
     * @scenario log_handler=money_side_effect
     *
     * @effects coupon_and_mileage_side_effects_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_coupon_restore_logs_activity(): void
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

    /**
     * 마일리지 복원 훅이 오면 복원 금액을 통화 형식으로 활동 로그에 남긴다.
     *
     * @scenario log_handler=money_side_effect
     *
     * @effects coupon_and_mileage_side_effects_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_mileage_restore_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-MR-001']);

        $this->listener->handleMileageRestore(5000.0, $order);

        $log = ActivityLog::where('action', 'mileage.restore')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(ecommerce_format_price(5000.0), $log->description_params['amount']);
    }

    // ═══════════════════════════════════════════
    // OrderProcessingService 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 주문 생성 훅이 오면 주문번호를 담은 주문 생성 활동 로그를 남긴다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_create_logs_activity(): void
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

    /**
     * 결제 완료 훅이 오면 결제 완료 활동 로그를 남긴다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_payment_complete_logs_activity(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-PAY-001']);

        $this->listener->handleOrderAfterPaymentComplete($order);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.payment_complete',
            'loggable_type' => Order::class,
            'loggable_id' => $order->id,
        ]);
    }

    /**
     * 결제 실패 훅이 오면 오류 코드·오류 메시지를 활동 로그 속성에 남긴다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_payment_failed_logs_activity(): void
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

    /**
     * 쿠폰 사용 훅이 오면 적용한 쿠폰 ID 목록을 활동 로그 속성에 남긴다.
     *
     * @scenario log_handler=money_side_effect
     *
     * @effects coupon_and_mileage_side_effects_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_coupon_use_logs_activity(): void
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

    /**
     * 마일리지 사용 훅이 오면 사용 금액을 통화 형식으로 활동 로그에 남긴다.
     *
     * @scenario log_handler=money_side_effect
     *
     * @effects coupon_and_mileage_side_effects_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_mileage_use_logs_activity(): void
    {
        $order = Order::factory()->create();

        $this->listener->handleMileageUse(3000.0, $order);

        $log = ActivityLog::where('action', 'mileage.use')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(ecommerce_format_price(3000.0), $log->description_params['amount']);
    }

    /**
     * 마일리지 적립 훅이 오면 적립 금액을 통화 형식으로 활동 로그에 남긴다.
     *
     * @scenario log_handler=money_side_effect
     *
     * @effects coupon_and_mileage_side_effects_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_mileage_earn_logs_activity(): void
    {
        $order = Order::factory()->create();

        $this->listener->handleMileageEarn(1500.0, $order);

        $log = ActivityLog::where('action', 'mileage.earn')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(ecommerce_format_price(1500.0), $log->description_params['amount']);
    }

    /**
     * 결제 완료 시점의 적립 훅은 「적립 예정」으로 남긴다 — 실제 적립은 따로 기록된다 (S3-D21, 2026-10-07 실측)
     *
     * 결제 완료가 발화하는 `sirsoft-ecommerce.mileage.earn` 은 이 리스너만 구독한다 — 마일리지는 그 시점에 지급되지
     * 않는다(적립은 적립 시점 설정에 맞춰 적립 배치가 하고, 그때 `UserMileageService` 가 「마일리지 적립」을 따로 남긴다).
     * 그런데 이 로그가 「마일리지 적립 (500원)」이라 말해, 원장에 적립 행이 없는 주문에도 적립된 것처럼 읽혔다
     * (실측: 주문 20261007-0203352356 — 결제 완료 직후 로그 「마일리지 적립 (500원)」, purchase_earn 0건, 적립 시점 구매확정).
     *
     * @scenario log_handler=money_side_effect
     *
     * @effects payment_time_earn_log_reads_as_scheduled
     *
     * @return void 반환값 없음
     */
    public function test_payment_time_earn_hook_logs_as_scheduled(): void
    {
        $order = Order::factory()->create();

        $this->listener->handleMileageEarn(500.0, $order);

        $log = ActivityLog::where('action', 'mileage.earn')
            ->where('loggable_id', $order->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('sirsoft-ecommerce::activity_log.description.mileage_earn_scheduled', $log->description_key, '지급되지 않은 적립을 「적립」으로 기록한다');
        $this->assertNotSame(
            __('sirsoft-ecommerce::activity_log.description.mileage_earn', ['amount' => 'X']),
            __($log->description_key, ['amount' => 'X']),
        );
    }

    // ═══════════════════════════════════════════
    // 이메일/기타 핸들러 테스트
    // ═══════════════════════════════════════════

    /**
     * 주문 메일 발송 훅이 실제 발행 페이로드로 오면 주문번호·주문 ID 를 활동 로그에 남긴다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_send_email_logs_activity(): void
    {
        // 발행부(OrderService::sendEmail)가 실제로 넘기는 모양 — camelCase 키. 예전 테스트는 발행부에 없는
        // snake_case 키를 직접 만들어 넣어, 활동 로그의 주문번호·주문 ID 가 늘 빈 값이던 결함을 놓쳤다(#699 검증 중 발견).
        $data = [
            'recipientEmail' => 'buyer@example.com',
            'subject' => '배송 안내',
            'body' => '본문',
            'orderId' => 10,
            'orderNumber' => 'ORD-EMAIL',
            'extensionType' => 'module',
            'extensionIdentifier' => 'sirsoft-ecommerce',
        ];

        $this->listener->handleOrderAfterSendEmail($data);

        $log = ActivityLog::where('action', 'order.send_email')->first();

        $this->assertNotNull($log);
        $this->assertSame('ORD-EMAIL', $log->description_params['order_number']);
        $this->assertEquals(10, $log->properties['order_id']);
    }

    /**
     * 주문 메일 발송 훅의 페이로드가 비어 있어도 예외 없이 빈 속성으로 활동 로그를 남긴다.
     *
     * @scenario log_handler=order_lifecycle
     *
     * @effects order_lifecycle_events_are_logged_with_before_and_after
     *
     * @return void 반환값 없음
     */
    public function test_handle_order_after_send_email_handles_missing_keys(): void
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

    /**
     * 기본 handle() 은 아무 일도 하지 않고 예외도 던지지 않는다.
     *
     * @scenario log_handler=no_op
     *
     * @effects handler_stays_silent_when_there_is_nothing_to_log
     *
     * @return void 반환값 없음
     */
    public function test_handle_does_nothing(): void
    {
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true);
    }
}
