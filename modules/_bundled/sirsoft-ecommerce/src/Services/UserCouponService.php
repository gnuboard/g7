<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;

/**
 * 사용자 쿠폰 서비스
 *
 * 마이페이지 쿠폰함 기능을 제공합니다.
 */
class UserCouponService
{
    public function __construct(
        protected CouponIssueRepositoryInterface $couponIssueRepository,
        protected CouponRepositoryInterface $couponRepository
    ) {}

    /**
     * 사용자 쿠폰함 목록 조회
     *
     * @param int $userId 사용자 ID
     * @param string|null $status 필터 상태 (available, used, expired)
     * @param int $perPage 페이지당 항목 수
     * @return LengthAwarePaginator
     */
    public function getUserCoupons(int $userId, ?string $status = null, int $perPage = 10): LengthAwarePaginator
    {
        HookManager::doAction('sirsoft-ecommerce.user_coupon.before_list', $userId, $status);

        $coupons = $this->couponIssueRepository->getUserCoupons($userId, $status, $perPage);

        $coupons = HookManager::applyFilters('sirsoft-ecommerce.user_coupon.filter_list_result', $coupons, $userId, $status);

        HookManager::doAction('sirsoft-ecommerce.user_coupon.after_list', $coupons, $userId, $status);

        return $coupons;
    }

    /**
     * 사용자의 사용 가능한 쿠폰 목록 조회
     *
     * @param int $userId 사용자 ID
     * @param array $productIds 상품 ID 배열 (해당 상품에 적용 가능한 쿠폰 필터링)
     * @return array
     */
    public function getAvailableCoupons(int $userId, array $productIds = []): array
    {
        HookManager::doAction('sirsoft-ecommerce.user_coupon.before_available', $userId, $productIds);

        $coupons = $this->couponIssueRepository->getAvailableCouponsForUser($userId, $productIds);

        $coupons = HookManager::applyFilters('sirsoft-ecommerce.user_coupon.filter_available_result', $coupons, $userId, $productIds);

        HookManager::doAction('sirsoft-ecommerce.user_coupon.after_available', $coupons, $userId, $productIds);

        return $coupons;
    }

    /**
     * 체크아웃용 쿠폰 목록 조회 (주문금액/배송비 쿠폰만)
     *
     * 주문금액과 배송비 할인 쿠폰만 필터링하고 로케일화된 형식으로 변환합니다.
     * min_order_amount 조건을 만족하는 쿠폰만 반환합니다.
     * 주문/배송비 쿠폰은 target_scope가 없으므로 전체 주문금액 기준으로 검사합니다.
     *
     * @param int $userId 사용자 ID
     * @param array $productIds 상품 ID 배열
     * @param float $subtotal 상품 금액 합계 (min_order_amount 비교용)
     * @param float $totalShipping 배송비 합계 (현재 미사용, 향후 확장용)
     * @return array 로케일화된 쿠폰 배열
     */
    public function getCheckoutCoupons(int $userId, array $productIds = [], float $subtotal = 0, float $totalShipping = 0): array
    {
        $rawCoupons = $this->getAvailableCoupons($userId, $productIds);

        // 주문금액/배송비 쿠폰만 필터링 + min_order_amount 조건 적용
        $filtered = array_filter($rawCoupons, function ($couponIssue) use ($subtotal) {
            $coupon = $couponIssue->coupon;
            $targetType = $coupon?->target_type;

            if (! in_array($targetType, [
                CouponTargetType::ORDER_AMOUNT,
                CouponTargetType::SHIPPING_FEE,
            ], true)) {
                return false;
            }

            // min_order_amount 조건 검사 (주문/배송비 쿠폰은 전체 주문금액 기준)
            $minAmount = (float) ($coupon->min_order_amount ?? 0);
            if ($minAmount > 0 && $subtotal < $minAmount) {
                return false;
            }

            return true;
        });

        // 프론트엔드용 형식으로 변환
        return array_values(array_map(function ($couponIssue) {
            return [
                'id' => $couponIssue->id,
                'localized_name' => $couponIssue->coupon?->getLocalizedName(),
                'benefit_formatted' => $couponIssue->coupon?->benefit_formatted,
                'target_type' => $couponIssue->coupon?->target_type?->value,
                'target_type_label' => $couponIssue->coupon?->target_type?->label(),
                'target_type_short_label' => $couponIssue->coupon?->target_type?->shortLabel(),
                'min_order_amount' => $couponIssue->coupon?->min_order_amount,
                'is_combinable' => $couponIssue->coupon?->is_combinable,
                'expired_at' => $couponIssue->expired_at?->toIso8601String(),
            ];
        }, $filtered));
    }

