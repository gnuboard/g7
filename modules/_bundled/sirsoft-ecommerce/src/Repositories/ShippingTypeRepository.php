<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;

/**
 * 배송유형 Repository 구현체
 */
class ShippingTypeRepository implements ShippingTypeRepositoryInterface
{
    /**
     * @param ShippingType $model 배송유형 모델
     */
    public function __construct(
        protected ShippingType $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getAll(array $filters = [], array $with = []): Collection
    {
        $query = $this->model->newQuery();

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 카테고리 필터
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        // 검색 키워드
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $query->where(function ($q) use ($keyword, $locales) {
                foreach ($locales as $locale) {
                    $q->orWhere("name->{$locale}", 'like', "%{$keyword}%");
                }
                $q->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        // 정렬
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
    public function findById(int $id, array $with = []): ?ShippingType
    {
        $query = $this->model->newQuery();

        if (! empty($with)) {
            $query->with($with);
        }

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ShippingType
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ShippingType
    {
        $type = $this->findById($id);
        $type->update($data);

        return $type->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $type = $this->findById($id);

        return $type->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function existsByCode(string $code, ?int $excludeId = null): bool
    {
        $query = $this->model->where('code', $code);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveTypes(?string $category = null): Collection
    {
        $query = $this->model->newQuery()
            ->active()
            ->ordered();

        if ($category !== null) {
            $query->ofCategory($category);
        }

        return $query->get();
    }
}
