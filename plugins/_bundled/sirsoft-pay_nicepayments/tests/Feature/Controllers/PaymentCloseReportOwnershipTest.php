<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 나이스페이먼츠 결제창 닫힘 보고(close-report) 주문 소유자 확인 회귀 (KVE-2026-2434)
 *
 * close-report 는 PG 서명 없이 브라우저가 보내는 요청으로 결제대기 주문을 취소한다. 그 자격은
 * 주문 소유자 인증(회원 본인 로그인 / 비회원 조회 토큰)만이 줄 수 있다 — 주문자 이메일·전화번호는
 * 제3자도 알 수 있으므로, 그것을 맞춰 보내도 남의 주문은 절대 바뀌지 않아야 한다.
 *
 * 모든 거부 경로는 주문·결제 상태가 요청 전과 같음을 DB 로 확인한다.
 */
class PaymentCloseReportOwnershipTest extends PluginTestCase
{
    private const ROUTE = '/api/plugins/sirsoft-pay_nicepayments/payment/close-report';

    private const BUYER_EMAIL = 'owner@example.com';

    private const BUYER_PHONE = '010-2434-2434';

    /**
     * 비로그인 요청이 주문자 연락처를 정확히 맞춰 보내도 거부되고 주문은 그대로다.
     *
     * 수정 전에는 이 요청이 남의 결제대기 주문을 취소시켰다.
     *
     * @effects unauthenticated_request_with_matching_buyer_contact_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unauthenticated_request_with_matching_buyer_contact_is_rejected(): void
    {
        $order = $this->makePendingOrder('ORD-2434-PII');

        $response = $this->postJson(self::ROUTE, $this->payload($order) + [
            'buyer_email' => self::BUYER_EMAIL,
            'buyer_phone' => self::BUYER_PHONE,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', 'Order ownership verification failed.');
        $this->assertOrderUnchanged($order);
    }

    /**
     * 배송지가 없는 주문도 소유자 확인 없이는 거부된다.
     *
     * 종전 대조는 배송지가 없으면 무조건 통과시켰다.
     *
     * @effects order_without_address_is_rejected_without_ownership
     *
     * @return void 반환값 없음
     */
    public function test_order_without_address_is_rejected_without_ownership(): void
    {
        $order = $this->makePendingOrder('ORD-2434-NOADDR', withAddress: false);

        $this->postJson(self::ROUTE, $this->payload($order))->assertStatus(403);

        $this->assertOrderUnchanged($order);
    }

    /**
     * 다른 회원이 로그인해 남의 회원 주문을 보고하면 거부된다.
     *
     * @effects other_member_cannot_report_close_of_member_order
     *
     * @return void 반환값 없음
     */
    public function test_other_member_cannot_report_close_of_member_order(): void
    {
        $owner = User::factory()->create();
        $order = $this->makePendingOrder('ORD-2434-OTHER', userId: $owner->id);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson(self::ROUTE, $this->payload($order))->assertStatus(403);

        $this->assertOrderUnchanged($order);
    }

    /**
     * 비로그인 상태로 회원 주문을 보고하면 비회원 토큰을 붙여도 거부된다.
     *
     * @effects member_order_cannot_be_reported_without_login
     *
     * @return void 반환값 없음
     */
    public function test_member_order_cannot_be_reported_without_login(): void
    {
        $owner = User::factory()->create();
        $order = $this->makePendingOrder('ORD-2434-MEMBER-GUEST', userId: $owner->id);

        $this->withHeader('X-Guest-Order-Token', $this->forgedToken())
            ->postJson(self::ROUTE, $this->payload($order))
            ->assertStatus(403);

        $this->assertOrderUnchanged($order);
    }

    /**
     * 주문한 회원 본인은 결제창 닫힘을 보고할 수 있고, 주문은 취소된다.
     *
     * @effects member_owner_can_report_close
     *
     * @return void 반환값 없음
     */
    public function test_member_owner_can_report_close(): void
    {
        $owner = User::factory()->create();
        $order = $this->makePendingOrder('ORD-2434-OWNER', userId: $owner->id);

        Sanctum::actingAs($owner);

        $this->postJson(self::ROUTE, $this->payload($order))
            ->assertOk()
            ->assertJsonPath('data.status', 'recorded');

        $this->assertOrderCancelled($order);
    }

    /**
     * 주문 생성 응답으로 받은 조회 토큰을 가진 비회원은 보고할 수 있고, 주문은 취소된다.
     *
     * @effects guest_with_order_token_can_report_close
     *
     * @return void 반환값 없음
     */
    public function test_guest_with_order_token_can_report_close(): void
    {
        $order = $this->makePendingOrder('ORD-2434-GUEST');
        $token = app(GuestOrderAuthService::class)->issueForOrder($order)['token'];

        $this->withHeader('X-Guest-Order-Token', $token)
            ->postJson(self::ROUTE, $this->payload($order))
            ->assertOk()
            ->assertJsonPath('data.status', 'recorded');

        $this->assertOrderCancelled($order);
    }

