<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductAdditionalOptionValueRepositoryInterface;

/**
 * 상품 추가옵션 선택지 Repository 구현체
 */
class ProductAdditionalOptionValueRepository implements ProductAdditionalOptionValueRepositoryInterface
{
    public function __construct(
        protected ProductAdditionalOptionValue $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findActiveByIds(array $valueIds): Collection
    {
        $valueIds = array_values(array_unique(array_filter(array_map('intval', $valueIds))));

        if (empty($valueIds)) {
            return new Collection;
        }

        return $this->model
            ->with('additionalOption')
            ->where('is_active', true)
            ->whereIn('id', $valueIds)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveByProductKeyed(int $productId): Collection
    {
        return $this->getActiveByProductIdsKeyed([$productId])->get($productId) ?? new Collection;
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveByProductIdsKeyed(array $productIds): Collection
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (empty($productIds)) {
            return new Collection;
        }

        // 상품별로 한 번씩 조회하면 장바구니 항목 수만큼 쿼리가 난다. 한 번에 읽고
        // 상품 ID 로 묶어 돌려준다 — 호출자는 상품별 맵을 그대로 쓴다.
        return $this->model
            ->with('additionalOption')
            ->where('is_active', true)
            ->whereHas('additionalOption', fn ($query) => $query->whereIn('product_id', $productIds))
            ->get()
            ->groupBy(fn ($value) => (int) $value->additionalOption?->product_id)
            ->map(fn (Collection $values) => $values->keyBy('id'));
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByAdditionalOptionIds(array $additionalOptionIds): int
    {
        $additionalOptionIds = array_values(array_unique(array_filter(array_map('intval', $additionalOptionIds))));

        if (empty($additionalOptionIds)) {
            return 0;
        }

        return $this->model->whereIn('additional_option_id', $additionalOptionIds)->delete();
    }
}
