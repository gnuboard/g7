<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\AppliedPromotions;
use Modules\Sirsoft\Ecommerce\DTO\AppliedShippingPolicy;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\SnapshotProduct;
use Modules\Sirsoft\Ecommerce\DTO\SnapshotProductOption;
use Modules\Sirsoft\Ecommerce\DTO\CouponApplication;
use Modules\Sirsoft\Ecommerce\DTO\ItemCalculation;
use Modules\Sirsoft\Ecommerce\DTO\MultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\PromotionsSummary;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\DTO\ValidationError;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;

/**
 * 주문 계산 서비스
 *
 * 9단계 계산 로직을 구현하는 핵심 서비스입니다.
 *
 * 사용처:
 * - 상품상세: 자동할인쿠폰, 유저 기본배송지에 따른 배송비 계산
 * - 장바구니: 자동할인쿠폰, 유저 기본배송지에 따른 배송비 계산
 * - 체크아웃: 쿠폰/마일리지 적용, 최종 결제금액 계산
 * - 주문서 생성: 최종 결제금액 확정
 * - 결제완료 처리: 최종 결제금액 검증
 * - 부분취소: 취소 금액 계산
 *
 * 계산 순서:
 * 1. 옵션별 판매금액 계산
 * 2. 상품/카테고리 할인쿠폰 적용
 *    2-a. 옵션별 적립 마일리지 계산
 *    2-b. 과세/면세 금액 분류
 * 3. 배송비 계산 (정책별 그룹)
 * 4. 배송비 할인쿠폰 적용
 * 5. 주문금액 할인쿠폰 적용
 * 6. 적립 마일리지 합계
 * 7. 최종 결제금액 계산
 * 8. 마일리지 사용 차감
 * 9. 최종 지불금액 계산
 *
 * @todo 결제완료 검증 시 Order 모델에서 CalculationInput 복원 메서드 필요
 *       - Order::toCalculationInput() 또는
 *       - OrderCalculationService::fromOrder(Order $order) 정적 메서드
 * @todo 부분취소는 별도 RefundCalculationService로 분리 권장
 *       - 이미 적용된 할인의 안분 비율 계산
 *       - 마일리지 환불 처리
 *       - 배송비 환불 여부 판단
 */
class OrderCalculationService
{
    /**
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스
     * @param  ProductOptionRepositoryInterface  $productOptionRepository  상품 옵션 Repository
     * @param  CouponIssueRepositoryInterface  $couponIssueRepository  쿠폰 발급 Repository
     * @param  ShippingPolicyRepositoryInterface  $shippingPolicyRepository  배송정책 Repository
     */
    public function __construct(
        protected CurrencyConversionService $currencyService,
        protected ProductOptionRepositoryInterface $productOptionRepository,
        protected CouponIssueRepositoryInterface $couponIssueRepository,
        protected ShippingPolicyRepositoryInterface $shippingPolicyRepository,
    ) {}

