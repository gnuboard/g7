<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Concerns;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNhnkcp\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * Phase 3 PoC (Feature) — NHN KCP PreventsReplayCallback trait 동작 검증
 *
 * trait 의 wasAlreadyPaid() 가 다음 시나리오에서 올바르게 분기:
 *   1. transaction_id null/empty → false (early return)
 *   2. paid 상태 + 동일 tno → true (replay detected)
 *   3. ready 상태만 존재 → false
 *   4. paid 상태이지만 다른 tno → false
 *   5. failed 상태 + 동일 tno → false
 */
class PreventsReplayCallbackTest extends PluginTestCase
{
    private object $traitSubject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traitSubject = new class {
            use PreventsReplayCallback {
                wasAlreadyPaid as public;
            }
        };
    }

    private function seedPayment(string $tno, PaymentStatusEnum $status, int $amount = 50000): void
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RC-' . random_int(10000, 99999),
            'order_status' => $status === PaymentStatusEnum::PAID
                ? OrderStatusEnum::PAYMENT_COMPLETE
                : OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => $amount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $amount,
            'total_due_amount' => $amount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => $status === PaymentStatusEnum::PAID ? $amount : 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => $status,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'paid_amount_local' => $status === PaymentStatusEnum::PAID ? $amount : 0,
            'paid_at' => $status === PaymentStatusEnum::PAID ? now() : null,
            'transaction_id' => $tno,
        ]);
    }

    /**
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     * @effects replay_first_arrival_proceeds_normally
     */
    public function test_null_transaction_id_returns_false(): void
    {
        $this->assertFalse($this->traitSubject->wasAlreadyPaid(null));
    }

    /**
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     * @effects replay_first_arrival_proceeds_normally
     */
    public function test_empty_transaction_id_returns_false(): void
    {
        $this->assertFalse($this->traitSubject->wasAlreadyPaid(''));
    }

    /**
     * @scenario context=paymentCallback_card, threat=replay, callback_state=second_arrival_same_tno
     * @effects replay_detected_on_second_arrival
     */
    public function test_paid_payment_with_same_tno_returns_true(): void
    {
        $tno = 'TNO_PAID_001';
        $this->seedPayment($tno, PaymentStatusEnum::PAID);

        $this->assertTrue($this->traitSubject->wasAlreadyPaid($tno));
    }

    /**
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     * @effects replay_first_arrival_proceeds_normally
     */
    public function test_ready_status_payment_returns_false(): void
    {
        $tno = 'TNO_READY_001';
        $this->seedPayment($tno, PaymentStatusEnum::READY);

        $this->assertFalse($this->traitSubject->wasAlreadyPaid($tno));
    }

    /**
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     * @effects replay_first_arrival_proceeds_normally
     */
    public function test_paid_with_different_tno_returns_false(): void
    {
        $this->seedPayment('TNO_OTHER_001', PaymentStatusEnum::PAID);

        $this->assertFalse($this->traitSubject->wasAlreadyPaid('TNO_QUERY_001'));
    }

    /**
     * @scenario context=paymentCallback_card, threat=replay, callback_state=first_arrival
     * @effects replay_first_arrival_proceeds_normally
     */
    public function test_failed_payment_with_same_tno_returns_false(): void
    {
        $tno = 'TNO_FAILED_001';
        $this->seedPayment($tno, PaymentStatusEnum::FAILED);

        $this->assertFalse($this->traitSubject->wasAlreadyPaid($tno));
    }
}
