<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;

/**
 * 주문 배송 Factory
 */
class OrderShippingFactory extends Factory
{
    protected $model = OrderShipping::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        $faker = \fake();

        return [
            'order_id' => Order::factory(),
            'order_option_id' => OrderOption::factory(),
            'shipping_policy_id' => null,
            'shipping_status' => ShippingStatusEnum::PENDING,
            'shipping_type' => 'parcel',
            'base_shipping_amount' => $faker->numberBetween(0, 5000),
            'extra_shipping_amount' => 0,
            'total_shipping_amount' => $faker->numberBetween(0, 5000),
            'shipping_discount_amount' => 0,
            'is_remote_area' => false,
            'carrier_id' => null,
            'tracking_number' => null,
            'return_shipping_amount' => 0,
            'return_carrier_id' => null,
            'return_tracking_number' => null,
            'exchange_carrier_id' => null,
            'exchange_tracking_number' => null,
            'package_number' => null,
            'visit_date' => null,
            'visit_time_slot' => null,
            'actual_weight' => null,
            'delivery_policy_snapshot' => null,
            'shipped_at' => null,
            'estimated_arrival_at' => null,
            'delivered_at' => null,
            'confirmed_at' => null,
        ];
    }

    /**
     * 특정 주문의 배송
     *
     * @param  Order  $order
     * @return static
     */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $order->id,
        ]);
    }

    /**
     * 특정 주문 옵션의 배송
     *
     * @param  OrderOption  $orderOption
     * @return static
     */
    public function forOrderOption(OrderOption $orderOption): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $orderOption->order_id,
            'order_option_id' => $orderOption->id,
        ]);
    }

    /**
     * 준비 중 상태
     *
     * @return static
     */
    public function preparing(): static
    {
        return $this->state(fn (array $attributes) => [
            'shipping_status' => ShippingStatusEnum::PREPARING,
            'tracking_number' => null,
            'shipped_at' => null,
        ]);
    }

    /**
     * 배송 중 상태
     *
     * @return static
     */
    public function inTransit(): static
    {
        $trackingNumber = \fake()->numerify('############');

        return $this->state(fn (array $attributes) => [
            'shipping_status' => ShippingStatusEnum::IN_TRANSIT,
            'tracking_number' => $trackingNumber,
            'shipped_at' => now(),
            'estimated_arrival_at' => now()->addDays(2),
        ]);
    }

    /**
     * 배송 완료 상태
     *
     * @return static
     */
    public function delivered(): static
    {
        $trackingNumber = \fake()->numerify('############');

        return $this->state(fn (array $attributes) => [
            'shipping_status' => ShippingStatusEnum::DELIVERED,
            'tracking_number' => $trackingNumber,
            'shipped_at' => now()->subDays(2),
            'estimated_arrival_at' => now(),
            'delivered_at' => now(),
        ]);
    }

    /**
     * 방문수령 배송
     *
     * @return static
     */
    public function pickup(): static
    {
        return $this->state(fn (array $attributes) => [
            'shipping_type' => 'pickup',
            'carrier_id' => null,
            'tracking_number' => null,
            'visit_date' => now()->addDays(3),
            'visit_time_slot' => '14:00-18:00',
        ]);
    }
}
