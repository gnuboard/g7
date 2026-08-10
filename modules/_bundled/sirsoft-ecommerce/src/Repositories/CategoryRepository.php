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
     * Sitemap 용으로 활성 카테고리를 스트리밍 조회합니다.
     *
     * lazyById 는 id 기준 키셋 페이징으로 청크를 순차 조회하므로,
     * 결과셋 전체가 메모리(및 DB 드라이버 버퍼)에 적재되지 않습니다.
     *
     * @param  int  $chunkSize  청크 크기
     * @return iterable<Category> 활성 카테고리 순회자 (id, slug, updated_at 만 조회)
     */
    public function streamActiveForSitemap(int $chunkSize = 500): iterable
    {
        return $this->model->newQuery()
            ->where('is_active', true)
            ->select(['id', 'slug', 'updated_at'])
            ->orderBy('id')
            ->lazyById($chunkSize);
    }

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
    public function findById(int $id, array $with = [], bool $withCounts = false): ?Category
    {
        $query = $this->model->newQuery();

        if (! empty($with)) {
            $query->with($with);
        }

        // 상품 수 및 자식 수 카운트는 화면에서만 쓴다. 재정렬 같은 쓰기 루프는 이 값을
        // 읽지 않으므로, 무조건 계산하면 항목마다 집계 서브쿼리가 두 개씩 따라붙는다.
        if ($withCounts) {
            $query->withCount(['products', 'children']);
        }

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdsKeyed(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            return new Collection;
        }

        return $this->model->newQuery()->whereIn('id', $ids)->get()->keyBy('id');
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
        // 재정렬은 항목 수만큼 이 메서드를 부른다. 집계를 켜 두면 항목마다 상품 수·자식 수
        // 서브쿼리가 두 개씩 붙지만 쓰기 경로는 그 값을 읽지 않는다.
        $category = $this->findById($id, withCounts: false);
        $category->update($data);

        return $category->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $category = $this->findById($id, withCounts: false);

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
        // 아래에서 products()->count() 를 직접 세므로 withCount 집계는 버려진다
        $category = $this->findById($id, withCounts: false);

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
    public function findBySlug(string $slug, array $with = [], bool $withCounts = false): ?Category
    {
        $query = $this->model->newQuery();

        if (! empty($with)) {
            $query->with($with);
        }

        if ($withCounts) {
            $query->withCount(['products', 'children']);
        }

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
