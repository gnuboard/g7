<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 클레임 추가결제 Factory
 */
class ClaimPaymentFactory extends Factory
{
    protected $model = ClaimPayment::class;

    /**
     * 기본 정의
     *
     * @return array 기본 속성 배열
     */
    public function definition(): array
    {
        $faker = \fake();

        return [
            'payable_type' => ClaimTypeEnum::EXCHANGE->value,
            'payable_id' => OrderExchange::factory(),
            'order_id' => Order::factory(),
            'payment_number' => 'CP'.strtoupper($faker->unique()->bothify('##########')),
            'payment_method' => PaymentMethodEnum::CARD->value,
            'pg_provider' => 'tosspayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => $faker->numberBetween(1000, 30000),
            'points_amount' => 0,
            'currency_code' => 'KRW',
            'mc_amount' => null,
            'transaction_id' => null,
            'pg_error_code' => null,
            'pg_error_message' => null,
            'raw_response' => null,
            'paid_at' => null,
            'refunded_amount' => 0,
            'refunded_at' => null,
        ];
    }

    /**
     * 수납 완료 건으로 설정합니다.
     *
     * @return static
     */
    public function paid(): static
    {
        $faker = \fake();

        return $this->state(fn () => [
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'transaction_id' => $faker->unique()->uuid(),
            'paid_at' => now(),
        ]);
    }

    /**
     * 마일리지 결제 건으로 설정합니다.
     *
     * @param  float  $points  마일리지 결제액
     * @return static
     */
    public function withPoints(float $points = 3000): static
    {
        return $this->state(fn () => [
            'payment_method' => PaymentMethodEnum::POINT->value,
            'pg_provider' => null,
            'amount' => 0,
            'points_amount' => $points,
        ]);
    }

    /**
     * 반품 신청서 결제 건으로 설정합니다.
     *
     * @param  OrderReturn|int|null  $return  반품 신청서 (미지정 시 신규 생성)
     * @return static
     */
    public function forReturn(OrderReturn|int|null $return = null): static
    {
        return $this->state(fn () => [
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return instanceof OrderReturn
                ? $return->id
                : ($return ?? OrderReturn::factory()),
        ]);
    }
}
