<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;

/**
 * 교환 신청서 리포지토리 구현체
 */
class OrderExchangeRepository implements OrderExchangeRepositoryInterface
{
    /**
     * @param  OrderExchange  $model  교환 신청서 모델
     */
    public function __construct(
        protected OrderExchange $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): OrderExchange
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(OrderExchange $exchange, array $data): OrderExchange
    {
        $exchange->update($data);

        return $exchange->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $relations = []): ?OrderExchange
    {
        return $this->model->newQuery()
            ->with($relations)
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByNumber(string $exchangeNumber, array $relations = []): ?OrderExchange
    {
        return $this->model->newQuery()
            ->with($relations)
            ->where('exchange_number', $exchangeNumber)
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
    public function findForUpdate(int $id): ?OrderExchange
    {
        return $this->model->newQuery()
            ->lockForUpdate()
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function sumActiveQuantityByOrderOption(int $orderOptionId, ?int $excludeExchangeId = null): int
    {
        $finalValues = array_map(
            fn (ExchangeStatusEnum $status) => $status->value,
            ExchangeStatusEnum::finalStatuses(),
        );

        $query = OrderExchangeOption::query()
            ->where('order_option_id', $orderOptionId)
            ->whereNotIn('option_status', $finalValues);

        if ($excludeExchangeId !== null) {
            $query->where('order_exchange_id', '!=', $excludeExchangeId);
        }

        return (int) $query->sum('exchange_quantity');
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
