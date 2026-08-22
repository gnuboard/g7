<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 토스페이먼츠 웹훅 컨트롤러 기능 테스트.
 *
 * 가상계좌 입금통보(deposit) — secret 대조 / 리플레이 멱등 / status 분기 / 상태 가드.
 *
 * @scenario webhook_status=done, webhook_secret=match, replay=first
 *
 * @effects deposit_done_completes_payment, deposit_canceled_fails_payment,
 *          webhook_secret_verified, webhook_replay_idempotent, non_waiting_deposit_is_noop
 */
class WebhookControllerTest extends PluginTestCase
{
    private const DEPOSIT_URL = '/plugins/sirsoft-tosspayments/webhook/deposit';

    private const SECRET = 'wsec_test_secret_value';

    /**
     * 입금대기(vbank) 주문을 생성합니다.
     *
     * @param  string  $secret  저장할 웹훅 secret
     * @param  int  $totalAmount  주문 총액
     */
    private function createWaitingDepositOrder(string $secret = self::SECRET, int $totalAmount = 50000): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-VBANK-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => $totalAmount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'total_due_amount' => $totalAmount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'tosspayments',
            'transaction_id' => 'pk_test_vbank_'.$order->id,
            'paid_amount_base' => $totalAmount,
            'paid_amount_local' => $totalAmount,
            'paid_at' => null,
            'payment_meta' => ['toss_secret' => $secret, 'status' => 'WAITING_FOR_DEPOSIT'],
        ]);

        return $order;
    }

    /**
     * 플러그인 설정을 mock 합니다.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function mockPluginSettings(array $overrides = []): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn(array_merge([
            'is_test_mode' => true,
            'webhook_secret_verify' => true,
        ], $overrides));
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    public function test_deposit_done_completes_payment(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => 'txn_abc',
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->payment->payment_status->value);
    }

    public function test_deposit_canceled_fails_payment(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'CANCELED',
            'secret' => self::SECRET,
        ]);

        $response->assertOk();
        $order->refresh();
        // failPayment 은 order_status 를 CANCELLED 로 전이한다 (payment_status 는 유지 — 코어 동작).
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    public function test_secret_mismatch_returns_401(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => 'wrong_secret',
        ]);

        $response->assertStatus(401);
        $order->refresh();
        // 결제완료로 전이되지 않아야 함
        $this->assertNotSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    public function test_secret_verification_can_be_disabled(): void
    {
        $this->mockPluginSettings(['webhook_secret_verify' => false]);
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => 'anything',
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    public function test_replay_after_paid_is_idempotent(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        // 1차 입금통보 → PAID
        $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => $order->payment->transaction_id,
        ])->assertOk();

        $order->refresh();
        $paidAt = $order->payment->paid_at;

        // 2차 (중복) 통보 → 멱등 200, 상태 불변
        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
            'transactionKey' => $order->payment->transaction_id,
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->payment->payment_status->value);
        $this->assertEquals($paidAt->timestamp, $order->payment->paid_at->timestamp);
    }

    public function test_order_not_found_returns_fail(): void
    {
        $this->mockPluginSettings();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => 'ORD-NONEXISTENT',
            'status' => 'DONE',
            'secret' => self::SECRET,
        ]);

        $response->assertOk();
        $this->assertSame('FAIL', $response->getContent());
    }

    public function test_invalid_status_rejected_by_validation(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'BOGUS',
        ]);

        $response->assertStatus(422);
    }

    public function test_deposit_on_non_waiting_order_is_noop_200(): void
    {
        $this->mockPluginSettings();
        $order = $this->createWaitingDepositOrder();
        // 이미 PAID 로 바꿔 놓되 transaction_id 는 달라 리플레이 가드는 안 걸리게 한다
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::READY,
        ]);

        $response = $this->postJson(self::DEPOSIT_URL, [
            'orderId' => $order->order_number,
            'status' => 'DONE',
            'secret' => self::SECRET,
        ]);

        $response->assertOk();
        $order->refresh();
        // WAITING_DEPOSIT 가 아니므로 completePayment 하지 않는다
        $this->assertNotSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }
}
