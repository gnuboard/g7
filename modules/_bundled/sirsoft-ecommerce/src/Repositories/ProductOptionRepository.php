<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;

/**
 * 상품 옵션 Repository 구현체
 */
class ProductOptionRepository implements ProductOptionRepositoryInterface
{
    public function __construct(
        protected ProductOption $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?ProductOption
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithRelations(int $id, array $with = []): ?ProductOption
    {
        return $this->model->with($with)->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function getByProductId(int $productId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->model->where('product_id', $productId)->get();
    }

    /**
     * 옵션 가격 조정액(price_adjustment) 일괄 변경
     *
     * 옵션의 최종 판매가 = 상품 판매가 + price_adjustment
     *
     * @param array $optionIds 옵션 ID 배열
     * @param string $method 변경 방식 (increase, decrease, fixed)
     * @param int $value 변경 값
     * @param string $unit 단위 (won, percent) - percent는 현재 조정액 기준 비율
     * @return int 업데이트된 레코드 수
     */
    public function bulkUpdatePrice(array $optionIds, string $method, int $value, string $unit): int
    {
        if (empty($optionIds)) {
            return 0;
        }

        // 고정 값으로 설정
        if ($method === 'fixed') {
            if ($unit === 'percent') {
                // 고정 퍼센트는 의미가 없으므로 무시
                return 0;
            }

            return $this->model->whereIn('id', $optionIds)
                ->update(['price_adjustment' => $value]);
        }

        // 개별 레코드 업데이트 (증가/감소)
        $options = $this->model->whereIn('id', $optionIds)->get();
        $updatedCount = 0;

        foreach ($options as $option) {
            $currentValue = $option->price_adjustment ?? 0;

            if ($unit === 'percent') {
                // 퍼센트 계산 (현재 조정액 기준)
                $multiplier = $method === 'increase'
                    ? (1 + $value / 100)
                    : (1 - $value / 100);
                $newValue = (int) floor($currentValue * $multiplier);
            } else {
                // 원 단위 계산
                $newValue = $method === 'increase'
                    ? $currentValue + $value
                    : $currentValue - $value;
            }

            $option->price_adjustment = $newValue;
            $option->save();
            $updatedCount++;
        }

        return $updatedCount;
    }

    /**
     * 옵션 재고 일괄 변경
     *
     * @param array $optionIds 옵션 ID 배열
     * @param string $method 변경 방식 (increase, decrease, set)
     * @param int $value 변경 값
     * @return int 업데이트된 레코드 수
     */
    public function bulkUpdateStock(array $optionIds, string $method, int $value): int
    {
        if (empty($optionIds)) {
            return 0;
        }

        // 고정 값으로 설정
        if ($method === 'set') {
            return $this->model->whereIn('id', $optionIds)
                ->update(['stock_quantity' => $value]);
        }

        // 개별 레코드 업데이트 (증가/감소)
        $options = $this->model->whereIn('id', $optionIds)->get();
        $updatedCount = 0;

        foreach ($options as $option) {
            $currentValue = $option->stock_quantity ?? 0;

            if ($method === 'increase') {
                $newValue = $currentValue + $value;
            } else {
                // 감소 시 0 미만으로 내려가지 않도록 처리
                $newValue = max(0, $currentValue - $value);
            }

            $option->stock_quantity = $newValue;
            $option->save();
            $updatedCount++;
        }

        return $updatedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ?ProductOption
    {
        $option = $this->model->find($id);

        if (! $option) {
            return null;
        }

        $option->update($data);

        return $option->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateFields(array $ids, array $fields): int
    {
        if (empty($ids) || empty($fields)) {
            return 0;
        }

        return $this->model
            ->whereIn('id', $ids)
            ->update($fields);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithLock(int $id): ?ProductOption
    {
        return $this->model->lockForUpdate()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function decrementStock(int $id, int $quantity): bool
    {
        $affected = $this->model
            ->where('id', $id)
            ->where('stock_quantity', '>=', $quantity)
            ->decrement('stock_quantity', $quantity);

        return $affected > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function incrementStock(int $id, int $quantity): bool
    {
        $affected = $this->model
            ->where('id', $id)
            ->increment('stock_quantity', $quantity);

        return $affected > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getIdsByProductIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return $this->model
            ->whereIn('product_id', $productIds)
            ->pluck('id')
            ->toArray();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $optionIds): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($optionIds)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return $this->model
            ->whereIn('id', $optionIds)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdsWithProduct(array $optionIds): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($optionIds)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return $this->model
            ->with(['product', 'product.images'])
            ->whereIn('id', $optionIds)
            ->get();
    }

    /**
     * ID 목록으로 옵션을 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  옵션 ID 목록
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($ids)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return $this->model->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * 옵션 ID 목록으로부터 고유 product_id 목록을 추출합니다.
     *
     * @param  array<int, int>  $optionIds  옵션 ID 목록
     * @return array<int, int> 고유 product_id 배열
     */
    public function pluckProductIds(array $optionIds): array
    {
        if (empty($optionIds)) {
            return [];
        }

        return $this->model
            ->whereIn('id', $optionIds)
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 특정 상품의 옵션 stock 합계를 반환합니다.
     *
     * @param  int  $productId  상품 ID
     * @return int 합계 stock
     */
    public function sumStockByProduct(int $productId): int
    {
        return (int) $this->model->where('product_id', $productId)->sum('stock_quantity');
    }

    /**
     * 특정 상품의 옵션 stock 합계를 반환하되, 지정한 옵션 ID 들은 제외합니다.
     *
     * @param  int  $productId  상품 ID
     * @param  array<int, int>  $excludedOptionIds  제외할 옵션 ID 목록
     * @return int 합계 stock
     */
    public function sumStockByProductExcluding(int $productId, array $excludedOptionIds): int
    {
        $query = $this->model->where('product_id', $productId);
        if (! empty($excludedOptionIds)) {
            $query->whereNotIn('id', $excludedOptionIds);
        }

        return (int) $query->sum('stock_quantity');
    }
}
