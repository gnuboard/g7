<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 상태 파생 전이 이력 훅(`order.after_status_sync`) — 단건 옵션 경로 · 구매확정 경로
 *
 * 부모 주문 상태가 상품 상태에서 따라 바뀌는 지점은 셋이다(옵션 일괄 변경 · 취소 후 동기화 ·
 * 구매확정). 일괄 경로는 `OrderOptionServiceTest` 가, 구매확정 경로는 이 테스트가 잠근다.
 * 단건 옵션 변경은 변경 전 스냅샷을 훅 4번째 인자로 실어야 활동 로그에 전/후 표가 선다.
 */
class OrderStatusSyncHookTest extends ModuleTestCase
{
    /** @var array<int, array{0: string|null, 1: string|null}> after_status_sync 발화 (전, 후) */
    private array $syncFired = [];

    /** @var array<int, array<int, mixed>> after_status_change 훅 인자 */
    private array $optionFired = [];

    private \Closure $syncCb;

    private \Closure $optionCb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncFired = [];
        $this->optionFired = [];
        $this->syncCb = function ($order, $prev = null, $new = null) {
            $this->syncFired[] = [$prev, $new];
        };
        $this->optionCb = function (...$args) {
            $this->optionFired[] = $args;
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_status_sync', $this->syncCb, 1);
        HookManager::addAction('sirsoft-ecommerce.order_option.after_status_change', $this->optionCb, 1);
    }

    protected function tearDown(): void
    {
        HookManager::removeAction('sirsoft-ecommerce.order.after_status_sync', $this->syncCb);
        HookManager::removeAction('sirsoft-ecommerce.order_option.after_status_change', $this->optionCb);
        parent::tearDown();
    }

    private function makeOrderWithOptions(OrderStatusEnum $status, int $count): Order
    {
        $order = OrderFactory::new()->create(['order_status' => $status, 'confirmed_at' => null]);
        for ($i = 0; $i < $count; $i++) {
            OrderOptionFactory::new()->forOrder($order)->create(['option_status' => $status, 'quantity' => 1]);
        }

        return $order->fresh();
    }

    /**
     * 단건 옵션 상태 변경은 변경 전 스냅샷을 훅에 싣는다 — 없으면 활동 로그에 전/후 표가 서지 않는다.
     *
     * @scenario transition_path=single_option, parent_outcome=stays
     *
     * @effects order_option_single_status_change_carries_before_and_after
     */
    public function test_single_option_change_passes_the_before_snapshot_to_the_hook(): void
    {
        $order = $this->makeOrderWithOptions(OrderStatusEnum::PAYMENT_COMPLETE, 1);
        $option = $order->options->first();

        app(OrderOptionService::class)->changeStatusWithQuantity($option, OrderStatusEnum::SHIPPING, $option->quantity);

        $this->assertCount(1, $this->optionFired);
        [$original, $newStatus, $split, $snapshot] = $this->optionFired[0];

        $this->assertSame($option->id, $original->id);
        $this->assertSame(OrderStatusEnum::SHIPPING, $newStatus);
        $this->assertNull($split);
        $this->assertIsArray($snapshot, '변경 전 스냅샷이 4번째 인자로 실려야 합니다.');
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE->value, $snapshot['option_status']);
    }

    /**
     * 모든 상품이 구매확정되어 주문이 확정으로 따라 바뀌면 이력 훅이 전/후와 함께 발화한다.
     *
     * @scenario transition_path=confirm_option, parent_outcome=transitions
     *
     * @effects order_status_sync_hook_fires_with_before_and_after
     */
    public function test_confirming_the_last_option_fires_the_sync_hook(): void
    {
        $order = $this->makeOrderWithOptions(OrderStatusEnum::DELIVERED, 1);
        $option = $order->options->first();

        app(OrderService::class)->confirmOption($order, $option);

        $this->assertSame(
            [[OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]],
            $this->syncFired
        );
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $order->fresh()->order_status);
    }

    /**
     * 확정되지 않은 상품이 남아 주문이 머물면 이력 훅도 발화하지 않는다.
     *
     * @scenario transition_path=confirm_option, parent_outcome=stays
     *
     * @effects order_status_sync_hook_silent_when_parent_stays
     */
    public function test_confirming_one_of_two_options_keeps_the_sync_hook_silent(): void
    {
        $order = $this->makeOrderWithOptions(OrderStatusEnum::DELIVERED, 2);

        app(OrderService::class)->confirmOption($order, $order->options->first());

        $this->assertSame([], $this->syncFired);
        $this->assertEquals(OrderStatusEnum::DELIVERED, $order->fresh()->order_status);
    }
}
