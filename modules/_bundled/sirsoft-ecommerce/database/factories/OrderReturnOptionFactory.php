<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;

/**
 * 반품 신청 상품 Factory
 */
class OrderReturnOptionFactory extends Factory
{
    protected $model = OrderReturnOption::class;

    /**
     * 기본 정의
     *
     * @return array 기본 속성 배열
     */
    public function definition(): array
    {
        $faker = \fake();
        $unitPrice = $faker->numberBetween(1000, 100000);

        return [
            'order_return_id' => OrderReturn::factory(),
            'order_id' => Order::factory(),
            'order_option_id' => OrderOption::factory(),
            'option_status' => ReturnStatusEnum::REQUESTED,
            'return_quantity' => 1,
            'original_quantity' => 1,
            'original_option_status' => OrderStatusEnum::DELIVERED,
            'unit_price' => $unitPrice,
            'subtotal_amount' => $unitPrice,
            'completed_at' => null,
            'processed_by' => null,
        ];
    }

    /**
     * 부분 수량 반품으로 설정합니다.
     *
     * @param  int  $returnQuantity  반품 수량
     * @param  int  $originalQuantity  원래 수량
     * @return static
     */
    public function partialQuantity(int $returnQuantity = 1, int $originalQuantity = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'return_quantity' => $returnQuantity,
            'original_quantity' => $originalQuantity,
            'subtotal_amount' => (float) $attributes['unit_price'] * $returnQuantity,
        ]);
    }
}
