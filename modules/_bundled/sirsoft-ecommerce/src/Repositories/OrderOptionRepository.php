<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
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
}
