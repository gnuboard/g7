<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderPayment 모델 테스트
 */
class OrderPaymentTest extends ModuleTestCase
{
    public function test_order_payment_can_be_created(): void
    {
        // paid_amount 컬럼은 paid_amount_local / paid_amount_base 로 분리됨 (다중 통화 지원)
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'paid_amount_local' => 50000,
            'paid_amount_base' => 50000,
        ]);

        $this->assertDatabaseHas('ecommerce_order_payments', [
            'id' => $payment->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_order_payment_belongs_to_order(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create();

        $this->assertInstanceOf(Order::class, $payment->order);
        $this->assertEquals($order->id, $payment->order->id);
    }

    public function test_order_payment_method_is_stored_as_plain_string(): void
    {
        // payment_method 는 enum 캐스트를 두지 않는다 (#475) — PG 플러그인이 등록하는
        // 확장 결제수단(예: nhnkcp_naverpay)은 enum case 가 없어 캐스트가 ValueError 를 던지기 때문.
        // 능력 질의는 모델의 능력 메서드가 PaymentMethodResolver 에 위임해 답한다.
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->card()->create();

        $this->assertSame(PaymentMethodEnum::CARD->value, $payment->fresh()->payment_method);
        $this->assertTrue($payment->isCardPayment());
    }

    public function test_order_payment_accepts_extension_payment_method_id(): void
    {
        // 확장 결제수단 ID 를 1급 시민으로 저장할 수 있어야 한다 (캐스트가 있으면 ValueError).
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_method' => 'nhnkcp_naverpay',
            'pg_provider' => 'nhnkcp',
        ]);

        $this->assertSame('nhnkcp_naverpay', $payment->fresh()->payment_method);
    }

    public function test_order_payment_casts_status_to_enum(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->completed()->create();

        $this->assertInstanceOf(PaymentStatusEnum::class, $payment->payment_status);
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
    }

    public function test_card_payment_has_card_info(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->card()->create([
            'card_name' => '신한카드',
        ]);

        $this->assertTrue($payment->isCardPayment());
        $this->assertEquals('신한카드', $payment->card_name);
    }

    public function test_virtual_account_payment_has_vbank_info(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->virtualAccount()->create();

        $this->assertTrue($payment->isVirtualAccount());
        $this->assertNotNull($payment->vbank_name);
        $this->assertNotNull($payment->vbank_number);
    }

    public function test_pending_payment_has_no_paid_at(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->pending()->create();

        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertNull($payment->paid_at);
    }

    public function test_completed_payment_has_paid_at(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->completed()->create();

        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_cancelled_payment_has_cancel_info(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->cancelled()->create();

        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertNotNull($payment->cancel_reason);
    }

    public function test_failed_payment_has_correct_status(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->failed()->create();

        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertNull($payment->paid_at);
    }

    public function test_cancellable_amount_uses_payment_currency_cumulative_when_cross_currency(): void
    {
        // base JPY 주문을 KRW 로 결제: paid_amount_local 은 결제 통화(KRW 4750), 누적 취소도
        // 결제 통화로 비교해야 한다. mc_cancelled_amount[KRW]=1000 이면 잔여 = 4750-1000 = 3750.
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'currency' => 'KRW',
            'paid_amount_local' => 4750,
            'paid_amount_base' => 500,
            // base 누적(cancelled_amount)은 JPY 100 이지만 결제 통화 비교에는 쓰지 않는다.
            'cancelled_amount' => 100,
            'mc_cancelled_amount' => ['JPY' => 100, 'KRW' => 1000],
        ]);

        $this->assertSame(1000.0, $payment->cancelledLocalAmount());
        // 3750 = 4750(KRW) - 1000(KRW). base cancelled(100)으로 빼면 4650 이 되는 회귀를 차단.
        $this->assertSame(3750.0, $payment->getCancellableAmount());
    }

    public function test_cancellable_amount_falls_back_to_base_cancelled_for_legacy_payment(): void
    {
        // mc_cancelled_amount 가 없는(레거시) 결제는 base 누적 cancelled_amount 로 폴백한다.
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'currency' => 'KRW',
            'paid_amount_local' => 50000,
            'paid_amount_base' => 50000,
            'cancelled_amount' => 20000,
            'mc_cancelled_amount' => null,
        ]);

        $this->assertSame(20000.0, $payment->cancelledLocalAmount());
        $this->assertSame(30000.0, $payment->getCancellableAmount());
    }
}
