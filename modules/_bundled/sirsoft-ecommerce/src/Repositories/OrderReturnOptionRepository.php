<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
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
    public function save(OrderReturnOption $option): OrderReturnOption
    {
        $option->save();

        return $option;
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
    public function listCompletedByOrderId(int $orderId, ?int $excludeReturnId = null): Collection
    {
        // 신청 상품 행이 `order_id` 를 직접 들고 있어 조인 없이 주문 단위로 모을 수 있다.
        return $this->model->newQuery()
            ->where('order_id', $orderId)
            ->where('option_status', ReturnStatusEnum::COMPLETED)
            ->when(
                $excludeReturnId !== null,
                fn ($query) => $query->where('order_return_id', '!=', $excludeReturnId)
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function listOptionIdsByReturnIds(array $returnIds): array
    {
        if ($returnIds === []) {
            return [];
        }

        $map = [];

        // 소속 판정에 필요한 두 컬럼만 읽는다 — 결과 크기가 요청에 실린 신청서 수에 묶인다.
        foreach ($this->model->newQuery()->whereIn('order_return_id', $returnIds)->get(['id', 'order_return_id']) as $row) {
            $map[(int) $row->order_return_id][] = (int) $row->id;
        }

        return $map;
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
}
