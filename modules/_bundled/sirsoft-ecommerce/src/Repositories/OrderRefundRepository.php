<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;

/**
 * 주문 환불 리포지토리 구현체
 */
class OrderRefundRepository implements OrderRefundRepositoryInterface
{
    /**
     * @param  OrderRefund  $model  주문 환불 모델
     */
    public function __construct(
        protected OrderRefund $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderRefund
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function listPendingByOrderId(int $orderId, array $excludeIds = []): Collection
    {
        $finalValues = array_map(
            fn (RefundStatusEnum $status) => $status->value,
            RefundStatusEnum::finalStatuses(),
        );

        $query = $this->model->newQuery()
            ->where('order_id', $orderId)
            ->whereNotIn('refund_status', $finalValues);

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->get();
    }
}
