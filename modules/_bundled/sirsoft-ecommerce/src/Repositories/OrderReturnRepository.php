<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;

/**
 * 반품 신청서 리포지토리 구현체
 */
class OrderReturnRepository implements OrderReturnRepositoryInterface
{
    /**
     * @param  OrderReturn  $model  반품 신청서 모델
     */
    public function __construct(
        protected OrderReturn $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderReturn
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(OrderReturn $return, array $data): OrderReturn
    {
        $return->update($data);

        return $return->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $relations = []): ?OrderReturn
    {
        return $this->model->newQuery()
            ->with($relations)
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByNumber(string $returnNumber, array $relations = []): ?OrderReturn
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('return_number', $returnNumber)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function listByOrderId(int $orderId, array $relations = []): Collection
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findForUpdate(int $id): ?OrderReturn
    {
        return $this->model->newQuery()
            ->lockForUpdate()
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function sumActiveQuantityByOrderOption(int $orderOptionId, ?int $excludeReturnId = null): int
    {
        $finalValues = array_map(
            fn (ReturnStatusEnum $status) => $status->value,
            ReturnStatusEnum::finalStatuses(),
        );

        $query = OrderReturnOption::query()
            ->where('order_option_id', $orderOptionId)
            ->whereNotIn('option_status', $finalValues);

        if ($excludeReturnId !== null) {
            $query->where('order_return_id', '!=', $excludeReturnId);
        }

        return (int) $query->sum('return_quantity');
    }

    /**
     * {@inheritDoc}
     */
    public function countByReasonCode(string $reasonCode): int
    {
        return $this->model->newQuery()
            ->where('reason_code', $reasonCode)
            ->count();
    }
}