    /**
     * 상품별 적용 가능한 쿠폰 그룹화
     *
     * 상품금액 할인 쿠폰만 필터링하고 상품별로 그룹화합니다.
     * target_scope에 따라 전체 상품 또는 특정 상품에만 적용 가능한 쿠폰을 분류합니다.
     * min_order_amount 조건을 만족하는 쿠폰만 반환합니다.
     *
     * @param int $userId 사용자 ID
     * @param array $productIds 상품 ID 배열
     * @param array $itemSubtotals 상품별 소계 (product_id => subtotal)
     * @return array 상품별 쿠폰 배열 (product_id => coupons)
     */
    public function getProductCouponsGrouped(int $userId, array $productIds, array $itemSubtotals = []): array
    {
        $rawCoupons = $this->getAvailableCoupons($userId, $productIds);

        // 상품금액 쿠폰만 필터링
        $productCoupons = array_filter($rawCoupons, function ($couponIssue) {
            return $couponIssue->coupon?->target_type === CouponTargetType::PRODUCT_AMOUNT;
        });

        // 키 타입 정규화 (int로 통일)
        $normalizedSubtotals = [];
        foreach ($itemSubtotals as $pid => $subtotal) {
            $normalizedSubtotals[(int) $pid] = (float) $subtotal;
        }

        $result = [];
        foreach ($productIds as $productId) {
            $productIdInt = (int) $productId;
            $result[$productIdInt] = [];

            // 해당 상품의 소계 (없으면 0)
            $productSubtotal = $normalizedSubtotals[$productIdInt] ?? 0;

            foreach ($productCoupons as $couponIssue) {
                $coupon = $couponIssue->coupon;
                if (! $coupon) {
                    continue;
                }

                // target_scope에 따라 적용 가능 여부 판단
                $isApplicable = false;
                $targetScope = $coupon->target_scope?->value ?? 'all';

                if ($targetScope === 'all') {
                    $isApplicable = true;
                } elseif ($targetScope === 'products') {
                    // 포함 상품에 있고 제외 상품에 없는지 확인
                    $includedIds = $coupon->includedProducts?->pluck('id')->toArray() ?? [];
                    $excludedIds = $coupon->excludedProducts?->pluck('id')->toArray() ?? [];
                    $isApplicable = in_array($productIdInt, $includedIds) && ! in_array($productIdInt, $excludedIds);
                } elseif ($targetScope === 'categories') {
                    // 카테고리 기반은 Repository에서 이미 필터링됨, 여기서는 all로 처리
                    $isApplicable = true;
                }

                if (! $isApplicable) {
                    continue;
                }

                // min_order_amount 조건 검사 (상품 소계 기준)
                $minAmount = (float) ($coupon->min_order_amount ?? 0);
                if ($minAmount > 0 && $productSubtotal < $minAmount) {
                    // 최소 주문금액 미충족 - 이 상품에는 적용 불가
                    continue;
                }

                $result[$productIdInt][] = [
                    'id' => $couponIssue->id,
                    'localized_name' => $coupon->getLocalizedName(),
                    'benefit_formatted' => $coupon->benefit_formatted,
                    'target_type' => $coupon->target_type?->value,
                    'target_type_short_label' => $coupon->target_type?->shortLabel(),
                    'min_order_amount' => $coupon->min_order_amount,
                    'is_combinable' => $coupon->is_combinable,
                    'expired_at' => $couponIssue->expired_at?->toIso8601String(),
                ];
            }
        }

        return $result;
    }

