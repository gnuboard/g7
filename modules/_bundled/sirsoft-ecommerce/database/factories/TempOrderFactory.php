<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;

/**
 * 임시 주문 Factory
 */
class TempOrderFactory extends Factory
{
    protected $model = TempOrder::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'cart_key' => null,
            'user_id' => User::factory(),
            'items' => [
                [
                    'cart_id' => $this->faker->numberBetween(1, 100),
                    'product_id' => $this->faker->numberBetween(1, 100),
                    'product_option_id' => $this->faker->numberBetween(1, 100),
                    'quantity' => $this->faker->numberBetween(1, 5),
                ],
            ],
            'calculation_input' => [
                'promotions' => [
                    'item_coupons' => [],
                    'order_coupon_issue_id' => null,
                    'shipping_coupon_issue_id' => null,
                ],
                'use_points' => 0,
                'shipping_address' => null,
            ],
            'calculation_result' => [
                'items' => [],
                'summary' => [
                    'subtotal' => 30000,
                    'product_coupon_discount' => 0,
                    'code_discount' => 0,
                    'order_coupon_discount' => 0,
                    'total_discount' => 0,
                    'total_shipping' => 3000,
                    'shipping_discount' => 0,
                    'taxable_amount' => 30000,
                    'tax_free_amount' => 0,
                    'points_earning' => 300,
                    'points_used' => 0,
                    'payment_amount' => 33000,
                    'final_amount' => 33000,
                ],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ],
            'expires_at' => Carbon::now()->addMinutes(30),
        ];
    }

    /**
     * 비회원 임시 주문
     *
     * @return static
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'cart_key' => 'ck_'.Str::random(32),
            'user_id' => null,
        ]);
    }

    /**
     * 특정 사용자의 임시 주문
     *
     * @param User $user
     * @return static
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'cart_key' => null,
            'user_id' => $user->id,
        ]);
    }

    /**
     * 특정 cart_key의 임시 주문
     *
     * @param string $cartKey
     * @return static
     */
    public function withCartKey(string $cartKey): static
    {
        return $this->state(fn (array $attributes) => [
            'cart_key' => $cartKey,
            'user_id' => null,
        ]);
    }

    /**
     * 만료된 임시 주문
     *
     * @return static
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => Carbon::now()->subHour(),
        ]);
    }

    /**
     * 특정 만료 시간의 임시 주문
     *
     * @param Carbon $expiresAt
     * @return static
     */
    public function expiresAt(Carbon $expiresAt): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 프로모션 적용된 임시 주문
     *
     * @param array $promotions {item_coupons, order_coupon_issue_id, shipping_coupon_issue_id}
     * @return static
     */
    public function withPromotions(array $promotions): static
    {
        return $this->state(function (array $attributes) use ($promotions) {
            $calculationInput = $attributes['calculation_input'] ?? [];
            $calculationInput['promotions'] = array_merge(
                $calculationInput['promotions'] ?? [],
                $promotions
            );

            return [
                'calculation_input' => $calculationInput,
            ];
        });
    }

    /**
     * 마일리지 사용된 임시 주문
     *
     * @param int $points
     * @return static
     */
    public function withUsePoints(int $points): static
    {
        return $this->state(function (array $attributes) use ($points) {
            $calculationInput = $attributes['calculation_input'] ?? [];
            $calculationInput['use_points'] = $points;

            return [
                'calculation_input' => $calculationInput,
            ];
        });
    }

    /**
     * 배송 주소 설정된 임시 주문
     *
     * @param array $shippingAddress
     * @return static
     */
    public function withShippingAddress(array $shippingAddress): static
    {
        return $this->state(function (array $attributes) use ($shippingAddress) {
            $calculationInput = $attributes['calculation_input'] ?? [];
            $calculationInput['shipping_address'] = $shippingAddress;

            return [
                'calculation_input' => $calculationInput,
            ];
        });
    }

    /**
     * 특정 아이템의 임시 주문
     *
     * @param array $items
     * @return static
     */
    public function withItems(array $items): static
    {
        return $this->state(fn (array $attributes) => [
            'items' => $items,
        ]);
    }

    /**
     * 특정 계산 결과의 임시 주문
     *
     * @param array $calculationResult
     * @return static
     */
    public function withCalculationResult(array $calculationResult): static
    {
        return $this->state(fn (array $attributes) => [
            'calculation_result' => $calculationResult,
        ]);
    }

    /**
     * 특정 계산 입력의 임시 주문
     *
     * @param array $calculationInput
     * @return static
     */
    public function withCalculationInput(array $calculationInput): static
    {
        return $this->state(fn (array $attributes) => [
            'calculation_input' => $calculationInput,
        ]);
    }
}
