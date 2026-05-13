<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingCarrierRepositoryInterface;

/**
 * 배송사 Repository 구현체
 */
class ShippingCarrierRepository implements ShippingCarrierRepositoryInterface
{
    public function __construct(
        protected ShippingCarrier $model
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

        // 유형 필터
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
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
    public function findById(int $id, array $with = []): ?ShippingCarrier
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
    public function create(array $data): ShippingCarrier
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ShippingCarrier
    {
        $carrier = $this->findById($id);
        $carrier->update($data);

        return $carrier->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $carrier = $this->findById($id);

        return $carrier->delete();
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
    public function getActiveCarriers(?string $type = null): Collection
    {
        $query = $this->model->newQuery()
            ->active()
            ->ordered();

        if ($type !== null) {
            $query->ofType($type);
        }

        return $query->get();
    }
}
