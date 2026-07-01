<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\MileageTransactionListener;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * MileageTransactionListener 테스트 (적립 트리거 분기 / 복원 / 회수)
 */
class MileageTransactionListenerTest extends ModuleTestCase
{
    private MileageTransactionListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeMileageSettings();
        $this->listener = app(MileageTransactionListener::class);
    }

    protected function tearDown(): void
    {
        $file = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/mileage.json');
        if (file_exists($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    /**
     * 마일리지 설정 파일을 작성합니다.
     *
     * @param  array  $overrides  덮어쓸 값
     */
    private function writeMileageSettings(array $overrides = []): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $settings = array_merge([
            'enabled' => true,
            'default_earn_rate' => 1,
            'earn_trigger' => 'confirmed',
            'earn_delay_days' => 0,
            'currency_rules' => [['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0]],
            'expiry_enabled' => true,
            'expiry_days' => 365,
        ], $overrides);

        file_put_contents($path.'/mileage.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 주문 + 적립 옵션을 생성합니다.
     *
     * @param  array  $orderOverrides  주문 덮어쓸 값
     * @param  float  $earnAmount  적립 예정액
     * @return array{0: Order, 1: OrderOption}
     */
    private function makeOrderWithOption(array $orderOverrides = [], float $earnAmount = 200): array
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(array_merge(['user_id' => $user->id, 'currency' => 'KRW'], $orderOverrides));
        $option = OrderOption::factory()->forOrder($order)->create(['subtotal_earned_points_amount' => $earnAmount]);

        return [$order, $option];
    }

    /**
     * trigger=confirmed + delay=0 → after_confirm 즉시 적립.
     */
    public function test_earns_on_after_confirm_when_trigger_confirmed(): void
    {
        [$order, $option] = $this->makeOrderWithOption();

        $this->listener->handleAfterConfirm($order, $option);

        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'order_option_id' => $option->id,
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
        ]);
    }

    /**
     * trigger=delivered → after_confirm 은 적립하지 않는다.
     */
    public function test_does_not_earn_on_confirm_when_trigger_delivered(): void
    {
        $this->writeMileageSettings(['earn_trigger' => 'delivered']);
        $this->listener = app(MileageTransactionListener::class);
        [$order, $option] = $this->makeOrderWithOption();

        $this->listener->handleAfterConfirm($order, $option);

        $this->assertDatabaseMissing('ecommerce_mileage_transactions', [
            'order_option_id' => $option->id,
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
        ]);
    }

    /**
     * delay>0 → after_confirm 즉시 적립 스킵 (스케줄러 담당).
     */
    public function test_skips_immediate_earn_when_delay_positive(): void
    {
        $this->writeMileageSettings(['earn_delay_days' => 3]);
        $this->listener = app(MileageTransactionListener::class);
        [$order, $option] = $this->makeOrderWithOption();

        $this->listener->handleAfterConfirm($order, $option);

        $this->assertDatabaseMissing('ecommerce_mileage_transactions', [
            'order_option_id' => $option->id,
        ]);
    }

    /**
     * trigger=delivered → 배송완료 상태 변경 시 적립.
     */
    public function test_earns_on_delivered_status_change_when_trigger_delivered(): void
    {
        $this->writeMileageSettings(['earn_trigger' => 'delivered']);
        $this->listener = app(MileageTransactionListener::class);
        [$order, $option] = $this->makeOrderWithOption();

        $this->listener->handleAfterStatusChange($option, OrderStatusEnum::DELIVERED, null);

        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'order_option_id' => $option->id,
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
        ]);
    }

    /**
     * 취소 전이 시 기적립 회수(earn_cancel).
     */
    public function test_cancels_earn_on_cancelled_status_change(): void
    {
        [$order, $option] = $this->makeOrderWithOption();
        // 먼저 적립
        $this->listener->handleAfterConfirm($order, $option);
        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'order_option_id' => $option->id,
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
        ]);

        // 취소 전이 → 회수
        $this->listener->handleAfterStatusChange($option, OrderStatusEnum::CANCELLED, null);

        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'order_option_id' => $option->id,
            'type' => MileageTransactionTypeEnum::EARN_CANCEL->value,
        ]);
    }

    /**
     * 사용 차감 핸들러가 FIFO 차감을 수행한다.
     */
    public function test_handle_use_deducts_balance(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);
        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000,
        ]);

        $this->listener->handleUse(400, $order);

        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'order_id' => $order->id,
            'type' => MileageTransactionTypeEnum::ORDER_USE->value,
        ]);
    }

    /**
     * 복원 핸들러 — order_cancel_id 부재 시 무시.
     */
    public function test_handle_restore_ignores_when_no_cancel_id(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);

        $this->listener->handleRestore(500, $order, null);

        $this->assertDatabaseMissing('ecommerce_mileage_transactions', [
            'order_id' => $order->id,
            'type' => MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value,
        ]);
    }

    /**
     * getSubscribedHooks 가 5개 훅을 구독한다 (mileage.earn 미구독).
     */
    public function test_subscribed_hooks(): void
    {
        $hooks = MileageTransactionListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.mileage.use', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.mileage.restore', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order-option.after_confirm', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order_option.after_status_change', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order_option.after_bulk_status_change', $hooks);
        // 결제완료 시점 적립 훅은 구독하지 않음 (이중 적립 방지)
        $this->assertArrayNotHasKey('sirsoft-ecommerce.mileage.earn', $hooks);
    }
}
