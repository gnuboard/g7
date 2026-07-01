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
        return $this->model
            ->with('additionalOption')
            ->where('is_active', true)
            ->whereHas('additionalOption', fn ($query) => $query->where('product_id', $productId))
            ->get()
            ->keyBy('id');
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
