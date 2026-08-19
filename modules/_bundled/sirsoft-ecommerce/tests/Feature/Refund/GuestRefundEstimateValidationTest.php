<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Refund;

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 환불예상 per-item 검증 회귀 테스트 (㉒)
 *
 * 비회원 환불예상(estimate-refund)도 회원판과 동일 강도로 각 항목이 대상 주문에
 * 속하는지, 취소 수량이 보유 수량을 넘지 않는지 검증해야 한다.
 */
class GuestRefundEstimateValidationTest extends ModuleTestCase
{
    private const PASSWORD = 'guest12';

    private const PHONE = '010-1234-5678';

    /**
     * 비회원 주문과 유효한 조회 토큰을 만들어 [주문, 토큰]을 반환합니다.
     *
     * @return array{0: Order, 1: string}
     */
    private function makeGuestOrderWithToken(string $orderNumber): array
    {
        $order = Order::factory()->forGuest()->create([
            'order_number' => $orderNumber,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'guest_lookup_password_hash' => Hash::make(self::PASSWORD),
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create([
            'orderer_phone' => self::PHONE,
        ]);

        /** @var GuestOrderAuthService $service */
        $service = app(GuestOrderAuthService::class);
        $token = $service->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.1')['token'];

        return [$order, $token];
    }

    private function estimateUrl(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/estimate-refund";
    }

    /**
     * 다른 주문에 속한 옵션 id 를 넘기면 422 (스코프 위반).
     *
     * @scenario case=guest_refund_estimate_validation
     *
     * @effects guest_refund_items_validated_per_item
     */
    public function test_foreign_option_id_is_rejected_422(): void
    {
        [$order, $token] = $this->makeGuestOrderWithToken('ORD-EST-1');

        // 다른 주문의 옵션
        $otherOrder = Order::factory()->forGuest()->create(['order_number' => 'ORD-EST-OTHER']);
        $foreignOption = OrderOption::factory()->forOrder($otherOrder)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => 3,
        ]);

        $response = $this->postJson(
            $this->estimateUrl($order),
            ['items' => [['order_option_id' => $foreignOption->id, 'cancel_quantity' => 1]]],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.order_option_id']);
    }

    /**
     * 보유 수량을 초과하는 취소 수량은 422.
     *
     * @scenario case=guest_refund_estimate_validation
     *
     * @effects guest_refund_items_validated_per_item
     */
    public function test_over_quantity_is_rejected_422(): void
    {
        [$order, $token] = $this->makeGuestOrderWithToken('ORD-EST-2');

        $option = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => 2,
        ]);

        $response = $this->postJson(
            $this->estimateUrl($order),
            ['items' => [['order_option_id' => $option->id, 'cancel_quantity' => 5]]],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.cancel_quantity']);
    }
}
