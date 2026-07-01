<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelOptionRepositoryInterface;

/**
 * 주문 취소 옵션 리포지토리 구현체
 */
class OrderCancelOptionRepository implements OrderCancelOptionRepositoryInterface
{
    /**
     * @param  OrderCancelOption  $model  주문 취소 옵션 모델
     */
    public function __construct(
        protected OrderCancelOption $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderCancelOption
    {
        return $this->model->create($data);
    }
}
