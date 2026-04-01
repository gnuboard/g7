<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\TaxInvoiceStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderTaxInvoice;

/**
 * 주문 세금계산서 Factory
 */
class OrderTaxInvoiceFactory extends Factory
{
    protected $model = OrderTaxInvoice::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        $supplyAmount = $this->faker->numberBetween(10000, 500000);
        $taxAmount = round($supplyAmount * 0.1, 2);
        $totalAmount = $supplyAmount + $taxAmount;

        return [
            'order_id' => Order::factory(),
            'payment_id' => null,
            'invoice_status' => TaxInvoiceStatusEnum::PENDING,
            'company_name' => $this->faker->company(),
            'company_number' => $this->faker->numerify('###-##-#####'),
            'ceo_name' => $this->faker->name(),
            'business_type' => '도소매',
            'business_category' => '전자상거래',
            'zipcode' => $this->faker->numerify('#####'),
            'address' => $this->faker->address(),
            'address_detail' => $this->faker->optional()->sentence(3),
            'manager_name' => $this->faker->name(),
            'manager_email' => $this->faker->email(),
            'manager_phone' => '010-'.$this->faker->numerify('####-####'),
            'supply_amount' => $supplyAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'invoice_number' => null,
            'invoice_url' => null,
            'requested_at' => now(),
            'issued_at' => null,
        ];
    }

    /**
     * 특정 주문의 세금계산서
     *
     * @param  Order  $order
     * @return static
     */
    public function forOrder(Order $order): static
    {
        $supplyAmount = round($order->total_amount / 1.1, 2);
        $taxAmount = $order->total_amount - $supplyAmount;

        return $this->state(fn (array $attributes) => [
            'order_id' => $order->id,
            'supply_amount' => $supplyAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $order->total_amount,
        ]);
    }

    /**
     * 특정 결제의 세금계산서
     *
     * @param  OrderPayment  $payment
     * @return static
     */
    public function forPayment(OrderPayment $payment): static
    {
        $supplyAmount = round($payment->paid_amount / 1.1, 2);
        $taxAmount = $payment->paid_amount - $supplyAmount;

        return $this->state(fn (array $attributes) => [
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'supply_amount' => $supplyAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $payment->paid_amount,
        ]);
    }

    /**
     * 발행 대기 상태
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_status' => TaxInvoiceStatusEnum::PENDING,
            'issued_at' => null,
        ]);
    }

    /**
     * 발행 완료 상태
     *
     * @return static
     */
    public function issued(): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_status' => TaxInvoiceStatusEnum::ISSUED,
            'invoice_number' => 'INV-'.now()->format('Ymd').'-'.str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'invoice_url' => 'https://example.com/invoice/'.$this->faker->uuid(),
            'issued_at' => now(),
        ]);
    }

    /**
     * 발행 실패 상태
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_status' => TaxInvoiceStatusEnum::FAILED,
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
            'invoice_status' => TaxInvoiceStatusEnum::CANCELLED,
        ]);
    }
}
