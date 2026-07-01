<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Notifications;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 상태변경 시 알림 트리거 누락 방지 Feature 테스트 (A35 / A36 / D9)
 *
 * 관리자가 실제 엔드포인트로 상태를 전이시킬 때, 매핑된 알림 트리거 훅이
 * 누락 없이 발화되는지(HTTP 경계) + 비결함 가드(CONFIRMED 전이가 재고를 건드리지 않음)를 검증한다.
 */
class OrderStatusNotificationTest extends ModuleTestCase
{
    protected User $adminUser;

    /** @var array<string> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->fired = [];

        foreach ([
            'sirsoft-ecommerce.order.after_confirm',
            'sirsoft-ecommerce.order.after_ship',
            'sirsoft-ecommerce.order.after_deliver',
            'sirsoft-ecommerce.order.after_complete',
        ] as $hook) {
            HookManager::addAction($hook, function () use ($hook) {
                $this->fired[] = $hook;
            }, 1);
        }
    }

    private function orderWithOption(OrderStatusEnum $status): Order
    {
        $order = OrderFactory::new()->create(['order_status' => $status]);
        OrderPaymentFactory::new()->forOrder($order)->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => $status,
        ]);

        return $order->fresh();
    }

    /**
     * D9 — 관리자 단건 update 로 payment_complete 전이 시 결제완료 알림(order.after_confirm) 발화.
     *
     * @scenario transition_path=update, target_status=payment_complete, previous_status=different, order_count=single
     *
     * @effects admin_status_update_to_payment_complete_sends_order_confirmed
     */
    public function test_admin_update_to_payment_complete_fires_order_confirmed(): void
    {
        $order = $this->orderWithOption(OrderStatusEnum::PENDING_PAYMENT);

        $this->actingAs($this->adminUser)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                'order_status' => 'payment_complete',
                'recipient_name' => '홍길동',
                'recipient_phone' => '010-1234-5678',
                'recipient_zipcode' => '12345',
                'recipient_address' => '서울특별시 강남구 테헤란로 123',
                'recipient_detail_address' => '101동 202호',
            ])
            ->assertOk();

        $this->assertContains('sirsoft-ecommerce.order.after_confirm', $this->fired);
    }

    /**
     * D9 — 관리자 일괄(bulk) payment_complete 전이 시 각 주문에 결제완료 알림 발화.
     *
     * @scenario transition_path=bulk_update, target_status=payment_complete, previous_status=different, order_count=multi
     *
     * @effects admin_bulk_to_payment_complete_sends_order_confirmed
     */
    public function test_admin_bulk_to_payment_complete_fires_order_confirmed_per_order(): void
    {
        $o1 = $this->orderWithOption(OrderStatusEnum::PENDING_PAYMENT);
        $o2 = $this->orderWithOption(OrderStatusEnum::PENDING_PAYMENT);

        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'ids' => [$o1->id, $o2->id],
                'order_status' => 'payment_complete',
            ])
            ->assertOk();

        $confirmFires = array_filter($this->fired, fn ($h) => $h === 'sirsoft-ecommerce.order.after_confirm');
        $this->assertCount(2, $confirmFires, '일괄 2건 전이 → 주문마다 결제완료 알림 1회씩');
    }

    /**
     * A36 — 배송완료(DELIVERED) 주문 일괄을 구매확정(CONFIRMED)으로 전이 시 각 주문에 구매확정 알림 발화.
     *
     * @scenario transition_path=bulk_update, target_status=confirmed, previous_status=different, order_count=multi
     *
     * @effects bulk_update_fires_after_status_change_per_order
     */
    public function test_admin_bulk_delivered_to_confirmed_fires_order_completed(): void
    {
        $o1 = $this->orderWithOption(OrderStatusEnum::DELIVERED);
        $o2 = $this->orderWithOption(OrderStatusEnum::DELIVERED);

        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'ids' => [$o1->id, $o2->id],
                'order_status' => 'confirmed',
            ])
            ->assertOk();

        $completeFires = array_filter($this->fired, fn ($h) => $h === 'sirsoft-ecommerce.order.after_complete');
        $this->assertCount(2, $completeFires, 'DELIVERED→CONFIRMED 일괄 → 주문마다 구매확정 알림 1회씩 (A36)');
    }

    /**
     * 미전이(이미 그 상태) 재저장 시 알림 미발화.
     *
     * @scenario transition_path=update, target_status=shipping, previous_status=same_as_target, order_count=single
     *
     * @effects listener_no_fire_when_status_unchanged
     */
    public function test_admin_update_to_same_status_does_not_fire(): void
    {
        $order = $this->orderWithOption(OrderStatusEnum::SHIPPING);

        $this->actingAs($this->adminUser)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                'order_status' => 'shipping',
                'recipient_name' => '홍길동',
                'recipient_phone' => '010-1234-5678',
                'recipient_zipcode' => '12345',
                'recipient_address' => '서울특별시 강남구 테헤란로 123',
                'recipient_detail_address' => '101동 202호',
            ])
            ->assertOk();

        $this->assertNotContains('sirsoft-ecommerce.order.after_ship', $this->fired);
    }

    /**
     * 비결함 가드 — CONFIRMED 전이가 옵션 재고차감 플래그를 변경하지 않는다 (A36 재고 무변동).
     *
     * @scenario transition_path=bulk_update, target_status=confirmed, previous_status=different, order_count=single
     *
     * @effects confirmed_transition_does_not_change_stock
     */
    public function test_confirmed_transition_does_not_change_stock(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::DELIVERED]);
        OrderPaymentFactory::new()->forOrder($order)->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::DELIVERED,
            'is_stock_deducted' => true,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'ids' => [$order->id],
                'order_status' => 'confirmed',
            ])
            ->assertOk();

        // 구매확정 전이는 재고 플래그를 건드리지 않아야 한다 (재고 이중차감/복구 없음).
        $this->assertTrue((bool) $option->fresh()->is_stock_deducted);
    }
}
