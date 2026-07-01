<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

/**
 * 주문 Factory
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * 주문번호 중복 방지용 프로세스 내 단조 증가 시퀀스.
     */
    private static int $orderNumberSequence = 1;

    /**
     * 기본 정의
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
            // 같은 날짜 + 랜덤 5자리는 한 테스트 실행에서 생일역설로 중복(unique 제약 위반) 발생.
            // 프로세스 내 단조 증가 카운터를 부여해 실행 중 중복을 원천 차단.
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.str_pad((string) self::$orderNumberSequence++, 6, '0', STR_PAD_LEFT),
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'order_device' => DeviceTypeEnum::PC,
            'is_first_order' => $faker->boolean(30),
            'ip_address' => $faker->ipv4(),
            'currency' => $this->defaultCurrency(),
            'currency_snapshot' => $this->defaultCurrencySnapshot(),
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
     * 설정의 기본 통화 코드를 반환합니다 (KRW 하드코딩 제거 — base 추종).
     *
     * @return string 기본 통화 코드
     */
    private function defaultCurrency(): string
    {
        return app(CurrencyConversionService::class)->getDefaultCurrency();
    }

    /**
     * 기본 통화 기준 통화 스냅샷을 반환합니다 (OrderProcessingService::buildCurrencySnapshot 형식).
     *
     * @return array 통화 스냅샷
     */
    private function defaultCurrencySnapshot(): array
    {
        $service = app(CurrencyConversionService::class);
        $base = $service->getDefaultCurrency();

        $rates = [];
        foreach ($service->getCurrencySettings() as $currency) {
            $code = $currency['code'];
            $rates[$code] = ($currency['is_default'] ?? false) ? 1.0 : (float) ($currency['exchange_rate'] ?? 0);
        }

        return [
            'base_currency' => $base,
            'order_currency' => $base,
            'exchange_rate' => 1.0,
            'exchange_rates' => $rates,
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 결제 대기 상태
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
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * 비회원 주문
     */
    public function forGuest(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            // 비회원 주문은 조회 비밀번호 해시를 보유 (기본 평문 'guest12')
            'guest_lookup_password_hash' => Hash::make('guest12'),
            // 비회원 주문은 첫 주문 혜택 대상이 아님
            'is_first_order' => false,
        ]);
    }

    /**
     * 모바일 주문
     */
    public function mobile(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_device' => DeviceTypeEnum::MOBILE,
        ]);
    }
}
