<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Order;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderOptionNotConfirmableException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 구매확정 상태 게이트 회귀 테스트 (㉙)
 *
 * OrderService::confirmOption 자체에 상태 가드가 내장되어 호출자(회원/비회원/훅)와 무관하게
 * 강제된다: 이미 확정된 옵션 재확정 차단, 확정 가능 상태(confirmable_statuses) 미포함 차단.
 * 비회원 경로는 이 예외를 422 로 응답한다.
 */
class GuestConfirmOptionGateTest extends ModuleTestCase
{
    private const PASSWORD = 'guest12';

    private const PHONE = '010-1234-5678';

    /**
     * 테스트용 주문을 생성합니다 (금액 필드 포함 — 확정 후속 훅 안정성).
     *
     * @param  array<string, mixed>  $overrides  오버라이드 속성
     */
    private function makeOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_status' => OrderStatusEnum::DELIVERED,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ], $overrides));
    }

    /**
     * 테스트용 주문 옵션을 생성합니다.
     */
    private function makeOption(Order $order, OrderStatusEnum $status): OrderOption
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
     * 비회원 주문 + 유효 토큰을 만들어 [주문, 토큰]을 반환합니다.
     *
     * @return array{0: Order, 1: string}
     */
    private function makeGuestOrderWithToken(string $orderNumber, OrderStatusEnum $status = OrderStatusEnum::DELIVERED): array
    {
        $order = $this->makeOrder([
            'order_number' => $orderNumber,
            'order_status' => $status,
            'user_id' => null,
            'guest_lookup_password_hash' => Hash::make(self::PASSWORD),
            'is_first_order' => false,
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create([
            'orderer_phone' => self::PHONE,
        ]);

        /** @var GuestOrderAuthService $service */
        $service = app(GuestOrderAuthService::class);
        $token = $service->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.3')['token'];

        return [$order, $token];
    }

    private function guestConfirmUrl(Order $order, int $optionId): string
    {
        return "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/options/{$optionId}/confirm";
    }

    private function memberConfirmUrl(int $orderId, int $optionId): string
    {
        return "/api/modules/sirsoft-ecommerce/user/orders/{$orderId}/options/{$optionId}/confirm";
    }

    // ============ 서비스 계층 가드 (호출자 무관) ============

    /**
     * 이미 확정된 옵션을 다시 확정하면 도메인 예외를 던진다.
     *
     * @scenario case=confirm_service_gate
     *
     * @effects confirm_rejected_unless_confirmable_status, double_confirm_rejected
     */
    public function test_service_rejects_already_confirmed_option(): void
    {
        $order = $this->makeOrder(['order_status' => OrderStatusEnum::CONFIRMED]);
        $option = $this->makeOption($order, OrderStatusEnum::CONFIRMED);

        try {
            app(OrderService::class)->confirmOption($order, $option);
            $this->fail('OrderOptionNotConfirmableException 이 발생해야 한다');
        } catch (OrderOptionNotConfirmableException $e) {
            $this->assertSame('order_option_already_confirmed', $e->getReason());
        }
    }

    /**
     * 확정 가능 상태(shipping/delivered)가 아닌 옵션(preparing)은 확정이 거부된다.
     *
     * @scenario case=confirm_service_gate
     *
     * @effects confirm_rejected_unless_confirmable_status
     */
    public function test_service_rejects_non_confirmable_status(): void
    {
        $order = $this->makeOrder(['order_status' => OrderStatusEnum::PREPARING]);
        $option = $this->makeOption($order, OrderStatusEnum::PREPARING);

        try {
            app(OrderService::class)->confirmOption($order, $option);
            $this->fail('OrderOptionNotConfirmableException 이 발생해야 한다');
        } catch (OrderOptionNotConfirmableException $e) {
            $this->assertSame('order_option_not_confirmable', $e->getReason());
        }
    }

    /**
     * 확정 가능 상태(delivered)는 확정되고 after_confirm 훅은 1회만 발화한다.
     * 이후 재확정은 already_confirmed 로 거부되어 훅이 다시 발화하지 않는다.
     *
     * @scenario case=confirm_service_gate
     *
     * @effects confirm_hook_fires_once
     */
    public function test_service_confirms_delivered_and_fires_after_confirm_hook_once(): void
    {
        $hookCount = 0;
        HookManager::addAction(
            'sirsoft-ecommerce.order-option.after_confirm',
            function () use (&$hookCount) {
                $hookCount++;
            }
        );

        $order = $this->makeOrder(['order_status' => OrderStatusEnum::DELIVERED]);
        $option = $this->makeOption($order, OrderStatusEnum::DELIVERED);

        $service = app(OrderService::class);

        $service->confirmOption($order, $option);
        $this->assertSame(1, $hookCount);

        // 재확정 시도 → 예외 + 훅 미발화
        $option->refresh();
        try {
            $service->confirmOption($order->fresh(), $option);
            $this->fail('재확정은 거부되어야 한다');
        } catch (OrderOptionNotConfirmableException $e) {
            $this->assertSame('order_option_already_confirmed', $e->getReason());
        }

        $this->assertSame(1, $hookCount);
    }

    // ============ 비회원 HTTP 경로 ============

    /**
     * 비회원: 배송 전(preparing) 옵션 확정 시 422.
     *
     * @scenario case=confirm_guest_http
     *
     * @effects confirm_rejected_unless_confirmable_status
     */
    public function test_guest_confirm_preparing_returns_422(): void
    {
        [$order, $token] = $this->makeGuestOrderWithToken('ORD-CF-1', OrderStatusEnum::PREPARING);
        $option = $this->makeOption($order, OrderStatusEnum::PREPARING);

        $this->postJson($this->guestConfirmUrl($order, $option->id), [], ['X-Guest-Order-Token' => $token])
            ->assertStatus(422);
    }

    /**
     * 비회원: 확정 가능 옵션의 이중 확정 시 두 번째 요청은 422.
     *
     * @scenario case=confirm_guest_http
     *
     * @effects double_confirm_rejected
     */
    public function test_guest_double_confirm_returns_422_on_second(): void
    {
        [$order, $token] = $this->makeGuestOrderWithToken('ORD-CF-2', OrderStatusEnum::DELIVERED);
        $option = $this->makeOption($order, OrderStatusEnum::DELIVERED);

        $this->postJson($this->guestConfirmUrl($order, $option->id), [], ['X-Guest-Order-Token' => $token])
            ->assertStatus(200);

        $this->postJson($this->guestConfirmUrl($order, $option->id), [], ['X-Guest-Order-Token' => $token])
            ->assertStatus(422);
    }

    // ============ 회원 HTTP 경로 ============

    /**
     * 회원: 배송 전(preparing) 옵션 확정 시 422.
     *
     * @scenario case=confirm_member_http
     *
     * @effects confirm_rejected_unless_confirmable_status
     */
    public function test_member_confirm_preparing_returns_422(): void
    {
        $user = $this->createUser();
        $order = $this->makeOrder([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PREPARING,
        ]);
        $option = $this->makeOption($order, OrderStatusEnum::PREPARING);

        $this->actingAs($user)
            ->postJson($this->memberConfirmUrl($order->id, $option->id))
            ->assertStatus(422);
    }
}
