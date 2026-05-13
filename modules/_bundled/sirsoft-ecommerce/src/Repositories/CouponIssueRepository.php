<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;

/**
 * 쿠폰 발급 Repository 구현체
 */
class CouponIssueRepository implements CouponIssueRepositoryInterface
{
    public function __construct(
        protected CouponIssue $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?CouponIssue
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdsWithRelations(array $ids, array $with = []): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->model
            ->with($with)
            ->whereIn('id', $ids)
            ->get()
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableCouponsForUser(int $userId, array $productIds = []): array
    {
        $now = Carbon::now();

        $query = $this->model
            ->with(['coupon', 'coupon.includedProducts', 'coupon.excludedProducts', 'coupon.includedCategories', 'coupon.excludedCategories'])
            ->where('user_id', $userId)
            ->where('status', CouponIssueRecordStatus::AVAILABLE->value)
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')
                    ->orWhere('expired_at', '>', $now);
            })
            ->whereHas('coupon', function ($q) use ($now) {
                // 쿠폰 유효기간만 체크 (issue_status는 신규 발급 시에만 확인)
                $q->where(function ($subQ) use ($now) {
                    $subQ->whereNull('valid_from')
                        ->orWhere('valid_from', '<=', $now);
                })
                    ->where(function ($subQ) use ($now) {
                        $subQ->whereNull('valid_to')
                            ->orWhere('valid_to', '>=', $now);
                    });
            });

        // 상품 ID가 제공된 경우, 해당 상품에 적용 가능한 쿠폰만 필터링
        if (! empty($productIds)) {
            // 상품들의 카테고리 ID 조회
            $categoryIds = Product::whereIn('id', $productIds)
                ->with('categories:id')
                ->get()
                ->pluck('categories')
                ->flatten()
                ->pluck('id')
                ->unique()
                ->values()
                ->all();

            $query->whereHas('coupon', function ($q) use ($productIds, $categoryIds) {
                $q->where(function ($scopeQuery) use ($productIds, $categoryIds) {
                    // target_scope = 'all': 모든 상품에 적용 가능
                    $scopeQuery->where('target_scope', 'all')
                        // target_scope = 'products': 특정 상품만 적용
                        ->orWhere(function ($productQuery) use ($productIds) {
                            $productQuery->where('target_scope', 'products')
                                // 포함 상품 목록에 있어야 함
                                ->whereHas('includedProducts', function ($incQ) use ($productIds) {
                                    $incQ->whereIn('ecommerce_products.id', $productIds);
                                })
                                // 제외 상품 목록에 없어야 함
                                ->whereDoesntHave('excludedProducts', function ($excQ) use ($productIds) {
                                    $excQ->whereIn('ecommerce_products.id', $productIds);
                                });
                        })
                        // target_scope = 'categories': 특정 카테고리만 적용
                        ->orWhere(function ($categoryQuery) use ($categoryIds) {
                            $categoryQuery->where('target_scope', 'categories');
                            if (! empty($categoryIds)) {
                                $categoryQuery
                                    // 포함 카테고리 목록에 있어야 함
                                    ->whereHas('includedCategories', function ($incQ) use ($categoryIds) {
                                        $incQ->whereIn('ecommerce_categories.id', $categoryIds);
                                    })
                                    // 제외 카테고리 목록에 없어야 함
                                    ->whereDoesntHave('excludedCategories', function ($excQ) use ($categoryIds) {
                                        $excQ->whereIn('ecommerce_categories.id', $categoryIds);
                                    });
                            }
                        });
                });
            });
        }

        return $query->orderBy('expired_at', 'asc')
            ->get()
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function getUserCoupons(int $userId, ?string $status = null, int $perPage = 10): LengthAwarePaginator
    {
        $now = Carbon::now();

        $query = $this->model
            ->with(['coupon'])
            ->where('user_id', $userId);

        // 상태별 필터링
        if ($status !== null) {
            switch ($status) {
                case 'available':
                    $query->where('status', CouponIssueRecordStatus::AVAILABLE->value)
                        ->where(function ($q) use ($now) {
                            $q->whereNull('expired_at')
                                ->orWhere('expired_at', '>', $now);
                        });
                    break;

                case 'used':
                    $query->where('status', CouponIssueRecordStatus::USED->value);
                    break;

                case 'expired':
                    $query->where(function ($q) use ($now) {
                        $q->where('status', CouponIssueRecordStatus::EXPIRED->value)
                            ->orWhere(function ($subQ) use ($now) {
                                $subQ->where('status', CouponIssueRecordStatus::AVAILABLE->value)
                                    ->whereNotNull('expired_at')
                                    ->where('expired_at', '<=', $now);
                            });
                    });
                    break;
            }
        }

        return $query->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdsForUser(array $couponIssueIds, int $userId): \Illuminate\Support\Collection
    {
        if (empty($couponIssueIds)) {
            return collect();
        }

        $now = Carbon::now();

        return $this->model
            ->with(['coupon'])
            ->whereIn('id', $couponIssueIds)
            ->where('user_id', $userId)
            ->where('status', CouponIssueRecordStatus::AVAILABLE->value)
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')
                    ->orWhere('expired_at', '>', $now);
            })
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): CouponIssue
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function getUserIssuedCountForCoupon(int $userId, int $couponId): int
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('coupon_id', $couponId)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): bool
    {
        return $this->model
            ->where('id', $id)
            ->update($data) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($ids)) {
            return $this->model->newCollection();
        }

        return $this->model->whereIn('id', $ids)->get();
    }
}
