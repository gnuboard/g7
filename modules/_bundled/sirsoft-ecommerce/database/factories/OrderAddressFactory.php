<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;

/**
 * 주문 주소 Factory
 */
class OrderAddressFactory extends Factory
{
    protected $model = OrderAddress::class;

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
            'address_type' => 'shipping',
            'orderer_name' => $faker->name(),
            'orderer_phone' => '010-'.$faker->numerify('####-####'),
            'orderer_email' => $faker->email(),
            'recipient_name' => $faker->name(),
            'recipient_phone' => '010-'.$faker->numerify('####-####'),
            'zipcode' => $faker->numerify('#####'),
            'address' => $faker->address(),
            'address_detail' => $faker->optional()->sentence(3),
            'delivery_memo' => $faker->optional()->sentence(),
            'recipient_country_code' => 'KR',
        ];
    }

    /**
     * 배송지 주소
     *
     * @return static
     */
    public function shipping(): static
    {
        return $this->state(fn (array $attributes) => [
            'address_type' => 'shipping',
        ]);
    }

    /**
     * 청구지 주소
     *
     * @return static
     */
    public function billing(): static
    {
        return $this->state(fn (array $attributes) => [
            'address_type' => 'billing',
        ]);
    }

    /**
     * 특정 주문의 주소
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
}
