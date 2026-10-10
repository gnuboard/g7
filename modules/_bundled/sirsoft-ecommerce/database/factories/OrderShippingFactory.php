<?php

namespace Modules\Sirsoft\Ecommerce\Database\Factories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 주문 배송 Factory
 */
class OrderShippingFactory extends Factory
{
    protected $model = OrderShipping::class;

    /**
     * 기본 정의
     *
     * @return array
     */
    public function definition(): array
    {
        $faker = \fake();

        return [
            'order_id' => Order::factory(),
            'order_option_id' => OrderOption::factory(),
            'shipping_policy_id' => null,
            'shipping_status' => ShippingStatusEnum::PENDING,
            'shipping_type' => 'parcel',
            'base_shipping_amount' => $faker->numberBetween(0, 5000),
            'extra_shipping_amount' => 0,
            'total_shipping_amount' => $faker->numberBetween(0, 5000),
            'shipping_discount_amount' => 0,
            'is_remote_area' => false,
            'carrier_id' => null,
            'tracking_number' => null,
            'return_shipping_amount' => 0,
            'package_number' => null,
            'visit_date' => null,
            'visit_time_slot' => null,
            'actual_weight' => null,
            'delivery_policy_snapshot' => null,
            'shipped_at' => null,
            'estimated_arrival_at' => null,
            'delivered_at' => null,
            'confirmed_at' => null,
        ];
    }

    /**
     * 특정 주문의 배송
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
     * 특정 주문 옵션의 배송
     *
     * @param  OrderOption  $orderOption
     * @return static
     */
    public function forOrderOption(OrderOption $orderOption): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $orderOption->order_id,
            'order_option_id' => $orderOption->id,
        ]);
    }

    /**
     * 준비 중 상태
     *
     * @return static
     */
    public function preparing(): static
    {
        return $this->state(fn (array $attributes) => [
            'shipping_status' => ShippingStatusEnum::PREPARING,
            'tracking_number' => null,
            'shipped_at' => null,
        ]);
    }

    /**
     * 배송 중 상태
     *
     * 송장이 있으면 택배사도 있어야 한다 — 택배사를 넘기지 않으면 활성 국내 택배사를 고른다
     * (택배사 행이 하나도 없는 시험 DB 에서만 null).
     *
     * @param  Carbon|null  $shippedAt  발송 시각 (생략하면 지금)
     * @param  int|null  $carrierId  택배사 id
     * @return static
     */
    public function inTransit(?Carbon $shippedAt = null, ?int $carrierId = null): static
    {
        $trackingNumber = \fake()->numerify('############');

        return $this->state(function (array $attributes) use ($trackingNumber, $shippedAt, $carrierId) {
            $shippedAt ??= now();

            return [
                'shipping_status' => ShippingStatusEnum::IN_TRANSIT,
                'carrier_id' => $carrierId ?? $attributes['carrier_id'] ?? self::defaultCarrierId(),
                'tracking_number' => $trackingNumber,
                'shipped_at' => $shippedAt,
                'estimated_arrival_at' => $shippedAt->copy()->addDays(2),
            ];
        });
    }

    /**
     * 배송 완료 상태
     *
     * @param  Carbon|null  $deliveredAt  배송완료 시각 (생략하면 지금, 발송은 그 2일 전)
     * @param  int|null  $carrierId  택배사 id
     * @return static
     */
    public function delivered(?Carbon $deliveredAt = null, ?int $carrierId = null): static
    {
        $trackingNumber = \fake()->numerify('############');

        return $this->state(function (array $attributes) use ($trackingNumber, $deliveredAt, $carrierId) {
            $deliveredAt ??= now();

            return [
                'shipping_status' => ShippingStatusEnum::DELIVERED,
                'carrier_id' => $carrierId ?? $attributes['carrier_id'] ?? self::defaultCarrierId(),
                'tracking_number' => $trackingNumber,
                'shipped_at' => $deliveredAt->copy()->subDays(2),
                'estimated_arrival_at' => $deliveredAt,
                'delivered_at' => $deliveredAt,
            ];
        });
    }

    /**
     * 활성 국내 택배사 id 를 고릅니다 (CJ대한통운 우선).
     *
     * @return int|null 택배사 id (택배사 행이 없으면 null)
     */
    private static function defaultCarrierId(): ?int
    {
        $carriers = ShippingCarrier::query()->where('is_active', true)->where('type', 'domestic')->orderBy('sort_order')->get(['id', 'code']);
        $carrier = $carriers->firstWhere('code', 'cj') ?? $carriers->first();

        return $carrier ? (int) $carrier->id : null;
    }

    /**
     * 방문수령 배송
     *
     * @return static
     */
    public function pickup(): static
    {
        return $this->state(fn (array $attributes) => [
            'shipping_type' => 'pickup',
            'carrier_id' => null,
            'tracking_number' => null,
            'visit_date' => now()->addDays(3),
            'visit_time_slot' => '14:00-18:00',
        ]);
    }
}
