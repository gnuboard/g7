<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;

/**
 * 상품 1:1 문의 Repository 구현체
 */
class ProductInquiryRepository implements ProductInquiryRepositoryInterface
{
    public function __construct(
        protected ProductInquiry $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?ProductInquiry
    {
        return $this->model->with(['product', 'user'])->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByProductId(int $productId): Collection
    {
        return $this->model->newQuery()
            ->where('product_id', $productId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateByProductId(int $productId, int $perPage = 10): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('product_id', $productId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function findByInquirable(string $inquirableType, int $inquirableId): ?ProductInquiry
    {
        return $this->model->newQuery()
            ->where('inquirable_type', $inquirableType)
            ->where('inquirable_id', $inquirableId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserId(int $userId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['product'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        // 답변 여부 필터
        if (isset($filters['is_answered']) && $filters['is_answered'] !== '') {
            $query->where('is_answered', (bool) $filters['is_answered']);
        }

        // 상품명 검색 (product_name_snapshot JSON 컬럼)
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $query->where(function ($q) use ($keyword, $locales) {
                foreach ($locales as $locale) {
                    $q->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(product_name_snapshot, '$.{$locale}')) LIKE ?",
                        ["%{$keyword}%"]
                    );
                }
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['product', 'user'])
            ->orderBy('created_at', 'desc');

        // 상품명 검색
        if (! empty($filters['search_keyword'])) {
            $keyword = $filters['search_keyword'];
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $query->whereHas('product', function ($q) use ($keyword, $locales) {
                $q->where(function ($inner) use ($keyword, $locales) {
                    foreach ($locales as $locale) {
                        $inner->orWhere("name->{$locale}", 'like', "%{$keyword}%");
                    }
                });
            });
        }

        // 답변 여부 필터
        if (isset($filters['is_answered']) && $filters['is_answered'] !== '') {
            $query->where('is_answered', (bool) $filters['is_answered']);
        }

        // 기간 필터
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ProductInquiry
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function markAsAnswered(ProductInquiry $inquiry): ProductInquiry
    {
        $inquiry->update([
            'is_answered' => true,
            'answered_at' => now(),
        ]);

        return $inquiry->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function unmarkAnswered(ProductInquiry $inquiry): ProductInquiry
    {
        $inquiry->update([
            'is_answered' => false,
            'answered_at' => null,
        ]);

        return $inquiry->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteById(int $id): bool
    {
        return $this->model->newQuery()->where('id', $id)->delete() > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByInquirableIds(string $inquirableType, array $inquirableIds): int
    {
        return $this->model->newQuery()
            ->where('inquirable_type', $inquirableType)
            ->whereIn('inquirable_id', $inquirableIds)
            ->delete();
    }
}
