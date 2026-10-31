<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class PaymentRetryControllerTest extends PluginTestCase
{
    private const ENDPOINT = '/api/plugins/sirsoft-pay_nhnkcp/payment/retry';

    /**
     * 재시도 대상 주문과 결제를 만듭니다.
     *
     * 재시도는 주문 상태를 바꾸므로 주문 소유자만 호출할 수 있다(KVE-2026-2434). 기본은 회원 주문을 만들고
     * 그 회원으로 로그인한다. 소유자 확인을 검증하는 테스트는 $owner 로 로그인 여부·주문 종류를 정한다.
     *
     * @param  int  $amount  주문 금액
     * @param  OrderStatusEnum  $orderStatus  주문 상태
     * @param  PaymentStatusEnum  $paymentStatus  결제 상태
     * @param  array<string, mixed>  $orderMeta  주문 메타
     * @param  array<string, mixed>  $paymentMeta  결제 메타
     * @param  string  $owner  'login'=회원 주문+본인 로그인, 'member'=회원 주문+비로그인, 'guest'=비회원 주문
     * @return Order 생성된 주문
     */
    private function createOrder(
        int $amount = 10000,
        OrderStatusEnum $orderStatus = OrderStatusEnum::PENDING_ORDER,
        PaymentStatusEnum $paymentStatus = PaymentStatusEnum::READY,
        array $orderMeta = [],
        array $paymentMeta = [],
        string $owner = 'login',
    ): Order {
        $user = $owner === 'guest' ? null : User::factory()->create();
        if ($owner === 'login') {
            Sanctum::actingAs($user);
        }

        $order = OrderFactory::new()->create([
            'user_id' => $user?->id,
            'order_number' => 'ORD-KCP-RETRY-'.random_int(10000, 99999),
            'order_status' => $orderStatus,
            'subtotal_amount' => $amount,
            'total_amount' => $amount,
            'total_due_amount' => $amount,
            'total_paid_amount' => 0,
            'order_meta' => $orderMeta,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => $paymentStatus,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'cancelled_at' => $paymentStatus === PaymentStatusEnum::CANCELLED ? now() : null,
            'transaction_id' => null,
            'card_approval_number' => null,
            'payment_meta' => $paymentMeta,
        ]);

        return $order;
    }

    /**
     * 원화 단일 통화 스냅샷을 돌려줍니다.
     *
     * @return array 구성한 배열
     */
    private static function krwCurrencySnapshot(): array
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

    /**
     * 청구할 수 없는(환율 0) 달러 통화 스냅샷을 돌려줍니다.
     *
     * @return array 구성한 배열
     */
    private static function invalidUsdCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1000,
                ],
                'USD' => [
                    'rate' => 0,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
                    'decimal_places' => 2,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    /**
     * 이미 결제대기인 주문의 재시도 준비는 상태를 바꾸지 않고 ready 를 돌려준다.
     *
     * @effects retry_returns_ready_for_pending_order
     *
     * @return void 반환값 없음
     */
    public function test_retry_returns_ready_for_pending_order(): void
    {
        $order = $this->createOrder(10000);

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
    }

    /**
     * 청구할 수 없는 통화 설정이면 서버 오류 없이 재시도를 거부한다.
     *
     * @effects retry_rejects_invalid_payment_currency_without_server_error
     *
     * @return void 반환값 없음
     */
    public function test_retry_rejects_invalid_payment_currency_without_server_error(): void
    {
        $order = $this->createOrder(10000);
        $order->update([
            'currency' => 'USD',
            'currency_snapshot' => self::invalidUsdCurrencySnapshot(),
        ]);

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment currency is not chargeable.');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $order->payment->payment_status);
    }

    /**
     * 주문 소유자는 KCP 실패로 취소된 주문을 같은 주문번호로 다시 결제할 수 있게 되돌린다.
     *
     * @effects retry_restores_cancelled_kcp_failure_order
     *
     * @return void 반환값 없음
     */
    public function test_retry_restores_cancelled_kcp_failure_order(): void
    {
        $order = $this->createOrder(
            20000,
            OrderStatusEnum::CANCELLED,
            PaymentStatusEnum::CANCELLED,
            [
                'payment_failure_code' => 'USER_CANCEL',
                'payment_failure_message' => '사용자가 NHN KCP 결제창을 닫았습니다.',
                'payment_failed_at' => now()->subMinute()->toIso8601String(),
            ],
            [
                'failure_source' => 'nhnkcp',
                'failure_code' => 'USER_CANCEL',
                'failure_message' => 'kcp-window-closed',
                'failure_stage' => 'window_closed',
                'failed_at' => now()->subMinute()->toIso8601String(),
            ],
        );

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 20000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'restored');

        $order->refresh();
        $payment = $order->payment;
        $payment->refresh();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertArrayNotHasKey('payment_failure_code', $order->order_meta);
        $this->assertSame(1, $order->order_meta['nhnkcp_retry_count'] ?? null);
        $this->assertNotEmpty($order->order_meta['nhnkcp_retry_failures'] ?? []);

        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->cancelled_at);
        $this->assertArrayNotHasKey('failure_source', $payment->payment_meta);
        $this->assertSame(1, $payment->payment_meta['retry_count'] ?? null);
    }

    /**
     * 금액 불일치로 실패한 주문은 재시도 상태로 되돌리지 않는다.
     *
     * @effects retry_rejects_amount_mismatch_failure_order
     *
     * @return void 반환값 없음
     */
    public function test_retry_rejects_amount_mismatch_failure_order(): void
    {
        $order = $this->createOrder(
            20000,
            OrderStatusEnum::CANCELLED,
            PaymentStatusEnum::FAILED,
            [
                'payment_failure_code' => 'AMOUNT_MISMATCH',
                'payment_failure_message' => '금액 불일치',
                'payment_failed_at' => now()->subMinute()->toIso8601String(),
            ],
            [
                'failure_source' => 'nhnkcp',
                'failure_code' => 'AMOUNT_MISMATCH',
                'failure_message' => '금액 불일치',
                'failure_stage' => 'amount_mismatch',
                'failed_at' => now()->subMinute()->toIso8601String(),
            ],
        );

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 20000,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('errors.message.0', 'Order is not retryable for NHN KCP payment.');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->payment_status);
    }

    /**
     * 취소된 KCP 실패 주문과 그 실패 메타를 만듭니다 (재시도로 되살릴 수 있는 상태).
     *
     * @param  string  $owner  createOrder 의 소유 형태
     * @return Order 생성된 주문
     */
    private function createRetryableCancelledOrder(string $owner): Order
    {
        return $this->createOrder(
            20000,
            OrderStatusEnum::CANCELLED,
            PaymentStatusEnum::CANCELLED,
            [
                'payment_failure_code' => 'USER_CANCEL',
                'payment_failed_at' => now()->subMinute()->toIso8601String(),
            ],
            [
                'failure_source' => 'nhnkcp',
                'failure_code' => 'USER_CANCEL',
                'failure_stage' => 'window_closed',
                'failed_at' => now()->subMinute()->toIso8601String(),
            ],
            $owner,
        );
    }

    /**
     * 주문자 연락처를 맞춰 보내도 로그인하지 않은 요청은 남의 취소 주문을 되살리지 못한다 (KVE-2026-2434).
     *
     * @effects retry_rejects_matching_buyer_contact_without_ownership
     *
     * @return void 반환값 없음
     */
    public function test_retry_rejects_matching_buyer_contact_without_ownership(): void
    {
        $order = $this->createRetryableCancelledOrder('member');
        OrderAddressFactory::new()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]);

        $response = $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 20000,
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', 'Order ownership verification failed.');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::CANCELLED, $order->payment->payment_status);
    }

    /**
     * 다른 회원은 남의 주문을 재시도 상태로 되돌릴 수 없다.
     *
     * @effects retry_rejects_other_member
     *
     * @return void 반환값 없음
     */
    public function test_retry_rejects_other_member(): void
    {
        $order = $this->createRetryableCancelledOrder('member');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 20000,
        ])->assertStatus(403);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 비회원은 주문 생성 때 받은 조회 토큰으로 자기 주문을 재시도할 수 있다.
     *
     * @effects retry_restores_guest_order_with_order_token
     *
     * @return void 반환값 없음
     */
    public function test_retry_restores_guest_order_with_order_token(): void
    {
        $order = $this->createRetryableCancelledOrder('guest');
        $token = app(GuestOrderAuthService::class)->issueForOrder($order)['token'];

        $this->withHeader('X-Guest-Order-Token', $token)
            ->postJson(self::ENDPOINT, [
                'oid' => $order->order_number,
                'price' => 20000,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'restored');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 비회원 주문은 조회 토큰이 없으면 재시도할 수 없다.
     *
     * @effects retry_rejects_guest_order_without_token
     *
     * @return void 반환값 없음
     */
    public function test_retry_rejects_guest_order_without_token(): void
    {
        $order = $this->createRetryableCancelledOrder('guest');

        $this->postJson(self::ENDPOINT, [
            'oid' => $order->order_number,
            'price' => 20000,
        ])->assertStatus(403);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }
}
