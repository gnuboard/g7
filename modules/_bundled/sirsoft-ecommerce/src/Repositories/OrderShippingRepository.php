<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;

/**
 * 주문 배송 리포지토리 구현체
 */
class OrderShippingRepository implements OrderShippingRepositoryInterface
{
    /**
     * @param  OrderShipping  $model  주문 배송 모델
     */
    public function __construct(
        protected OrderShipping $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?OrderShipping
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): bool
    {
        return $this->model
            ->where('id', $id)
            ->update($data) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function listByOrderId(int $orderId): Collection
    {
        return $this->model->newQuery()->where('order_id', $orderId)->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function updateRow(OrderShipping $shipping, array $data): bool
    {
        return $shipping->update($data);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByOrderOptionId(int $orderOptionId): int
    {
        return $this->model
            ->where('order_option_id', $orderOptionId)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function countByCarrierId(int $carrierId): int
    {
        return $this->model
            ->where('carrier_id', $carrierId)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function transferByOrderOptionId(int $fromOrderOptionId, int $toOrderOptionId): int
    {
        return $this->model
            ->where('order_option_id', $fromOrderOptionId)
            ->update(['order_option_id' => $toOrderOptionId]);
    }

    /**
     * {@inheritDoc}
     */
    public function countByShippingType(string $shippingType): int
    {
        return $this->model
            ->where('shipping_type', $shippingType)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function findFirstByOrderOptionId(int $orderOptionId): ?OrderShipping
    {
        return $this->model
            ->where('order_option_id', $orderOptionId)
            ->orderBy('id')
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function replicateForOrderOption(OrderShipping $source, int $orderOptionId, array $overrides = []): OrderShipping
    {
        $copy = $source->replicate(['shipped_at', 'estimated_arrival_at', 'delivered_at', 'confirmed_at']);
        $copy->order_option_id = $orderOptionId;

        foreach ($overrides as $column => $value) {
            $copy->{$column} = $value;
        }

        $copy->save();

        return $copy;
    }

    /**
     * {@inheritDoc}
     */
    public function updateTrackingByOrderOptionId(int $orderOptionId, array $tracking): ?OrderShipping
    {
        $payload = array_filter(
            [
                'carrier_id' => $tracking['carrier_id'] ?? null,
                'tracking_number' => $tracking['tracking_number'] ?? null,
            ],
            static fn ($value) => $value !== null && $value !== ''
        );

        // 송장도 상태도 없으면 쓸 것이 없다. 송장 없이 상태만 옮기는 호출(직접 건넨 교환품의 배송완료)은 받는다.
        if ($payload === [] && ! isset($tracking['shipping_status'])) {
            return null;
        }

        $shipping = $this->model
            ->where('order_option_id', $orderOptionId)
            ->first();

        // 배송 레코드는 주문 생성 시점에 만들어진다. 없으면 정책 파생 컬럼을 채울 수 없으므로
        // 생성하지 않고 갱신을 건너뛴다(상태 전이 자체는 막지 않음).
        if ($shipping === null) {
            return null;
        }

        // 배송 진행 상태·시각은 호출자(상태 전이)가 함께 넘긴다. 송장만 정정하는 호출에는
        // 들어 있지 않으므로 그때는 상태가 보존된다.
        if (isset($tracking['shipping_status'])) {
            $payload['shipping_status'] = $tracking['shipping_status'];
        }

        // 발생 시각은 최초 1회만 남긴다 — 같은 단계를 다시 지나도 원래 시각을 덮지 않는다.
        foreach (['shipped_at', 'delivered_at'] as $timestamp) {
            if (isset($tracking[$timestamp]) && $shipping->{$timestamp} === null) {
                $payload[$timestamp] = $tracking[$timestamp];
            }
        }

        $shipping->fill($payload)->save();

        return $shipping;
    }

    /**
     * {@inheritDoc}
     */
    public function updateProgressByOrderOptionId(int $orderOptionId, array $state): int
    {
        if (! isset($state['shipping_status'])) {
            return 0;
        }

        $updated = 0;

        foreach ($this->model->where('order_option_id', $orderOptionId)->get() as $shipping) {
            $payload = ['shipping_status' => $state['shipping_status']];

            foreach (['shipped_at', 'delivered_at'] as $timestamp) {
                if (isset($state[$timestamp]) && $shipping->{$timestamp} === null) {
                    $payload[$timestamp] = $state[$timestamp];
                }
            }

            $shipping->fill($payload)->save();
            $updated++;
        }

        return $updated;
    }
}
