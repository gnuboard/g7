<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;

/**
 * 교환 신청 상품 Factory
 */
class OrderExchangeOptionFactory extends Factory
{
    protected $model = OrderExchangeOption::class;

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
            'order_exchange_id' => OrderExchange::factory(),
            'order_id' => Order::factory(),
            'order_option_id' => OrderOption::factory(),
            'option_status' => ExchangeStatusEnum::REQUESTED,
            'exchange_quantity' => 1,
            'original_quantity' => 1,
            'unit_price' => $unitPrice,
            'new_product_option_id' => null,
            'new_option_snapshot' => null,
            'new_unit_price' => null,
            'price_difference' => 0,
            'mc_price_difference' => null,
            'new_order_option_id' => null,
            'is_new_stock_deducted' => false,
            'completed_at' => null,
            'processed_by' => null,
        ];
    }

    /**
     * 신규 옵션이 더 비싼(추가 청구) 교환으로 설정합니다.
     *
     * @param  float  $difference  차액 (양수)
     * @return static
     */
    public function higherPrice(float $difference = 5000): static
    {
        return $this->state(fn (array $attributes) => [
            'new_unit_price' => (float) $attributes['unit_price'] + $difference,
            'price_difference' => $difference * (int) $attributes['exchange_quantity'],
        ]);
    }

    /**
     * 신규 옵션이 더 싼(차액 환불) 교환으로 설정합니다.
     *
     * @param  float  $difference  차액 (양수로 전달하면 음수로 기록)
     * @return static
     */
    public function lowerPrice(float $difference = 5000): static
    {
        return $this->state(fn (array $attributes) => [
            'new_unit_price' => max(0, (float) $attributes['unit_price'] - $difference),
            'price_difference' => -1 * $difference * (int) $attributes['exchange_quantity'],
        ]);
    }

    /**
     * 부분 수량 교환으로 설정합니다.
     *
     * @param  int  $exchangeQuantity  교환 수량
     * @param  int  $originalQuantity  원래 수량
     * @return static
     */
    public function partialQuantity(int $exchangeQuantity = 1, int $originalQuantity = 3): static
    {
        return $this->state(fn () => [
            'exchange_quantity' => $exchangeQuantity,
            'original_quantity' => $originalQuantity,
        ]);
    }
}
