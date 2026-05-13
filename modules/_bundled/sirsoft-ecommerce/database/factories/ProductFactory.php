<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 Factory
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        $listPrice = $this->faker->numberBetween(10000, 1000000);
        $sellingPrice = round($listPrice * $this->faker->randomFloat(2, 0.7, 1.0));

        return [
            'name' => [
                'ko' => $this->faker->words(3, true),
                'en' => $this->faker->words(3, true),
            ],
            'product_code' => strtoupper($this->faker->bothify('PROD-????-####')),
            'sku' => strtoupper($this->faker->bothify('SKU-????-####')),
            'brand_id' => null,
            'list_price' => $listPrice,
            'selling_price' => $sellingPrice,
            'stock_quantity' => $this->faker->numberBetween(0, 1000),
            'safe_stock_quantity' => $this->faker->numberBetween(5, 50),
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'tax_status' => ProductTaxStatus::TAXABLE,
            'tax_rate' => 10.00,
            'shipping_policy_id' => null,
            'description' => [
                'ko' => $this->faker->paragraphs(3, true),
                'en' => $this->faker->paragraphs(3, true),
            ],
            'meta_title' => $this->faker->optional()->sentence(5),
            'meta_description' => $this->faker->optional()->paragraph(),
            'meta_keywords' => $this->faker->optional()->words(5),
            'has_options' => false,
            'option_groups' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /**
     * 옵션 있는 상품 (다국어 지원)
     *
     * @return static
     */
    public function withOptions(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_options' => true,
            'option_groups' => [
                [
                    'name' => ['ko' => '색상', 'en' => 'Color'],
                    'values' => [
                        ['ko' => '빨강', 'en' => 'Red'],
                        ['ko' => '파랑', 'en' => 'Blue'],
                        ['ko' => '검정', 'en' => 'Black'],
                    ],
                ],
                [
                    'name' => ['ko' => '사이즈', 'en' => 'Size'],
                    'values' => [
                        ['ko' => 'S', 'en' => 'S'],
                        ['ko' => 'M', 'en' => 'M'],
                        ['ko' => 'L', 'en' => 'L'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * 판매중 상태
     *
     * @return static
     */
    public function onSale(): static
    {
        return $this->state(fn (array $attributes) => [
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
        ]);
    }

    /**
     * 품절 상태
     *
     * @return static
     */
    public function soldOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'sales_status' => ProductSalesStatus::SOLD_OUT,
            'stock_quantity' => 0,
        ]);
    }

    /**
     * 판매 중지 상태
     *
     * @return static
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'sales_status' => ProductSalesStatus::SUSPENDED,
        ]);
    }

    /**
     * 숨김 상태
     *
     * @return static
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'display_status' => ProductDisplayStatus::HIDDEN,
        ]);
    }

    /**
     * 면세 상품
     *
     * @return static
     */
    public function taxFree(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_status' => ProductTaxStatus::TAX_FREE,
            'tax_rate' => 0,
        ]);
    }
}