    /**
     * 다운로드 가능한 쿠폰 목록 조회
     *
     * @param int $userId 사용자 ID
     * @param int $perPage 페이지당 항목 수
     * @return LengthAwarePaginator
     */
    public function getDownloadableCoupons(int $userId, int $perPage = 8): LengthAwarePaginator
    {
        HookManager::doAction('sirsoft-ecommerce.user_coupon.before_downloadable_list', $userId);

        $coupons = $this->couponRepository->getDownloadableCoupons($perPage);

        // 각 쿠폰에 사용자별 다운로드 정보 추가
        $coupons->getCollection()->transform(function ($coupon) use ($userId) {
            $userIssuedCount = $this->couponIssueRepository->getUserIssuedCountForCoupon($userId, $coupon->id);
            $coupon->is_downloaded = $userIssuedCount > 0;
            $coupon->user_issued_count = $userIssuedCount;
            $coupon->coupon_id = $coupon->id;
            $coupon->localized_name = $coupon->getLocalizedName();
            $coupon->target_type_short_label = $coupon->target_type?->shortLabel();
            $coupon->valid_period_formatted = $coupon->valid_period_formatted;
            $coupon->min_order_amount_formatted = ecommerce_format_price($coupon->min_order_amount ?? 0);
            $coupon->remaining_quantity = $coupon->total_quantity !== null
                ? max(0, $coupon->total_quantity - $coupon->issued_count)
                : null;

            return $coupon;
        });

        $coupons = HookManager::applyFilters('sirsoft-ecommerce.user_coupon.filter_downloadable_result', $coupons, $userId);

        HookManager::doAction('sirsoft-ecommerce.user_coupon.after_downloadable_list', $coupons, $userId);

        return $coupons;
    }

    /**
     * 쿠폰 다운로드 (발급)
     *
     * @param int $userId 사용자 ID
     * @param int $couponId 쿠폰 ID
     * @return CouponIssue 생성된 발급 레코드
     * @throws Exception 다운로드 불가 시
     */
    public function downloadCoupon(int $userId, int $couponId): CouponIssue
    {
        HookManager::doAction('sirsoft-ecommerce.user_coupon.before_download', $userId, $couponId);

        return DB::transaction(function () use ($userId, $couponId) {
            $coupon = $this->couponRepository->findByIdForUpdate($couponId);

            if (! $coupon) {
                throw new Exception(__('sirsoft-ecommerce::messages.coupon.not_downloadable'), 400);
            }

            // 다운로드 가능 조건 검증
            if (! $coupon->isIssuable()) {
                if ($coupon->issue_status->value !== 'issuing') {
                    throw new Exception(__('sirsoft-ecommerce::messages.coupon.not_downloadable'), 400);
                }
                if ($coupon->total_quantity !== null && $coupon->issued_count >= $coupon->total_quantity) {
                    throw new Exception(__('sirsoft-ecommerce::messages.coupon.quantity_exhausted'), 400);
                }
                throw new Exception(__('sirsoft-ecommerce::messages.coupon.issue_period_expired'), 400);
            }

            // per_user_limit 검증 (0 = 무제한)
            $userIssuedCount = $this->couponIssueRepository->getUserIssuedCountForCoupon($userId, $couponId);
            if ($coupon->per_user_limit > 0 && $userIssuedCount >= $coupon->per_user_limit) {
                throw new Exception(__('sirsoft-ecommerce::messages.coupon.download_limit_exceeded'), 400);
            }

            // 쿠폰 코드 생성
            $couponCode = 'DL-'.strtoupper(Str::random(8));

            // expired_at 계산
            $expiredAt = null;
            if ($coupon->valid_type === 'period') {
                $expiredAt = $coupon->valid_to;
            } elseif ($coupon->valid_type === 'days_from_issue') {
                $expiredAt = now()->addDays($coupon->valid_days);
            }

            // CouponIssue 생성
            $couponIssue = $this->couponIssueRepository->create([
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'coupon_code' => $couponCode,
                'status' => CouponIssueRecordStatus::AVAILABLE->value,
                'issued_at' => now(),
                'expired_at' => $expiredAt,
            ]);

            // issued_count 증가
            $this->couponRepository->incrementIssuedCount($couponId);

            HookManager::doAction('sirsoft-ecommerce.user_coupon.after_download', $couponIssue, $userId, $couponId);

            return $couponIssue;
        });
    }

