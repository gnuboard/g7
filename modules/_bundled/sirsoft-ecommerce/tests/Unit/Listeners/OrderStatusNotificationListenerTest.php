<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\OrderStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderStatusNotificationListener 테스트 (A35 / A36 / D9 / D10)
 *
 * 전이 훅(order.after_status_change) → 구체 알림 훅 매핑이 현재 상태별로 정확히
 * 발화되는지, 미전이(동일 상태) 시 미발화되는지 검증한다.
 */
class OrderStatusNotificationListenerTest extends ModuleTestCase
{
    private OrderStatusNotificationListener $listener;

    /** @var array<string> 발화된 알림 훅 이름 수집 */
    private array $firedHooks = [];

    /** @var array<callable> 정리용 콜백 핸들 */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new OrderStatusNotificationListener;
        $this->firedHooks = [];

        foreach ([
            'sirsoft-ecommerce.order.after_confirm',
            'sirsoft-ecommerce.order.after_ship',
            'sirsoft-ecommerce.order.after_deliver',
            'sirsoft-ecommerce.order.after_complete',
        ] as $hook) {
            $cb = function () use ($hook) {
                $this->firedHooks[] = $hook;
            };
            HookManager::addAction($hook, $cb, 1);
            $this->registered[$hook] = $cb;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->registered as $hook => $cb) {
            HookManager::removeAction($hook, $cb);
        }
        parent::tearDown();
    }

    private function orderWith(OrderStatusEnum $status): Order
    {
        return new Order(['order_status' => $status->value]);
    }

    /**
     * @scenario transition_path=update, target_status=payment_complete, previous_status=different, order_count=single
     *
     * @effects listener_maps_payment_complete_to_after_confirm
     */
    public function test_maps_payment_complete_to_after_confirm(): void
    {
        $this->listener->handleStatusChange($this->orderWith(OrderStatusEnum::PAYMENT_COMPLETE), OrderStatusEnum::PENDING_PAYMENT->value);

        $this->assertSame(['sirsoft-ecommerce.order.after_confirm'], $this->firedHooks);
    }

    /**
     * @scenario transition_path=update, target_status=shipping, previous_status=different, order_count=single
     *
     * @effects listener_maps_shipping_to_after_ship
     */
    public function test_maps_shipping_to_after_ship(): void
    {
        $this->listener->handleStatusChange($this->orderWith(OrderStatusEnum::SHIPPING), OrderStatusEnum::PAYMENT_COMPLETE->value);

        $this->assertSame(['sirsoft-ecommerce.order.after_ship'], $this->firedHooks);
    }

    /**
     * @scenario transition_path=update, target_status=delivered, previous_status=different, order_count=single
     *
     * @effects listener_maps_delivered_to_after_deliver
     */
    public function test_maps_delivered_to_after_deliver(): void
    {
        $this->listener->handleStatusChange($this->orderWith(OrderStatusEnum::DELIVERED), OrderStatusEnum::SHIPPING->value);

        $this->assertSame(['sirsoft-ecommerce.order.after_deliver'], $this->firedHooks);
    }

    /**
     * @scenario transition_path=update, target_status=confirmed, previous_status=different, order_count=single
     *
     * @effects listener_maps_confirmed_to_after_complete
     */
    public function test_maps_confirmed_to_after_complete(): void
    {
        $this->listener->handleStatusChange($this->orderWith(OrderStatusEnum::CONFIRMED), OrderStatusEnum::DELIVERED->value);

        $this->assertSame(['sirsoft-ecommerce.order.after_complete'], $this->firedHooks);
    }

    /**
     * 동일 상태(미전이) 재호출 시 어떤 알림 훅도 발화하지 않는다.
     *
     * @scenario transition_path=update, target_status=shipping, previous_status=same_as_target, order_count=single
     *
     * @effects listener_no_fire_when_status_unchanged
     */
    public function test_no_fire_when_status_unchanged(): void
    {
        $this->listener->handleStatusChange($this->orderWith(OrderStatusEnum::SHIPPING), OrderStatusEnum::SHIPPING->value);

        $this->assertSame([], $this->firedHooks);
    }

    /**
     * N1 회귀 핵심: 큐 지연으로 재로드된 모델 상태가 목표와 다를 때, 매핑 기준은
     * 발화 측이 전이 시점에 캡처해 넘긴 $targetStatus(스칼라)여야 한다. 모델 상태가
     * 아니다. (큐 적체 중 추가 전이 시 모델 order_status 가 "현재값"으로 읽혀 오매핑되는
     * 결함의 회귀 가드)
     *
     * @scenario transition_path=update_queued, target_status=shipping, model_reloaded_status=confirmed, order_count=single
     *
     * @effects listener_maps_by_target_status_not_reloaded_model
     */
    public function test_maps_by_target_status_when_model_reloaded_to_different_status(): void
    {
        // 모델은 큐 재로드로 CONFIRMED(현재값)지만, 전이 시점 목표는 SHIPPING.
        $reloadedModel = $this->orderWith(OrderStatusEnum::CONFIRMED);

        $this->listener->handleStatusChange(
            $reloadedModel,
            OrderStatusEnum::PAYMENT_COMPLETE->value, // previousStatus
            OrderStatusEnum::SHIPPING->value          // targetStatus (전이 시점 캡처)
        );

        // 모델값(CONFIRMED→after_complete)이 아니라 targetStatus(SHIPPING→after_ship)로 매핑
        $this->assertSame(['sirsoft-ecommerce.order.after_ship'], $this->firedHooks);
    }

    /**
     * $targetStatus 가 null 인 레거시 호출은 모델 상태로 폴백한다.
     *
     * @scenario transition_path=update, target_status=null, model_status=delivered, order_count=single
     *
     * @effects listener_falls_back_to_model_status_when_target_null
     */
    public function test_falls_back_to_model_status_when_target_status_null(): void
    {
        $this->listener->handleStatusChange(
            $this->orderWith(OrderStatusEnum::DELIVERED),
            OrderStatusEnum::SHIPPING->value,
            null // targetStatus 미전달 → 모델 상태(DELIVERED)로 폴백
        );

        $this->assertSame(['sirsoft-ecommerce.order.after_deliver'], $this->firedHooks);
    }
}
