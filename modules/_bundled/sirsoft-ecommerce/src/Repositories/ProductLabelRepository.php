<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductLabelRepositoryInterface;

/**
 * 상품 라벨 Repository 구현체
 */
class ProductLabelRepository implements ProductLabelRepositoryInterface
{
    public function __construct(
        protected ProductLabel $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getAll(array $filters = [], array $with = []): Collection
    {
        $query = $this->model->newQuery();

        // 할당된 상품 수 조회를 위한 withCount
        $query->withCount('assignments');

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 검색 키워드
        if (!empty($filters['search'])) {
            $keyword = $filters['search'];
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $query->where(function ($q) use ($keyword, $locales) {
                foreach ($locales as $locale) {
                    $q->orWhere("name->{$locale}", 'like', "%{$keyword}%");
                }
            });
        }

        // 정렬 처리
        $locale = $filters['locale'] ?? app()->getLocale();

        if (!empty($filters['sort'])) {
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
        } else {
            // 기본 정렬: sort_order → 이름 → ID
            $query->orderBy('sort_order')->orderBy("name->{$locale}")->orderBy('id');
        }

        // Eager loading
        if (!empty($with)) {
            $query->with($with);
        }

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $with = []): ?ProductLabel
    {
        $query = $this->model->newQuery();

        if (!empty($with)) {
            $query->with($with);
        }

        // 할당 수 카운트 추가
        $query->withCount('assignments');

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ProductLabel
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ProductLabel
    {
        $label = $this->findById($id);
        $label->update($data);

        return $label->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $label = $this->findById($id);

        return $label->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function getProductCount(int $id): int
    {
        $label = $this->findById($id);

        return $label->assignments()->distinct('product_id')->count('product_id');
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveLabels(): Collection
    {
        return $this->model->newQuery()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function exists(int $id): bool
    {
        return $this->model->where('id', $id)->exists();
    }
}
