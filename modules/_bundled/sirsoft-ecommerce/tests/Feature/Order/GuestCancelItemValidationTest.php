<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Order;

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 주문취소 per-item 검증 회귀 테스트 (N-1)
 *
 * 비회원 부분취소도 회원판 CancelOrderRequest 와 동일 강도로 각 항목이 대상 주문에
 * 속하는지, 취소 수량이 보유 수량을 넘지 않는지 검증해야 한다.
 */
class GuestCancelItemValidationTest extends ModuleTestCase
{
    private const PASSWORD = 'guest12';

    private const PHONE = '010-1234-5678';

    /**
     * 취소 가능한 비회원 주문 + 유효 토큰을 만들어 [주문, 토큰]을 반환합니다.
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
        $token = $service->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.2')['token'];

        return [$order, $token];
    }

    /**
     * 사용자가 선택 가능한 refund 사유 코드를 반환합니다.
     */
    private function refundReasonCode(): string
    {
        return (string) ClaimReason::where('type', 'refund')
            ->where('is_active', true)
            ->where('is_user_selectable', true)
            ->value('code');
    }

    private function cancelUrl(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/cancel";
    }

    /**
     * 다른 주문에 속한 옵션 id 로 부분취소를 시도하면 422.
     *
     * @scenario case=guest_cancel_validation
     *
     * @effects guest_cancel_items_validated_per_item
     */
    public function test_foreign_option_id_is_rejected_422(): void
    {
        [$order, $token] = $this->makeGuestOrderWithToken('ORD-GC-1');

        $otherOrder = Order::factory()->forGuest()->create(['order_number' => 'ORD-GC-OTHER']);
        $foreignOption = OrderOption::factory()->forOrder($otherOrder)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => 3,
        ]);

        $response = $this->postJson(
            $this->cancelUrl($order),
            [
                'reason' => $this->refundReasonCode(),
                'items' => [['order_option_id' => $foreignOption->id, 'cancel_quantity' => 1]],
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.order_option_id']);
    }

    /**
     * 보유 수량을 초과하는 부분취소 수량은 422.
     *
     * @scenario case=guest_cancel_validation
     *
     * @effects guest_cancel_items_validated_per_item
     */
    public function test_over_quantity_is_rejected_422(): void
    {
        [$order, $token] = $this->makeGuestOrderWithToken('ORD-GC-2');

        $option = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => 2,
        ]);

        $response = $this->postJson(
            $this->cancelUrl($order),
            [
                'reason' => $this->refundReasonCode(),
                'items' => [['order_option_id' => $option->id, 'cancel_quantity' => 5]],
            ],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.cancel_quantity']);
    }
}
