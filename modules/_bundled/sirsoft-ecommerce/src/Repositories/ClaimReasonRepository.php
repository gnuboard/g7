<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;

/**
 * 클래임 사유 Repository 구현체
 */
class ClaimReasonRepository implements ClaimReasonRepositoryInterface
{
    public function __construct(
        protected ClaimReason $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getAll(array $filters = [], array $with = []): Collection
    {
        $query = $this->model->newQuery();

        // 유형 필터 (기본값: refund)
        $query->where('type', $filters['type'] ?? 'refund');

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 귀책 유형 필터
        if (! empty($filters['fault_type'])) {
            $query->where('fault_type', $filters['fault_type']);
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
    public function findById(int $id, array $with = []): ?ClaimReason
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
    public function findByCode(string $code, string $type = 'refund'): ?ClaimReason
    {
        return $this->model->where('type', $type)->where('code', $code)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ClaimReason
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ClaimReason
    {
        $reason = $this->findById($id);
        $reason->update($data);

        return $reason->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $reason = $this->findById($id);

        return $reason->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function existsByCode(string $code, string $type = 'refund', ?int $excludeId = null): bool
    {
        $query = $this->model->where('type', $type)->where('code', $code);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveReasons(string $type = 'refund'): Collection
    {
        return $this->model->newQuery()
            ->ofType($type)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getUserSelectableReasons(string $type = 'refund'): Collection
    {
        return $this->model->newQuery()
            ->ofType($type)
            ->active()
            ->userSelectable()
            ->ordered()
            ->get();
    }
}
