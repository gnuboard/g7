<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Helpers\PermissionHelper;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\BrandRepositoryInterface;

/**
 * 브랜드 Repository 구현체
 */
class BrandRepository implements BrandRepositoryInterface
{
    public function __construct(
        protected Brand $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getAll(array $filters = [], array $with = []): Collection
    {
        $query = $this->model->newQuery();

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.brands.read');

        // 상품 수 조회를 위한 withCount (목록에서 products_count 표시)
        $query->withCount('products');

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 검색 키워드가 있으면 Scout 사용
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];

            return Brand::search($keyword)
                ->query(function ($query) use ($filters, $keyword, $with) {
                    // 권한 스코프 필터링
                    PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.brands.read');

                    // 상품 수 조회
                    $query->withCount('products');

                    // 활성 상태 필터
                    if (isset($filters['is_active'])) {
                        $query->where('is_active', $filters['is_active']);
                    }

                    // FULLTEXT 외 필드 OR 조건 (slug, website)
                    $query->where(function ($q) use ($keyword) {
                        $q->orWhere('slug', 'like', "%{$keyword}%")
                            ->orWhere('website', 'like', "%{$keyword}%");
                    });

                    // 정렬 처리
                    $locale = $filters['locale'] ?? app()->getLocale();
                    $this->applySorting($query, $filters, $locale);

                    // Eager loading
                    if (! empty($with)) {
                        $query->with($with);
                    }
                })
                ->get();
        }

        // 정렬 처리
        $locale = $filters['locale'] ?? app()->getLocale();
        $this->applySorting($query, $filters, $locale);

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $with = []): ?Brand
    {
        $query = $this->model->newQuery();

        if (!empty($with)) {
            $query->with($with);
        }

        // 상품 수 카운트 추가
        $query->withCount('products');

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Brand
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): Brand
    {
        $brand = $this->findById($id);
        $brand->update($data);
        return $brand->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $brand = $this->findById($id);
        return $brand->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function getProductCount(int $id): int
    {
        $brand = $this->findById($id);
        return $brand->products()->count();
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
     * 정렬 조건을 쿼리에 적용합니다.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Eloquent 쿼리 빌더
     * @param array $filters 필터 배열
     * @param string $locale 로케일
     * @return void
     */
    private function applySorting($query, array $filters, string $locale): void
    {
        if (! empty($filters['sort'])) {
            switch ($filters['sort']) {
                case 'name_asc':
                    $query->orderBy("name->{$locale}")->orderBy('id');
                    break;
                case 'name_desc':
                    $query->orderBy("name->{$locale}", 'desc')->orderBy('id', 'desc');
                    break;
                case 'created_desc':
                    $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
                    break;
                case 'created_asc':
                    $query->orderBy('created_at')->orderBy('id');
                    break;
                default:
                    $query->orderBy('sort_order')->orderBy("name->{$locale}")->orderBy('id');
            }
        } elseif (! empty($filters['sort_by'])) {
            // 레거시 지원
            $sortBy = $filters['sort_by'];
            $sortOrder = $filters['sort_order'] ?? 'asc';

            if ($sortBy === 'name') {
                $query->orderBy("name->{$locale}", $sortOrder)->orderBy('id', $sortOrder);
            } elseif ($sortBy === 'sort_order') {
                $query->orderBy('sort_order', $sortOrder)->orderBy("name->{$locale}")->orderBy('id');
            }
        } else {
            // 기본 정렬: 이름 → ID (정렬 파라미터 없을 때)
            $query->orderBy("name->{$locale}")->orderBy('id');
        }
    }
}