    /**
     * 주문 계산 실행 (9단계)
     *
     * @param  CalculationInput  $input  계산 입력 데이터
     * @return OrderCalculationResult 계산 결과
     */
    public function calculate(CalculationInput $input): OrderCalculationResult
    {
        // 아이템 데이터 준비 (상품/옵션 정보 로드 또는 스냅샷 사용)
        $preparedItems = $this->prepareItems($input->items, $input);

        if (empty($preparedItems)) {
            return new OrderCalculationResult;
        }

        // 쿠폰 정보 로드 (주문/배송비 쿠폰)
        $coupons = $this->loadCoupons($input->couponIssueIds);

        // 상품별 쿠폰 정보 로드
        $itemCouponIds = $this->extractCouponIdsFromItemCoupons($input->itemCoupons);
        $itemCouponIssues = $this->loadCoupons($itemCouponIds);

        $validationErrors = [];

        // 단계 1: 옵션별 판매금액 계산
        // Before: 단가/수량 조작 가능 (회원등급 할인, 프로모션 단가 등)
        $preparedItems = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.before_item_subtotals',
            $preparedItems,
            $input
        );
        $itemSubtotals = $this->calculateItemSubtotals($preparedItems);
        // After: 계산된 소계 수정 가능
        $itemSubtotals = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_item_subtotals',
            $itemSubtotals,
            $preparedItems
        );

        // 단계 2: 상품/카테고리 할인쿠폰 적용
        $productCoupons = $this->filterCouponsByType($coupons, CouponTargetType::PRODUCT_AMOUNT);
        // Before: 쿠폰 목록 조작 가능 (자동 쿠폰 주입 등)
        $productCoupons = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.before_product_discount',
            $productCoupons,
            $itemSubtotals,
            $preparedItems
        );
        [$itemsAfterProductDiscount, $productPromotions, $productErrors] = $this->applyProductCoupons(
            $itemSubtotals,
            $productCoupons,
            $preparedItems,
            $input->itemCoupons,
            $itemCouponIssues,
            $input
        );
        // After: 할인 결과 수정 가능
        $itemsAfterProductDiscount = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_product_discount',
            $itemsAfterProductDiscount,
            $productCoupons,
            $preparedItems
        );
        $validationErrors = array_merge($validationErrors, $productErrors);

        // 단계 2-b: 과세/면세 금액 분류
        $taxClassification = $this->classifyTaxStatus($preparedItems, $itemsAfterProductDiscount);
        $taxClassification = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_tax_classification',
            $taxClassification,
            $preparedItems,
            $itemsAfterProductDiscount
        );

        // 단계 3: 배송비 계산 (정책별 그룹)
        // Before: 배송정책 그룹 조작 가능 (지역별 정책 변경 등)
        $shippingContext = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.before_shipping_fee',
            [
                'prepared_items' => $preparedItems,
                'discounted_items' => $itemsAfterProductDiscount,
                'shipping_address' => $input->shippingAddress,
            ],
            $input
        );
        $shippingCalculation = $this->calculateShippingFee(
            $shippingContext['prepared_items'],
            $shippingContext['discounted_items'],
            $shippingContext['shipping_address'] ?? $input->shippingAddress,
            $input
        );
        // After: 계산된 배송비 수정 가능
        $shippingCalculation = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_shipping_fee',
            $shippingCalculation,
            $preparedItems,
            $itemsAfterProductDiscount
        );

        // 단계 4: 배송비 할인쿠폰 적용
        $shippingCoupons = $this->filterCouponsByType($coupons, CouponTargetType::SHIPPING_FEE);
        // Before: 배송비 쿠폰 목록 조작 가능
        $shippingCoupons = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.before_shipping_discount',
            $shippingCoupons,
            $shippingCalculation
        );
        [$shippingAfterDiscount, $shippingPromotions, $shippingErrors] = $this->applyShippingCoupon(
            $shippingCalculation,
            $shippingCoupons,
            $input
        );
        // After: 배송비 할인 결과 수정 가능
        $shippingAfterDiscount = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_shipping_discount',
            $shippingAfterDiscount,
            $shippingCoupons
        );
        $validationErrors = array_merge($validationErrors, $shippingErrors);

        // 단계 5: 주문금액 할인쿠폰 적용
        $orderCoupons = $this->filterCouponsByType($coupons, CouponTargetType::ORDER_AMOUNT);
        // Before: 주문 쿠폰 목록 조작 가능
        $orderCoupons = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.before_order_discount',
            $orderCoupons,
            $itemsAfterProductDiscount,
            $preparedItems
        );
        [$itemsAfterOrderDiscount, $orderPromotions, $orderErrors] = $this->applyOrderCoupon(
            $itemsAfterProductDiscount,
            $orderCoupons,
            $preparedItems,
            $input
        );
        // After: 주문 할인 결과 수정 가능
        $itemsAfterOrderDiscount = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_order_discount',
            $itemsAfterOrderDiscount,
            $orderCoupons,
            $preparedItems
        );
        $validationErrors = array_merge($validationErrors, $orderErrors);

        // 단계 6: 최종 결제금액 계산
        // Before: 결제금액 계산 전 데이터 조작 가능 (수수료 추가 등)
        $paymentContext = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.before_payment_amount',
            [
                'discounted_items' => $itemsAfterOrderDiscount,
                'shipping_result' => $shippingAfterDiscount,
            ],
            $input
        );
        $paymentCalculation = $this->calculatePaymentAmount(
            $paymentContext['discounted_items'],
            $paymentContext['shipping_result']
        );
        // After: 계산된 결제금액 수정 가능
        $paymentCalculation = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_payment_amount',
            $paymentCalculation,
            $itemsAfterOrderDiscount,
            $shippingAfterDiscount
        );

        // 단계 7: 마일리지 사용 차감
        $pointsUsageResult = $this->applyPointsUsage(
            $paymentCalculation['payment_amount'],
            $input->usePoints,
            $itemsAfterOrderDiscount
        );
        // After: 마일리지 사용 후 추가 결제 수단 처리 가능 (예치금, 상품권 등)
        $pointsUsageResult = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_points_usage',
            $pointsUsageResult,
            $paymentCalculation['payment_amount'],
            $itemsAfterOrderDiscount,
            $input
        );

        // 단계 8: 적립 마일리지 계산 (마일리지 사용 후 금액 기준)
        $pointsPerItem = $this->calculatePointsEarning(
            $preparedItems,
            $itemsAfterOrderDiscount,
            $pointsUsageResult
        );
        $pointsPerItem = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_points_earning',
            $pointsPerItem,
            $preparedItems,
            $itemsAfterOrderDiscount,
            $pointsUsageResult
        );
        $totalPointsEarning = $this->sumPointsEarning($pointsPerItem);

        // 단계 9: 최종 지불금액 계산
        // Before: 최종 결과 빌드 전 데이터 조작 가능 (마일리지 사용 수정 등)
        $finalContext = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.before_final_result',
            [
                'prepared_items' => $preparedItems,
                'item_subtotals' => $itemSubtotals,
                'items_after_product_discount' => $itemsAfterProductDiscount,
                'items_after_order_discount' => $itemsAfterOrderDiscount,
                'points_per_item' => $pointsPerItem,
                'tax_classification' => $taxClassification,
                'shipping_result' => $shippingAfterDiscount,
                'points_usage_result' => $pointsUsageResult,
                'payment_calculation' => $paymentCalculation,
            ],
            $input
        );
        $finalResult = $this->buildFinalResult(
            $finalContext['prepared_items'],
            $finalContext['item_subtotals'],
            $finalContext['items_after_product_discount'],
            $finalContext['items_after_order_discount'],
            $finalContext['points_per_item'],
            $finalContext['tax_classification'],
            $finalContext['shipping_result'],
            $finalContext['points_usage_result'],
            $finalContext['payment_calculation'],
            $productPromotions,
            $orderPromotions,
            $shippingPromotions,
            $totalPointsEarning,
            $validationErrors,
            $input
        );
        // After: 최종 결과 수정 가능
        // - 플러그인에서 metadata 추가 (리워드, 예치금, 회원등급 할인 등)
        // - 검증 오류 추가
        // - items[].metadata, summary.metadata, metadata 모두 수정 가능
        $finalResult = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.after_final_result',
            $finalResult,
            $input
        );

        return $finalResult;
    }

    /**
     * 계산용 아이템 데이터를 준비합니다.
     *
     * 스냅샷이 있으면 DB 조회 대신 스냅샷 데이터를 사용합니다 (환불 재계산용).
     *
     * @param  CalculationItem[]  $items  계산 아이템 목록
     * @param  CalculationInput|null  $input  계산 입력 (스냅샷 모드 판별용)
     * @return array 상품/옵션 정보가 로드된 아이템 배열
     */
    protected function prepareItems(array $items, ?CalculationInput $input = null): array
    {
        $preparedItems = [];
        $shippingPolicySnapshots = $input?->shippingPolicySnapshots ?? [];

        foreach ($items as $item) {
            // 스냅샷 모드: 주문 시점 데이터로 구성 (DB 조회 없음)
            if ($item->productSnapshot !== null && $item->optionSnapshot !== null) {
                $shippingPolicyId = null;
                if (isset($shippingPolicySnapshots[$item->productOptionId]['policy_id'])) {
                    $shippingPolicyId = (int) $shippingPolicySnapshots[$item->productOptionId]['policy_id'];
                }

                $snapshotProduct = new SnapshotProduct($item->productSnapshot, $shippingPolicyId);
                $snapshotOption = new SnapshotProductOption($item->optionSnapshot, $snapshotProduct->selling_price);

                $preparedItems[] = [
                    'cart_id' => $item->cartId,
                    'product_id' => $item->productId,
                    'product_option_id' => $item->productOptionId,
                    'quantity' => $item->quantity,
                    'product' => $snapshotProduct,
                    'product_option' => $snapshotOption,
                    'unit_price' => $snapshotOption->getSellingPrice(),
                    '_snapshot_mode' => true,
                ];

                continue;
            }

            // 통상 모드: DB에서 상품/옵션 정보 로드
            $productOption = $this->productOptionRepository->findWithRelations(
                $item->productOptionId,
                ['product.categories', 'product.shippingPolicy']
            );

            if (! $productOption || ! $productOption->product) {
                continue;
            }

            $preparedItems[] = [
                'cart_id' => $item->cartId,
                'product_id' => $item->productId,
                'product_option_id' => $item->productOptionId,
                'quantity' => $item->quantity,
                'product' => $productOption->product,
                'product_option' => $productOption,
                'unit_price' => $productOption->getSellingPrice(),
            ];
        }

        return $preparedItems;
    }

    /**
     * 쿠폰 정보를 로드합니다.
     *
     * @param  int[]  $couponIssueIds  쿠폰 발급 ID 배열
     * @return CouponIssue[] 쿠폰 발급 정보 배열
     */
    protected function loadCoupons(array $couponIssueIds): array
    {
        if (empty($couponIssueIds)) {
            return [];
        }

        return $this->couponIssueRepository->findByIdsWithRelations(
            $couponIssueIds,
            ['coupon.includedProducts', 'coupon.excludedProducts', 'coupon.includedCategories', 'coupon.excludedCategories']
        );
    }

    /**
     * 쿠폰을 타입별로 필터링합니다.
     *
     * @param  CouponIssue[]  $coupons  쿠폰 발급 목록
     * @param  CouponTargetType  $type  쿠폰 적용 대상 타입
     * @return CouponIssue[] 필터링된 쿠폰 목록
     */
    protected function filterCouponsByType(array $coupons, CouponTargetType $type): array
    {
        return array_filter($coupons, fn (CouponIssue $issue) => $issue->coupon->target_type === $type);
    }

    /**
     * 단계 1: 옵션별 판매금액을 계산합니다.
     *
     * @param  array  $preparedItems  준비된 아이템 배열
     * @return array 옵션별 소계 배열 [product_option_id => subtotal]
     */
    protected function calculateItemSubtotals(array $preparedItems): array
    {
        $subtotals = [];

        foreach ($preparedItems as $item) {
            $subtotal = $item['unit_price'] * $item['quantity'];
            $subtotals[$item['product_option_id']] = [
                'unit_price' => $item['unit_price'],
                'quantity' => $item['quantity'],
                'subtotal' => $subtotal,
            ];
        }

        return $subtotals;
    }

    /**
     * itemCoupons에서 모든 쿠폰 발급 ID를 추출합니다.
     *
     * @param  array<int, int[]>  $itemCoupons  상품옵션별 쿠폰 매핑
     * @return int[] 쿠폰 발급 ID 배열
     */
    protected function extractCouponIdsFromItemCoupons(array $itemCoupons): array
    {
        $allCouponIds = [];
        foreach ($itemCoupons as $couponIds) {
            $allCouponIds = array_merge($allCouponIds, $couponIds);
        }

        return array_values(array_unique($allCouponIds));
    }

    /**
     * 단계 2: 상품/카테고리 할인쿠폰을 적용합니다.
     *
     * 스냅샷 모드에서는 쿠폰 규칙(할인값/유형)을 스냅샷 값으로 사용하고,
     * 스코프 검증(상품/카테고리 범위)을 스킵합니다.
     *
     * @param  array  $itemSubtotals  옵션별 소계 배열
     * @param  array  $coupons  상품쿠폰 목록 (주문 레벨에서 지정된 상품 쿠폰)
     * @param  array  $preparedItems  준비된 아이템 배열
     * @param  array<int, int[]>  $itemCoupons  상품옵션별 쿠폰 매핑 [상품옵션ID => [쿠폰발급ID, ...]]
     * @param  CouponIssue[]  $itemCouponIssues  상품별 쿠폰 발급 정보
     * @param  CalculationInput|null  $input  계산 입력 (스냅샷 모드용)
     * @return array [아이템별 할인 후 금액, AppliedPromotions, ValidationError[]]
     */
    protected function applyProductCoupons(
        array $itemSubtotals,
        array $coupons,
        array $preparedItems,
        array $itemCoupons = [],
        array $itemCouponIssues = [],
        ?CalculationInput $input = null
    ): array {
        $discountedItems = $itemSubtotals;
        $appliedPromotions = new AppliedPromotions;
        $validationErrors = [];

        $snapshotMode = $input->metadata['snapshot_mode'] ?? false;
        $couponSnapshots = $input->metadata['coupon_snapshots'] ?? [];

        // 상품별 쿠폰 적용 (itemCoupons)
        if (! empty($itemCoupons) && ! empty($itemCouponIssues)) {
            // 쿠폰 발급 ID를 키로 하는 맵 생성
            $couponIssueMap = [];
            foreach ($itemCouponIssues as $couponIssue) {
                $couponIssueMap[$couponIssue->id] = $couponIssue;
            }

            // 각 상품옵션에 지정된 쿠폰 적용
            foreach ($itemCoupons as $optionId => $couponIds) {
                foreach ($couponIds as $couponIssueId) {
                    if (! isset($couponIssueMap[$couponIssueId])) {
                        continue;
                    }

                    $couponIssue = $couponIssueMap[$couponIssueId];
                    $coupon = $couponIssue->coupon;
                    $snapshot = $couponSnapshots[$couponIssueId] ?? null;

                    // 쿠폰 검증
                    $error = $this->validateCoupon(
                        $couponIssue,
                        $itemSubtotals,
                        $preparedItems,
                        $snapshotMode,
                        (int) ($snapshot['min_order_amount'] ?? 0)
                    );
                    if ($error !== null) {
                        $validationErrors[] = $error;

                        continue;
                    }

                    // 해당 상품옵션에만 할인 적용
                    $currentSubtotal = $discountedItems[$optionId]['discounted_subtotal']
                        ?? $discountedItems[$optionId]['subtotal']
                        ?? 0;

                    // 상품금액 쿠폰 정액할인: 수량만큼 할인
                    $targetType = $snapshot['target_type'] ?? $coupon->target_type->value;
                    $itemQuantity = ($targetType === CouponTargetType::PRODUCT_AMOUNT->value || $targetType === 'product_amount')
                        ? ($discountedItems[$optionId]['quantity'] ?? 1)
                        : 1;

                    $discount = $this->calculateCouponDiscount($coupon, $currentSubtotal, $snapshot, $itemQuantity);

                    $discountedItems[$optionId]['coupon_discount'] = ($discountedItems[$optionId]['coupon_discount'] ?? 0) + $discount;
                    $discountedItems[$optionId]['discounted_subtotal'] = $currentSubtotal - $discount;

                    // 적용 프로모션 기록 (스냅샷 규칙 우선)
                    $defaultCurrency = $this->currencyService->getDefaultCurrency();
                    $appliedPromotions->addCoupon(new CouponApplication(
                        couponId: $coupon->id,
                        couponIssueId: $couponIssue->id,
                        name: $coupon->getLocalizedName(),
                        targetType: $snapshot['target_type'] ?? $coupon->target_type->value,
                        targetScope: $snapshot['target_scope'] ?? $coupon->target_scope?->value,
                        discountType: $snapshot['discount_type'] ?? $coupon->discount_type->value,
                        discountValue: (float) ($snapshot['discount_value'] ?? $coupon->discount_value),
                        totalDiscount: $discount,
                        totalDiscountFormatted: $this->currencyService->formatPrice($discount, $defaultCurrency),
                        minOrderAmount: (int) $coupon->min_order_amount,
                        maxDiscountAmount: (int) ($coupon->discount_max_amount ?? 0),
                        appliedItems: [
                            [
                                'product_option_id' => $optionId,
                                'discount_amount' => $discount,
                                'discount_amount_formatted' => $this->currencyService->formatPrice($discount, $defaultCurrency),
                            ],
                        ],
                    ));
                }
            }
        }

        // 기존 로직: 주문 레벨 상품 쿠폰 적용 (target_scope에 따라 적용)
        if (! empty($coupons)) {
            // 쿠폰 조합 가능 여부 검증 (is_combinable)
            $combinationErrors = $this->validateCouponCombination($coupons);
            if (! empty($combinationErrors)) {
                return [$discountedItems, $appliedPromotions, $combinationErrors];
            }

            foreach ($coupons as $couponIssue) {
                $coupon = $couponIssue->coupon;
                $snapshot = $couponSnapshots[$couponIssue->id] ?? null;

                // 쿠폰 검증
                $error = $this->validateCoupon(
                    $couponIssue,
                    $itemSubtotals,
                    $preparedItems,
                    $snapshotMode,
                    (int) ($snapshot['min_order_amount'] ?? 0)
                );
                if ($error !== null) {
                    $validationErrors[] = $error;

                    continue;
                }

                // 적용 대상 아이템 필터링
                // 스냅샷 모드: applied_items가 있으면 해당 상품옵션만, 없으면 전체
                if ($snapshotMode) {
                    $snapshotAppliedItems = $snapshot['applied_items'] ?? null;
                    if ($snapshotAppliedItems !== null) {
                        $appliedOptionIds = array_column($snapshotAppliedItems, 'product_option_id');
                        $targetItems = array_filter($preparedItems, fn ($item) => in_array($item['product_option_id'], $appliedOptionIds));
                    } else {
                        $targetItems = $preparedItems;
                    }
                } else {
                    $targetItems = $this->filterItemsByScope($preparedItems, $coupon);
                }
                if (empty($targetItems)) {
                    $validationErrors[] = ValidationError::invalidTarget($coupon->id);

                    continue;
                }

                // 할인 계산 (1단계: 아이템별 할인 산출)
                $totalDiscount = 0;
                $appliedItems = [];

                foreach ($targetItems as $item) {
                    $optionId = $item['product_option_id'];
                    // 원래 소계 기준으로 할인 계산 (병렬 적용)
                    $originalSubtotal = $discountedItems[$optionId]['subtotal'] ?? 0;

                    // 상품금액 쿠폰 정액할인: 수량만큼 할인
                    $itemQuantity = $discountedItems[$optionId]['quantity'] ?? 1;

                    $discount = $this->calculateCouponDiscount($coupon, $originalSubtotal, $snapshot, $itemQuantity);

                    $totalDiscount += $discount;
                    $appliedItems[] = [
                        'product_option_id' => $optionId,
                        'discount_amount' => $discount,
                    ];
                }

                // 2단계: 글로벌 최대 할인금액 제한
                // 복수 아이템 적용 시 per-item cap 통과해도 합계가 글로벌 cap 초과 가능
                $maxDiscountAmount = (int) ($coupon->discount_max_amount ?? 0);
                if ($maxDiscountAmount > 0 && $totalDiscount > $maxDiscountAmount) {
                    // 비례 안분: 각 아이템 할인액 비율에 따라 cap을 배분
                    $adjustedTotal = 0;
                    for ($i = 0; $i < count($appliedItems); $i++) {
                        if ($i === count($appliedItems) - 1) {
                            // 마지막 아이템: 반올림 오차 보정
                            $appliedItems[$i]['discount_amount'] = $maxDiscountAmount - $adjustedTotal;
                        } else {
                            $appliedItems[$i]['discount_amount'] = (int) floor(
                                $maxDiscountAmount * $appliedItems[$i]['discount_amount'] / $totalDiscount
                            );
                            $adjustedTotal += $appliedItems[$i]['discount_amount'];
                        }
                    }
                    $totalDiscount = $maxDiscountAmount;
                }

                // 3단계: discountedItems 업데이트 (글로벌 cap 반영 후)
                foreach ($appliedItems as $appliedItem) {
                    $optionId = $appliedItem['product_option_id'];
                    $discountedItems[$optionId]['coupon_discount'] = ($discountedItems[$optionId]['coupon_discount'] ?? 0) + $appliedItem['discount_amount'];
                    $originalSubtotal = $discountedItems[$optionId]['subtotal'] ?? 0;
                    $discountedItems[$optionId]['discounted_subtotal'] = $originalSubtotal - ($discountedItems[$optionId]['coupon_discount'] ?? 0);
                }

                // 적용 프로모션 기록 (스냅샷 규칙 우선)
                $defaultCurrency = $this->currencyService->getDefaultCurrency();
                $appliedPromotions->addCoupon(new CouponApplication(
                    couponId: $coupon->id,
                    couponIssueId: $couponIssue->id,
                    name: $coupon->getLocalizedName(),
                    targetType: $snapshot['target_type'] ?? $coupon->target_type->value,
                    targetScope: $snapshot['target_scope'] ?? $coupon->target_scope?->value,
                    discountType: $snapshot['discount_type'] ?? $coupon->discount_type->value,
                    discountValue: (float) ($snapshot['discount_value'] ?? $coupon->discount_value),
                    totalDiscount: $totalDiscount,
                    totalDiscountFormatted: $this->currencyService->formatPrice($totalDiscount, $defaultCurrency),
                    minOrderAmount: (int) $coupon->min_order_amount,
                    maxDiscountAmount: (int) ($coupon->discount_max_amount ?? 0),
                    appliedItems: array_map(fn ($item) => [
                        'product_option_id' => $item['product_option_id'],
                        'discount_amount' => $item['discount_amount'],
                        'discount_amount_formatted' => $this->currencyService->formatPrice($item['discount_amount'], $defaultCurrency),
                    ], $appliedItems),
                ));
            }
        }

        return [$discountedItems, $appliedPromotions, $validationErrors];
    }

    /**
     * 단계 8-a: 옵션별 적립 마일리지를 계산합니다.
     *
     * 적립 마일리지는 주문쿠폰 적용 및 마일리지 사용 후 금액 기준으로 계산됩니다.
     *
     * @param  array  $preparedItems  준비된 아이템 배열
     * @param  array  $discountedItems  할인 후 아이템 배열 (주문쿠폰 적용 후)
     * @param  array  $pointsUsageResult  마일리지 사용 결과 (points_by_option 포함)
     * @return array 옵션별 마일리지 배열 [product_option_id => points]
     */
    protected function calculatePointsEarning(array $preparedItems, array $discountedItems, array $pointsUsageResult = []): array
    {
        $pointsPerItem = [];

        foreach ($preparedItems as $item) {
            $optionId = $item['product_option_id'];
            $option = $item['product_option'];

            $baseAmount = $discountedItems[$optionId]['discounted_subtotal']
                ?? $discountedItems[$optionId]['subtotal']
                ?? 0;

            // 주문쿠폰 안분액 차감
            $orderDiscountShare = $discountedItems[$optionId]['order_discount_share'] ?? 0;
            // 마일리지 사용 안분액 차감
            $pointsUsedShare = $pointsUsageResult['points_by_option'][$optionId] ?? 0;

            // 적립 대상 금액 계산 (상품쿠폰 할인 후 금액 - 주문쿠폰 안분 - 마일리지 안분)
            $earnableAmount = max(0, $baseAmount - $orderDiscountShare - $pointsUsedShare);

            // 옵션별 마일리지 설정이 있는 경우 활용
            if ($option->mileage_value !== null && $option->mileage_type !== null) {
                if ($option->mileage_type === 'fixed') {
                    // 정액 적립: mileage_value를 그대로 사용 (수량 곱)
                    $pointsPerItem[$optionId] = (int) floor($option->mileage_value * $item['quantity']);
                } else {
                    // 정률 적립: earnableAmount 기준으로 계산
                    $pointsPerItem[$optionId] = (int) floor($earnableAmount * $option->mileage_value / 100);
                }
            } else {
                // 기본 마일리지 적립율 1%
                $pointsPerItem[$optionId] = (int) floor($earnableAmount * 0.01);
            }
        }

        return $pointsPerItem;
    }

    /**
     * 단계 2-b: 과세/면세 금액을 분류합니다.
     *
     * @param  array  $preparedItems  준비된 아이템 배열
     * @param  array  $discountedItems  할인 후 아이템 배열
     * @return array 옵션별 과세/면세 분류 [product_option_id => [taxable, tax_free]]
     */
    protected function classifyTaxStatus(array $preparedItems, array $discountedItems): array
    {
        $classification = [];

        foreach ($preparedItems as $item) {
            $optionId = $item['product_option_id'];
            $product = $item['product'];
            $amount = $discountedItems[$optionId]['discounted_subtotal']
                ?? $discountedItems[$optionId]['subtotal']
                ?? 0;

            $isTaxable = $product->tax_status === ProductTaxStatus::TAXABLE;

            $classification[$optionId] = [
                'taxable_amount' => $isTaxable ? $amount : 0,
                'tax_free_amount' => $isTaxable ? 0 : $amount,
            ];
        }

        return $classification;
    }

    /**
     * 단계 3: 배송비를 계산합니다 (정책별 그룹).
     *
     * 스냅샷 모드 시 DB 조회 대신 shippingPolicySnapshots의 정책 데이터를 사용합니다.
     *
     * @param  array  $preparedItems  준비된 아이템 배열
     * @param  array  $discountedItems  할인 후 아이템 배열
     * @param  ShippingAddress|null  $shippingAddress  배송 주소 정보
     * @param  CalculationInput|null  $input  계산 입력 (스냅샷 모드용)
     * @return array 배송비 계산 결과
     */
    protected function calculateShippingFee(array $preparedItems, array $discountedItems, ?ShippingAddress $shippingAddress = null, ?CalculationInput $input = null): array
    {
        // 배송정책별로 그룹화
        $policyGroups = $this->groupByShippingPolicy($preparedItems, $discountedItems, $input);

        $shippingResults = [];
        $baseShippingTotal = 0;
        $extraShippingTotal = 0;
        $zipcode = $shippingAddress?->zipcode;
        $countryCode = $shippingAddress?->countryCode ?? 'KR';

        foreach ($policyGroups as $policyId => $group) {
            $policy = $group['policy'];
            $groupItems = $group['items'];

            // 배송정책이 삭제되었거나 조회 불가한 경우 배송비 0으로 처리
            if (! $policy) {
                foreach ($groupItems as $item) {
                    $shippingResults[$item['product_option_id']] = new AppliedShippingPolicy(
                        policyId: $policyId,
                        policyName: '',
                        countryCode: $countryCode,
                        chargePolicy: '',
                        policySnapshot: ['country_setting' => null],
                    );
                }

                continue;
            }

            // 수신자 국가코드 기준으로 국가별 설정 조회 (스냅샷 모드 시 스냅샷 데이터 사용)
            $countrySetting = $group['_snapshot_country_setting']
                ?? $this->resolveCountrySetting($policy, $countryCode);

            if (! $countrySetting) {
                // 해당 국가 설정이 없으면 배송비 0으로 처리
                foreach ($groupItems as $item) {
                    $shippingResults[$item['product_option_id']] = new AppliedShippingPolicy(
                        policyId: $policyId,
                        policyName: $policy->getLocalizedName(),
                        countryCode: $countryCode,
                        chargePolicy: '',
                        policySnapshot: ['country_setting' => null],
                    );
                }

                continue;
            }

            // 기본 배송비 계산 (국가별 설정 기반)
            $shippingFee = $this->calculateCountryShippingFee($countrySetting, $group);
            $baseShippingTotal += $shippingFee;

            // 추가 배송비(도서산간) 계산 - KR 전용
            $extraShippingFee = $this->calculateExtraShippingFee($countrySetting, $countryCode, $zipcode, $group['total_quantity']);
            $extraShippingTotal += $extraShippingFee;

            // 배송비를 그룹 내 아이템들에게 안분
            $apportionedShipping = $this->apportionShippingFee($groupItems, $shippingFee);
            $apportionedExtraShipping = $this->apportionShippingFee($groupItems, $extraShippingFee);

            foreach ($apportionedShipping as $optionId => $shippingInfo) {
                $baseAmount = $shippingInfo['amount'];
                $extraAmount = $apportionedExtraShipping[$optionId]['amount'] ?? 0;

                // 단독 구매 시 예상 배송비 계산 (해당 아이템만 구매하는 경우)
                $standaloneShippingAmount = $this->calculateStandaloneShippingFee($countrySetting, $countryCode, $groupItems, $optionId, $zipcode);

                $shippingResults[$optionId] = new AppliedShippingPolicy(
                    policyId: $policyId,
                    policyName: $policy->getLocalizedName(),
                    countryCode: $countryCode,
                    chargePolicy: $countrySetting->charge_policy->value,
                    shippingAmount: $baseAmount,
                    extraShippingAmount: $extraAmount,
                    totalShippingAmount: $baseAmount + $extraAmount,
                    policySnapshot: [
                        'country_code' => $countrySetting->country_code,
                        'shipping_method' => $countrySetting->shipping_method,
                        'custom_shipping_name' => $countrySetting->custom_shipping_name,
                        'currency_code' => $countrySetting->currency_code,
                        'charge_policy' => $countrySetting->charge_policy->value,
                        'base_fee' => (int) $countrySetting->base_fee,
                        'free_threshold' => $countrySetting->free_threshold ? (int) $countrySetting->free_threshold : null,
                        'ranges' => $countrySetting->ranges,
                        'extra_fee_enabled' => $countrySetting->extra_fee_enabled,
                        'extra_fee_multiply' => $countrySetting->extra_fee_multiply ?? false,
                        'extra_fee_settings' => $countrySetting->extra_fee_settings ?? [],
                    ],
                    standaloneShippingAmount: $standaloneShippingAmount,
                    hookOverridden: ! empty($group['_hook_overridden']),
                );
            }
        }

        return [
            'shipping_by_option' => $shippingResults,
            'base_shipping_total' => $baseShippingTotal,
            'extra_shipping_total' => $extraShippingTotal,
            'total_shipping' => $baseShippingTotal + $extraShippingTotal,
            'policy_groups' => $policyGroups,
        ];
    }

    /**
     * 추가 배송비(도서산간)를 계산합니다 - KR 전용.
     *
     * @param  ShippingPolicyCountrySetting  $countrySetting  국가별 설정
     * @param  string  $countryCode  수신자 국가코드
     * @param  string|null  $zipcode  우편번호
     * @param  int  $quantity  그룹 합계 수량
     * @return int 추가 배송비
     */
    protected function calculateExtraShippingFee(ShippingPolicyCountrySetting $countrySetting, string $countryCode, ?string $zipcode, int $quantity): int
    {
        // 도서산간 추가배송비는 KR 전용
        if ($countryCode !== 'KR') {
            return 0;
        }

        if (empty($zipcode)) {
            return 0;
        }

        // 국가별 설정에서 우편번호 기반 추가배송비 조회
        $extraFee = $countrySetting->getExtraFeeForZipcode($zipcode);

        if ($extraFee <= 0) {
            return 0;
        }

        // per_* 정책에서 extra_fee_multiply가 true면 건수만큼 중복 부과
        if ($countrySetting->extra_fee_multiply && $this->isPerUnitPolicy($countrySetting)) {
            $unitValue = $countrySetting->ranges['unit_value'] ?? 1;
            if ($unitValue <= 0) {
                $unitValue = 1;
            }
            $units = (int) ceil($quantity / $unitValue);

            return $extraFee * $units;
        }

        // 기본: 1회만 부과
        return $extraFee;
    }

    /**
     * per_* 부과정책인지 확인합니다.
     *
     * @param  ShippingPolicyCountrySetting  $countrySetting  국가별 설정
     * @return bool per_* 정책 여부
     */
    protected function isPerUnitPolicy(ShippingPolicyCountrySetting $countrySetting): bool
    {
        return in_array($countrySetting->charge_policy, [
            ChargePolicyEnum::PER_QUANTITY,
            ChargePolicyEnum::PER_WEIGHT,
            ChargePolicyEnum::PER_VOLUME,
            ChargePolicyEnum::PER_VOLUME_WEIGHT,
            ChargePolicyEnum::PER_AMOUNT,
        ]);
    }

    /**
     * 단독 구매 시 예상 배송비를 계산합니다.
     *
     * 해당 상품옵션만 단독 구매하는 상황을 가정하여 배송비를 계산합니다.
     * UI에서 각 상품별 예상 배송비를 표시하기 위해 사용됩니다.
     *
     * @param  ShippingPolicyCountrySetting  $countrySetting  국가별 설정
     * @param  string  $countryCode  수신자 국가코드
     * @param  array  $groupItems  그룹 내 모든 아이템
     * @param  int  $targetOptionId  대상 상품옵션 ID
     * @param  string|null  $zipcode  우편번호 (추가배송비 계산용)
     * @return int 단독 구매 시 예상 배송비
     */
    protected function calculateStandaloneShippingFee(ShippingPolicyCountrySetting $countrySetting, string $countryCode, array $groupItems, int $targetOptionId, ?string $zipcode): int
    {
        // 대상 아이템 찾기
        $targetItem = null;
        foreach ($groupItems as $item) {
            if ($item['product_option_id'] === $targetOptionId) {
                $targetItem = $item;
                break;
            }
        }

        if (! $targetItem) {
            return 0;
        }

        // 해당 아이템만의 가상 그룹 생성
        $standaloneGroup = [
            'total_amount' => $targetItem['subtotal'],
            'total_quantity' => $targetItem['quantity'],
            'total_weight' => $targetItem['weight'] ?? 0.0,
            'total_volume' => $targetItem['volume'] ?? 0.0,
        ];

        // 기본 배송비 계산 (단독 구매 기준, 국가별 설정 기반)
        $baseShippingFee = $this->calculateCountryShippingFee($countrySetting, $standaloneGroup);

        // 추가 배송비 계산 (도서산간 - KR 전용)
        $extraShippingFee = $this->calculateExtraShippingFee($countrySetting, $countryCode, $zipcode, $targetItem['quantity']);

        return $baseShippingFee + $extraShippingFee;
    }

    /**
     * 단계 4: 배송비 할인쿠폰을 적용합니다.
     *
     * @param  array  $shippingCalculation  배송비 계산 결과
     * @param  array  $coupons  배송비쿠폰 목록
     * @param  CalculationInput|null  $input  계산 입력 (스냅샷 모드 메타데이터 포함)
     * @return array [배송비 할인 후 결과, AppliedPromotions, ValidationError[]]
     */
    protected function applyShippingCoupon(array $shippingCalculation, array $coupons, ?CalculationInput $input = null): array
    {
        $shippingResult = $shippingCalculation;
        $appliedPromotions = new AppliedPromotions;
        $validationErrors = [];

        if (empty($coupons)) {
            return [$shippingResult, $appliedPromotions, $validationErrors];
        }

        $snapshotMode = $input->metadata['snapshot_mode'] ?? false;
        $couponSnapshots = $input->metadata['coupon_snapshots'] ?? [];

        // 할인 대상은 총 배송비 (기본 + 추가)
        $totalShipping = $shippingCalculation['total_shipping'];

        foreach ($coupons as $couponIssue) {
            $coupon = $couponIssue->coupon;
            $snapshot = $couponSnapshots[$couponIssue->id] ?? null;

            // 최소 주문금액 검증은 배송비 쿠폰에서는 배송비 기준으로 체크
            $minAmount = $snapshotMode && $snapshot
                ? (int) ($snapshot['min_order_amount'] ?? 0)
                : (int) $coupon->min_order_amount;

            if ($minAmount > 0 && $totalShipping < $minAmount) {
                $validationErrors[] = ValidationError::minAmountNotMet(
                    $coupon->id,
                    $minAmount,
                    $totalShipping
                );

                continue;
            }

            $discount = $this->calculateCouponDiscount($coupon, $totalShipping, $snapshot);

            $shippingResult['shipping_discount'] = ($shippingResult['shipping_discount'] ?? 0) + $discount;
            $shippingResult['total_shipping_after_discount'] = $totalShipping - $shippingResult['shipping_discount'];

            $defaultCurrency = $this->currencyService->getDefaultCurrency();
            $appliedPromotions->addCoupon(new CouponApplication(
                couponId: $coupon->id,
                couponIssueId: $couponIssue->id,
                name: $coupon->getLocalizedName(),
                targetType: $snapshot['target_type'] ?? $coupon->target_type->value,
                discountType: $snapshot['discount_type'] ?? $coupon->discount_type->value,
                discountValue: (float) ($snapshot['discount_value'] ?? $coupon->discount_value),
                totalDiscount: $discount,
                totalDiscountFormatted: $this->currencyService->formatPrice($discount, $defaultCurrency),
                minOrderAmount: (int) $coupon->min_order_amount,
                maxDiscountAmount: (int) ($coupon->discount_max_amount ?? 0),
            ));

            // 배송비 쿠폰은 하나만 적용
            break;
        }

        return [$shippingResult, $appliedPromotions, $validationErrors];
    }

    /**
     * 단계 5: 주문금액 할인쿠폰을 적용합니다.
     *
     * @param  array  $discountedItems  할인 후 아이템 배열
     * @param  array  $coupons  주문쿠폰 목록
     * @param  array  $preparedItems  준비된 아이템 배열
     * @param  CalculationInput|null  $input  계산 입력 (스냅샷 모드 메타데이터 포함)
     * @return array [아이템별 할인 후 금액, AppliedPromotions, ValidationError[]]
     */
    protected function applyOrderCoupon(array $discountedItems, array $coupons, array $preparedItems, ?CalculationInput $input = null): array
    {
        $appliedPromotions = new AppliedPromotions;
        $validationErrors = [];

        if (empty($coupons)) {
            return [$discountedItems, $appliedPromotions, $validationErrors];
        }

        $snapshotMode = $input->metadata['snapshot_mode'] ?? false;
        $couponSnapshots = $input->metadata['coupon_snapshots'] ?? [];

        // 전체 주문금액 계산
        $totalOrderAmount = 0;
        foreach ($discountedItems as $item) {
            $totalOrderAmount += $item['discounted_subtotal'] ?? $item['subtotal'] ?? 0;
        }

        foreach ($coupons as $couponIssue) {
            $coupon = $couponIssue->coupon;
            $snapshot = $couponSnapshots[$couponIssue->id] ?? null;

            // 최소 주문금액 검증 (스냅샷 모드에서는 스냅샷 규칙 사용)
            $minAmount = $snapshotMode && $snapshot
                ? (int) ($snapshot['min_order_amount'] ?? 0)
                : (int) $coupon->min_order_amount;

            if ($minAmount > 0 && $totalOrderAmount < $minAmount) {
                $validationErrors[] = ValidationError::minAmountNotMet(
                    $coupon->id,
                    $minAmount,
                    $totalOrderAmount
                );

                continue;
            }

            // 주문 할인 계산 (스냅샷 오버라이드)
            $totalDiscount = $this->calculateCouponDiscount($coupon, $totalOrderAmount, $snapshot);

            // 각 옵션별로 안분
            $apportioned = $this->apportionAmount($discountedItems, $totalDiscount);
            $appliedItems = [];

            foreach ($apportioned as $optionId => $share) {
                $discountedItems[$optionId]['order_discount_share'] = ($discountedItems[$optionId]['order_discount_share'] ?? 0) + $share;
                $appliedItems[] = [
                    'product_option_id' => $optionId,
                    'discount_amount' => $share,
                ];
            }

            $defaultCurrency = $this->currencyService->getDefaultCurrency();
            $appliedPromotions->addCoupon(new CouponApplication(
                couponId: $coupon->id,
                couponIssueId: $couponIssue->id,
                name: $coupon->getLocalizedName(),
                targetType: $snapshot['target_type'] ?? $coupon->target_type->value,
                discountType: $snapshot['discount_type'] ?? $coupon->discount_type->value,
                discountValue: (float) ($snapshot['discount_value'] ?? $coupon->discount_value),
                totalDiscount: $totalDiscount,
                totalDiscountFormatted: $this->currencyService->formatPrice($totalDiscount, $defaultCurrency),
                minOrderAmount: (int) $coupon->min_order_amount,
                maxDiscountAmount: (int) ($coupon->discount_max_amount ?? 0),
                appliedItems: array_map(fn ($item) => [
                    'product_option_id' => $item['product_option_id'],
                    'discount_amount' => $item['discount_amount'],
                    'discount_amount_formatted' => $this->currencyService->formatPrice($item['discount_amount'], $defaultCurrency),
                ], $appliedItems),
            ));

            // 주문금액 쿠폰은 하나만 적용
            break;
        }

        return [$discountedItems, $appliedPromotions, $validationErrors];
    }

    /**
     * 단계 6: 적립 마일리지 합계를 계산합니다.
     *
     * @param  array  $pointsPerItem  옵션별 마일리지 배열
     * @return int 마일리지 합계
     */
    protected function sumPointsEarning(array $pointsPerItem): int
    {
        return array_sum($pointsPerItem);
    }

    /**
     * 단계 7: 결제금액을 계산합니다.
     *
     * @param  array  $discountedItems  할인 후 아이템 배열
     * @param  array  $shippingResult  배송비 결과
     * @return array 결제금액 계산 결과
     */
    protected function calculatePaymentAmount(array $discountedItems, array $shippingResult): array
    {
        $subtotal = 0;
        $totalCouponDiscount = 0;
        $totalOrderDiscount = 0;

        foreach ($discountedItems as $item) {
            $subtotal += $item['subtotal'] ?? 0;
            $totalCouponDiscount += $item['coupon_discount'] ?? 0;
            $totalOrderDiscount += $item['order_discount_share'] ?? 0;
        }

        $baseShippingTotal = $shippingResult['base_shipping_total'] ?? 0;
        $extraShippingTotal = $shippingResult['extra_shipping_total'] ?? 0;
        $totalShipping = $shippingResult['total_shipping'] ?? ($baseShippingTotal + $extraShippingTotal);
        $shippingDiscount = $shippingResult['shipping_discount'] ?? 0;

        $paymentAmount = $subtotal - $totalCouponDiscount - $totalOrderDiscount + $totalShipping - $shippingDiscount;

        return [
            'subtotal' => $subtotal,
            'coupon_discount' => $totalCouponDiscount,
            'order_discount' => $totalOrderDiscount,
            'base_shipping_total' => $baseShippingTotal,
            'extra_shipping_total' => $extraShippingTotal,
            'total_shipping' => $totalShipping,
            'shipping_discount' => $shippingDiscount,
            'payment_amount' => max(0, $paymentAmount),
        ];
    }

    /**
     * 단계 8: 마일리지 사용을 적용합니다.
     *
     * @param  int  $paymentAmount  결제금액
     * @param  int  $usePoints  사용할 마일리지
     * @param  array  $discountedItems  할인 후 아이템 배열
     * @return array 마일리지 사용 결과
     */
    protected function applyPointsUsage(int $paymentAmount, int $usePoints, array $discountedItems): array
    {
        // 사용 가능한 마일리지는 결제금액을 초과할 수 없음
        $actualPoints = min($usePoints, $paymentAmount);

        // 마일리지를 각 옵션별로 안분
        $apportioned = $this->apportionAmount($discountedItems, $actualPoints);

        return [
            'requested_points' => $usePoints,
            'actual_points' => $actualPoints,
            'points_by_option' => $apportioned,
            'final_amount' => max(0, $paymentAmount - $actualPoints),
        ];
    }

    /**
     * 최종 결과를 빌드합니다.
     *
     * @param  CalculationInput  $input  계산 입력 데이터 (paymentCurrency 접근용)
     */
    protected function buildFinalResult(
        array $preparedItems,
        array $itemSubtotals,
        array $itemsAfterProductDiscount,
        array $itemsAfterOrderDiscount,
        array $pointsPerItem,
        array $taxClassification,
        array $shippingResult,
        array $pointsUsageResult,
        array $paymentCalculation,
        AppliedPromotions $productPromotions,
        AppliedPromotions $orderPromotions,
        AppliedPromotions $shippingPromotions,
        int $totalPointsEarning,
        array $validationErrors,
        CalculationInput $input
    ): OrderCalculationResult {
        $items = [];

        // 각 옵션별 적용된 쿠폰 매핑 생성
        $promotionsByOption = $this->buildPromotionsByOption($productPromotions);

        foreach ($preparedItems as $item) {
            $optionId = $item['product_option_id'];

            $itemCalc = new ItemCalculation(
                productId: $item['product_id'],
                productOptionId: $optionId,
                quantity: $item['quantity'],
                unitPrice: $item['unit_price'],
                subtotal: $itemSubtotals[$optionId]['subtotal'] ?? 0,
                productCouponDiscountAmount: $itemsAfterProductDiscount[$optionId]['coupon_discount'] ?? 0,
                codeDiscountAmount: 0,
                orderCouponDiscountShare: $itemsAfterOrderDiscount[$optionId]['order_discount_share'] ?? 0,
                pointsUsedShare: $pointsUsageResult['points_by_option'][$optionId] ?? 0,
                pointsEarning: $pointsPerItem[$optionId] ?? 0,
                taxableAmount: $taxClassification[$optionId]['taxable_amount'] ?? 0,
                taxFreeAmount: $taxClassification[$optionId]['tax_free_amount'] ?? 0,
                finalAmount: $this->calculateItemFinalAmount($optionId, $itemsAfterOrderDiscount, $pointsUsageResult),
                appliedShippingPolicy: $shippingResult['shipping_by_option'][$optionId] ?? null,
                appliedPromotions: $promotionsByOption[$optionId] ?? null,
                productName: $item['product']->getLocalizedName(),
                optionName: $item['product_option']->getLocalizedOptionName(),
            );

            $items[] = $itemCalc;
        }

        // Summary 계산
        $totalTaxable = 0;
        $totalTaxFree = 0;
        foreach ($taxClassification as $tax) {
            $totalTaxable += $tax['taxable_amount'];
            $totalTaxFree += $tax['tax_free_amount'];
        }

        $summary = new Summary(
            subtotal: $paymentCalculation['subtotal'],
            productCouponDiscount: $paymentCalculation['coupon_discount'],
            codeDiscount: 0,
            orderCouponDiscount: $paymentCalculation['order_discount'],
            totalDiscount: $paymentCalculation['coupon_discount'] + $paymentCalculation['order_discount'],
            baseShippingTotal: $paymentCalculation['base_shipping_total'],
            extraShippingTotal: $paymentCalculation['extra_shipping_total'],
            totalShipping: $paymentCalculation['total_shipping'],
            shippingDiscount: $paymentCalculation['shipping_discount'],
            taxableAmount: $totalTaxable,
            taxFreeAmount: $totalTaxFree,
            pointsEarning: $totalPointsEarning,
            pointsUsed: $pointsUsageResult['actual_points'],
            paymentAmount: $paymentCalculation['payment_amount'],
            finalAmount: $pointsUsageResult['final_amount'],
        );

        // 다통화 변환 적용
        foreach ($items as $itemCalc) {
            $itemCalc->multiCurrency = $this->buildItemMultiCurrency($itemCalc);
        }
        $summary->multiCurrency = $this->buildSummaryMultiCurrency($summary);

        // 결제 통화 선택
        if ($input->paymentCurrency !== null) {
            $summary->selectedPaymentCurrency = $input->paymentCurrency;
        }

        // 프로모션 요약
        $promotionsSummary = new PromotionsSummary(
            productPromotions: $productPromotions,
            orderPromotions: new AppliedPromotions(
                coupons: array_merge($orderPromotions->coupons, $shippingPromotions->coupons),
            ),
        );

        return new OrderCalculationResult(
            items: $items,
            summary: $summary,
            promotions: $promotionsSummary,
            validationErrors: $validationErrors,
        );
    }

    /**
     * 아이템의 최종 금액을 계산합니다.
     */
    protected function calculateItemFinalAmount(int $optionId, array $discountedItems, array $pointsUsageResult): int
    {
        $item = $discountedItems[$optionId] ?? [];
        $subtotal = $item['subtotal'] ?? 0;
        $couponDiscount = $item['coupon_discount'] ?? 0;
        $orderDiscount = $item['order_discount_share'] ?? 0;
        $pointsUsed = $pointsUsageResult['points_by_option'][$optionId] ?? 0;

        return max(0, $subtotal - $couponDiscount - $orderDiscount - $pointsUsed);
    }

    /**
     * 아이템의 다통화 변환 금액을 생성합니다.
     *
     * @param  ItemCalculation  $item  아이템 계산 결과
     * @return MultiCurrencyPrices 다통화 변환 금액
     */
    protected function buildItemMultiCurrency(ItemCalculation $item): MultiCurrencyPrices
    {
        $amounts = [
            'unit_price' => $item->unitPrice,
            'subtotal' => $item->subtotal,
            'product_coupon_discount_amount' => $item->productCouponDiscountAmount,
            'code_discount_amount' => $item->codeDiscountAmount,
            'order_coupon_discount_share' => $item->orderCouponDiscountShare,
            'points_used_share' => $item->pointsUsedShare,
            'taxable_amount' => $item->taxableAmount,
            'tax_free_amount' => $item->taxFreeAmount,
            'final_amount' => $item->finalAmount,
        ];

        return new MultiCurrencyPrices(
            currencies: $this->currencyService->convertMultipleAmounts($amounts)
        );
    }

    /**
     * Summary의 다통화 변환 금액을 생성합니다.
     *
     * @param  Summary  $summary  합계 정보
     * @return MultiCurrencyPrices 다통화 변환 금액
     */
    protected function buildSummaryMultiCurrency(Summary $summary): MultiCurrencyPrices
    {
        $amounts = [
            'subtotal' => $summary->subtotal,
            'product_coupon_discount' => $summary->productCouponDiscount,
            'code_discount' => $summary->codeDiscount,
            'order_coupon_discount' => $summary->orderCouponDiscount,
            'total_discount' => $summary->totalDiscount,
            'base_shipping_total' => $summary->baseShippingTotal,
            'extra_shipping_total' => $summary->extraShippingTotal,
            'total_shipping' => $summary->totalShipping,
            'shipping_discount' => $summary->shippingDiscount,
            'taxable_amount' => $summary->taxableAmount,
            'tax_free_amount' => $summary->taxFreeAmount,
            'payment_amount' => $summary->paymentAmount,
            'final_amount' => $summary->finalAmount,
        ];

        return new MultiCurrencyPrices(
            currencies: $this->currencyService->convertMultipleAmounts($amounts)
        );
    }

    /**
     * 쿠폰을 검증합니다.
     *
     * 스냅샷 모드에서는 만료/유효기간 검증을 스킵하고, 스냅샷의 min_order_amount를 사용합니다.
     *
     * @param  CouponIssue  $couponIssue  쿠폰 발급 정보
     * @param  array  $itemSubtotals  아이템 소계 배열
     * @param  array  $preparedItems  준비된 아이템 배열
     * @param  bool  $snapshotMode  스냅샷 모드 여부
     * @param  int  $snapshotMinOrderAmount  스냅샷의 최소 주문금액
     */
    protected function validateCoupon(
        CouponIssue $couponIssue,
        array $itemSubtotals,
        array $preparedItems,
        bool $snapshotMode = false,
        int $snapshotMinOrderAmount = 0
    ): ?ValidationError {
        $coupon = $couponIssue->coupon;

        // 스냅샷 모드: 만료/유효기간 검증 스킵 (이미 적용 완료된 쿠폰)
        if (! $snapshotMode) {
            // 만료 여부 검증 (CouponIssue의 expired_at 포함)
            if ($couponIssue->isExpired()) {
                return ValidationError::couponExpired($coupon->id);
            }

            // 유효기간 검증 (valid_from, valid_to)
            $now = now();
            if ($couponIssue->valid_from && $now->lt($couponIssue->valid_from)) {
                return ValidationError::couponExpired($coupon->id);
            }
            if ($couponIssue->valid_to && $now->gt($couponIssue->valid_to)) {
                return ValidationError::couponExpired($coupon->id);
            }

            // 이미 사용된 쿠폰 검증
            if ($couponIssue->used_at !== null) {
                return ValidationError::alreadyUsed($coupon->id);
            }
        }

        // 최소 주문금액 검증 (스냅샷 모드: 스냅샷 값 사용)
        $minAmount = $snapshotMode ? $snapshotMinOrderAmount : (int) $coupon->min_order_amount;
        if ($minAmount > 0) {
            $totalAmount = array_sum(array_column($itemSubtotals, 'subtotal'));
            if ($totalAmount < $minAmount) {
                return ValidationError::minAmountNotMet(
                    $coupon->id,
                    $minAmount,
                    $totalAmount
                );
            }
        }

        return null;
    }

    /**
     * 쿠폰 조합 가능 여부를 검증합니다. (is_combinable)
     *
     * @param  CouponIssue[]  $coupons  쿠폰 발급 목록
     * @return ValidationError[] 검증 오류 목록
     */
    protected function validateCouponCombination(array $coupons): array
    {
        if (count($coupons) <= 1) {
            return [];
        }

        $errors = [];
        foreach ($coupons as $couponIssue) {
            $coupon = $couponIssue->coupon;
            if (! $coupon->is_combinable) {
                $errors[] = ValidationError::notCombinable($coupon->id);
            }
        }

        return $errors;
    }

    /**
     * 쿠폰 할인액을 계산합니다.
     *
     * 스냅샷 모드에서는 쿠폰 규칙(할인유형/할인값)을 스냅샷 값으로 오버라이드합니다.
     *
     * @param  Coupon  $coupon  쿠폰
     * @param  int  $amount  적용 대상 금액
     * @param  array|null  $snapshotOverride  스냅샷 오버라이드 [discount_type, discount_value, max_discount_amount]
     * @return int 할인액
     */
    protected function calculateCouponDiscount(Coupon $coupon, int $amount, ?array $snapshotOverride = null, int $quantity = 1): int
    {
        $discountType = $snapshotOverride['discount_type'] ?? $coupon->discount_type->value;
        $discountValue = (float) ($snapshotOverride['discount_value'] ?? $coupon->discount_value);

        if ($discountType === CouponDiscountType::FIXED->value || $discountType === 'fixed') {
            // 정액 할인: 수량만큼 할인 (상품금액 쿠폰에서 수량 전달)
            return min((int) $discountValue * $quantity, $amount);
        }

        // 정률 할인
        $discount = (int) floor($amount * $discountValue / 100);

        // 최대 할인금액 제한 (스냅샷 우선, 없으면 라이브 쿠폰 값)
        $maxDiscountAmount = (int) ($snapshotOverride['max_discount_amount'] ?? $coupon->discount_max_amount ?? 0);
        if ($maxDiscountAmount > 0) {
            $discount = min($discount, $maxDiscountAmount);
        }

        return min($discount, $amount);
    }

    /**
     * 아이템을 쿠폰 적용 범위로 필터링합니다.
     *
     * @param  array  $preparedItems  준비된 아이템 배열
     * @param  Coupon  $coupon  쿠폰
     * @return array 필터링된 아이템 배열
     */
    protected function filterItemsByScope(array $preparedItems, Coupon $coupon): array
    {
        if ($coupon->target_scope === CouponTargetScope::ALL) {
            return $preparedItems;
        }

        $includedProductIds = $coupon->includedProducts->pluck('id')->toArray();
        $excludedProductIds = $coupon->excludedProducts->pluck('id')->toArray();
        $includedCategoryIds = $coupon->includedCategories->pluck('id')->toArray();
        $excludedCategoryIds = $coupon->excludedCategories->pluck('id')->toArray();

        return array_filter($preparedItems, function ($item) use (
            $coupon,
            $includedProductIds,
            $excludedProductIds,
            $includedCategoryIds,
            $excludedCategoryIds
        ) {
            $productId = $item['product_id'];
            $product = $item['product'];
            $categoryIds = $product->categories->pluck('id')->toArray();

            // 제외 상품/카테고리 체크
            if (in_array($productId, $excludedProductIds)) {
                return false;
            }
            if (! empty(array_intersect($categoryIds, $excludedCategoryIds))) {
                return false;
            }

            // 포함 조건 체크
            if ($coupon->target_scope === CouponTargetScope::PRODUCTS) {
                return in_array($productId, $includedProductIds);
            }

            if ($coupon->target_scope === CouponTargetScope::CATEGORIES) {
                return ! empty(array_intersect($categoryIds, $includedCategoryIds));
            }

            return true;
        });
    }

    /**
     * 아이템을 배송정책별로 그룹화합니다.
     *
     * 스냅샷 모드 시 shippingPolicySnapshots에서 정책 데이터를 가져옵니다.
     *
     * @param  array  $preparedItems  준비된 아이템 배열
     * @param  array  $discountedItems  할인 후 아이템 배열
     * @param  CalculationInput|null  $input  계산 입력 (스냅샷 모드용)
     * @return array 배송정책별 그룹
     */
    protected function groupByShippingPolicy(array $preparedItems, array $discountedItems, ?CalculationInput $input = null): array
    {
        $groups = [];
        $shippingPolicySnapshots = $input?->shippingPolicySnapshots ?? [];

        foreach ($preparedItems as $item) {
            $product = $item['product'];
            $policyId = $product->shipping_policy_id;

            if (! $policyId) {
                continue;
            }

            $optionId = $item['product_option_id'];
            $isSnapshotMode = ! empty($item['_snapshot_mode']);

            if (! isset($groups[$policyId])) {
                $policy = null;
                $snapshotCountrySetting = null;

                if ($isSnapshotMode && isset($shippingPolicySnapshots[$optionId])) {
                    // 스냅샷 모드: DB 조회 없이 스냅샷의 정책 데이터 사용
                    $policySnapshotData = $shippingPolicySnapshots[$optionId];
                    $policy = $this->buildSnapshotShippingPolicy($policyId, $policySnapshotData);
                    $snapshotCountrySetting = $this->buildSnapshotCountrySetting($policySnapshotData);
                } else {
                    // 통상 모드: DB에서 배송정책 로드
                    $policy = $product->shippingPolicy;
                    if ($policy && ! $policy->relationLoaded('countrySettings')) {
                        $policy->load('countrySettings');
                    }
                    $policy = $policy ?? $this->shippingPolicyRepository->find($policyId);
                }

                $groups[$policyId] = [
                    'policy' => $policy,
                    'items' => [],
                    'total_amount' => 0,
                    'total_quantity' => 0,
                    'total_weight' => 0.0,
                    'total_volume' => 0.0,
                    '_snapshot_country_setting' => $snapshotCountrySetting,
                ];
            } elseif ($isSnapshotMode && $groups[$policyId]['_snapshot_country_setting'] === null && isset($shippingPolicySnapshots[$optionId])) {
                // 같은 정책 그룹에 이미 들어갔지만 스냅샷 국가설정이 아직 없는 경우
                $groups[$policyId]['_snapshot_country_setting'] = $this->buildSnapshotCountrySetting($shippingPolicySnapshots[$optionId]);
            }

            $amount = $discountedItems[$optionId]['discounted_subtotal']
                ?? $discountedItems[$optionId]['subtotal']
                ?? 0;

            $productOption = $item['product_option'];
            $itemWeight = (float) ($productOption->weight ?? 0) * $item['quantity'];
            $itemVolume = (float) ($productOption->volume ?? 0) * $item['quantity'];

            $groups[$policyId]['items'][] = [
                'product_option_id' => $optionId,
                'subtotal' => $amount,
                'quantity' => $item['quantity'],
                'weight' => $itemWeight,
                'volume' => $itemVolume,
            ];
            $groups[$policyId]['total_amount'] += $amount;
            $groups[$policyId]['total_quantity'] += $item['quantity'];
            $groups[$policyId]['total_weight'] += $itemWeight;
            $groups[$policyId]['total_volume'] += $itemVolume;

            // 스냅샷 모드 + 훅 오버라이드 배송비: 원 주문 시 훅이 계산한 배송비를 그룹에 누적
            // → 훅 비활성 시에도 스냅샷 기반 환불 정확성 보장 (내장 정책은 재계산으로 처리)
            if ($isSnapshotMode
                && ! empty($shippingPolicySnapshots[$optionId]['hook_overridden'])
                && isset($shippingPolicySnapshots[$optionId]['shipping_amount'])
            ) {
                $groups[$policyId]['_snapshot_shipping_fee'] = ($groups[$policyId]['_snapshot_shipping_fee'] ?? 0)
                    + (int) $shippingPolicySnapshots[$optionId]['shipping_amount'];
            }
        }

        return $groups;
    }

    /**
     * 배송정책의 국가별 설정을 조회합니다.
     *
     * @param  ShippingPolicy  $policy  배송정책
     * @param  string  $countryCode  국가코드
     * @return ShippingPolicyCountrySetting|null 국가별 설정
     */
    protected function resolveCountrySetting(ShippingPolicy $policy, string $countryCode): ?ShippingPolicyCountrySetting
    {
        $settings = $policy->relationLoaded('countrySettings')
            ? $policy->countrySettings
            : $policy->countrySettings()->get();

        return $settings
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();
    }

    /**
     * 국가별 설정에 따라 배송비를 계산합니다.
     *
     * @param  ShippingPolicyCountrySetting  $countrySetting  국가별 설정
     * @param  array  $group  그룹 정보 (total_amount, total_quantity, total_weight, total_volume)
     * @return int 배송비
     */
    protected function calculateCountryShippingFee(ShippingPolicyCountrySetting $countrySetting, array &$group): int
    {
        // 스냅샷 모드: 원 주문 시 계산된 배송비를 직접 사용 (플러그인 훅 미호출)
        // → 플러그인 ON/OFF 무관하게 환불 정확성 보장
        if (isset($group['_snapshot_shipping_fee'])) {
            return (int) $group['_snapshot_shipping_fee'];
        }

        // 플러그인 우선 계산 (비스냅샷 모드: 주문 생성, 가격 미리보기 등)
        // 플러그인이 non-null을 반환하면 내장 match 로직을 건너뜀
        $pluginFee = HookManager::applyFilters(
            'sirsoft-ecommerce.shipping.calculate_fee',
            null,
            $countrySetting,
            $group
        );
        if ($pluginFee !== null) {
            $group['_hook_overridden'] = true;

            return (int) $pluginFee;
        }

        // 내장 정책 계산 (플러그인 미개입 시)
        $groupTotal = $group['total_amount'];
        $groupQuantity = $group['total_quantity'];
        $groupWeight = $group['total_weight'] ?? 0.0;
        $groupVolume = $group['total_volume'] ?? 0.0;

        // 부피무게 계산 (부피 / 부피무게 계수, 기본값 6000)
        $volumeWeightDivisor = $countrySetting->ranges['volume_weight_divisor'] ?? 6000;
        $volumeWeight = $volumeWeightDivisor > 0 ? $groupVolume / $volumeWeightDivisor : 0.0;
        $chargeableWeight = max($groupWeight, $volumeWeight);

        return match ($countrySetting->charge_policy) {
            ChargePolicyEnum::FREE => 0,
            ChargePolicyEnum::FIXED => (int) $countrySetting->base_fee,
            ChargePolicyEnum::CONDITIONAL_FREE => $groupTotal >= $countrySetting->free_threshold ? 0 : (int) $countrySetting->base_fee,
            ChargePolicyEnum::RANGE_AMOUNT => $this->calculateRangeFee($countrySetting->ranges, $groupTotal),
            ChargePolicyEnum::RANGE_QUANTITY => $this->calculateRangeFee($countrySetting->ranges, $groupQuantity),
            ChargePolicyEnum::RANGE_WEIGHT => $this->calculateRangeFee($countrySetting->ranges, (int) ($groupWeight * 1000)), // kg → g 변환
            ChargePolicyEnum::RANGE_VOLUME => $this->calculateRangeFee($countrySetting->ranges, (int) $groupVolume),
            ChargePolicyEnum::RANGE_VOLUME_WEIGHT => $this->calculateRangeFee($countrySetting->ranges, (int) ($chargeableWeight * 1000)),
            ChargePolicyEnum::PER_QUANTITY => $this->calculatePerUnitFee($countrySetting->base_fee, $groupQuantity, $countrySetting->ranges['unit_value'] ?? 1),
            ChargePolicyEnum::PER_WEIGHT => $this->calculatePerUnitFee($countrySetting->base_fee, $groupWeight, $countrySetting->ranges['unit_value'] ?? 0.5),
            ChargePolicyEnum::PER_VOLUME => $this->calculatePerUnitFee($countrySetting->base_fee, $groupVolume, $countrySetting->ranges['unit_value'] ?? 1000),
            ChargePolicyEnum::PER_VOLUME_WEIGHT => $this->calculatePerUnitFee($countrySetting->base_fee, $chargeableWeight, $countrySetting->ranges['unit_value'] ?? 0.5),
            ChargePolicyEnum::PER_AMOUNT => $this->calculatePerUnitFee($countrySetting->base_fee, $groupTotal, $countrySetting->ranges['unit_value'] ?? 10000),
            ChargePolicyEnum::API => $this->calculateApiShippingFee($countrySetting, $group),
            default => (int) $countrySetting->base_fee,
        };
    }

    /**
     * 구간별 배송비를 계산합니다.
     *
     * @param  array|null  $ranges  구간 설정
     * @param  int  $value  비교 값 (금액 또는 수량)
     * @return int 배송비
     */
    protected function calculateRangeFee(?array $ranges, int $value): int
    {
        if (empty($ranges) || empty($ranges['tiers'])) {
            return 0;
        }

        foreach ($ranges['tiers'] as $tier) {
            $min = $tier['min'] ?? 0;
            $max = $tier['max'] ?? PHP_INT_MAX;

            if ($value >= $min && ($max === null || $value < $max)) {
                return (int) ($tier['fee'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * 단위당 배송비를 계산합니다.
     *
     * @param  int|float  $baseFee  기본 배송비
     * @param  int|float  $value  계산 기준 값 (수량, 무게, 부피, 금액 등)
     * @param  int|float  $unitValue  단위 값
     * @return int 배송비
     */
    protected function calculatePerUnitFee(int|float $baseFee, int|float $value, int|float $unitValue): int
    {
        if ($unitValue <= 0) {
            $unitValue = 1;
        }

        return (int) ceil($value / $unitValue) * (int) $baseFee;
    }

    /**
     * API를 통해 배송비를 계산합니다.
     *
     * @param  ShippingPolicyCountrySetting  $countrySetting  국가별 설정
     * @param  array  $group  그룹 정보
     * @return int 배송비
     */
    protected function calculateApiShippingFee(ShippingPolicyCountrySetting $countrySetting, array $group): int
    {
        // API 엔드포인트가 설정되어 있지 않으면 기본 배송비 반환
        $apiEndpoint = $countrySetting->api_endpoint;
        if (empty($apiEndpoint)) {
            return (int) $countrySetting->base_fee;
        }

        try {
            // 그룹 정보를 API에 전달할 데이터로 변환
            $requestData = [
                'policy_id' => $countrySetting->shipping_policy_id,
                'country_code' => $countrySetting->country_code,
                'items' => array_map(fn ($item) => [
                    'product_option_id' => $item['product_option_id'],
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['subtotal'],
                    'weight' => $item['weight'] ?? 0,
                    'volume' => $item['volume'] ?? 0,
                ], $group['items'] ?? []),
                'group_total' => $group['total_amount'] ?? 0,
                'total_quantity' => $group['total_quantity'] ?? 0,
            ];

            // API 전송필드가 지정된 경우 해당 필드만 포함
            if (! empty($countrySetting->api_request_fields)) {
                $filteredData = [];
                foreach ($countrySetting->api_request_fields as $field) {
                    if (isset($requestData[$field])) {
                        $filteredData[$field] = $requestData[$field];
                    }
                }
                $requestData = $filteredData;
            }

            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->post($apiEndpoint, $requestData);

            if ($response->successful()) {
                $data = $response->json();
                $feeField = $countrySetting->api_response_fee_field ?? 'shipping_fee';

                return (int) ($data[$feeField] ?? $countrySetting->base_fee);
            }

            // API 호출 실패 시 기본 배송비 반환
            return (int) $countrySetting->base_fee;
        } catch (\Exception $e) {
            // 예외 발생 시 기본 배송비 반환
            \Illuminate\Support\Facades\Log::warning('API 배송비 계산 실패', [
                'policy_id' => $countrySetting->shipping_policy_id,
                'country_code' => $countrySetting->country_code,
                'error' => $e->getMessage(),
            ]);

            return (int) $countrySetting->base_fee;
        }
    }

    /**
     * 배송비를 그룹 내 아이템들에게 안분합니다.
     *
     * @param  array  $items  그룹 내 아이템 배열
     * @param  int  $totalFee  총 배송비
     * @return array 아이템별 배송비 안분 결과
     */
    protected function apportionShippingFee(array $items, int $totalFee): array
    {
        if (empty($items) || $totalFee <= 0) {
            $result = [];
            foreach ($items as $item) {
                $result[$item['product_option_id']] = ['amount' => 0];
            }

            return $result;
        }

        $groupTotal = array_sum(array_column($items, 'subtotal'));
        if ($groupTotal <= 0) {
            // 균등 분배
            $share = (int) floor($totalFee / count($items));
            $result = [];
            $allocated = 0;

            foreach ($items as $index => $item) {
                $isLast = ($index === count($items) - 1);
                $result[$item['product_option_id']] = [
                    'amount' => $isLast ? ($totalFee - $allocated) : $share,
                ];
                $allocated += $share;
            }

            return $result;
        }

        $result = [];
        $allocated = 0;
        $itemCount = count($items);

        foreach ($items as $index => $item) {
            $isLast = ($index === $itemCount - 1);

            if ($isLast) {
                $share = $totalFee - $allocated;
            } else {
                $ratio = $item['subtotal'] / $groupTotal;
                $share = (int) round($totalFee * $ratio);
                $allocated += $share;
            }

            $result[$item['product_option_id']] = ['amount' => $share];
        }

        return $result;
    }

    /**
     * 상품 프로모션에서 옵션별 적용 쿠폰을 추출합니다.
     *
     * @param  AppliedPromotions  $productPromotions  상품 프로모션 정보
     * @return array<int, AppliedPromotions> 옵션별 적용 프로모션 [product_option_id => AppliedPromotions]
     */
    protected function buildPromotionsByOption(AppliedPromotions $productPromotions): array
    {
        $promotionsByOption = [];

        foreach ($productPromotions->coupons as $couponApplication) {
            if (empty($couponApplication->appliedItems)) {
                continue;
            }

            foreach ($couponApplication->appliedItems as $appliedItem) {
                $optionId = $appliedItem['product_option_id'] ?? null;
                if ($optionId === null) {
                    continue;
                }

                // 해당 옵션에 대한 AppliedPromotions가 없으면 생성
                if (! isset($promotionsByOption[$optionId])) {
                    $promotionsByOption[$optionId] = new AppliedPromotions;
                }

                // 해당 옵션에 대한 CouponApplication 생성 (해당 옵션의 할인액만 포함)
                $optionCouponApplication = new CouponApplication(
                    couponId: $couponApplication->couponId,
                    couponIssueId: $couponApplication->couponIssueId,
                    name: $couponApplication->name,
                    targetType: $couponApplication->targetType,
                    targetScope: $couponApplication->targetScope,
                    discountType: $couponApplication->discountType,
                    discountValue: $couponApplication->discountValue,
                    totalDiscount: $appliedItem['discount_amount'] ?? 0,
                    totalDiscountFormatted: $appliedItem['discount_amount_formatted'] ?? '',
                    appliedItems: [$appliedItem],
                    isExclusive: $couponApplication->isExclusive,
                    minOrderAmount: $couponApplication->minOrderAmount,
                    maxDiscountAmount: $couponApplication->maxDiscountAmount,
                );

                $promotionsByOption[$optionId]->addCoupon($optionCouponApplication);
            }
        }

        return $promotionsByOption;
    }

    /**
     * 금액을 아이템별로 안분합니다 (플러그인용 공개 메서드).
     *
     * 플러그인에서 예치금, 상품권 등 추가 결제 수단의 안분 계산에 사용합니다.
     *
     * @param  array  $items  아이템 배열 [product_option_id => ['subtotal' => int, ...], ...]
     * @param  int  $totalAmount  안분할 총 금액
     * @return array 아이템별 안분 금액 [product_option_id => amount]
     *
     * @example
     * // 예치금 플러그인에서 사용
     * $depositShares = $calculationService->apportionAmountByItems(
     *     $discountedItems,  // 할인 후 아이템 배열
     *     $useDeposit        // 사용할 예치금
     * );
     * // 결과: [option_1 => 3000, option_2 => 2000]
     */
    public function apportionAmountByItems(array $items, int $totalAmount): array
    {
        return $this->apportionAmount($items, $totalAmount);
    }

    /**
     * 금액을 아이템별로 안분합니다.
     *
     * @param  array  $items  아이템 배열
     * @param  int  $totalAmount  안분할 총 금액
     * @return array 아이템별 안분 금액 [product_option_id => amount]
     */
    protected function apportionAmount(array $items, int $totalAmount): array
    {
        if (empty($items) || $totalAmount <= 0) {
            $result = [];
            foreach ($items as $optionId => $item) {
                $result[$optionId] = 0;
            }

            return $result;
        }

        $totalSubtotal = 0;
        foreach ($items as $item) {
            $totalSubtotal += $item['subtotal'] ?? 0;
        }

        if ($totalSubtotal <= 0) {
            // 균등 분배
            $count = count($items);
            $share = (int) floor($totalAmount / $count);
            $result = [];
            $allocated = 0;
            $index = 0;

            foreach ($items as $optionId => $item) {
                $isLast = ($index === $count - 1);
                $result[$optionId] = $isLast ? ($totalAmount - $allocated) : $share;
                $allocated += $share;
                $index++;
            }

            return $result;
        }

        $result = [];
        $allocated = 0;
        $itemCount = count($items);
        $index = 0;

        foreach ($items as $optionId => $item) {
            $isLast = ($index === $itemCount - 1);

            if ($isLast) {
                $share = $totalAmount - $allocated;
            } else {
                $subtotal = $item['subtotal'] ?? 0;
                $ratio = $subtotal / $totalSubtotal;
                $share = (int) round($totalAmount * $ratio);
                $allocated += $share;
            }

            $result[$optionId] = $share;
            $index++;
        }

        return $result;
    }

    /**
     * 스냅샷 데이터에서 가상 ShippingPolicy 모델을 생성합니다.
     *
     * @param  int  $policyId  배송정책 ID
     * @param  array  $snapshotData  AppliedShippingPolicy 직렬화 데이터
     * @return ShippingPolicy 스냅샷 기반 가상 배송정책
     */
    protected function buildSnapshotShippingPolicy(int $policyId, array $snapshotData): ShippingPolicy
    {
        $policyName = $snapshotData['policy_name'] ?? '';

        $policy = new ShippingPolicy;
        $policy->id = $policyId;
        $policy->name = ['ko' => $policyName, 'en' => $policyName];
        $policy->is_active = true;

        return $policy;
    }

    /**
     * 스냅샷의 policy_snapshot 데이터에서 ShippingPolicyCountrySetting 모델을 생성합니다.
     *
     * @param  array  $snapshotData  AppliedShippingPolicy 직렬화 데이터
     * @return ShippingPolicyCountrySetting|null 스냅샷 기반 국가별 설정
     */
    protected function buildSnapshotCountrySetting(array $snapshotData): ?ShippingPolicyCountrySetting
    {
        $policySnapshot = $snapshotData['policy_snapshot'] ?? null;

        if (! $policySnapshot || ($policySnapshot['country_setting'] ?? null) === null && empty($policySnapshot['charge_policy'])) {
            return null;
        }

        return new ShippingPolicyCountrySetting([
            'country_code' => $policySnapshot['country_code'] ?? 'KR',
            'shipping_method' => $policySnapshot['shipping_method'] ?? null,
            'currency_code' => $policySnapshot['currency_code'] ?? 'KRW',
            'charge_policy' => $policySnapshot['charge_policy'] ?? 'fixed',
            'base_fee' => $policySnapshot['base_fee'] ?? 0,
            'free_threshold' => $policySnapshot['free_threshold'] ?? null,
            'ranges' => $policySnapshot['ranges'] ?? [],
            'extra_fee_enabled' => $policySnapshot['extra_fee_enabled'] ?? false,
            'extra_fee_multiply' => $policySnapshot['extra_fee_multiply'] ?? false,
            'extra_fee_settings' => $policySnapshot['extra_fee_settings'] ?? [],
            'is_active' => true,
        ]);
    }
}
