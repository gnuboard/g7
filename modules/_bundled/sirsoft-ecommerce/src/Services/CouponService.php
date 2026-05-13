<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;

/**
 * 쿠폰 서비스
 */
class CouponService
{
    public function __construct(
        protected CouponRepositoryInterface $repository
    ) {}

    /**
     * 쿠폰 목록 조회
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 항목 수
     * @return LengthAwarePaginator
     */
    public function getCoupons(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.coupon.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_list_query', $filters);

        $coupons = $this->repository->paginate($filters, $perPage);

        // 필터 훅 - 결과 데이터 변형
        $coupons = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_list_result', $coupons, $filters);

        // After 훅 - 조회 후처리
        HookManager::doAction('sirsoft-ecommerce.coupon.after_list', $coupons, $filters);

        return $coupons;
    }

    /**
     * 쿠폰 상세 조회
     *
     * @param int $id 쿠폰 ID
     * @return Coupon|null
     */
    public function getCoupon(int $id): ?Coupon
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_show', $id);

        $coupon = $this->repository->findById($id, [
            'creator',
            'includedProducts',
            'excludedProducts',
            'includedCategories',
            'excludedCategories',
        ]);

        if ($coupon) {
            // 필터 훅 - 조회 결과 변형
            $coupon = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_show_result', $coupon);

            // After 훅
            HookManager::doAction('sirsoft-ecommerce.coupon.after_show', $coupon);
        }

        return $coupon;
    }

    /**
     * 쿠폰 생성
     *
     * @param array $data 쿠폰 데이터
     * @return Coupon
     */
    public function createCoupon(array $data): Coupon
    {
        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.coupon.before_create', $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_create_data', $data);

        // 생성자 정보 추가
        $data['created_by'] = Auth::id();

        // 상품/카테고리 데이터 분리
        $products = $data['products'] ?? [];
        $categories = $data['categories'] ?? [];
        unset($data['products'], $data['categories']);

        $coupon = DB::transaction(function () use ($data, $products, $categories) {
            // 쿠폰 생성
            $coupon = $this->repository->create($data);

            // 적용 상품 동기화
            if (! empty($products)) {
                $this->repository->syncProducts($coupon, $products);
            }

            // 적용 카테고리 동기화
            if (! empty($categories)) {
                $this->repository->syncCategories($coupon, $categories);
            }

            return $coupon->fresh([
                'creator',
                'includedProducts',
                'excludedProducts',
                'includedCategories',
                'excludedCategories',
            ]);
        });

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.coupon.after_create', $coupon, $data);

        return $coupon;
    }

    /**
     * 쿠폰 수정
     *
     * @param int $id 쿠폰 ID
     * @param array $data 수정할 데이터
     * @return Coupon
     */
    public function updateCoupon(int $id, array $data): Coupon
    {
        $coupon = $this->repository->findById($id);

        if (! $coupon) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.coupon_not_found'));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_update', $id, $data);

        // 수정 전 스냅샷 캡처
        $snapshot = $coupon->toArray();

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_update_data', $data, $id);

        // 상품/카테고리 데이터 분리
        $products = $data['products'] ?? null;
        $categories = $data['categories'] ?? null;
        unset($data['products'], $data['categories']);

        $coupon = DB::transaction(function () use ($coupon, $data, $products, $categories) {
            // 쿠폰 수정
            $coupon = $this->repository->update($coupon->id, $data);

            // 적용 상품 동기화 (null이 아닌 경우에만)
            if ($products !== null) {
                $this->repository->syncProducts($coupon, $products);
            }

            // 적용 카테고리 동기화 (null이 아닌 경우에만)
            if ($categories !== null) {
                $this->repository->syncCategories($coupon, $categories);
            }

            return $coupon->fresh([
                'creator',
                'includedProducts',
                'excludedProducts',
                'includedCategories',
                'excludedCategories',
            ]);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.after_update', $coupon, $data, $snapshot);

        return $coupon;
    }

    /**
     * 쿠폰 삭제
     *
     * @param int $id 쿠폰 ID
     * @return array 삭제 결과 정보
     */
    public function deleteCoupon(int $id): array
    {
        $coupon = $this->repository->findById($id);

        if (! $coupon) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.coupon_not_found'));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_delete', $coupon);

        DB::transaction(function () use ($coupon) {
            // 연결된 상품/카테고리 관계 제거
            $coupon->products()->detach();
            $coupon->categories()->detach();

            // 쿠폰 삭제 (SoftDelete)
            $this->repository->delete($coupon->id);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.after_delete', $coupon->id);

        return [
            'coupon_id' => $coupon->id,
        ];
    }

    /**
     * 일괄 발급상태 변경
     *
     * @param array $ids 쿠폰 ID 배열
     * @param string $issueStatus 발급상태
     * @return int 변경된 레코드 수
     */
    public function bulkUpdateIssueStatus(array $ids, string $issueStatus): int
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_bulk_status', $ids, $issueStatus);

        // 수정 전 스냅샷 캡처
        $snapshots = Coupon::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        $count = DB::transaction(function () use ($ids, $issueStatus) {
            return $this->repository->bulkUpdateIssueStatus($ids, $issueStatus);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.after_bulk_status', $ids, $issueStatus, $count, $snapshots);

        return $count;
    }

    /**
     * 쿠폰 발급 내역 조회
     *
     * @param int $couponId 쿠폰 ID
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 항목 수
     * @return LengthAwarePaginator
     */
    public function getCouponIssues(int $couponId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $coupon = $this->repository->findById($couponId);

        if (! $coupon) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.coupon_not_found'));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_issues_list', $couponId, $filters);

        $issues = $this->repository->getIssues($couponId, $filters, $perPage);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.after_issues_list', $issues, $couponId, $filters);

        return $issues;
    }
}
