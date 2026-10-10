<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderOptionService 전이 알림 테스트 (A35 / A36 / C3)
 *
 * 옵션별 일괄 상태변경(운송장 등)으로 부모 주문 상태가 전이되면
 * order.after_status_change 훅이 발화되어 OrderStatusNotificationListener 가
 * 알림으로 매핑하는지 검증한다.
 */
class OrderOptionServiceTest extends ModuleTestCase
{
    private OrderOptionService $service;

    /** @var array<string|null> */
    private array $fired = [];

    private \Closure $cb;

    /** @var array<int, array{0: string|null, 1: string|null}> after_status_sync 발화 (전, 후) */
    private array $syncFired = [];

    private \Closure $syncCb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderOptionService::class);
        $this->fired = [];
        $this->cb = function ($order, $prev = null) {
            $this->fired[] = $prev;
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_status_change', $this->cb, 1);
        $this->syncFired = [];
        $this->syncCb = function ($order, $prev = null, $new = null) {
            $this->syncFired[] = [$prev, $new];
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_status_sync', $this->syncCb, 1);
    }

    protected function tearDown(): void
    {
        HookManager::removeAction('sirsoft-ecommerce.order.after_status_change', $this->cb);
        HookManager::removeAction('sirsoft-ecommerce.order.after_status_sync', $this->syncCb);
        parent::tearDown();
    }

    private function makePaidOrderWithOptions(int $optionCount, int $quantity = 1): Order
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        for ($i = 0; $i < $optionCount; $i++) {
            OrderOptionFactory::new()->forOrder($order)->create([
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'quantity' => $quantity,
            ]);
        }

        return $order->fresh();
    }

    /**
     * 전 옵션이 SHIPPING 으로 전이되면 부모도 SHIPPING 으로 전이 → after_status_change 발화.
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=shipping, previous_status=different, order_count=single
     *
     * @effects bulk_change_with_quantity_fires_when_parent_transitions
     */
    public function test_bulk_change_fires_when_parent_transitions(): void
    {
        $order = $this->makePaidOrderWithOptions(2);
        $items = $order->options->map(fn ($o) => ['option_id' => $o->id, 'quantity' => $o->quantity])->all();

        $this->service->bulkChangeStatusWithQuantity($items, OrderStatusEnum::SHIPPING, $order->id);

        $this->assertContains(OrderStatusEnum::PAYMENT_COMPLETE->value, $this->fired);
        $this->assertEquals(OrderStatusEnum::SHIPPING, $order->fresh()->order_status);
    }

    /**
     * 부모 주문이 상품 상태에서 파생 전이하면 이력용 훅(after_status_sync)이 전/후와 함께 발화한다.
     * 알림 훅(after_status_change)과 별개 축이다 — 그쪽에 로그를 붙이면 단건 수정·일괄 변경에서
     * 한 전이가 두 줄이 된다.
     *
     * @scenario transition_path=bulk_option, parent_outcome=transitions
     *
     * @effects order_status_sync_hook_fires_with_before_and_after
     */
    public function test_bulk_change_fires_the_sync_hook_with_before_and_after(): void
    {
        $order = $this->makePaidOrderWithOptions(2);
        $items = $order->options->map(fn ($o) => ['option_id' => $o->id, 'quantity' => $o->quantity])->all();

        $this->service->bulkChangeStatusWithQuantity($items, OrderStatusEnum::SHIPPING, $order->id);

        $this->assertSame(
            [[OrderStatusEnum::PAYMENT_COMPLETE->value, OrderStatusEnum::SHIPPING->value]],
            $this->syncFired,
            '파생 전이 한 번에 이력 훅이 정확히 한 번, 전/후 값과 함께 발화해야 합니다.'
        );
    }

    /**
     * 부모가 혼합 상태로 남아 전이하지 않으면 이력 훅도 발화하지 않는다.
     *
     * @scenario transition_path=bulk_option, parent_outcome=stays
     *
     * @effects order_status_sync_hook_silent_when_parent_stays
     */
    public function test_bulk_change_does_not_fire_the_sync_hook_when_parent_stays(): void
    {
        $order = $this->makePaidOrderWithOptions(2);
        $first = $order->options->first();

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $first->id, 'quantity' => $first->quantity]],
            OrderStatusEnum::SHIPPING,
            $order->id
        );

        $this->assertSame([], $this->syncFired);
    }

    /**
     * 일부 옵션만 전이되면 부모는 혼합상태 → 가장 낮은 단계(payment_complete) 유지 → 미전이 → 미발화.
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=shipping, previous_status=same_as_target, order_count=single
     *
     * @effects no_fire_when_status_unchanged_in_bulk
     */
    public function test_bulk_change_does_not_fire_when_parent_stays_mixed(): void
    {
        $order = $this->makePaidOrderWithOptions(2);
        // 2개 옵션 중 1개만 SHIPPING 으로 전이 → 부모는 혼합(payment_complete + shipping) → 가장 낮은 payment_complete 유지
        $firstOption = $order->options->first();

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $firstOption->id, 'quantity' => $firstOption->quantity]],
            OrderStatusEnum::SHIPPING,
            $order->id,
        );

        $this->assertSame([], $this->fired, '부모가 전이되지 않으면 after_status_change 미발화여야 함');
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    /**
     * 배송 상태(shipping)로 전이할 때 metadata(carrier_id/tracking_number)가
     * 옵션의 배송 레코드에 기록된다 (송장 input 버그 — 백엔드).
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=shipping, has_shipping_row=true, metadata=carrier_and_tracking
     *
     * @effects bulk_change_persists_tracking_to_shipping_row
     */
    public function test_bulk_change_persists_tracking_metadata_to_shipping_row(): void
    {
        $order = $this->makePaidOrderWithOptions(1);
        $option = $order->options->first();
        // 정상 주문 흐름과 동일하게 옵션별 배송 레코드 존재
        $shipping = OrderShippingFactory::new()->forOrderOption($option)->create([
            'carrier_id' => null,
            'tracking_number' => null,
        ]);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => $option->quantity]],
            OrderStatusEnum::SHIPPING,
            $order->id,
            ['carrier_id' => 1, 'tracking_number' => 'SAVED-TRACK-108']
        );

        $fresh = $shipping->fresh();
        $this->assertEquals(1, $fresh->carrier_id);
        $this->assertEquals('SAVED-TRACK-108', $fresh->tracking_number);
    }

    /**
     * 비배송 상태(payment_complete 등)로 전이할 때는 metadata 가 있어도
     * 배송 레코드에 기록하지 않는다 (배송 상태 전이에만 반영).
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=payment_complete, has_shipping_row=true, metadata=carrier_and_tracking
     *
     * @effects bulk_change_skips_tracking_for_non_shipping_status
     */
    public function test_bulk_change_does_not_persist_tracking_for_non_shipping_status(): void
    {
        // 미결제 → 결제완료 전이 (비배송 상태)
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PENDING_PAYMENT,
            'quantity' => 1,
        ]);
        $shipping = OrderShippingFactory::new()->forOrderOption($option)->create([
            'carrier_id' => null,
            'tracking_number' => null,
        ]);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => $option->quantity]],
            OrderStatusEnum::PAYMENT_COMPLETE,
            $order->id,
            ['carrier_id' => 1, 'tracking_number' => 'SHOULD-NOT-SAVE']
        );

        $fresh = $shipping->fresh();
        $this->assertNull($fresh->carrier_id, '비배송 전이 시 carrier_id 미기록');
        $this->assertNull($fresh->tracking_number, '비배송 전이 시 tracking_number 미기록');
    }

    /**
     * 발화된 IDV 훅 이름을 수집하는 임시 리스너를 등록하고, 정리 콜백을 반환한다.
     *
     * @param  array<string>  &$collected  발화된 훅 이름 수집 배열
     * @return callable 정리(removeAction) 콜백
     */
    private function captureIdvHooks(array &$collected): callable
    {
        $hooks = [
            'sirsoft-ecommerce.payment.before_confirm_deposit',
            'sirsoft-ecommerce.payment.before_approve',
        ];
        $handles = [];
        foreach ($hooks as $hook) {
            $cb = function () use ($hook, &$collected) {
                $collected[] = $hook;
            };
            HookManager::addAction($hook, $cb, 1);
            $handles[$hook] = $cb;
        }

        return function () use ($handles) {
            foreach ($handles as $hook => $cb) {
                HookManager::removeAction($hook, $cb);
            }
        };
    }

    /**
     * N4: 무통장(dbank) 주문이 옵션 일괄변경으로 결제완료 전이될 때, 진입부에서
     * payment.before_confirm_deposit IDV 훅이 발화된다.
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=payment_complete, payment_method=dbank
     *
     * @effects bulk_payment_complete_fires_confirm_deposit_idv_for_dbank
     */
    public function test_bulk_payment_complete_fires_confirm_deposit_idv_for_dbank(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        OrderPaymentFactory::new()->forOrder($order)->directBank()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PENDING_PAYMENT,
            'quantity' => 1,
        ]);

        $collected = [];
        $cleanup = $this->captureIdvHooks($collected);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => $option->quantity]],
            OrderStatusEnum::PAYMENT_COMPLETE,
            $order->id,
        );

        $cleanup();

        $this->assertContains('sirsoft-ecommerce.payment.before_confirm_deposit', $collected);
        $this->assertNotContains('sirsoft-ecommerce.payment.before_approve', $collected);
    }

    /**
     * N4: 카드 등 그 외 결제수단 주문은 결제완료 전이 시 payment.before_approve 발화.
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=payment_complete, payment_method=card
     *
     * @effects bulk_payment_complete_fires_approve_idv_for_non_dbank
     */
    public function test_bulk_payment_complete_fires_approve_idv_for_card(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        OrderPaymentFactory::new()->forOrder($order)->card()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PENDING_PAYMENT,
            'quantity' => 1,
        ]);

        $collected = [];
        $cleanup = $this->captureIdvHooks($collected);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => $option->quantity]],
            OrderStatusEnum::PAYMENT_COMPLETE,
            $order->id,
        );

        $cleanup();

        $this->assertContains('sirsoft-ecommerce.payment.before_approve', $collected);
        $this->assertNotContains('sirsoft-ecommerce.payment.before_confirm_deposit', $collected);
    }

    /**
     * N4: 이미 결제완료인 주문은 전이가 일어나지 않으므로 IDV 가드 대상에서 제외된다.
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=payment_complete, current_status=payment_complete
     *
     * @effects bulk_payment_complete_skips_idv_when_already_paid
     */
    public function test_bulk_payment_complete_skips_idv_when_already_paid(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        OrderPaymentFactory::new()->forOrder($order)->directBank()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => 1,
        ]);

        $collected = [];
        $cleanup = $this->captureIdvHooks($collected);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => $option->quantity]],
            OrderStatusEnum::PAYMENT_COMPLETE,
            $order->id,
        );

        $cleanup();

        $this->assertSame([], $collected, '이미 결제완료인 주문은 IDV 훅 미발화');
    }

    /**
     * N4: 결제완료가 아닌 다른 상태(배송중) 전이에는 IDV 가드를 발화하지 않는다.
     *
     * @scenario transition_path=bulk_change_with_quantity, target_status=shipping, payment_method=dbank
     *
     * @effects bulk_non_payment_complete_skips_idv
     */
    public function test_bulk_non_payment_complete_does_not_fire_idv(): void
    {
        $order = $this->makePaidOrderWithOptions(1);
        $option = $order->options->first();

        $collected = [];
        $cleanup = $this->captureIdvHooks($collected);

        $this->service->bulkChangeStatusWithQuantity(
            [['option_id' => $option->id, 'quantity' => $option->quantity]],
            OrderStatusEnum::SHIPPING,
            $order->id,
        );

        $cleanup();

        $this->assertSame([], $collected, '결제완료 외 전이는 IDV 훅 미발화');
    }
}
