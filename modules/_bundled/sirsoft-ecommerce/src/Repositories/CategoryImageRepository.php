<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;

/**
 * 카테고리 이미지 Repository 구현체
 */
class CategoryImageRepository implements CategoryImageRepositoryInterface
{
    /**
     * @param  CategoryImage  $model  카테고리 이미지 모델
     */
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
    public function deleteByCategoryId(int $categoryId): int
    {
        return $this->model->where('category_id', $categoryId)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findStaleTempImages(Carbon $threshold, int $limit): Collection
    {
        return $this->model->newQuery()
            ->withTrashed()
            ->whereNotNull('temp_key')
            ->whereNull('category_id')
            ->where('created_at', '<', $threshold)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'temp_key', 'disk', 'path', 'created_at', 'deleted_at']);
    }

    /**
     * {@inheritDoc}
     */
    public function forceDeleteById(int $id): bool
    {
        $image = $this->model->newQuery()->withTrashed()->find($id);

        return $image?->forceDelete() ?? false;
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

    /**
     * {@inheritDoc}
     */
    public function countByMimeTypes(array $mimeTypes): int
    {
        return $this->model->newQuery()
            ->whereNotNull('category_id')
            ->whereIn('mime_type', $mimeTypes)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function chunkByMimeTypes(array $mimeTypes, int $afterId, int $limit): Collection
    {
        return $this->model->newQuery()
            ->whereNotNull('category_id')
            ->whereIn('mime_type', $mimeTypes)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'disk', 'path', 'mime_type', 'width', 'height']);
    }
}
