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
}
