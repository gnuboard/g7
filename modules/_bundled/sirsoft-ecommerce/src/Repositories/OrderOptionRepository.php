<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;

/**
 * 주문 옵션 리포지토리
 *
 * 주문 옵션의 데이터 접근을 담당합니다.
 */
class OrderOptionRepository implements OrderOptionRepositoryInterface
{
    public function __construct(
        protected OrderOption $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findOrFail(int $id): OrderOption
    {
        return $this->model->findOrFail($id);
    }

    /**
     * {@inheritDoc}
     */
    public function update(OrderOption $option, array $data): bool
    {
        return $option->update($data);
    }

    /**
     * {@inheritDoc}
     */
    public function save(OrderOption $option): bool
    {
        return $option->save();
    }

    /**
     * {@inheritDoc}
     */
    public function countByProductId(int $productId): int
    {
        return $this->model->where('product_id', $productId)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function existsByProductOptionIds(array $productOptionIds): bool
    {
        if (empty($productOptionIds)) {
            return false;
        }

        return $this->model->whereIn('product_option_id', $productOptionIds)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function findMergeCandidate(OrderOption $option, OrderStatusEnum $status): ?OrderOption
    {
        // 병합 조건: 동일 주문 + 동일 상품 + 동일 상품옵션 + 동일 상태 + 형제/부모-자식 관계
        return $this->model
            ->where('id', '!=', $option->id)
            ->where('order_id', $option->order_id)
            ->where('product_id', $option->product_id)
            ->where('product_option_id', $option->product_option_id)
            ->where('option_status', $status)
            ->where(function ($query) use ($option) {
                // 형제 관계 (같은 parent_option_id)
                if ($option->parent_option_id) {
                    $query->where('parent_option_id', $option->parent_option_id)
                        // 부모-자식 관계
                        ->orWhere('id', $option->parent_option_id);
                }
                // 자신이 부모인 경우 → 자식 중 같은 상태 검색
                $query->orWhere('parent_option_id', $option->id);
            })
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(OrderOption $option): bool
    {
        return (bool) $option->delete();
    }

    /**
     * ID 목록으로 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  ID 목록
     * @return Collection<int, OrderOption> id => OrderOption 매핑
     */
    public function findByIdsKeyed(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return OrderOption::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * {@inheritDoc}
     */
    public function getSnapshotsByIds(array $ids): array
    {
        return OrderOption::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();
    }

    /**
     * {@inheritDoc}
     */
    public function getOrderIdsByOptionIds(array $optionIds): array
    {
        return OrderOption::whereIn('id', $optionIds)->pluck('order_id')->unique()->toArray();
    }

    /**
     * {@inheritDoc}
     */
    public function transferChildren(int $fromParentId, int $toParentId): int
    {
        return OrderOption::where('parent_option_id', $fromParentId)
            ->update(['parent_option_id' => $toParentId]);
    }

    /**
     * {@inheritDoc}
     */
    public function clearStockDeductedForCancelledOptions(int $orderId, int $productOptionId): int
    {
        return OrderOption::where('order_id', $orderId)
            ->where('product_option_id', $productOptionId)
            ->where('option_status', OrderStatusEnum::CANCELLED->value)
            ->where('is_stock_deducted', true)
            ->update(['is_stock_deducted' => false]);
    }

    /**
     * {@inheritDoc}
     */
    public function sumNetSalesOnDate(string $date): float
    {
        // 컬럼명은 비한정(unqualified) 사용 — quantity/cancelled_quantity/unit_price 는
        // order_options 에만 존재하므로 join 상황에서도 모호하지 않다 (테이블 prefix 비의존).
        return (float) $this->salesEligibleOnDateQuery($date)
            ->selectRaw('COALESCE(SUM(unit_price * (quantity - cancelled_quantity)), 0) as total')
            ->value('total');
    }

    /**
     * {@inheritDoc}
     */
    public function sumNetQuantityOnDate(string $date): int
    {
        return (int) $this->salesEligibleOnDateQuery($date)
            ->selectRaw('COALESCE(SUM(quantity - cancelled_quantity), 0) as total')
            ->value('total');
    }

    /**
     * {@inheritDoc}
     */
    public function countByOptionStatusOnDate(string $date): array
    {
        $rows = $this->orderedOnDateQuery($date)
            ->selectRaw('option_status as status, SUM(quantity) as qty')
            ->groupBy('option_status')
            ->pluck('qty', 'status');

        $counts = [];
        foreach ($rows as $status => $qty) {
            $counts[$status] = (int) $qty;
        }

        return $counts;
    }

    /**
     * 매출 반영 상태 + 특정 주문일(ordered_at) 옵션 쿼리를 구성합니다.
     *
     * 주문상품(option)을 주문(order)에 조인하여 주문일 기준으로 귀속하고,
     * 매출 반영 상태(option_status)와 삭제되지 않은 주문만 포함합니다.
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return Builder<OrderOption> 쿼리 빌더
     */
    protected function salesEligibleOnDateQuery(string $date): Builder
    {
        $optionsTable = $this->model->getTable();

        return $this->orderedOnDateQuery($date)
            ->whereIn("{$optionsTable}.option_status", OrderStatusEnum::salesEligibleValues());
    }

    /**
     * 특정 주문일(orders.ordered_at)에 귀속되는 옵션 쿼리를 구성합니다.
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return Builder<OrderOption> 쿼리 빌더
     */
    protected function orderedOnDateQuery(string $date): Builder
    {
        $optionsTable = $this->model->getTable();
        $ordersTable = (new Order)->getTable();

        return $this->model->newQuery()
            ->join($ordersTable, "{$ordersTable}.id", '=', "{$optionsTable}.order_id")
            ->whereNull("{$ordersTable}.deleted_at")
            ->whereDate("{$ordersTable}.ordered_at", $date);
    }
}
