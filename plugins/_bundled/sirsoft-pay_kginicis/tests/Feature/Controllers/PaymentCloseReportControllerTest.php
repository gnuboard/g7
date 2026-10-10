<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

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
        $order = $this->makeOrder('ORD-CLOSE-001', 10000);
        $this->actAsOrderOwner($order);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-001')
            ->andReturn($order);
        $orderService->shouldReceive('failPayment')
            ->once()
            ->with($order, 'USER_CANCEL', '사용자가 KG 이니시스 결제창을 닫았습니다.')
            ->andReturn($order);
        $orderService->shouldReceive('recordPaymentCancellation')
            ->once()
            ->with($order, 'USER_CANCEL', 'inicis-overlay-closed')
            ->andReturn($order);

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-001',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'inicis-overlay-closed',
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
        $order = $this->makeOrder('ORD-CLOSE-002', 10000);
        $this->actAsOrderOwner($order);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-002')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-002',
            'price' => 9000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment amount does not match the order amount.');
    }

    /**
     * 청구할 수 없는 통화 설정이면 서버 오류 없이 422 로 거부한다.
     *
     * @effects close_report_rejects_unchargeable_payment_currency_without_server_error
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_unchargeable_payment_currency_without_server_error(): void
    {
        $order = $this->makeOrder('ORD-CLOSE-CURRENCY-001', 10000);
        $order->currency_snapshot = self::unchargeableKrwCurrencySnapshot();
        $this->actAsOrderOwner($order);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-CURRENCY-001')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-CURRENCY-001',
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment currency is not chargeable.');
    }

    /**
     * 원화가 아닌 주문의 표준결제창 닫힘 보고는 결제를 실패 처리하지 않고 거부한다.
     *
     * @effects close_report_rejects_non_krw_order_without_failing_payment
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_non_krw_order_without_failing_payment(): void
    {
        $order = $this->makeOrder('ORD-CLOSE-USD-001', 10000, 'USD');

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-USD-001')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-USD-001',
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Standard KG Inicis close report is only available for KRW orders.');
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
        $order = $this->makeOrder('ORD-CLOSE-003', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-003')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-003',
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
        $order = $this->makeOrder('ORD-CLOSE-004', 10000);
        $order->order_status = OrderStatusEnum::PAYMENT_COMPLETE;

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-004')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-004',
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
        $order = $this->makeOrder('ORD-CLOSE-PAID-001', 0);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));
        $order->setRelation('payment', new OrderPayment([
            'payment_status' => PaymentStatusEnum::PAID,
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-CLOSE-PAID-001')
            ->andReturn($order);
        // 결제가 이미 성공했으므로 실패/취소 처리를 호출하면 안 된다.
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-PAID-001',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'inicis-overlay-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'payment_already_paid');
    }

    /**
     * 취소로 기록할 때 고른 간편결제 수단 정보를 보존한다.
     *
     * @effects close_report_preserves_easy_pay_context_on_cancelled_order
     *
     * @return void 반환값 없음
     */
    public function test_close_report_preserves_easy_pay_context_on_cancelled_order(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $order = OrderFactory::new()->create([
            'user_id' => $owner->id,
            'order_number' => 'ORD-CLOSE-EASYPAY-001',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);

        $payment = OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'embedded_pg_provider' => null,
            'transaction_id' => null,
            'paid_amount_local' => 0,
            'paid_amount_base' => 10000,
            'payment_meta' => ['existing' => 'value'],
            'paid_at' => null,
        ]);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/close-report', [
            'oid' => 'ORD-CLOSE-EASYPAY-001',
            'price' => 10000,
            'payment_method' => 'kginicis_kakaopay',
            'reason' => 'inicis-overlay-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'recorded');

        $payment->refresh();

        $this->assertSame(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertSame('kakaopay', $payment->embedded_pg_provider);
        $this->assertSame('value', $payment->payment_meta['existing'] ?? null);
        $this->assertSame('kginicis_kakaopay', $payment->payment_meta['selected_payment_method'] ?? null);
        $this->assertSame('kakaopay', $payment->payment_meta['embedded_pg_provider'] ?? null);
        $this->assertSame('카카오페이', $payment->payment_meta['embedded_pg_provider_label'] ?? null);
        $this->assertSame('kginicis_kakaopay', $payment->payment_meta['close_report_payment_method'] ?? null);
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
     * @param  string  $currency  통화 코드
     * @return Order 생성한 주문
     */
    private function makeOrder(string $orderNumber, int $amount, string $currency = 'KRW'): Order
    {
        $order = new Order;
        $order->order_number = $orderNumber;
        $order->order_status = OrderStatusEnum::PENDING_ORDER;
        $order->currency = $currency;
        $order->total_due_amount = $amount;
        $order->currency_snapshot = self::currencySnapshotFor($currency);

        return $order;
    }
}
