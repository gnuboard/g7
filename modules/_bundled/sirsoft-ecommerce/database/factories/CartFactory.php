<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 장바구니 Factory
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

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
            'product_id' => ProductFactory::new(),
            'product_option_id' => ProductOptionFactory::new(),
            'quantity' => $this->faker->numberBetween(1, 10),
        ];
    }

    /**
     * 비회원 장바구니
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
     * 특정 사용자의 장바구니
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
     * 특정 상품의 장바구니
     *
     * @param Product $product
     * @return static
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
        ]);
    }

    /**
     * 특정 상품 옵션의 장바구니
     *
     * @param ProductOption $option
     * @return static
     */
    public function forOption(ProductOption $option): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $option->product_id,
            'product_option_id' => $option->id,
        ]);
    }

    /**
     * 특정 cart_key의 장바구니
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
     * 특정 수량의 장바구니
     *
     * @param int $quantity
     * @return static
     */
    public function withQuantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }
}
