<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnOptionRepositoryInterface;

/**
 * 반품 신청 상품 리포지토리 구현체
 */
class OrderReturnOptionRepository implements OrderReturnOptionRepositoryInterface
{
    /**
     * @param  OrderReturnOption  $model  반품 신청 상품 모델
     */
    public function __construct(
        protected OrderReturnOption $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderReturnOption
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(OrderReturnOption $option, array $data): OrderReturnOption
    {
        $option->update($data);

        return $option->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $relations = []): ?OrderReturnOption
    {
        return $this->model->newQuery()
            ->with($relations)
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function listByReturnId(int $returnId, array $relations = []): Collection
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('order_return_id', $returnId)
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(int $returnId, string $status, array $optionIds = []): int
    {
        $query = $this->model->newQuery()
            ->where('order_return_id', $returnId);

        if ($optionIds !== []) {
            $query->whereIn('id', $optionIds);
        }

        return $query->update([
            'option_status' => $status,
            'updated_at' => now(),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByReturnId(int $returnId): int
    {
        return $this->model->newQuery()
            ->where('order_return_id', $returnId)
            ->delete();
    }
}
