<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Listeners\MileageTransactionListener;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * MileageTransactionListener 테스트 (적립 트리거 분기 / 복원 / 회수)
 */
class MileageTransactionListenerTest extends ModuleTestCase
{
    private MileageTransactionListener $listener;

    /**
     * 리스너와 마일리지 설정을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeMileageSettings();
        $this->listener = app(MileageTransactionListener::class);
    }

    /**
     * 테스트가 쓴 마일리지 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
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
     * @return void 반환값 없음
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
     * @return array{0: Order, 1: OrderOption} 주문과 적립 대상 주문옵션 한 쌍
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
     *
     * @return void 반환값 없음
     *
     * @scenario event=confirm_with_confirmed_trigger
     *
     * @effects mileage_earns_on_confirm_when_trigger_is_confirmed
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
     *
     * @return void 반환값 없음
     *
     * @scenario event=confirm_with_delivered_trigger
     *
     * @effects mileage_skips_confirm_earn_when_trigger_is_delivered
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
     *
     * @return void 반환값 없음
     *
     * @scenario event=confirm_with_delay
     *
     * @effects mileage_defers_immediate_earn_when_delay_is_positive
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
     *
     * @return void 반환값 없음
     *
     * @scenario event=delivered_with_delivered_trigger
     *
     * @effects mileage_earns_on_delivered_when_trigger_is_delivered
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
     * 이미 적립이 끝난 건은 취소 전이에서도 회수하지 않는다. (운영 결정 2026-09-23)
     *
     * 원장에 기록이 확정된 적립은 되돌리지 않는다 — 운영자가 반품·교환·취소를 늦게 처리해
     * 적립이 먼저 나간 것은 운영의 책임이고, 그 뒤 회수를 시도하면 **이미 소멸했거나 쓴 적립을
     * 살아 있는 다른 적립에서 대신 빼 오게 된다**(2026-09-22 #599 3차 실측 L-1 · T2-D13:
     * 만료로 잔여 0 이 된 1,260원 lot 을 회수하자 관리자 지급 lot 이 34,000 → 32,740 으로 깎였다).
     *
     * 부분 반품에서 적립 lot 이 분할 전 금액을 그대로 들고 있어 과다 회수되던 것도 함께 사라진다 —
     * 회수 자체를 하지 않기 때문이다. 적립 **전**이면 lot 이 없어 원래부터 no-op 이라 분기가 필요 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario event=cancel_transition_after_earn
     *
     * @effects mileage_cancel_transition_keeps_the_earned_mileage
     */
    public function test_does_not_cancel_earn_on_cancelled_status_change(): void
    {
        [$order, $option] = $this->makeOrderWithOption();
        // 먼저 적립
        $this->listener->handleAfterConfirm($order, $option);
        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'order_option_id' => $option->id,
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
        ]);

        $earnLot = MileageTransaction::query()
            ->where('order_option_id', $option->id)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->firstOrFail();

        // 취소 전이 → 회수하지 않는다
        $this->listener->handleAfterStatusChange($option, OrderStatusEnum::CANCELLED, null);

        $this->assertDatabaseMissing('ecommerce_mileage_transactions', [
            'order_option_id' => $option->id,
            'type' => MileageTransactionTypeEnum::EARN_CANCEL->value,
        ]);

        // 적립 lot 의 잔여도 그대로여야 한다 — 자기 lot 에서 빼는 것도 회수다.
        $this->assertSame(
            (float) $earnLot->remaining_amount,
            (float) $earnLot->fresh()->remaining_amount,
            '적립 lot 의 잔여가 바뀌었다 — 회수가 일어났다',
        );
    }

    /**
     * 사용 차감 핸들러가 FIFO 차감을 수행한다.
     *
     * @return void 반환값 없음
     *
     * @scenario event=use_deduction
     *
     * @effects mileage_use_deducts_the_balance_in_fifo_order
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
     *
     * @return void 반환값 없음
     *
     * @scenario event=restore_without_cancel_id
     *
     * @effects mileage_restore_is_ignored_without_a_cancel_id
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
     * 환불 현금분 지급 핸들러 — 회원 원장에 그 환불로 묶인 무기한 지급 거래를 남긴다(D1).
     *
     * @return void 반환값 없음
     *
     * @effects mileage_listener_credits_the_refund_cash_share
     */
    public function test_handle_refund_credit_credits_the_member(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);
        $refund = $this->makeRefund($order);

        $this->listener->handleRefundCredit(5000, $order, $refund->id);

        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'user_id' => $user->id,
            'order_refund_id' => $refund->id,
            'type' => MileageTransactionTypeEnum::REFUND_CREDIT->value,
            'amount' => 5000,
            'expires_at' => null,
        ]);
    }

    /**
     * 환불 현금분 지급 핸들러 — 비회원 주문이면 조용히 넘기지 않고 던진다(돈이 사라지지 않게).
     *
     * @return void 반환값 없음
     *
     * @effects mileage_listener_rejects_a_guest_refund_credit
     */
    public function test_handle_refund_credit_rejects_a_guest_order(): void
    {
        $order = Order::factory()->create(['user_id' => null, 'currency' => 'KRW']);
        $refund = $this->makeRefund($order);

        $this->expectException(MileageValidationException::class);
        $this->listener->handleRefundCredit(5000, $order, $refund->id);
    }

    /**
     * 환불신청서 한 건을 만듭니다. (`order_refund_id` 는 FK 라 실제 행이 필요하다)
     *
     * @param  Order  $order  주문
     * @return OrderRefund 환불신청서
     */
    private function makeRefund(Order $order): OrderRefund
    {
        return OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_source_id' => null,
            'refund_number' => 'RF-'.uniqid(),
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::POINTS,
            'refund_amount' => 5000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);
    }

    /**
     * getSubscribedHooks 가 5개 훅을 구독한다 (mileage.earn 미구독).
     *
     * @return void 반환값 없음
     *
     * @scenario event=subscribed_hooks
     *
     * @effects mileage_listener_subscribes_the_declared_hooks
     */
    public function test_subscribed_hooks(): void
    {
        $hooks = MileageTransactionListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.mileage.use', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.mileage.restore', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.mileage.refund_credit', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order-option.after_confirm', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order_option.after_status_change', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order_option.after_bulk_status_change', $hooks);
        // 결제완료 시점 적립 훅은 구독하지 않음 (이중 적립 방지)
        $this->assertArrayNotHasKey('sirsoft-ecommerce.mileage.earn', $hooks);
    }
}
