<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class PaymentCloseReportControllerTest extends PluginTestCase
{
    /**
     * 주문 소유자의 닫힘 보고는 결제대기 주문을 실패·취소로 기록한다.
     *
     * @effects close_report_marks_pending_order_failed_and_payment_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_close_report_marks_pending_order_failed_and_payment_cancelled(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-001', 10000);
        $this->actAsOrderOwner($order);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-001')
            ->andReturn($order);
        $orderService->shouldReceive('failPayment')
            ->once()
            ->with($order, 'USER_CANCEL', '사용자가 NHN KCP 결제창을 닫았습니다.')
            ->andReturn($order);
        $orderService->shouldReceive('recordPaymentCancellation')
            ->once()
            ->with($order, 'USER_CANCEL', 'kcp-window-closed')
            ->andReturn($order);

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-001',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'kcp-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'recorded');
    }

    /**
     * 보고 금액이 주문 청구액과 다르면 거부한다.
     *
     * @effects close_report_rejects_amount_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_amount_mismatch(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-002', 10000);
        $this->actAsOrderOwner($order);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-002')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-002',
            'price' => 9000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment amount does not match the order amount.');
    }

    /**
     * 청구할 수 없는 통화 설정이면 서버 오류 없이 422 로 거부한다.
     *
     * @effects close_report_rejects_invalid_payment_currency_without_server_error
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_invalid_payment_currency_without_server_error(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-CURRENCY-001', 10000);
        $order->currency = 'USD';
        $order->currency_snapshot = self::invalidUsdCurrencySnapshot();
        $this->actAsOrderOwner($order);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-CURRENCY-001')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-CURRENCY-001',
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment currency is not chargeable.');
    }

    /**
     * 주문자 연락처를 정확히 맞춰 보내도 소유자 인증이 없으면 거부된다 (KVE-2026-2434).
     *
     * 연락처는 제3자도 알 수 있어 소유 증명이 아니다. 종전에는 이 요청이 주문을 취소시켰다.
     *
     * @effects close_report_rejects_matching_buyer_contact_without_ownership
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_matching_buyer_contact_without_ownership(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-003', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-003')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-003',
            'price' => 10000,
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', 'Order ownership verification failed.');
    }

    /**
     * 이미 결제할 수 없는 주문의 닫힘 보고는 무시한다.
     *
     * @effects close_report_ignores_order_that_is_no_longer_payable
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_order_that_is_no_longer_payable(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-004', 10000);
        $order->order_status = OrderStatusEnum::PAYMENT_COMPLETE;

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-004')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-004',
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'order_not_payable');
    }

    /**
     * 결제가 이미 성공한 주문의 닫힘 보고는 무시한다 (승인 콜백과의 경쟁).
     *
     * @effects close_report_ignores_order_when_payment_already_paid
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_order_when_payment_already_paid(): void
    {
        // race 재현: 승인 콜백이 payment 를 PAID 로 갱신했고 결제 예정액은 이미 0원이 됐으나,
        // order_status 관계가 아직 PENDING_ORDER 로 관측되는 순간에도 close-report 는 금액 불일치가
        // 아니라 결제 성공으로 판단해 무시해야 한다.
        $order = $this->makeOrder('ORD-KCP-CLOSE-PAID-001', 0);
        $order->setRelation('payment', new OrderPayment([
            'payment_status' => PaymentStatusEnum::PAID,
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-PAID-001')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-PAID-001',
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'payment_already_paid');
    }

    /**
     * 가상계좌가 이미 발급된(입금대기) 주문의 닫힘 보고는 무시한다 — 발급 콜백과 경쟁해 늦게 온 보고가
     * 발급된 주문을 실패·취소로 덮으면 고객은 받은 계좌로 입금할 수 없게 된다.
     *
     * @effects close_report_ignores_issued_vbank_waiting_deposit
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_issued_vbank_waiting_deposit(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-VBANK-001', 10000);
        $order->order_status = OrderStatusEnum::PENDING_PAYMENT;
        $order->setRelation('payment', new OrderPayment([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
        ]));
        $this->actAsOrderOwner($order);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-VBANK-001')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-VBANK-001',
            'price' => 10000,
            'payment_method' => 'vbank',
            'reason' => 'kcp-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'payment_not_ready');
    }

    /**
     * 주문을 회원 소유로 만들고 그 회원으로 로그인합니다.
     *
     * 소유자 확인이 이 테스트의 관심사가 아닐 때 게이트를 정당하게 통과시키기 위한 준비다.
     * 소유자 확인 자체는 PaymentCloseReportOwnershipTest 가 검증한다.
     *
     * @param  Order  $order  대상 주문 (저장 여부 무관)
     * @return User 로그인한 소유 회원
     */
    private function actAsOrderOwner(Order $order): User
    {
        $owner = User::factory()->create();
        $order->user_id = $owner->id;
        Sanctum::actingAs($owner);

        return $owner;
    }

    /**
     * 저장하지 않은 결제대기 주문 모델을 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  int  $amount  금액
     * @return Order 생성한 주문
     */
    private function makeOrder(string $orderNumber, int $amount): Order
    {
        $order = new Order;
        $order->order_number = $orderNumber;
        $order->order_status = OrderStatusEnum::PENDING_ORDER;
        $order->currency = 'KRW';
        $order->currency_snapshot = self::krwCurrencySnapshot();
        $order->total_due_amount = $amount;

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
}
