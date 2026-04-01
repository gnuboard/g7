<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 상품 옵션 Factory
 */
class ProductOptionFactory extends Factory
{
    protected $model = ProductOption::class;

    /**
     * 기본 정의 (다국어 지원)
     *
     * @return array
     */
    public function definition(): array
    {
        $colorKo = $this->faker->randomElement(['빨강', '파랑', '검정', '흰색']);
        $colorEn = $this->faker->randomElement(['Red', 'Blue', 'Black', 'White']);
        $sizeKo = $this->faker->randomElement(['S', 'M', 'L', 'XL']);

        return [
            'product_id' => Product::factory(),
            'option_code' => strtoupper($this->faker->bothify('OPT-????-####')),
            // 다국어 option_values (배열 형식)
            'option_values' => [
                [
                    'key' => ['ko' => '색상', 'en' => 'Color'],
                    'value' => ['ko' => $colorKo, 'en' => $colorEn],
                ],
                [
                    'key' => ['ko' => '사이즈', 'en' => 'Size'],
                    'value' => ['ko' => $sizeKo, 'en' => $sizeKo],
                ],
            ],
            // 다국어 option_name
            'option_name' => [
                'ko' => $colorKo.'/'.$sizeKo,
                'en' => $colorEn.'/'.$sizeKo,
            ],
            'price_adjustment' => $this->faker->randomElement([0, 1000, 2000, 3000, -1000]),
            'stock_quantity' => $this->faker->numberBetween(0, 100),
            'safe_stock_quantity' => $this->faker->numberBetween(5, 20),
            'is_default' => false,
            'is_active' => true,
            'sku' => strtoupper($this->faker->bothify('SKU-OPT-????-####')),
            'sort_order' => $this->faker->numberBetween(0, 100),
        ];
    }

    /**
     * 특정 상품의 옵션
     *
     * @param  Product  $product
     * @return static
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
        ]);
    }

    /**
     * 기본 옵션
     *
     * @return static
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * 비활성 옵션
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * 품절 옵션
     *
     * @return static
     */
    public function soldOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_quantity' => 0,
        ]);
    }

    /**
     * 추가 금액 있는 옵션
     *
     * @param  int  $amount
     * @return static
     */
    public function withPriceAdjustment(int $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'price_adjustment' => $amount,
        ]);
    }
}