    /**
     * 다른 주문의 조회 토큰으로는 이 주문을 보고할 수 없다.
     *
     * @effects guest_token_of_another_order_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_guest_token_of_another_order_is_rejected(): void
    {
        $victim = $this->makePendingOrder('ORD-2434-VICTIM');
        $attackerOrder = $this->makePendingOrder('ORD-2434-ATTACKER');
        $attackerToken = app(GuestOrderAuthService::class)->issueForOrder($attackerOrder)['token'];

        $this->withHeader('X-Guest-Order-Token', $attackerToken)
            ->postJson(self::ROUTE, $this->payload($victim))
            ->assertStatus(403);

        $this->assertOrderUnchanged($victim);
    }

    /**
     * 만료된 조회 토큰은 거부된다.
     *
     * @effects expired_guest_token_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_expired_guest_token_is_rejected(): void
    {
        $order = $this->makePendingOrder('ORD-2434-EXPIRED');
        $token = app(GuestOrderAuthService::class)->issueForOrder($order)['token'];

        $this->travel(GuestOrderAuthService::TOKEN_TTL_MINUTES + 1)->minutes();

        $this->withHeader('X-Guest-Order-Token', $token)
            ->postJson(self::ROUTE, $this->payload($order))
            ->assertStatus(403);

        $this->assertOrderUnchanged($order);
    }

    /**
     * 형식만 맞춘 위조 토큰은 거부된다.
     *
     * @effects forged_guest_token_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_forged_guest_token_is_rejected(): void
    {
        $order = $this->makePendingOrder('ORD-2434-FORGED');

        $this->withHeader('X-Guest-Order-Token', $this->forgedToken())
            ->postJson(self::ROUTE, $this->payload($order))
            ->assertStatus(403);

        $this->assertOrderUnchanged($order);
    }

    /**
     * 결제대기 주문과 결제(READY)·배송지를 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  int|null  $userId  회원 주문이면 회원 ID, 비회원이면 null
     * @param  bool  $withAddress  주문자 연락처를 담은 배송지 생성 여부
     * @return Order 생성된 주문
     */
    private function makePendingOrder(string $orderNumber, ?int $userId = null, bool $withAddress = true): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => $userId,
            'order_number' => $orderNumber,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::ownershipKrwSnapshot(),
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'transaction_id' => null,
            'paid_amount_local' => 0,
            'paid_at' => null,
        ]);

        if ($withAddress) {
            OrderAddress::create([
                'order_id' => $order->id,
                'address_type' => 'shipping',
                'orderer_name' => '홍길동',
                'orderer_phone' => self::BUYER_PHONE,
                'orderer_email' => self::BUYER_EMAIL,
                'recipient_name' => '홍길동',
                'recipient_phone' => self::BUYER_PHONE,
                'zipcode' => '06234',
                'address' => '서울시 강남구',
                'address_detail' => '101호',
            ]);
        }

        return $order->fresh(['payment', 'shippingAddress']);
    }

    /**
     * close-report 요청 본문을 만듭니다 (소유자 증명은 헤더·로그인으로만).
     *
     * @param  Order  $order  대상 주문
     * @return array<string, mixed> 요청 본문
     */
    private function payload(Order $order): array
    {
        return [
            'oid' => $order->order_number,
            'price' => 10000,
            'reason' => 'window-closed',
        ];
    }

    /**
     * 형식은 맞지만 서명이 틀린 조회 토큰을 돌려줍니다.
     *
     * @return string 위조 토큰
     */
    private function forgedToken(): string
    {
        return now()->addMinutes(10)->getTimestamp().'|'.str_repeat('a', 64);
    }

    /**
     * 거부된 요청 뒤에도 주문·결제 상태가 결제대기 그대로인지 확인합니다.
     *
     * @param  Order  $order  대상 주문
     * @return void 반환값 없음
     */
    private function assertOrderUnchanged(Order $order): void
    {
        $fresh = $order->fresh('payment');

        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $fresh->order_status);
        $this->assertSame(PaymentStatusEnum::READY, $fresh->payment->payment_status);
    }

    /**
     * 결제창 닫힘이 주문·결제 취소로 반영됐는지 확인합니다.
     *
     * @param  Order  $order  대상 주문
     * @return void 반환값 없음
     */
    private function assertOrderCancelled(Order $order): void
    {
        $fresh = $order->fresh('payment');

        $this->assertSame(OrderStatusEnum::CANCELLED, $fresh->order_status);
        // 결제창 닫힘은 다른 PG 와 같이 결제 취소(CANCELLED)와 취소 이력 1건으로 남는다.
        $this->assertSame(PaymentStatusEnum::CANCELLED, $fresh->payment->payment_status);
        $this->assertCount(1, $fresh->payment->cancel_history ?? []);
    }

    /**
     * 원화 단일 통화 스냅샷을 돌려줍니다.
     *
     * @return array<string, mixed> 통화 스냅샷
     */
    private static function ownershipKrwSnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1,
                ],
            ],
        ];
    }
}
