<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * 주문 결제 Factory
 */
class OrderPaymentFactory extends Factory
{
    protected $model = OrderPayment::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        $faker = \fake();
        $amount = $faker->numberBetween(10000, 500000);

        return [
            'order_id' => Order::factory(),
            'payment_status' => PaymentStatusEnum::PAID,
            'pg_provider' => $faker->randomElement(['tosspayments', 'inicis', 'nicepay']),
            'embedded_pg_provider' => null,
            'transaction_id' => $faker->uuid(),
            'merchant_order_id' => 'MO-'.strtoupper($faker->bothify('????-####-????')),
            'payment_method' => PaymentMethodEnum::CARD,
            'payment_device' => $faker->randomElement(['pc', 'mobile']),
            'paid_amount_local' => $amount,
            'paid_amount_base' => $amount,
            'vat_amount' => round($amount / 11, 2),
            'currency' => 'KRW',
            'currency_snapshot' => ['KRW' => 1.0, 'USD' => 0.00074],
            'card_name' => $faker->randomElement(['신한카드', '삼성카드', 'KB국민카드', '현대카드']),
            'card_number_masked' => $faker->numerify('####-****-****-####'),
            'card_approval_number' => $faker->numerify('########'),
            'card_installment_months' => 0,
            'is_interest_free' => false,
            'vbank_code' => null,
            'vbank_name' => null,
            'vbank_number' => null,
            'vbank_holder' => null,
            'vbank_due_at' => null,
            'vbank_issued_at' => null,
            'dbank_code' => null,
            'dbank_name' => null,
            'dbank_account' => null,
            'dbank_holder' => null,
            'depositor_name' => null,
            'deposit_due_at' => null,
            'is_escrow' => false,
            'buyer_name' => $faker->name(),
            'buyer_email' => $faker->email(),
            'buyer_phone' => '010-'.$faker->numerify('####-####'),
            'is_cash_receipt_requested' => false,
            'is_cash_receipt_issued' => false,
            'cash_receipt_type' => null,
            'cash_receipt_identifier' => null,
            'cash_receipt_issued_at' => null,
            'cancelled_amount' => 0,
            'cancelled_vat_amount' => 0,
            'cancel_reason' => null,
            'cancel_history' => null,
            'refund_bank_code' => null,
            'refund_bank_name' => null,
            'refund_bank_account' => null,
            'refund_bank_holder' => null,
            'receipt_url' => $faker->optional()->url(),
            'payment_name' => $faker->words(3, true),
            'user_agent' => null,
            'payment_meta' => null,
            'payment_started_at' => now()->subMinutes(5),
            'paid_at' => now(),
            'cancelled_at' => null,
        ];
    }

    /**
     * 특정 주문의 결제
     *
     * @param  Order  $order
     * @return static
     */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $order->id,
            'paid_amount_local' => $order->total_amount,
            'paid_amount_base' => $order->total_amount,
        ]);
    }

    /**
     * 카드 결제
     *
     * @return static
     */
    public function card(): static
    {
        $faker = \fake();

        return $this->state(fn (array $attributes) => [
            'payment_method' => PaymentMethodEnum::CARD,
            'card_name' => $faker->randomElement(['신한카드', '삼성카드', 'KB국민카드', '현대카드']),
            'card_number_masked' => $faker->numerify('####-****-****-####'),
        ]);
    }

    /**
     * 무통장입금 결제
     *
     * @return static
     */
    public function directBank(): static
    {
        $faker = \fake();

        return $this->state(fn (array $attributes) => [
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'pg_provider' => '',
            'embedded_pg_provider' => null,
            'dbank_code' => '088',
            'dbank_name' => $faker->randomElement(['신한은행', '국민은행', '우리은행', '하나은행']),
            'dbank_account' => $faker->numerify('###-###-######'),
            'dbank_holder' => '(주)테스트몰',
            'depositor_name' => $faker->name(),
            'deposit_due_at' => now()->addDays(3),
            'card_name' => null,
            'card_number_masked' => null,
            'card_approval_number' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * 가상계좌 결제
     *
     * @return static
     */
    public function virtualAccount(): static
    {
        $faker = \fake();

        return $this->state(fn (array $attributes) => [
            'payment_method' => PaymentMethodEnum::VBANK,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'vbank_code' => '088',
            'vbank_name' => $faker->randomElement(['신한은행', '국민은행', '우리은행']),
            'vbank_number' => $faker->numerify('###-###-######'),
            'vbank_holder' => $faker->name(),
            'vbank_due_at' => now()->addDays(7),
            'vbank_issued_at' => now(),
            'card_name' => null,
            'card_number_masked' => null,
            'card_approval_number' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * 결제 대기 상태
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => PaymentStatusEnum::READY,
            'paid_at' => null,
        ]);
    }

    /**
     * 결제 완료 상태
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_at' => now(),
        ]);
    }

    /**
     * 결제 실패 상태
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => PaymentStatusEnum::FAILED,
            'paid_at' => null,
        ]);
    }

    /**
     * 취소 상태
     *
     * @return static
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => PaymentStatusEnum::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_amount' => $attributes['paid_amount_local'],
            'cancelled_vat_amount' => $attributes['vat_amount'],
            'cancel_reason' => '고객 요청 취소',
        ]);
    }
}
