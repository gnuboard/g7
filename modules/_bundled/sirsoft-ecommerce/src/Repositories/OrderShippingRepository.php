<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

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
    public function updateTrackingByOrderOptionId(int $orderOptionId, array $tracking): ?OrderShipping
    {
        $payload = array_filter(
            [
                'carrier_id' => $tracking['carrier_id'] ?? null,
                'tracking_number' => $tracking['tracking_number'] ?? null,
            ],
            static fn ($value) => $value !== null && $value !== ''
        );

        if ($payload === []) {
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

        $shipping->fill($payload)->save();

        return $shipping;
    }
}
