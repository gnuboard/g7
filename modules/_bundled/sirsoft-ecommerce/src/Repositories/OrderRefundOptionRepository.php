<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;

/**
 * 주문 환불 옵션 리포지토리 구현체
 */
class OrderRefundOptionRepository implements OrderRefundOptionRepositoryInterface
{
    /**
     * @param  OrderRefundOption  $model  주문 환불 옵션 모델
     */
    public function __construct(
        protected OrderRefundOption $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderRefundOption
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(int $refundId, string $status, ?int $processedBy = null): int
    {
        return $this->model->newQuery()
            ->where('order_refund_id', $refundId)
            ->update([
                'option_status' => $status,
                'processed_by' => $processedBy,
                'updated_at' => now(),
            ]);
    }
}
