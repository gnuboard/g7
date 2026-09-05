<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http;

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 결제취소 기록 인증 회귀 테스트 (KVE-2026-2041).
 *
 * 회귀 배경: `orders/{orderNumber}/cancel-payment` 은 optional.sanctum 로 회원/비회원을
 * 공유하는데, 소유권 검증이 `user_id !== null` 일 때만 수행됐다. 비회원 주문(user_id=null)은
 * 그 분기를 통과해 **주문번호만 아는 익명 요청**이 결제를 cancelled 로 영속 변경할 수 있었다.
 * 보호된 게스트 경로(`guest/orders/*`)와 동일하게 X-Guest-Order-Token 을 요구해야 한다.
 *
 * @group ecommerce
 * @group security
 */
class GuestCancelPaymentAuthTest extends ModuleTestCase
{
    private const PASSWORD = 'guest12';

    private const PHONE = '010-5555-6666';

    /**
     * 결제취소 기록 엔드포인트 URL 을 만듭니다.
     */
    private function cancelPaymentUrl(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment";
    }

    /**
     * 결제 대기 상태의 비회원 주문 + 유효 토큰을 만듭니다.
     *
     * @return array{0: Order, 1: string}
     */
    private function makeGuestOrderWithToken(string $orderNumber): array
    {
        $order = Order::factory()->forGuest()->create([
            'order_number' => $orderNumber,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'guest_lookup_password_hash' => Hash::make(self::PASSWORD),
        ]);

        OrderAddress::factory()->shipping()->forOrder($order)->create([
            'orderer_phone' => self::PHONE,
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        /** @var GuestOrderAuthService $service */
        $service = app(GuestOrderAuthService::class);
        $token = $service->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.9')['token'];

        return [$order, $token];
    }

    /**
     * 비회원 주문에 토큰 없이 익명 요청하면 404 이고 결제 상태가 변하지 않는다.
     */
    public function test_guest_order_without_token_is_rejected(): void
    {
        [$order] = $this->makeGuestOrderWithToken('ORD-CP-NOTOKEN');

        $response = $this->postJson($this->cancelPaymentUrl($order), [
            'cancel_code' => 'USER_CANCEL',
        ]);

        $response->assertStatus(404);

        $this->assertSame(
            PaymentStatusEnum::READY->value,
            $order->fresh()->payment->payment_status->value,
            '차단된 요청은 결제 상태를 바꾸지 않아야 한다'
        );
    }

    /**
     * 위조/무효 토큰도 차단된다.
     */
    public function test_guest_order_with_invalid_token_is_rejected(): void
    {
        [$order] = $this->makeGuestOrderWithToken('ORD-CP-BADTOKEN');

        $response = $this->postJson(
            $this->cancelPaymentUrl($order),
            ['cancel_code' => 'USER_CANCEL'],
            ['X-Guest-Order-Token' => '9999999999|'.str_repeat('a', 64)]
        );

        $response->assertStatus(404);
        $this->assertSame(
            PaymentStatusEnum::READY->value,
            $order->fresh()->payment->payment_status->value
        );
    }

    /**
     * 다른 비회원 주문으로 발급된 토큰은 재사용할 수 없다.
     */
    public function test_guest_token_of_other_order_is_rejected(): void
    {
        [$order] = $this->makeGuestOrderWithToken('ORD-CP-TARGET');
        [, $otherToken] = $this->makeGuestOrderWithToken('ORD-CP-OTHER');

        $response = $this->postJson(
            $this->cancelPaymentUrl($order),
            ['cancel_code' => 'USER_CANCEL'],
            ['X-Guest-Order-Token' => $otherToken]
        );

        $response->assertStatus(404);
        $this->assertSame(
            PaymentStatusEnum::READY->value,
            $order->fresh()->payment->payment_status->value
        );
    }

    /**
     * 유효한 게스트 토큰이면 정상 취소된다 (정상 흐름 불변).
     */
    public function test_guest_order_with_valid_token_succeeds(): void
    {
        [$order, $token] = $this->makeGuestOrderWithToken('ORD-CP-VALID');

        $response = $this->postJson(
            $this->cancelPaymentUrl($order),
            ['cancel_code' => 'USER_CANCEL', 'cancel_message' => '사용자가 결제를 취소했습니다.'],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertSame(
            PaymentStatusEnum::CANCELLED->value,
            $order->fresh()->payment->payment_status->value
        );
    }

    /**
     * 회원 주문의 소유자는 게스트 토큰 없이 그대로 통과한다 (회귀 방지).
     */
    public function test_member_owner_still_succeeds_without_guest_token(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        $response = $this->postJson($this->cancelPaymentUrl($order), [
            'cancel_code' => 'USER_CANCEL',
        ]);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertSame(
            PaymentStatusEnum::CANCELLED->value,
            $order->fresh()->payment->payment_status->value
        );
    }

    /**
     * 회원 주문에 타인이 접근하면 404 (기존 동작 보존).
     */
    public function test_member_order_of_another_user_is_rejected(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($other)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        $response = $this->postJson($this->cancelPaymentUrl($order));

        $response->assertStatus(404);
        $this->assertSame(
            PaymentStatusEnum::READY->value,
            $order->fresh()->payment->payment_status->value
        );
    }

    /**
     * 존재하지 않는 주문번호는 404 (정보 노출 차단 — 동일 문구).
     */
    public function test_unknown_order_number_is_rejected(): void
    {
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/orders/ORD-CP-NOPE/cancel-payment'
        );

        $response->assertStatus(404);
    }
}
