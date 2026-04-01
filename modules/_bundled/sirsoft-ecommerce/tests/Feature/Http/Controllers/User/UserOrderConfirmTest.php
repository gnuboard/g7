<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사용자 주문 구매확정 API Feature 테스트
 *
 * 마이페이지에서 주문 옵션의 구매확정 처리를 테스트합니다.
 */
class UserOrderConfirmTest extends ModuleTestCase
{
    /**
     * 구매확정 API 경로를 반환합니다.
     *
     * @param int $orderId 주문 ID
     * @param int $optionId 옵션 ID
     * @return string
     */
    private function confirmUrl(int $orderId, int $optionId): string
    {
        return "/api/modules/sirsoft-ecommerce/user/orders/{$orderId}/options/{$optionId}/confirm";
    }

    /**
     * 테스트용 주문을 생성합니다.
     *
     * @param int $userId 사용자 ID
     * @param OrderStatusEnum $status 주문 상태
     * @return Order
     */
    private function createOrder(int $userId, OrderStatusEnum $status = OrderStatusEnum::SHIPPING): Order
    {
        return Order::factory()->create([
            'user_id' => $userId,
            'order_status' => $status,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);
    }

    /**
     * 테스트용 주문 옵션을 생성합니다.
     *
     * @param Order $order 주문 모델
     * @param OrderStatusEnum $status 옵션 상태
     * @return OrderOption
     */
    private function createOption(Order $order, OrderStatusEnum $status = OrderStatusEnum::SHIPPING): OrderOption
    {
        return OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 15000,
            'subtotal_price' => 15000,
            'subtotal_paid_amount' => 15000,
            'option_status' => $status,
        ]);
    }

    /**
     * 배송중 상태 옵션 구매확정 성공 테스트
     *
     * @return void
     */
    public function test_confirm_option_success_with_shipping_status(): void
    {
        $hookCalled = false;
        HookManager::addAction('sirsoft-ecommerce.order-option.after_confirm', function () use (&$hookCalled) {
            $hookCalled = true;
        }, 10);

        $user = $this->createUser();
        $order = $this->createOrder($user->id, OrderStatusEnum::SHIPPING);
        $option = $this->createOption($order, OrderStatusEnum::SHIPPING);

        $response = $this->actingAs($user)->postJson(
            $this->confirmUrl($order->id, $option->id)
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $option->refresh();
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $option->option_status);
        $this->assertNotNull($option->confirmed_at);
        $this->assertTrue($hookCalled, 'after_confirm 훅이 실행되어야 합니다.');
    }

    /**
     * 배송완료 상태 옵션 구매확정 성공 테스트
     *
     * @return void
     */
    public function test_confirm_option_success_with_delivered_status(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user->id, OrderStatusEnum::DELIVERED);
        $option = $this->createOption($order, OrderStatusEnum::DELIVERED);

        $response = $this->actingAs($user)->postJson(
            $this->confirmUrl($order->id, $option->id)
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $option->refresh();
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $option->option_status);
        $this->assertNotNull($option->confirmed_at);
    }

    /**
     * 본인 주문이 아닌 경우 구매확정 실패 테스트
     *
     * @return void
     */
    public function test_confirm_option_fails_for_other_users_order(): void
    {
        $owner = $this->createUser();
        $otherUser = $this->createUser();
        $order = $this->createOrder($owner->id, OrderStatusEnum::SHIPPING);
        $option = $this->createOption($order, OrderStatusEnum::SHIPPING);

        $response = $this->actingAs($otherUser)->postJson(
            $this->confirmUrl($order->id, $option->id)
        );

        $response->assertNotFound();
    }

    /**
     * 이미 구매확정된 옵션 재확정 시 실패 테스트
     *
     * @return void
     */
    public function test_confirm_option_fails_when_already_confirmed(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user->id, OrderStatusEnum::CONFIRMED);
        $option = $this->createOption($order, OrderStatusEnum::CONFIRMED);

        $response = $this->actingAs($user)->postJson(
            $this->confirmUrl($order->id, $option->id)
        );

        $response->assertUnprocessable();
    }

    /**
     * confirmable_statuses 설정에 해당하지 않는 상태 구매확정 실패 테스트
     *
     * @return void
     */
    public function test_confirm_option_fails_for_non_confirmable_status(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user->id, OrderStatusEnum::PAYMENT_COMPLETE);
        $option = $this->createOption($order, OrderStatusEnum::PAYMENT_COMPLETE);

        $response = $this->actingAs($user)->postJson(
            $this->confirmUrl($order->id, $option->id)
        );

        $response->assertUnprocessable();
    }

    /**
     * 모든 비취소 옵션이 확정되면 주문도 CONFIRMED 전환 테스트
     *
     * @return void
     */
    public function test_order_becomes_confirmed_when_all_options_confirmed(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user->id, OrderStatusEnum::DELIVERED);

        $option1 = $this->createOption($order, OrderStatusEnum::DELIVERED);
        $option2 = $this->createOption($order, OrderStatusEnum::DELIVERED);

        // 첫 번째 옵션 확정 — 주문은 아직 CONFIRMED 아님
        $this->actingAs($user)->postJson(
            $this->confirmUrl($order->id, $option1->id)
        )->assertOk();

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::CONFIRMED, $order->order_status);

        // 두 번째 옵션 확정 — 주문도 CONFIRMED
        $this->actingAs($user)->postJson(
            $this->confirmUrl($order->id, $option2->id)
        )->assertOk();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $order->order_status);
        $this->assertNotNull($order->confirmed_at);
    }

    /**
     * 취소된 옵션이 있어도 나머지 모두 확정 시 주문 CONFIRMED 전환 테스트
     *
     * @return void
     */
    public function test_order_confirmed_ignoring_cancelled_options(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user->id, OrderStatusEnum::DELIVERED);

        $activeOption = $this->createOption($order, OrderStatusEnum::DELIVERED);
        // 취소된 옵션
        $this->createOption($order, OrderStatusEnum::CANCELLED);

        $this->actingAs($user)->postJson(
            $this->confirmUrl($order->id, $activeOption->id)
        )->assertOk();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $order->order_status);
    }

    /**
     * confirmed_at 타임스탬프 기록 확인 테스트
     *
     * @return void
     */
    public function test_confirmed_at_timestamp_is_recorded(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user->id, OrderStatusEnum::SHIPPING);
        $option = $this->createOption($order, OrderStatusEnum::SHIPPING);

        $this->actingAs($user)->postJson(
            $this->confirmUrl($order->id, $option->id)
        )->assertOk();

        $option->refresh();
        $this->assertNotNull($option->confirmed_at);
        $this->assertEqualsWithDelta(now()->timestamp, $option->confirmed_at->timestamp, 5);
    }

    /**
     * 비인증 사용자 구매확정 시 401 반환 테스트
     *
     * @return void
     */
    public function test_confirm_option_requires_authentication(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user->id, OrderStatusEnum::SHIPPING);
        $option = $this->createOption($order, OrderStatusEnum::SHIPPING);

        $response = $this->postJson(
            $this->confirmUrl($order->id, $option->id)
        );

        $response->assertUnauthorized();
    }

    /**
     * 존재하지 않는 주문에 대한 구매확정 실패 테스트
     *
     * @return void
     */
    public function test_confirm_option_fails_for_nonexistent_order(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->postJson(
            $this->confirmUrl(99999, 99999)
        );

        $response->assertNotFound();
    }

    /**
     * 존재하지 않는 옵션에 대한 구매확정 실패 테스트
     *
     * @return void
     */
    public function test_confirm_option_fails_for_nonexistent_option(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user->id, OrderStatusEnum::SHIPPING);

        $response = $this->actingAs($user)->postJson(
            $this->confirmUrl($order->id, 99999)
        );

        $response->assertNotFound();
    }
}
