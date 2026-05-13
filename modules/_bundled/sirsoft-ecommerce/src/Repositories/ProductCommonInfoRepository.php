<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductCommonInfoRepositoryInterface;

/**
 * 공통정보 Repository 구현체
 */
class ProductCommonInfoRepository implements ProductCommonInfoRepositoryInterface
{
    public function __construct(
        protected ProductCommonInfo $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getAll(array $filters = [], array $with = []): Collection
    {
        // 검색 키워드가 있으면 Scout 사용
        if (! empty($filters['search'])) {
            return ProductCommonInfo::search($filters['search'])
                ->query(function ($query) use ($filters, $with) {
                    $query->withCount('products');
                    if (isset($filters['is_active'])) {
                        $query->where('is_active', $filters['is_active']);
                    }
                    if (isset($filters['is_default'])) {
                        $query->where('is_default', $filters['is_default']);
                    }
                    $query->orderBy('sort_order')->orderBy('id');
                    if (! empty($with)) {
                        $query->with($with);
                    }
                })
                ->get();
        }

        $query = $this->model->newQuery();

        // 상품 수 조회를 위한 withCount
        $query->withCount('products');

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 기본 설정 필터
        if (isset($filters['is_default'])) {
            $query->where('is_default', $filters['is_default']);
        }

        // 정렬: 기본은 sort_order → id
        $query->orderBy('sort_order')->orderBy('id');

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getPaginated(array $filters = [], int $perPage = 20, array $with = []): LengthAwarePaginator
    {
        // 검색 키워드가 있으면 Scout 사용
        if (! empty($filters['search'])) {
            return ProductCommonInfo::search($filters['search'])
                ->query(function ($query) use ($filters, $with) {
                    $query->withCount('products');
                    if (isset($filters['is_active'])) {
                        $query->where('is_active', $filters['is_active']);
                    }
                    if (isset($filters['is_default'])) {
                        $query->where('is_default', $filters['is_default']);
                    }
                    $query->orderBy('sort_order')->orderBy('id');
                    if (! empty($with)) {
                        $query->with($with);
                    }
                })
                ->paginate($perPage);
        }

        $query = $this->model->newQuery();

        // 상품 수 조회를 위한 withCount
        $query->withCount('products');

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 기본 설정 필터
        if (isset($filters['is_default'])) {
            $query->where('is_default', $filters['is_default']);
        }

        // 정렬: 기본은 sort_order → id
        $query->orderBy('sort_order')->orderBy('id');

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $with = []): ?ProductCommonInfo
    {
        $query = $this->model->newQuery();

        if (! empty($with)) {
            $query->with($with);
        }

        // 상품 수 카운트 추가
        $query->withCount('products');

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ProductCommonInfo
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ProductCommonInfo
    {
        $commonInfo = $this->findById($id);
        $commonInfo->update($data);

        return $commonInfo->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $commonInfo = $this->findById($id);

        return $commonInfo->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function getProductCount(int $id): int
    {
        $commonInfo = $this->findById($id);

        return $commonInfo ? $commonInfo->products()->count() : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function clearDefault(?int $exceptId = null): int
    {
        $query = $this->model->where('is_default', true);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->update(['is_default' => false]);
    }

    /**
     * {@inheritDoc}
     */
    public function findDefault(): ?ProductCommonInfo
    {
        return $this->model->where('is_default', true)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxSortOrder(): int
    {
        return (int) $this->model->max('sort_order');
    }
}
