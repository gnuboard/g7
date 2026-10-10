<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;

/**
 * 교환 신청 상품 리포지토리 구현체
 */
class OrderExchangeOptionRepository implements OrderExchangeOptionRepositoryInterface
{
    /**
     * @param  OrderExchangeOption  $model  교환 신청 상품 모델
     */
    public function __construct(
        protected OrderExchangeOption $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderExchangeOption
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(OrderExchangeOption $option, array $data): OrderExchangeOption
    {
        $option->update($data);

        return $option->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function save(OrderExchangeOption $option): OrderExchangeOption
    {
        $option->save();

        return $option;
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $relations = []): ?OrderExchangeOption
    {
        return $this->model->newQuery()
            ->with($relations)
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function listByExchangeId(int $exchangeId, array $relations = []): Collection
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('order_exchange_id', $exchangeId)
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function listByNewOrderOptionIds(array $orderOptionIds, array $relations = []): Collection
    {
        if ($orderOptionIds === []) {
            return new Collection;
        }

        return $this->model->newQuery()
            ->with($relations)
            ->whereIn('new_order_option_id', $orderOptionIds)
            ->orderBy('id')
            ->get(); // audit:allow query-unbounded-get reason: 한 주문의 후속 주문옵션 수에 묶인다
    }

    /**
     * {@inheritDoc}
     */
    public function listOptionIdsByExchangeIds(array $exchangeIds): array
    {
        if ($exchangeIds === []) {
            return [];
        }

        $map = [];

        // 소속 판정에 필요한 두 컬럼만 읽는다 — 결과 크기가 요청에 실린 신청서 수에 묶인다.
        foreach ($this->model->newQuery()->whereIn('order_exchange_id', $exchangeIds)->get(['id', 'order_exchange_id']) as $row) {
            $map[(int) $row->order_exchange_id][] = (int) $row->id;
        }

        return $map;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(int $exchangeId, string $status, array $optionIds = []): int
    {
        $query = $this->model->newQuery()
            ->where('order_exchange_id', $exchangeId);

        if ($optionIds !== []) {
            $query->whereIn('id', $optionIds);
        }

        return $query->update([
            'option_status' => $status,
            'updated_at' => now(),
        ]);
    }
}
