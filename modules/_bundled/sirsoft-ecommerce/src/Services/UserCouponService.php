<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueSource;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponNotIssuableException;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
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
    /** 한 번에 받을 수 있는 쿠폰 수 상한 (쿠폰 일괄 받기) */
    public const BULK_DOWNLOAD_MAX = 50;

    /**
     * 회원 쿠폰 서비스를 생성합니다.
     *
     * @param  CouponIssueRepositoryInterface  $couponIssueRepository  쿠폰 발급 내역 저장소
     * @param  CouponRepositoryInterface  $couponRepository  쿠폰 저장소
     * @param  CurrencyConversionService  $currencyConverter  통화 환산 서비스(선택 통화 표시값)
     * @param  UserRepositoryInterface  $userRepository  회원 저장소(직접 발급 대상 탈퇴 판정)
     */
    public function __construct(
        protected CouponIssueRepositoryInterface $couponIssueRepository,
        protected CouponRepositoryInterface $couponRepository,
        protected CurrencyConversionService $currencyConverter,
        protected UserRepositoryInterface $userRepository
    ) {}

    /**
     * 사용자 쿠폰함 목록 조회
     *
     * @param  int  $userId  사용자 ID
     * @param  string|null  $status  필터 상태 (available, used, expired)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 쿠폰함 페이지네이터
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
     * @param  int  $userId  사용자 ID
     * @param  array  $productIds  상품 ID 배열 (해당 상품에 적용 가능한 쿠폰 필터링)
     * @return array 발급 내역(CouponIssue) 배열
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
     * @param  int  $userId  사용자 ID
     * @param  array  $productIds  상품 ID 배열
     * @param  float  $subtotal  상품 금액 합계 (min_order_amount 비교용)
     * @param  float  $totalShipping  배송비 합계 (현재 미사용, 향후 확장용)
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
                // 선택 통화별 베네핏 포맷 맵(D5 — 체크아웃 주문/배송비 쿠폰 옵션 KRW 고정 해소)
                'multi_currency_benefit_formatted' => $couponIssue->coupon
                    ?->buildMultiCurrencyBenefitFormatted($this->currencyConverter),
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
     * @param  int  $userId  사용자 ID
     * @param  array  $productIds  상품 ID 배열
     * @param  array  $itemSubtotals  상품별 소계 (product_id => subtotal)
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

        // 상품별 카테고리 ID 맵 (categories scope 매칭용, N+1 회피)
        $productCategoryMap = $this->buildProductCategoryMap($productIds);

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

                // target_scope에 따라 적용 가능 여부 판단 (공통 헬퍼)
                $productCategoryIds = $productCategoryMap[$productIdInt] ?? [];
                if (! $this->isCouponApplicableToProduct($coupon, $productIdInt, $productCategoryIds)) {
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
                    'coupon_id' => $coupon->id,
                    'localized_name' => $coupon->getLocalizedName(),
                    'benefit_formatted' => $coupon->benefit_formatted,
                    // 선택 통화별 혜택 문구 맵 — 주문서 상품 쿠폰 선택칸이 선택 통화로 보이게 한다(7.1.1)
                    'multi_currency_benefit_formatted' => $coupon->buildMultiCurrencyBenefitFormatted($this->currencyConverter),
                    'target_type' => $coupon->target_type?->value,
                    'target_type_short_label' => $coupon->target_type?->shortLabel(),
                    'min_order_amount' => $coupon->min_order_amount,
                    'per_user_limit' => (int) $coupon->per_user_limit,
                    'is_combinable' => (bool) $coupon->is_combinable,
                    'expired_at' => $couponIssue->expired_at?->toIso8601String(),
                ];
            }
        }

        return $result;
    }

    /**
     * 쿠폰이 특정 상품에 적용 가능한지 target_scope 기준으로 판정합니다.
     *
     * all → 항상 적용. products → 포함 상품에 있고 제외 상품에 없을 때. categories →
     * 상품 카테고리가 포함 카테고리와 교집합이 있고 제외 카테고리와 교집합이 없을 때.
     *
     * @param  Coupon  $coupon  쿠폰(include/exclude 관계 eager load 권장)
     * @param  int  $productId  상품 ID
     * @param  int[]  $productCategoryIds  상품이 속한 카테고리 ID 배열
     * @return bool 적용 가능 여부
     */
    public function isCouponApplicableToProduct(Coupon $coupon, int $productId, array $productCategoryIds): bool
    {
        $targetScope = $coupon->target_scope?->value ?? 'all';

        if ($targetScope === 'all') {
            return true;
        }

        if ($targetScope === 'products') {
            $includedIds = $coupon->includedProducts?->pluck('id')->toArray() ?? [];
            $excludedIds = $coupon->excludedProducts?->pluck('id')->toArray() ?? [];

            return in_array($productId, $includedIds) && ! in_array($productId, $excludedIds);
        }

        if ($targetScope === 'categories') {
            if (empty($productCategoryIds)) {
                return false;
            }

            $includedCatIds = $coupon->includedCategories?->pluck('id')->toArray() ?? [];
            $excludedCatIds = $coupon->excludedCategories?->pluck('id')->toArray() ?? [];

            return ! empty(array_intersect($productCategoryIds, $includedCatIds))
                && empty(array_intersect($productCategoryIds, $excludedCatIds));
        }

        return false;
    }

    /**
     * 상품 ID 배열에 대한 카테고리 ID 맵을 구성합니다. (categories scope 매칭용, N+1 회피)
     *
     * @param  array  $productIds  상품 ID 배열
     * @return array<int, int[]> product_id => category_id[] 맵
     */
    private function buildProductCategoryMap(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $intIds = array_map('intval', $productIds);

        return Product::with('categories:id')
            ->whereIn('id', $intIds)
            ->get(['id'])
            ->mapWithKeys(fn ($product) => [
                $product->id => $product->categories->pluck('id')->toArray(),
            ])
            ->toArray();
    }

    /**
     * 다운로드 가능한 쿠폰 목록 조회
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 다운로드 가능 쿠폰 페이지네이터
     */
    public function getDownloadableCoupons(int $userId, int $perPage = 8): LengthAwarePaginator
    {
        HookManager::doAction('sirsoft-ecommerce.user_coupon.before_downloadable_list', $userId);

        $coupons = $this->couponRepository->getDownloadableCoupons($perPage);

        // 각 쿠폰에 사용자별 다운로드 정보 추가
        $coupons->getCollection()->transform(fn (Coupon $coupon) => $this->decorateDownloadableCoupon($coupon, $userId));

        $coupons = HookManager::applyFilters('sirsoft-ecommerce.user_coupon.filter_downloadable_result', $coupons, $userId);

        HookManager::doAction('sirsoft-ecommerce.user_coupon.after_downloadable_list', $coupons, $userId);

        return $coupons;
    }

    /**
     * 비로그인 방문자도 볼 수 있는 쿠폰존 목록을 조회합니다.
     *
     * 회원용 목록과 같은 쿠폰·같은 정렬이다. 로그인한 회원이 부르면(토큰이 있으면) 받은 여부를 함께 채우고, 비로그인이면 채우지 않는다
     * (응답은 허용 목록 리소스가 정한다). 쿠폰존 화면이 로그인 확인 전후로 주소를 바꾸지 않게 한 주소로 둘 다 답한다.
     *
     * @param  int  $perPage  페이지당 항목 수
     * @param  int|null  $userId  로그인 회원 ID (비로그인이면 null)
     * @return LengthAwarePaginator 받을 수 있는 쿠폰 페이지네이터
     */
    public function getPublicDownloadableCoupons(int $perPage = 12, ?int $userId = null): LengthAwarePaginator
    {
        HookManager::doAction('sirsoft-ecommerce.user_coupon.before_public_downloadable_list', $perPage);

        $coupons = $this->couponRepository->getDownloadableCoupons($perPage);
        $coupons->getCollection()->transform(fn (Coupon $coupon) => $this->decorateDownloadableCoupon($coupon, $userId));

        $coupons = HookManager::applyFilters('sirsoft-ecommerce.user_coupon.filter_public_downloadable_result', $coupons);

        HookManager::doAction('sirsoft-ecommerce.user_coupon.after_public_downloadable_list', $coupons);

        return $coupons;
    }

    /**
     * 받기 목록의 쿠폰 1장에 화면 표시값을 채웁니다. 회원용·공개용 목록이 함께 씁니다.
     *
     * @param  Coupon  $coupon  쿠폰 (포함·제외 상품·분류 관계 로드)
     * @param  int|null  $userId  회원 ID (비로그인이면 null — 받은 여부를 계산하지 않는다)
     * @return Coupon 표시값을 채운 쿠폰
     */
    public function decorateDownloadableCoupon(Coupon $coupon, ?int $userId): Coupon
    {
        $userIssuedCount = $userId !== null
            ? $this->couponIssueRepository->getUserIssuedCountForCoupon($userId, $coupon->id)
            : 0;
        $coupon->is_downloaded = $userIssuedCount > 0;
        $coupon->user_issued_count = $userIssuedCount;
        $coupon->coupon_id = $coupon->id;
        $coupon->localized_name = $coupon->getLocalizedName();
        $coupon->localized_description = $coupon->getLocalizedDescription();
        $coupon->target_type_short_label = $coupon->target_type?->shortLabel();
        $coupon->valid_period_formatted = $coupon->valid_period_formatted;
        $coupon->min_order_amount_formatted = ecommerce_format_price($coupon->min_order_amount ?? 0);
        // 선택 통화 표시값 — 상품 상세 목록·CouponResource 와 같은 모양({통화: {price, formatted, is_default}}).
        // 주문서 쿠폰 받기 창이 읽는다(없으면 다른 통화로 보는 회원에게도 기본 통화 숫자 + 「원」, 7.1.1).
        $coupon->multi_currency_benefit_formatted = $coupon->buildMultiCurrencyBenefitFormatted($this->currencyConverter);
        $coupon->multi_currency_discount_value = $coupon->discount_type === CouponDiscountType::FIXED
            ? $this->currencyConverter->convertToMultiCurrency((int) ($coupon->discount_value ?? 0))
            : null;
        $coupon->multi_currency_min_order_amount = ($coupon->min_order_amount ?? 0) > 0
            ? $this->currencyConverter->convertToMultiCurrency((int) $coupon->min_order_amount)
            : null;
        $coupon->multi_currency_discount_max_amount = ($coupon->discount_max_amount ?? 0) > 0
            ? $this->currencyConverter->convertToMultiCurrency((int) $coupon->discount_max_amount)
            : null;
        $coupon->remaining_quantity = $coupon->total_quantity !== null
            ? max(0, $coupon->total_quantity - $coupon->issued_count)
            : null;

        return $coupon;
    }

    /**
     * 쿠폰 다운로드 (발급)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $couponId  쿠폰 ID
     * @return CouponIssue 생성된 발급 레코드
     *
     * @throws CouponNotIssuableException 다운로드 불가 시
     */
    public function downloadCoupon(int $userId, int $couponId): CouponIssue
    {
        HookManager::doAction('sirsoft-ecommerce.user_coupon.before_download', $userId, $couponId);

        return DB::transaction(function () use ($userId, $couponId) {
            $coupon = $this->couponRepository->findByIdForUpdate($couponId);

            if (! $coupon) {
                throw new CouponNotIssuableException('not_downloadable');
            }

            // 다운로드 경로 전용 게이트(발급 방법/조건/유효기간) — getDownloadableCoupons 목록 조건과 대칭.
            // assertIssuable 은 관리자 직접발급(CouponService::issueDirectly)과 공유하므로 여기에 두지 않는다.
            $this->assertDownloadable($coupon);

            // 발급 가능 조건 + per_user_limit 검증 (위반 시 사유별 예외)
            $this->assertIssuable($coupon);
            $this->assertWithinUserLimit($coupon, $userId);

            // 발급 내역 생성 (코드 접두사 DL = 다운로드)
            $couponIssue = $this->createIssueRecord($coupon, $userId, CouponIssueSource::DOWNLOAD->codePrefix(), [
                'issue_source' => CouponIssueSource::DOWNLOAD->value,
            ]);

            HookManager::doAction('sirsoft-ecommerce.user_coupon.after_download', $couponIssue, $userId, $couponId);

            return $couponIssue;
        });
    }

    /**
     * 쿠폰을 한 번에 여러 장 받습니다. (쿠폰 일괄 받기)
     *
     * 쿠폰마다 단건 받기(`downloadCoupon`)를 그대로 거친다 — 쿠폰별 트랜잭션·잠금·발급 검사·훅이
     * 같다. 한 장이 실패해도 나머지를 막지 않는다. 이미 한 장 이상 받은 쿠폰은 회원당 한도가 남아
     * 있어도 더 받지 않는다(화면의 「받음」 표시가 받은 수 > 0 기준이다).
     *
     * @param  int  $userId  사용자 ID
     * @param  array<int, int>|null  $couponIds  받을 쿠폰 ID 목록 (null 이면 받을 수 있는 쿠폰 전체, 최대 50장)
     * @return array{results: array<int, array{coupon_id: int, status: string, reason_code: string|null}>, issued_count: int, skipped_count: int} 쿠폰별 결과와 합계
     */
    public function bulkDownloadCoupons(int $userId, ?array $couponIds = null): array
    {
        $takeAll = $couponIds === null;
        if ($takeAll) {
            $couponIds = $this->couponRepository->getDownloadableCoupons()->pluck('id')->all();
        }

        $couponIds = array_values(array_unique(array_map('intval', $couponIds)));

        // 「이미 받은 수」는 쿠폰 수와 무관하게 한 번에 센다
        $issuedCounts = $this->couponIssueRepository->getUserIssuedCountsForCoupons($userId, $couponIds);

        if ($takeAll && count($couponIds) > self::BULK_DOWNLOAD_MAX) {
            // 한 번에 다루는 장수는 아직 받지 않은 쿠폰부터 채운다 — 최신순으로 먼저 자르면 최신 쿠폰을 이미 받은
            // 회원은 그 뒤의 쿠폰을 몇 번을 불러도 받지 못한다
            $pending = array_filter($couponIds, static fn (int $id): bool => ($issuedCounts[$id] ?? 0) === 0);
            $received = array_diff($couponIds, $pending);
            $couponIds = array_slice(array_merge($pending, $received), 0, self::BULK_DOWNLOAD_MAX);
        }

        $results = [];
        $issued = 0;

        foreach ($couponIds as $couponId) {
            if (($issuedCounts[$couponId] ?? 0) > 0) {
                $results[] = ['coupon_id' => $couponId, 'status' => 'skipped', 'reason_code' => 'already_downloaded'];

                continue;
            }

            try {
                $this->downloadCoupon($userId, $couponId);
                $results[] = ['coupon_id' => $couponId, 'status' => 'issued', 'reason_code' => null];
                $issued++;
            } catch (CouponNotIssuableException $e) {
                $results[] = ['coupon_id' => $couponId, 'status' => 'skipped', 'reason_code' => $e->getReason()];
            } catch (\Throwable $e) {
                Log::error('쿠폰 일괄 받기 중 한 장 실패', [
                    'coupon_id' => $couponId,
                    'user_id' => $userId,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
                $results[] = ['coupon_id' => $couponId, 'status' => 'skipped', 'reason_code' => 'operation_failed'];
            }
        }

        return [
            'results' => $results,
            'issued_count' => $issued,
            'skipped_count' => count($results) - $issued,
        ];
    }

    /**
     * 쿠폰이 사용자 다운로드 경로로 발급 가능한지 검증합니다. 위반 시 사유별 예외를 던집니다.
     *
     * 발급 방법(다운로드)·발급 조건(수동)·유효기간(valid_to) 만료 여부를 검사하며,
     * `CouponRepository::getDownloadableCoupons` 의 목록 조건과 대칭입니다. 이 게이트가
     * 없으면 자동/관리자 발급 전용 쿠폰이나 만료 쿠폰의 id 를 직접 지정해 다운로드할 수 있습니다.
     *
     * @param  Coupon  $coupon  다운로드 대상 쿠폰
     * @return void 반환값 없음
     *
     * @throws CouponNotIssuableException 다운로드 불가(발급 방법/조건 불일치) 또는 만료 시
     */
    public function assertDownloadable(Coupon $coupon): void
    {
        if ($coupon->issue_method !== CouponIssueMethod::DOWNLOAD) {
            throw new CouponNotIssuableException('not_downloadable');
        }

        if ($coupon->issue_condition !== CouponIssueCondition::MANUAL) {
            throw new CouponNotIssuableException('not_downloadable');
        }

        if ($coupon->valid_to !== null && $coupon->valid_to->isPast()) {
            throw new CouponNotIssuableException('expired');
        }
    }

    /**
     * 쿠폰의 발급 가능 상태(발급중/재고/기간)를 검증합니다. 위반 시 사유별 예외를 던집니다.
     *
     * @param  Coupon  $coupon  발급 대상 쿠폰
     * @return void 반환값 없음
     *
     * @throws CouponNotIssuableException 발급 불가 시(상태/재고/유효기간 미설정/기간)
     */
    public function assertIssuable(Coupon $coupon): void
    {
        if ($coupon->isIssuable()) {
            return;
        }

        if ($coupon->issue_status->value !== 'issuing') {
            throw new CouponNotIssuableException('not_downloadable');
        }
        if ($coupon->total_quantity !== null && $coupon->issued_count >= $coupon->total_quantity) {
            throw new CouponNotIssuableException('quantity_exhausted');
        }
        if (! $coupon->hasResolvableValidity()) {
            throw new CouponNotIssuableException('validity_not_configured');
        }
        throw new CouponNotIssuableException('issue_period_expired');
    }

    /**
     * 회원별 발급 한도(per_user_limit)를 검증합니다. (0 = 무제한)
     *
     * @param  Coupon  $coupon  발급 대상 쿠폰
     * @param  int  $userId  발급 대상 회원 ID
     * @return void 반환값 없음
     *
     * @throws CouponNotIssuableException 한도 초과 시
     */
    public function assertWithinUserLimit(Coupon $coupon, int $userId): void
    {
        $userIssuedCount = $this->couponIssueRepository->getUserIssuedCountForCoupon($userId, $coupon->id);
        if ($coupon->per_user_limit > 0 && $userIssuedCount >= $coupon->per_user_limit) {
            throw new CouponNotIssuableException('download_limit_exceeded');
        }
    }

    /**
     * 관리자 직접발급용 회원별 발급 1건을 처리합니다. (per_user_limit 검증 + DR 코드 발급)
     *
     * 쿠폰 자체의 발급 가능 여부(assertIssuable)는 호출 측에서 일괄 검증한 뒤 호출합니다.
     *
     * @param  Coupon  $coupon  발급 대상 쿠폰(잠금 조회된 인스턴스)
     * @param  int  $userId  발급 대상 회원 ID
     * @return CouponIssue 생성된 발급 레코드
     *
     * @throws CouponNotIssuableException 탈퇴 회원(user_withdrawn)·per_user_limit 초과 시
     */
    public function issueDirectlyToUser(Coupon $coupon, int $userId): CouponIssue
    {
        // 탈퇴 회원은 로그인할 수 없어 받은 쿠폰을 쓰지 못한다 — 요청 검증이 먼저 막지만, 서비스를 바로 부르는
        // 확장·훅 경로도 같은 판정을 받게 여기서도 막는다(7.1.1).
        $user = $this->userRepository->findById($userId);
        if ($user !== null && $user->status === UserStatus::Withdrawn->value) {
            throw new CouponNotIssuableException('user_withdrawn');
        }

        // 자동발급 쿠폰의 직접발급은 자동발급과 한도를 공유한다 — 같은 발급 키로 기록해, 직접 받은 회원이
        // 같은 조건으로 자동으로 다시 받지 않게 한다. 회원당 횟수는 발급 키와 DB 유니크가 정한다(per_user_limit 은 0 고정).
        $autoKey = $this->autoIssueKeyFor($coupon);
        if ($autoKey !== null) {
            if ($this->couponIssueRepository->existsByAutoIssueKey($coupon->id, $userId, $autoKey)) {
                throw new CouponNotIssuableException('auto_issue_already_issued');
            }

            try {
                return $this->createIssueRecord($coupon, $userId, CouponIssueSource::DIRECT->codePrefix(), [
                    'issue_source' => CouponIssueSource::DIRECT->value,
                    'auto_issue_key' => $autoKey,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new CouponNotIssuableException('auto_issue_already_issued');
            }
        }

        $this->assertWithinUserLimit($coupon, $userId);

        return $this->createIssueRecord($coupon, $userId, CouponIssueSource::DIRECT->codePrefix(), [
            'issue_source' => CouponIssueSource::DIRECT->value,
        ]);
    }

    /**
     * 자동발급 쿠폰 1건을 회원에게 발급합니다.
     *
     * 쿠폰 잠금·발급 가능 판정·발급 키 중복 확인은 호출 측(`CouponAutoIssueService::issueOne`)이 한 트랜잭션 안에서 먼저 한다.
     * 활성 회원만 받는다 — 탈퇴·차단·본인인증 대기 회원은 예외로 거절한다.
     *
     * @param  Coupon  $coupon  발급 대상 쿠폰(잠금 조회된 인스턴스)
     * @param  int  $userId  회원 ID
     * @param  CouponIssueSource  $source  자동발급 경로 (가입·첫구매·생일)
     * @param  string  $autoIssueKey  발급 키 (signup·first_purchase·birthday:YYYY)
     * @param  int|null  $sourceOrderId  첫구매 쿠폰을 발급하게 한 주문 ID
     * @return CouponIssue 생성된 발급 레코드
     *
     * @throws CouponNotIssuableException 회원이 활성 상태가 아닐 때(user_inactive)
     */
    public function issueAutomaticallyToUser(Coupon $coupon, int $userId, CouponIssueSource $source, string $autoIssueKey, ?int $sourceOrderId = null): CouponIssue
    {
        $user = $this->userRepository->findById($userId);
        $status = $user?->status;
        $status = $status instanceof \BackedEnum ? $status->value : (string) $status;

        if ($user === null || $status !== UserStatus::Active->value) {
            throw new CouponNotIssuableException('user_inactive');
        }

        return $this->createIssueRecord($coupon, $userId, $source->codePrefix(), [
            'issue_source' => $source->value,
            'auto_issue_key' => $autoIssueKey,
            'source_order_id' => $sourceOrderId,
        ]);
    }

    /**
     * 자동발급 쿠폰의 발급 키를 반환합니다. 자동발급 쿠폰이 아니면 null 입니다.
     *
     * 생일 쿠폰은 사이트 시간대의 올해로 「해마다 1회」 를 나눈다.
     *
     * @param  Coupon  $coupon  쿠폰
     * @return string|null 발급 키
     */
    public function autoIssueKeyFor(Coupon $coupon): ?string
    {
        if ($coupon->issue_method !== CouponIssueMethod::AUTO) {
            return null;
        }

        return match ($coupon->issue_condition) {
            CouponIssueCondition::SIGNUP => 'signup',
            CouponIssueCondition::FIRST_PURCHASE => 'first_purchase',
            CouponIssueCondition::BIRTHDAY => self::birthdayIssueKey((int) now(TimezoneHelper::getSiteTimezone())->year),
            default => null,
        };
    }

    /**
     * 생일 쿠폰의 연도별 발급 키를 반환합니다.
     *
     * @param  int  $year  사이트 시간대 기준 연도
     * @return string 발급 키 (birthday:YYYY)
     */
    public static function birthdayIssueKey(int $year): string
    {
        return 'birthday:'.$year;
    }

    /**
     * 쿠폰 발급 내역 1건을 생성합니다. (코드 생성·만료 계산·issued_count 증가)
     *
     * 다운로드(DL)·직접발급(DR) 공용 발급 본체. 호출 측에서 잠금 조회·발급 가능 여부를
     * 검증한 뒤 이 메서드를 호출합니다.
     *
     * @param  Coupon  $coupon  발급 대상 쿠폰(잠금 조회된 인스턴스)
     * @param  int  $userId  발급 대상 회원 ID
     * @param  string  $codePrefix  쿠폰 코드 접두사 (DL=다운로드, DR=직접발급, AU=자동발급)
     * @param  array<string, mixed>  $extra  발급 행에 더할 값 (issue_source·auto_issue_key·source_order_id)
     * @return CouponIssue 생성된 발급 레코드
     */
    protected function createIssueRecord(Coupon $coupon, int $userId, string $codePrefix, array $extra = []): CouponIssue
    {
        // 쿠폰 코드 생성
        $couponCode = $codePrefix.'-'.strtoupper(Str::random(8));

        // expired_at 계산
        //
        // valid_days 는 nullable 컬럼이라 캐스트를 거쳐도 NULL/문자열이 그대로 도착할 수 있다.
        // Carbon 은 strict 타입 경계라 숫자 문자열을 받으면 TypeError 를 던지고, NULL 은
        // 0 일로 흡수해 "발급 즉시 만료" 라는 조용한 오작동을 만든다. 정수로 확정한 뒤
        // 계산하고, 계산 불가(0 이하)면 기간지정 쿠폰의 valid_to 가 비어 있을 때와 같은
        // 규약(만료 없음)을 따른다. 이 조합은 assertIssuable 이 앞단에서 차단한다.
        $expiredAt = null;
        if ($coupon->valid_type === 'period') {
            $expiredAt = $coupon->valid_to;
        } elseif ($coupon->valid_type === 'days_from_issue') {
            $validDays = is_numeric($coupon->valid_days) ? (int) $coupon->valid_days : 0;
            $expiredAt = $validDays > 0 ? now()->addDays($validDays) : null;
        }

        // CouponIssue 생성
        $couponIssue = $this->couponIssueRepository->create(array_merge($extra, [
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'coupon_code' => $couponCode,
            'status' => CouponIssueRecordStatus::AVAILABLE->value,
            'issued_at' => now(),
            'expired_at' => $expiredAt,
        ]));

        // issued_count 증가
        $this->couponRepository->incrementIssuedCount($coupon->id);

        return $couponIssue;
    }

    /**
     * 상품별 다운로드 가능한 쿠폰 목록 조회
     *
     * @param  int  $productId  상품 ID
     * @param  int|null  $userId  사용자 ID (로그인 시)
     * @return array 쿠폰 배열
     */
    public function getProductDownloadableCoupons(int $productId, ?int $userId = null): array
    {
        $coupons = $this->couponRepository->getDownloadableCoupons(null);

        // 상품 적용 범위 필터링
        $product = Product::with('categories:id')->find($productId);
        $categoryIds = $product?->categories?->pluck('id')->toArray() ?? [];

        $filtered = $coupons->filter(function ($coupon) use ($productId, $categoryIds) {
            return $this->isCouponApplicableToProduct($coupon, $productId, $categoryIds);
        });

        // 프론트엔드용 형식으로 변환
        $result = $filtered->map(function ($coupon) use ($userId) {
            $data = [
                'coupon_id' => $coupon->id,
                'localized_name' => $coupon->getLocalizedName(),
                'benefit_formatted' => $coupon->benefit_formatted,
                // 선택 통화별 베네핏 포맷 맵(D4 — 상세 쿠폰 칩 KRW 고정 해소). 정률 %는 통화 무관.
                'multi_currency_benefit_formatted' => $coupon->buildMultiCurrencyBenefitFormatted($this->currencyConverter),
                'target_type' => $coupon->target_type?->value,
                'target_type_short_label' => $coupon->target_type?->shortLabel(),
                'valid_period_formatted' => $coupon->valid_period_formatted,
                'min_order_amount' => $coupon->min_order_amount,
                'min_order_amount_formatted' => ecommerce_format_price($coupon->min_order_amount ?? 0),
                'multi_currency_min_order_amount' => ($coupon->min_order_amount ?? 0) > 0
                    ? $this->currencyConverter->convertToMultiCurrency((int) $coupon->min_order_amount)
                    : null,
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
