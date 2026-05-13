<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryRepositoryInterface;

/**
 * 카테고리 Repository 구현체
 */
class CategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        protected Category $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getHierarchical(array $filters = [], array $with = []): Collection
    {
        // 검색 키워드가 있으면 Scout 사용
        if (! empty($filters['search'])) {
            return Category::search($filters['search'])
                ->query(function ($query) use ($filters, $with) {
                    if (isset($filters['parent_id'])) {
                        $query->where('parent_id', $filters['parent_id']);
                    } else {
                        $query->whereNull('parent_id');
                    }
                    if (isset($filters['is_active'])) {
                        $query->where('is_active', $filters['is_active']);
                    }
                    $query->withCount('products');
                    if (! empty($with)) {
                        $query->with($with);
                    }
                    $query->orderBy('sort_order')->orderBy('id');
                })
                ->get();
        }

        $query = $this->model->newQuery();

        // 부모 ID 필터
        if (isset($filters['parent_id'])) {
            $query->where('parent_id', $filters['parent_id']);
        } else {
            $query->whereNull('parent_id');
        }

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 상품 수 카운트 추가
        $query->withCount('products');

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        // 정렬
        $query->orderBy('sort_order')->orderBy('id');

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $with = []): ?Category
    {
        $query = $this->model->newQuery();

        if (!empty($with)) {
            $query->with($with);
        }

        // 상품 수 및 자식 수 카운트 추가
        $query->withCount(['products', 'children']);

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Category
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): Category
    {
        $category = $this->findById($id);
        $category->update($data);
        return $category->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $category = $this->findById($id);
        return $category->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function hasChildren(int $id): bool
    {
        return $this->model->where('parent_id', $id)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function getProductCount(int $id): int
    {
        $category = $this->findById($id);
        return $category->products()->count();
    }

    /**
     * {@inheritDoc}
     */
    public function getNextSortOrder(?int $parentId = null): int
    {
        $maxSortOrder = $this->model
            ->where('parent_id', $parentId)
            ->max('sort_order');

        return $maxSortOrder !== null ? $maxSortOrder + 1 : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function existsBySlug(string $slug, ?int $excludeId = null): bool
    {
        $query = $this->model->where('slug', $slug);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function findBySlug(string $slug, array $with = []): ?Category
    {
        $query = $this->model->newQuery();

        if (! empty($with)) {
            $query->with($with);
        }

        $query->withCount(['products', 'children']);

        return $query->where('slug', $slug)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function getFlatList(array $filters = [], array $with = []): Collection
    {
        // 검색 키워드가 있으면 Scout 사용
        if (! empty($filters['search'])) {
            return Category::search($filters['search'])
                ->query(function ($query) use ($filters, $with) {
                    if (isset($filters['is_active'])) {
                        $query->where('is_active', $filters['is_active']);
                    }
                    if (isset($filters['max_depth'])) {
                        $query->where('depth', '<', $filters['max_depth']);
                    }
                    if (! empty($with)) {
                        $query->with($with);
                    }
                    $query->orderBy('depth')->orderBy('sort_order')->orderBy('id');
                })
                ->get();
        }

        $query = $this->model->newQuery();

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // depth 필터 (최대 깊이 제한)
        if (isset($filters['max_depth'])) {
            $query->where('depth', '<', $filters['max_depth']);
        }

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        // 정렬: depth → sort_order → id
        $query->orderBy('depth')->orderBy('sort_order')->orderBy('id');

        return $query->get();
    }
}
