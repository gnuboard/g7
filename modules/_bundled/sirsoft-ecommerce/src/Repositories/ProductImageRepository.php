<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductImageRepositoryInterface;

/**
 * 상품 이미지 Repository 구현체
 */
class ProductImageRepository implements ProductImageRepositoryInterface
{
    public function __construct(
        protected ProductImage $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?ProductImage
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByHash(string $hash): ?ProductImage
    {
        return $this->model->where('hash', $hash)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function getByProductId(int $productId, ?string $collection = null): Collection
    {
        $query = $this->model->where('product_id', $productId);

        if ($collection) {
            $query->where('collection', $collection);
        }

        return $query->orderBy('sort_order')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getByTempKey(string $tempKey, ?string $collection = null): Collection
    {
        $query = $this->model
            ->where('temp_key', $tempKey)
            ->whereNull('product_id');

        if ($collection) {
            $query->where('collection', $collection);
        }

        return $query->orderBy('sort_order')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ProductImage
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ProductImage
    {
        $image = $this->findById($id);
        $image->update($data);

        return $image->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $image = $this->findById($id);

        return $image?->forceDelete() ?? false;
    }

    /**
     * {@inheritDoc}
     */
    public function linkTempImages(string $tempKey, int $productId): int
    {
        return $this->model
            ->where('temp_key', $tempKey)
            ->whereNull('product_id')
            ->update([
                'product_id' => $productId,
                'temp_key' => null,
            ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxSortOrder(int $productId, string $collection): int
    {
        return $this->model
            ->where('product_id', $productId)
            ->where('collection', $collection)
            ->max('sort_order') ?? 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxSortOrderByTempKey(string $tempKey, string $collection): int
    {
        return $this->model
            ->where('temp_key', $tempKey)
            ->where('collection', $collection)
            ->whereNull('product_id')
            ->max('sort_order') ?? 0;
    }

    /**
     * {@inheritDoc}
     */
    public function reorder(array $orders): bool
    {
        foreach ($orders as $id => $sortOrder) {
            $this->model->where('id', $id)->update(['sort_order' => $sortOrder]);
        }

        return true;
    }
}