    /**
     * 상품별 다운로드 가능한 쿠폰 목록 조회
     *
     * @param int $productId 상품 ID
     * @param int|null $userId 사용자 ID (로그인 시)
     * @return array 쿠폰 배열
     */
    public function getProductDownloadableCoupons(int $productId, ?int $userId = null): array
    {
        $coupons = $this->couponRepository->getDownloadableCoupons(null);

        // 상품 적용 범위 필터링
        $product = Product::with('categories:id')->find($productId);
        $categoryIds = $product?->categories?->pluck('id')->toArray() ?? [];

        $filtered = $coupons->filter(function ($coupon) use ($productId, $categoryIds) {
            $targetScope = $coupon->target_scope?->value ?? 'all';

            if ($targetScope === 'all') {
                return true;
            }

            if ($targetScope === 'products') {
                $includedIds = $coupon->includedProducts->pluck('id')->toArray();
                $excludedIds = $coupon->excludedProducts->pluck('id')->toArray();

                return in_array($productId, $includedIds) && ! in_array($productId, $excludedIds);
            }

            if ($targetScope === 'categories') {
                if (empty($categoryIds)) {
                    return false;
                }
                $includedCatIds = $coupon->includedCategories->pluck('id')->toArray();
                $excludedCatIds = $coupon->excludedCategories->pluck('id')->toArray();

                $hasIncluded = ! empty(array_intersect($categoryIds, $includedCatIds));
                $hasExcluded = ! empty(array_intersect($categoryIds, $excludedCatIds));

                return $hasIncluded && ! $hasExcluded;
            }

            return false;
        });

        // 프론트엔드용 형식으로 변환
        $result = $filtered->map(function ($coupon) use ($userId) {
            $data = [
                'coupon_id' => $coupon->id,
                'localized_name' => $coupon->getLocalizedName(),
                'benefit_formatted' => $coupon->benefit_formatted,
                'target_type' => $coupon->target_type?->value,
                'target_type_short_label' => $coupon->target_type?->shortLabel(),
                'valid_period_formatted' => $coupon->valid_period_formatted,
                'min_order_amount' => $coupon->min_order_amount,
                'min_order_amount_formatted' => ecommerce_format_price($coupon->min_order_amount ?? 0),
                'total_quantity' => $coupon->total_quantity,
                'remaining_quantity' => $coupon->total_quantity !== null
                    ? max(0, $coupon->total_quantity - $coupon->issued_count)
                    : null,
                'is_downloaded' => false,
            ];

            if ($userId) {
                $userIssuedCount = $this->couponIssueRepository->getUserIssuedCountForCoupon($userId, $coupon->id);
                $data['is_downloaded'] = $userIssuedCount > 0;
            }

            return $data;
        })->values()->all();

        $result = HookManager::applyFilters('sirsoft-ecommerce.user_coupon.filter_product_downloadable_result', $result, $productId, $userId);

        return $result;
    }
}
