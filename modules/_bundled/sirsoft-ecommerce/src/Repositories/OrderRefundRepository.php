<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

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
}
