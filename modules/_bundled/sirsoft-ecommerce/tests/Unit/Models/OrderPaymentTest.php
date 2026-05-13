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

    public function test_order_payment_casts_method_to_enum(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->card()->create();

        $this->assertInstanceOf(PaymentMethodEnum::class, $payment->payment_method);
        $this->assertEquals(PaymentMethodEnum::CARD, $payment->payment_method);
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

        $this->assertEquals(PaymentMethodEnum::CARD, $payment->payment_method);
        $this->assertEquals('신한카드', $payment->card_name);
    }

    public function test_virtual_account_payment_has_vbank_info(): void
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->virtualAccount()->create();

        $this->assertEquals(PaymentMethodEnum::VBANK, $payment->payment_method);
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
}
