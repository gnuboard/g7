<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;

/**
 * 주문 취소 리포지토리 구현체
 */
class OrderCancelRepository implements OrderCancelRepositoryInterface
{
    /**
     * @param  OrderCancel  $model  주문 취소 모델
     */
    public function __construct(
        protected OrderCancel $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderCancel
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function latestByOrderId(int $orderId): ?OrderCancel
    {
        return $this->model->newQuery()
            ->where('order_id', $orderId)
            ->latest('id')
            ->first();
    }
}
