<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;

/**
 * 카테고리 이미지 Repository 구현체
 */
class CategoryImageRepository implements CategoryImageRepositoryInterface
{
    public function __construct(
        protected CategoryImage $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?CategoryImage
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByHash(string $hash): ?CategoryImage
    {
        return $this->model->where('hash', $hash)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function getByCategoryId(int $categoryId, ?string $collection = null): Collection
    {
        $query = $this->model->where('category_id', $categoryId);

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
            ->whereNull('category_id');

        if ($collection) {
            $query->where('collection', $collection);
        }

        return $query->orderBy('sort_order')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): CategoryImage
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): CategoryImage
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

        return $image?->delete() ?? false;
    }

    /**
     * {@inheritDoc}
     */
    public function linkTempImages(string $tempKey, int $categoryId): int
    {
        return $this->model
            ->where('temp_key', $tempKey)
            ->whereNull('category_id')
            ->update([
                'category_id' => $categoryId,
                'temp_key' => null,
            ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxSortOrder(int $categoryId, string $collection): int
    {
        return $this->model
            ->where('category_id', $categoryId)
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
            ->whereNull('category_id')
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
