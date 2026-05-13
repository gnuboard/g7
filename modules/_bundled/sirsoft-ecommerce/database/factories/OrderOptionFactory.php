<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

/**
 * 주문 옵션 Factory
 */
class OrderOptionFactory extends Factory
{
    protected $model = OrderOption::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        $faker = \fake();
        $quantity = $faker->numberBetween(1, 5);
        $unitPrice = $faker->numberBetween(5000, 100000);
        $subtotalPrice = $quantity * $unitPrice;
        // 할인은 쿠폰/코드 미적용 시 0 (출처 없는 유령 할인 방지)
        $discountAmount = 0;

        $productName = $faker->words(3, true);

        return [
            'order_id' => Order::factory(),
            'parent_option_id' => null,
            'product_id' => Product::factory(),
            'product_option_id' => ProductOption::factory(),
            'option_status' => OrderStatusEnum::PENDING_ORDER,
            'source_type' => 'order',
            'source_option_id' => null,
            'sku' => strtoupper($faker->bothify('SKU-????-####')),
            'product_name' => ['ko' => $productName, 'en' => $productName],
            'product_option_name' => ['ko' => $faker->word(), 'en' => $faker->word()],
            'option_name' => ['ko' => $faker->word(), 'en' => $faker->word()],
            'option_value' => ['ko' => '색상: '.$faker->word(), 'en' => 'Color: '.$faker->word()],
            'quantity' => $quantity,
            'unit_weight' => $faker->randomFloat(3, 0.1, 2),
            'unit_volume' => $faker->randomFloat(3, 0.01, 0.1),
            'subtotal_weight' => $faker->randomFloat(3, 0.1, 10),
            'subtotal_volume' => $faker->randomFloat(3, 0.01, 0.5),
            'unit_price' => $unitPrice,
            'subtotal_price' => $subtotalPrice,
            'subtotal_discount_amount' => $discountAmount,
            'coupon_discount_amount' => 0,
            'product_coupon_discount_amount' => 0,
            'order_coupon_discount_amount' => 0,
            'code_discount_amount' => 0,
            'subtotal_points_used_amount' => 0,
            'subtotal_deposit_used_amount' => 0,
            'subtotal_paid_amount' => $subtotalPrice - $discountAmount,
            'subtotal_tax_amount' => round(($subtotalPrice - $discountAmount) / 11, 2),
            'subtotal_tax_free_amount' => 0,
            'subtotal_earned_points_amount' => round(($subtotalPrice - $discountAmount) * 0.01, 2),
            'product_snapshot' => [
                'id' => null,
                'name' => ['ko' => $productName, 'en' => $productName],
                'product_code' => null,
                'sku' => null,
                'brand_id' => null,
                'list_price' => $unitPrice,
                'selling_price' => $unitPrice,
                'currency_code' => 'KRW',
                'stock_quantity' => 100,
                'tax_status' => 'taxable',
                'tax_rate' => 10,
                'has_options' => false,
                'option_groups' => null,
                'thumbnail_url' => null,
            ],
            'option_snapshot' => [
                'id' => null,
                'option_code' => null,
                'option_values' => null,
                'option_name' => $faker->word(),
                'price_adjustment' => 0,
                'list_price' => $unitPrice,
                'selling_price' => $unitPrice,
                'currency_code' => 'KRW',
                'stock_quantity' => 100,
                'weight' => 0.5,
                'volume' => 0.01,
                'sku' => null,
                'is_default' => true,
            ],
            'promotions_applied_snapshot' => null,
            // 다중 통화 필드
            'mc_unit_price' => $this->buildMcAmount($unitPrice),
            'mc_subtotal_price' => $this->buildMcAmount($subtotalPrice),
            'mc_product_coupon_discount_amount' => $this->buildMcAmount(0),
            'mc_order_coupon_discount_amount' => $this->buildMcAmount(0),
            'mc_coupon_discount_amount' => $this->buildMcAmount(0),
            'mc_code_discount_amount' => $this->buildMcAmount(0),
            'mc_subtotal_points_used_amount' => $this->buildMcAmount(0),
            'mc_subtotal_deposit_used_amount' => $this->buildMcAmount(0),
            'mc_subtotal_tax_amount' => $this->buildMcAmount(round(($subtotalPrice - $discountAmount) / 11, 2)),
            'mc_subtotal_tax_free_amount' => $this->buildMcAmount(0),
            'mc_final_amount' => $this->buildMcAmount($subtotalPrice - $discountAmount),
            'external_option_id' => null,
            'external_meta' => null,
        ];
    }

    /**
     * 특정 주문의 옵션
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
     * 배송 중 상태
     *
     * @return static
     */
    public function shipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'option_status' => OrderStatusEnum::SHIPPING,
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
            'option_status' => OrderStatusEnum::CANCELLED,
        ]);
    }

    /**
     * 교환 상품
     *
     * @param  OrderOption  $sourceOption
     * @return static
     */
    public function exchangedFrom(OrderOption $sourceOption): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => 'exchange',
            'source_option_id' => $sourceOption->id,
            'order_id' => $sourceOption->order_id,
        ]);
    }

    /**
     * CurrencyConversionService를 사용하여 다중 통화 금액을 생성합니다.
     * 서비스 사용 불가 시 KRW 단일 통화로 fallback합니다.
     *
     * @param  float  $amount  KRW 기준 금액
     * @return array 다중 통화 데이터
     */
    private function buildMcAmount(float $amount): array
    {
        try {
            $service = app(CurrencyConversionService::class);
            $multiCurrency = $service->convertToMultiCurrency((int) $amount);

            $result = [];
            foreach ($multiCurrency as $code => $data) {
                $result[$code] = $data['price'];
            }

            return ! empty($result) ? $result : ['KRW' => $amount];
        } catch (\Exception $e) {
            return ['KRW' => $amount];
        }
    }
}
