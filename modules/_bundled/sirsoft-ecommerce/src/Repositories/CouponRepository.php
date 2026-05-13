<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Helpers\PermissionHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;

/**
 * 쿠폰 Repository 구현체
 */
class CouponRepository implements CouponRepositoryInterface
{
    public function __construct(
        protected Coupon $model,
        protected CouponIssue $issueModel
    ) {}

    /**
     * {@inheritDoc}
     */
    public function paginate(array $filters = [], int $perPage = 10, array $with = []): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.promotion-coupon.read');

        // 발급내역 카운트 추가
        $query->withCount('issues');

        // 필터 적용
        $this->applyCouponFilters($query, $filters);

        // 키워드 검색
        if (! empty($filters['search_keyword'])) {
            $keyword = $filters['search_keyword'];
            $searchField = $filters['search_field'] ?? 'all';

            // FULLTEXT 대상 필드인 경우 Scout 사용
            if (in_array($searchField, ['all', 'name', 'description'])) {
                return Coupon::search($keyword)
                    ->query(function ($q) use ($filters, $keyword, $searchField, $with, $perPage) {
                        // 권한 스코프 필터링
                        PermissionHelper::applyPermissionScope($q, 'sirsoft-ecommerce.promotion-coupon.read');

                        // 발급내역 카운트 추가
                        $q->withCount('issues');

                        // 필터 적용
                        $this->applyCouponFilters($q, $filters);

                        // FULLTEXT 외 필드 OR 조건 (created_by — all인 경우)
                        if ($searchField === 'all') {
                            $q->orWhereHas('creator', function ($creatorQuery) use ($keyword) {
                                $creatorQuery->where('name', 'like', "%{$keyword}%")
                                    ->orWhere('email', 'like', "%{$keyword}%");
                            });
                        }

                        // 정렬
                        $sortBy = $filters['sort_by'] ?? 'created_at';
                        $sortOrder = $filters['sort_order'] ?? 'desc';
                        $q->orderBy($sortBy, $sortOrder)->orderBy('id', $sortOrder);

                        // Eager loading
                        if (! empty($with)) {
                            $q->with($with);
                        }

                        // 기본 관계 로드
                        $q->with(['creator:id,uuid,name,email']);
                    })
                    ->paginate($perPage);
            }

            // FULLTEXT 미대상 필드 (created_by) → LIKE 직접
            if ($searchField === 'created_by') {
                $query->whereHas('creator', function ($creatorQuery) use ($keyword) {
                    $creatorQuery->where('name', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");
                });
            }
        }

        // 정렬
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder)->orderBy('id', $sortOrder);

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        // 기본 관계 로드
        $query->with(['creator:id,uuid,name,email']);

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $with = []): ?Coupon
    {
        $query = $this->model->newQuery();

        if (! empty($with)) {
            $query->with($with);
        }

        // 발급내역 카운트 추가
        $query->withCount('issues');

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Coupon
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): Coupon
    {
        $coupon = $this->findById($id);
        $coupon->update($data);

        return $coupon->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $coupon = $this->findById($id);

        return $coupon->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateIssueStatus(array $ids, string $issueStatus): int
    {
        return $this->model->whereIn('id', $ids)->update(['issue_status' => $issueStatus]);
    }

    /**
     * {@inheritDoc}
     */
    public function getIssues(int $couponId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->issueModel->newQuery();

        $query->forCoupon($couponId);

        // 회원 필터 (UUID → 정수 FK 변환)
        if (! empty($filters['user_id'])) {
            $query->whereHas('user', fn ($q) => $q->where('uuid', $filters['user_id']));
        }

        // 상태 필터
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        // 기본 정렬: 발급일 최신순
        $query->orderBy('issued_at', 'desc')->orderBy('id', 'desc');

        // 관계 로드
        $query->with(['user:id,uuid,name']);

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function incrementIssuedCount(int $couponId, int $count = 1): void
    {
        $this->model->where('id', $couponId)->increment('issued_count', $count);
    }

    /**
     * {@inheritDoc}
     */
    public function syncProducts(Coupon $coupon, array $products): void
    {
        // 기존 연결 제거 후 새로 연결
        $syncData = [];
        foreach ($products as $product) {
            $syncData[$product['id']] = ['type' => $product['type']];
        }
        $coupon->products()->sync($syncData);
    }

    /**
     * {@inheritDoc}
     */
    public function syncCategories(Coupon $coupon, array $categories): void
    {
        // 기존 연결 제거 후 새로 연결
        $syncData = [];
        foreach ($categories as $category) {
            $syncData[$category['id']] = ['type' => $category['type']];
        }
        $coupon->categories()->sync($syncData);
    }

    /**
     * {@inheritDoc}
     */
    public function getDownloadableCoupons(?int $perPage = null): LengthAwarePaginator|Collection
    {
        $query = $this->model->newQuery()
            ->where('issue_method', CouponIssueMethod::DOWNLOAD)
            ->where('issue_condition', CouponIssueCondition::MANUAL)
            ->where('issue_status', CouponIssueStatus::ISSUING)
            ->where(fn ($q) => $q->whereNull('issue_from')->orWhere('issue_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('issue_to')->orWhere('issue_to', '>=', now()))
            ->where(fn ($q) => $q->whereNull('total_quantity')->orWhereColumn('issued_count', '<', 'total_quantity'))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
            ->with(['includedProducts', 'excludedProducts', 'includedCategories', 'excludedCategories'])
            ->orderBy('created_at', 'desc');

        if ($perPage !== null) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdForUpdate(int $id): ?Coupon
    {
        return $this->model->newQuery()->lockForUpdate()->find($id);
    }

    /**
     * 쿠폰 필터 조건을 쿼리에 적용합니다.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Eloquent 쿼리 빌더
     * @param array $filters 필터 배열
     * @return void
     */
    private function applyCouponFilters($query, array $filters): void
    {
        // 적용대상 필터
        if (! empty($filters['target_type']) && $filters['target_type'] !== 'all') {
            $query->byTargetType($filters['target_type']);
        }

        // 할인타입 필터
        if (! empty($filters['discount_type']) && $filters['discount_type'] !== 'all') {
            $query->where('discount_type', $filters['discount_type']);
        }

        // 발급상태 필터
        if (! empty($filters['issue_status']) && $filters['issue_status'] !== 'all') {
            $query->where('issue_status', $filters['issue_status']);
        }

        // 발급방법 필터
        if (! empty($filters['issue_method']) && $filters['issue_method'] !== 'all') {
            $query->byIssueMethod($filters['issue_method']);
        }

        // 발급조건 필터
        if (! empty($filters['issue_condition']) && $filters['issue_condition'] !== 'all') {
            $query->byIssueCondition($filters['issue_condition']);
        }

        // 혜택금액 범위 필터
        if (! empty($filters['min_benefit_amount'])) {
            $query->where('discount_value', '>=', $filters['min_benefit_amount']);
        }
        if (! empty($filters['max_benefit_amount'])) {
            $query->where('discount_value', '<=', $filters['max_benefit_amount']);
        }

        // 최소주문금액 필터
        if (! empty($filters['min_order_amount'])) {
            $query->where('min_order_amount', '>=', $filters['min_order_amount']);
        }

        // 등록일 필터
        if (! empty($filters['created_start_date'])) {
            $query->whereDate('created_at', '>=', $filters['created_start_date']);
        }
        if (! empty($filters['created_end_date'])) {
            $query->whereDate('created_at', '<=', $filters['created_end_date']);
        }

        // 유효기간 필터
        if (! empty($filters['valid_start_date'])) {
            $query->whereDate('valid_from', '>=', $filters['valid_start_date']);
        }
        if (! empty($filters['valid_end_date'])) {
            $query->whereDate('valid_to', '<=', $filters['valid_end_date']);
        }

        // 발급기간 필터
        if (! empty($filters['issue_start_date'])) {
            $query->whereDate('issue_from', '>=', $filters['issue_start_date']);
        }
        if (! empty($filters['issue_end_date'])) {
            $query->whereDate('issue_to', '<=', $filters['issue_end_date']);
        }

        // 등록자 필터 (UUID → 정수 FK 변환)
        if (! empty($filters['created_by'])) {
            $query->whereHas('creator', fn ($q) => $q->where('uuid', $filters['created_by']));
        }
    }

    /**
     * ID 목록으로 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  ID 목록
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($ids)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return Coupon::whereIn('id', $ids)->get()->keyBy('id');
    }
}
