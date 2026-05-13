<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 주문 Factory
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        $faker = \fake();
        $subtotalAmount = $faker->numberBetween(10000, 500000);
        $shippingAmount = $faker->randomElement([0, 2500, 3000]);
        // 할인은 쿠폰/코드 미적용 시 0 (출처 없는 유령 할인 방지)
        $discountAmount = 0;
        $totalAmount = $subtotalAmount + $shippingAmount - $discountAmount;

        return [
            'user_id' => User::factory(),
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'order_device' => DeviceTypeEnum::PC,
            'is_first_order' => $faker->boolean(30),
            'ip_address' => $faker->ipv4(),
            'currency' => 'KRW',
            'currency_snapshot' => ['KRW' => 1.0, 'USD' => 0.00074],
            'subtotal_amount' => $subtotalAmount,
            'total_discount_amount' => $discountAmount,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => $shippingAmount,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => $shippingAmount,
            'total_amount' => $totalAmount,
            'total_tax_amount' => round($totalAmount / 11, 2),
            'total_tax_free_amount' => 0,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
            'total_due_amount' => $totalAmount,
            'total_cancelled_amount' => 0,
            'total_refunded_amount' => 0,
            'total_refunded_points_amount' => 0,
            'total_earned_points_amount' => round($totalAmount * 0.01, 2),
            'item_count' => $faker->numberBetween(1, 5),
            'total_weight' => $faker->randomFloat(3, 0.1, 10),
            'total_volume' => $faker->randomFloat(3, 0.01, 1),
            'ordered_at' => now(),
            'paid_at' => null,
            'payment_due_at' => now()->addDays(7),
            'confirmed_at' => null,
            'admin_memo' => null,
        ];
    }

    /**
     * 결제 대기 상태
     *
     * @return static
     */
    public function pendingPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'paid_at' => null,
        ]);
    }

    /**
     * 결제 완료 상태
     *
     * @return static
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'paid_at' => now(),
            'total_paid_amount' => $attributes['total_amount'],
            'total_due_amount' => 0,
        ]);
    }

    /**
     * 배송 중 상태
     *
     * @return static
     */
    public function shipping(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatusEnum::SHIPPING,
            'paid_at' => now()->subDays(2),
            'total_paid_amount' => $attributes['total_amount'],
            'total_due_amount' => 0,
        ]);
    }

    /**
     * 배송 완료 상태
     *
     * @return static
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatusEnum::DELIVERED,
            'paid_at' => now()->subDays(5),
            'total_paid_amount' => $attributes['total_amount'],
            'total_due_amount' => 0,
        ]);
    }

    /**
     * 구매 확정 상태
     *
     * @return static
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatusEnum::CONFIRMED,
            'paid_at' => now()->subDays(10),
            'confirmed_at' => now(),
            'total_paid_amount' => $attributes['total_amount'],
            'total_due_amount' => 0,
        ]);
    }

    /**
     * 취소된 상태
     *
     * @return static
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_status' => OrderStatusEnum::CANCELLED,
            'total_cancelled_amount' => $attributes['total_amount'],
        ]);
    }

    /**
     * 특정 사용자의 주문
     *
     * @param  User  $user
     * @return static
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * 비회원 주문
     *
     * @return static
     */
    public function forGuest(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    /**
     * 모바일 주문
     *
     * @return static
     */
    public function mobile(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_device' => DeviceTypeEnum::MOBILE,
        ]);
    }
}
