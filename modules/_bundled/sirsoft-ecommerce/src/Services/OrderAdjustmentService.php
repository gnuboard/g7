<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\OrderAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;

/**
 * 주문 변경 금액 계산 서비스
 *
 * 취소/반품/교환 시 환불금액을 계산합니다.
 * OrderAdjustment 다형성으로 시나리오별 로직을 분기합니다.
 *
 * 핵심 공식: 원 결제금액 - 재계산 결제금액 - 추가비용 = 환불금액
 */
class OrderAdjustmentService
{
    /**
     * @param  OrderCalculationService  $calculationService  주문 계산 서비스
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스
     * @param  CouponIssueRepositoryInterface  $couponIssueRepository  쿠폰 발급 Repository
     */
    public function __construct(
        protected OrderCalculationService $calculationService,
        protected CurrencyConversionService $currencyService,
        protected CouponIssueRepositoryInterface $couponIssueRepository,
    ) {}

    /**
     * 주문 변경에 따른 금액 차이를 계산합니다.
     *
     * @param  Order  $order  대상 주문 (옵션, 배송 관계 로드 필요)
     * @param  OrderAdjustment  $adjustment  변경 정보
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return AdjustmentResult 계산 결과
     */
    public function calculate(
        Order $order,
        OrderAdjustment $adjustment,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): AdjustmentResult {
        $order->loadMissing(['options', 'shippings', 'payment']);

        // 1. 원 주문 금액 스냅샷 확보
        $originalSnapshot = $this->captureOrderSnapshot($order);
        $originalPaidAmount = (float) $order->total_paid_amount;
        $originalPointsUsed = (float) $order->total_points_used_amount;

        // 2. 제외 대상 분석
        $excludedItems = $adjustment->getExcludedItems();
        $excludedMap = $this->buildExcludedMap($excludedItems);

        // 3. 잔여 옵션으로 CalculationInput 구성
        $recalcInput = $this->buildRecalcInput($order, $excludedMap);

        // 전량 제외(전체취소)인 경우
        if (empty($recalcInput->items)) {
            return $this->buildFullCancelResult($order, $originalSnapshot, $originalPaidAmount, $originalPointsUsed, $excludedItems, $refundPriority);
        }

        // 4. 쿠폰 used_at 일시 리셋 (재계산 시 검증 통과를 위해)
        $couponIssueIds = $recalcInput->couponIssueIds;
        $originalCouponStates = $this->temporarilyResetCouponUsage($couponIssueIds);

        // 5. 재계산 실행
        try {
            $recalcResult = $this->calculationService->calculate($recalcInput);
        } finally {
            // 쿠폰 상태 복원 (예외 발생 시에도 반드시 복원)
            $this->restoreCouponUsage($originalCouponStates);
        }

        // 6. 환불금액 계산
        $recalculatedPaidAmount = (float) $recalcResult->summary->finalAmount;
        $recalculatedPointsUsed = (float) $recalcResult->summary->pointsUsed;
        $additionalCharges = $adjustment->getAdditionalCharges();

        $totalRefundable = ($originalPaidAmount + $originalPointsUsed)
            - ($recalculatedPaidAmount + $recalculatedPointsUsed)
            - $additionalCharges;
        $totalRefundable = max(0, $totalRefundable);

        // 포인트 우선순위 기반 배분
        $remainingPg = $originalPaidAmount - (float) $order->total_refunded_amount;
        $remainingPoints = $originalPointsUsed - (float) $order->total_refunded_points_amount;

        if ($refundPriority === RefundPriorityEnum::POINTS_FIRST) {
            $refundPointsAmount = min($totalRefundable, max(0, $remainingPoints));
            $refundAmount = min($totalRefundable - $refundPointsAmount, max(0, $remainingPg));
        } else {
            $refundAmount = min($totalRefundable, max(0, $remainingPg));
            $refundPointsAmount = min($totalRefundable - $refundAmount, max(0, $remainingPoints));
        }

        // 7. 배송비/할인 차이
        $originalShipping = (float) $order->total_shipping_amount;
        $recalculatedShipping = (float) $recalcResult->summary->totalShipping;
        $shippingDifference = $originalShipping - $recalculatedShipping;

        $originalDiscount = (float) $order->total_discount_amount;
        $recalculatedDiscount = (float) $recalcResult->summary->totalDiscount;
        $discountDifference = $originalDiscount - $recalculatedDiscount;

        // 8. 재계산 스냅샷
        $recalculatedSnapshot = $this->captureRecalcSnapshot($recalcResult);
        $recalculatedSnapshot['total_list_price_amount'] = $this->calculateListPriceTotal($order, $excludedMap);

        // 9. 옵션별 업데이트 정보 생성
        $optionUpdates = $this->buildOptionUpdates($order, $excludedMap, $recalcResult);
        $shippingUpdates = $this->buildShippingUpdates($order, $recalcResult);
        $orderUpdates = $this->buildOrderUpdates($recalcResult);

        // 10. 취소 대상 아이템 정보
        $adjustedItems = $this->buildAdjustedItems($order, $excludedMap);

        // 11. 쿠폰 복원 대상 확인
        $restoredCouponIssueIds = $this->detectRestoredCoupons($order, $recalcResult);

        // 12. 복원 쿠폰 상세 정보 구성
        $restoredCoupons = $this->buildRestoredCouponsInfo($restoredCouponIssueIds);

        // 13. 배송비 정책별 상세
        $shippingDetails = $this->buildShippingDetails($order, $recalcResult);

        // 14. mc_* 다통화 환불금 변환
        $currencySnapshot = $order->currency_snapshot;
        $mcRefundAmount = null;
        $mcRefundPointsAmount = null;
        $mcRefundShippingAmount = null;

        if ($currencySnapshot) {
            $mcRefundAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                ['refund_amount' => $refundAmount],
                $currencySnapshot
            );
            $mcRefundAmount = $this->extractCurrencyAmounts($mcRefundAmount, 'refund_amount');

            $mcRefundPointsAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                ['refund_points_amount' => $refundPointsAmount],
                $currencySnapshot
            );
            $mcRefundPointsAmount = $this->extractCurrencyAmounts($mcRefundPointsAmount, 'refund_points_amount');

            if ($shippingDifference > 0) {
                $mcRefundShippingAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                    ['refund_shipping_amount' => $shippingDifference],
                    $currencySnapshot
                );
                $mcRefundShippingAmount = $this->extractCurrencyAmounts($mcRefundShippingAmount, 'refund_shipping_amount');
            }
        }

        // 15. 환불 후 잔여 잔액
        $remainingPgBalance = max(0, $remainingPg - $refundAmount);
        $remainingPointsBalance = max(0, $remainingPoints - $refundPointsAmount);

        // 16. 다통화 스냅샷 (총 정가금액 + 실결제금액)
        $mcOriginalSnapshot = $this->buildMcOriginalSnapshot($order);
        $mcRecalculatedSnapshot = $this->buildMcRecalcSnapshot($recalculatedSnapshot, $order->currency_snapshot);

        // 17. 쿠폰 상세 (전/후)
        $originalCoupons = $this->extractCouponDetails($order->promotions_applied_snapshot ?? []);
        $recalculatedCoupons = $this->extractCouponDetails($recalcResult->promotions?->toArray() ?? []);

        return new AdjustmentResult(
            refundAmount: $refundAmount,
            refundPointsAmount: $refundPointsAmount,
            originalPaidAmount: $originalPaidAmount,
            recalculatedPaidAmount: $recalculatedPaidAmount,
            shippingDifference: $shippingDifference,
            discountDifference: $discountDifference,
            recalculated: $recalcResult,
            adjustedItems: $adjustedItems,
            orderUpdates: $orderUpdates,
            optionUpdates: $optionUpdates,
            shippingUpdates: $shippingUpdates,
            originalSnapshot: $originalSnapshot,
            recalculatedSnapshot: $recalculatedSnapshot,
            restoredCouponIssueIds: $restoredCouponIssueIds,
            refundPriority: $refundPriority,
            remainingPgBalance: $remainingPgBalance,
            remainingPointsBalance: $remainingPointsBalance,
            restoredCoupons: $restoredCoupons,
            shippingDetails: $shippingDetails,
            mcRefundAmount: $mcRefundAmount,
            mcRefundPointsAmount: $mcRefundPointsAmount,
            mcRefundShippingAmount: $mcRefundShippingAmount,
            mcOriginalSnapshot: $mcOriginalSnapshot,
            mcRecalculatedSnapshot: $mcRecalculatedSnapshot,
            originalCoupons: $originalCoupons,
            recalculatedCoupons: $recalculatedCoupons,
        );
    }

    /**
     * 미리보기용 환불금액을 계산합니다. (DB 변경 없음)
     *
     * @param  Order  $order  대상 주문
     * @param  OrderAdjustment  $adjustment  변경 정보
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return AdjustmentResult 계산 결과
     */
    public function preview(
        Order $order,
        OrderAdjustment $adjustment,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): AdjustmentResult {
        return $this->calculate($order, $adjustment, $refundPriority);
    }

    /**
     * 주문 금액 스냅샷을 캡처합니다.
     *
     * @param  Order  $order  주문
     * @return array 스냅샷
     */
    private function captureOrderSnapshot(Order $order): array
    {
        return [
            'total_amount' => (float) $order->total_amount,
            'subtotal_amount' => (float) $order->subtotal_amount,
            'total_list_price_amount' => $this->calculateListPriceTotal($order),
            'total_discount_amount' => (float) $order->total_discount_amount,
            'total_coupon_discount_amount' => (float) $order->total_coupon_discount_amount,
            'total_product_coupon_discount_amount' => (float) $order->total_product_coupon_discount_amount,
            'total_order_coupon_discount_amount' => (float) $order->total_order_coupon_discount_amount,
            'total_code_discount_amount' => (float) $order->total_code_discount_amount,
            'total_shipping_amount' => (float) $order->total_shipping_amount,
            'base_shipping_amount' => (float) $order->base_shipping_amount,
            'extra_shipping_amount' => (float) $order->extra_shipping_amount,
            'shipping_discount_amount' => (float) $order->shipping_discount_amount,
            'total_paid_amount' => (float) $order->total_paid_amount,
            'total_points_used_amount' => (float) $order->total_points_used_amount,
            'total_deposit_used_amount' => (float) $order->total_deposit_used_amount,
            'total_tax_amount' => (float) $order->total_tax_amount,
            'total_tax_free_amount' => (float) $order->total_tax_free_amount,
            'total_vat_amount' => (float) $order->total_vat_amount,
            'total_earned_points_amount' => (float) $order->total_earned_points_amount,
            'item_count' => $order->options
                ->where('option_status', '!=', OrderStatusEnum::CANCELLED)
                ->sum('quantity'),
        ];
    }

    /**
     * 주문 옵션의 정가(list_price) 합계를 계산합니다.
     *
     * 옵션 스냅샷의 list_price를 우선 사용하고,
     * 없으면 상품 스냅샷의 list_price + 옵션 price_adjustment로 계산합니다.
     *
     * @param  Order  $order  주문
     * @param  array|null  $excludedMap  제외 맵 (null이면 전체 옵션 대상)
     * @return float 정가 합계
     */
    private function calculateListPriceTotal(Order $order, ?array $excludedMap = null): float
    {
        $total = 0;

        foreach ($order->options as $option) {
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }

            $quantity = $option->quantity;
            if ($excludedMap !== null) {
                $cancelQty = $excludedMap[$option->id] ?? 0;
                $quantity -= $cancelQty;
            }

            if ($quantity <= 0) {
                continue;
            }

            $optionSnapshot = $option->option_snapshot ?? [];
            $productSnapshot = $option->product_snapshot ?? [];

            // 옵션 스냅샷에 list_price가 있으면 사용, 없으면 상품 정가 + 옵션 조정액
            $listPrice = $optionSnapshot['list_price']
                ?? (($productSnapshot['list_price'] ?? 0) + ($optionSnapshot['price_adjustment'] ?? 0));

            $total += (float) $listPrice * $quantity;
        }

        return $total;
    }

    /**
     * 제외 대상 맵을 생성합니다.
     *
     * @param  array  $excludedItems  [{order_option_id, cancel_quantity}]
     * @return array [order_option_id => cancel_quantity]
     */
    private function buildExcludedMap(array $excludedItems): array
    {
        $map = [];
        foreach ($excludedItems as $item) {
            $map[$item['order_option_id']] = $item['cancel_quantity'];
        }

        return $map;
    }

    /**
     * 잔여 옵션으로 재계산 입력을 구성합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $excludedMap  제외 맵
     * @return CalculationInput 재계산 입력
     */
    private function buildRecalcInput(Order $order, array $excludedMap): CalculationInput
    {
        $items = [];
        $restoredCoupons = [];

        foreach ($order->options as $option) {
            // 이미 취소된 옵션은 제외
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }

            $optionId = $option->id;
            $cancelQty = $excludedMap[$optionId] ?? 0;
            $remainingQty = $option->quantity - $cancelQty;

            if ($remainingQty <= 0) {
                continue;
            }

            $items[] = CalculationItem::fromArray([
                'product_id' => $option->product_id,
                'product_option_id' => $option->product_option_id,
                'quantity' => $remainingQty,
                'product_snapshot' => $option->product_snapshot,
                'option_snapshot' => $option->option_snapshot,
            ]);
        }

        // 쿠폰 정보 복원 (프로모션 스냅샷에서)
        $couponIssueIds = [];
        $itemCoupons = [];
        $discountCode = null;

        $promoSnapshot = $order->promotions_applied_snapshot ?? [];

        // 플러그인이 스냅샷에서 자체 할인 데이터를 해석/복원할 수 있는 훅
        // 플러그인 OFF 시 훅 미등록 → $promoSnapshot 그대로 반환 (쿠폰만)
        $promoSnapshot = HookManager::applyFilters(
            'sirsoft-ecommerce.adjustment.filter_restore_promotions',
            $promoSnapshot,
            $order
        );

        if (! empty($promoSnapshot)) {
            $couponIssueIds = $promoSnapshot['coupon_issue_ids'] ?? [];
            $itemCoupons = $promoSnapshot['item_coupons'] ?? [];
            $discountCode = $promoSnapshot['discount_code'] ?? null;
        }

        // 배송지 복원
        $shippingAddress = null;
        $shippingSnapshot = $order->shipping_policy_applied_snapshot ?? [];
        if (! empty($shippingSnapshot['address'])) {
            $shippingAddress = ShippingAddress::fromArray($shippingSnapshot['address']);
        }

        // 배송정책 스냅샷을 product_option_id 키 맵으로 변환
        $shippingPolicySnapshots = $this->buildShippingPolicySnapshotMap($shippingSnapshot);

        // 쿠폰 스냅샷 구성 (스냅샷 모드에서 쿠폰 규칙 복원용)
        $couponSnapshots = $this->buildCouponSnapshotsFromOrder($order);

        return new CalculationInput(
            items: $items,
            couponIssueIds: $couponIssueIds,
            itemCoupons: $itemCoupons,
            discountCode: $discountCode,
            usePoints: (int) $order->total_points_used_amount,
            shippingAddress: $shippingAddress,
            paymentCurrency: $order->currency,
            metadata: [
                'snapshot_mode' => true,
                'coupon_snapshots' => $couponSnapshots,
                'currency_snapshot' => $order->currency_snapshot,
            ],
            shippingPolicySnapshots: $shippingPolicySnapshots,
            promotionSnapshots: $order->promotions_applied_snapshot,
        );
    }

    /**
     * 전체취소 결과를 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $originalSnapshot  원 스냅샷
     * @param  float  $originalPaidAmount  원 결제금액
     * @param  float  $originalPointsUsed  원 포인트 사용액
     * @param  array  $excludedItems  취소 대상 아이템
     * @return AdjustmentResult 전체취소 결과
     */
    private function buildFullCancelResult(
        Order $order,
        array $originalSnapshot,
        float $originalPaidAmount,
        float $originalPointsUsed,
        array $excludedItems,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): AdjustmentResult {
        $adjustedItems = [];
        foreach ($order->options as $option) {
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }
            $adjustedItems[] = [
                'order_option_id' => $option->id,
                'cancel_quantity' => $option->quantity,
                'cancel_amount' => (float) $option->subtotal_paid_amount,
            ];
        }

        // 모든 쿠폰 복원
        $restoredCouponIds = [];
        $promoSnapshot = $order->promotions_applied_snapshot ?? [];
        if (! empty($promoSnapshot['coupon_issue_ids'])) {
            $restoredCouponIds = $promoSnapshot['coupon_issue_ids'];
        }

        // 환불 우선순위 기반 배분 (전체취소 시)
        $refundAmount = $originalPaidAmount;
        $refundPointsAmount = $originalPointsUsed;
        $shippingDifference = (float) $order->total_shipping_amount;

        // 잔여 잔액
        $remainingPgBalance = max(0, $originalPaidAmount - $refundAmount);
        $remainingPointsBalance = max(0, $originalPointsUsed - $refundPointsAmount);

        // 복원 쿠폰 상세 정보
        $restoredCoupons = $this->buildRestoredCouponsInfo($restoredCouponIds);

        // 배송비 정책별 상세 (전체취소: 모든 배송비 환불)
        $shippingDetails = [];
        foreach ($order->shippings as $shipping) {
            $baseFee = (float) ($shipping->base_shipping_amount ?? 0);
            $extraFee = (float) ($shipping->extra_shipping_amount ?? 0);
            $totalFee = $baseFee + $extraFee;
            if ($totalFee > 0) {
                $shippingDetails[] = [
                    'policy_name' => $shipping->shippingPolicy?->getLocalizedName() ?? __('sirsoft-ecommerce::order.default_shipping'),
                    'base_difference' => $baseFee,
                    'extra_difference' => $extraFee,
                    'total_difference' => $totalFee,
                ];
            }
        }

        // mc_* 다통화 환불금 변환
        $currencySnapshot = $order->currency_snapshot;
        $mcRefundAmount = null;
        $mcRefundPointsAmount = null;
        $mcRefundShippingAmount = null;

        if ($currencySnapshot) {
            $mcRefundAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                ['refund_amount' => $refundAmount],
                $currencySnapshot
            );
            $mcRefundAmount = $this->extractCurrencyAmounts($mcRefundAmount, 'refund_amount');

            $mcRefundPointsAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                ['refund_points_amount' => $refundPointsAmount],
                $currencySnapshot
            );
            $mcRefundPointsAmount = $this->extractCurrencyAmounts($mcRefundPointsAmount, 'refund_points_amount');

            if ($shippingDifference > 0) {
                $mcRefundShippingAmount = $this->currencyService->convertMultipleAmountsWithSnapshot(
                    ['refund_shipping_amount' => $shippingDifference],
                    $currencySnapshot
                );
                $mcRefundShippingAmount = $this->extractCurrencyAmounts($mcRefundShippingAmount, 'refund_shipping_amount');
            }
        }

        // 다통화 스냅샷
        $mcOriginalSnapshot = $this->buildMcOriginalSnapshot($order);
        $zeroMcSnapshot = null;
        if ($mcOriginalSnapshot) {
            $zeroFormattedSubtotal = [];
            foreach ($mcOriginalSnapshot['mc_subtotal_amount'] ?? [] as $code => $amount) {
                $zeroFormattedSubtotal[$code] = $this->currencyService->formatPrice(0, $code);
            }
            $zeroFormattedTotalPaid = [];
            foreach ($mcOriginalSnapshot['mc_total_paid_amount'] ?? [] as $code => $amount) {
                $zeroFormattedTotalPaid[$code] = $this->currencyService->formatPrice(0, $code);
            }
            $zeroFormattedListPrice = [];
            foreach ($mcOriginalSnapshot['mc_total_list_price_amount'] ?? [] as $code => $amount) {
                $zeroFormattedListPrice[$code] = $this->currencyService->formatPrice(0, $code);
            }

            $zeroMcSnapshot = [
                'mc_subtotal_amount' => array_map(fn () => 0, $mcOriginalSnapshot['mc_subtotal_amount'] ?? []),
                'mc_total_paid_amount' => array_map(fn () => 0, $mcOriginalSnapshot['mc_total_paid_amount'] ?? []),
                'mc_total_list_price_amount' => array_map(fn () => 0, $mcOriginalSnapshot['mc_total_list_price_amount'] ?? []),
                'mc_subtotal_amount_formatted' => $zeroFormattedSubtotal,
                'mc_total_paid_amount_formatted' => $zeroFormattedTotalPaid,
                'mc_total_list_price_amount_formatted' => $zeroFormattedListPrice,
            ];
        }

        // 쿠폰 상세 (전체취소 시 취소 후는 빈 배열)
        $originalCoupons = $this->extractCouponDetails($order->promotions_applied_snapshot ?? []);

        return new AdjustmentResult(
            refundAmount: $refundAmount,
            refundPointsAmount: $refundPointsAmount,
            originalPaidAmount: $originalPaidAmount,
            recalculatedPaidAmount: 0,
            shippingDifference: $shippingDifference,
            discountDifference: (float) $order->total_discount_amount,
            recalculated: null,
            adjustedItems: $adjustedItems,
            orderUpdates: [
                'subtotal_amount' => 0,
                'total_discount_amount' => 0,
                'total_coupon_discount_amount' => 0,
                'total_shipping_amount' => 0,
                'total_amount' => 0,
                'total_paid_amount' => 0,
                'total_points_used_amount' => 0,
            ],
            optionUpdates: [],
            shippingUpdates: [],
            originalSnapshot: $originalSnapshot,
            recalculatedSnapshot: [
                'total_amount' => 0,
                'subtotal_amount' => 0,
                'total_list_price_amount' => 0,
                'total_discount_amount' => 0,
                'total_coupon_discount_amount' => 0,
                'total_product_coupon_discount_amount' => 0,
                'total_order_coupon_discount_amount' => 0,
                'total_code_discount_amount' => 0,
                'total_shipping_amount' => 0,
                'base_shipping_amount' => 0,
                'extra_shipping_amount' => 0,
                'shipping_discount_amount' => 0,
                'total_paid_amount' => 0,
                'total_points_used_amount' => 0,
                'total_deposit_used_amount' => 0,
                'total_tax_amount' => 0,
                'total_tax_free_amount' => 0,
                'total_vat_amount' => 0,
                'total_earned_points_amount' => 0,
                'item_count' => 0,
            ],
            restoredCouponIssueIds: $restoredCouponIds,
            refundPriority: $refundPriority,
            remainingPgBalance: $remainingPgBalance,
            remainingPointsBalance: $remainingPointsBalance,
            restoredCoupons: $restoredCoupons,
            shippingDetails: $shippingDetails,
            mcRefundAmount: $mcRefundAmount,
            mcRefundPointsAmount: $mcRefundPointsAmount,
            mcRefundShippingAmount: $mcRefundShippingAmount,
            mcOriginalSnapshot: $mcOriginalSnapshot,
            mcRecalculatedSnapshot: $zeroMcSnapshot,
            originalCoupons: $originalCoupons,
            recalculatedCoupons: [],
        );
    }

    /**
     * 재계산 결과 스냅샷을 캡처합니다.
     *
     * @param  \Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult  $result  재계산 결과
     * @return array 스냅샷
     */
    private function captureRecalcSnapshot($result): array
    {
        $taxableAmount = (float) $result->summary->taxableAmount;

        // 남은 아이템 수량 합계
        $itemCount = 0;
        foreach ($result->items ?? [] as $item) {
            $itemCount += (int) ($item->quantity ?? 0);
        }

        return [
            'total_amount' => (float) $result->summary->paymentAmount,
            'subtotal_amount' => (float) $result->summary->subtotal,
            'total_discount_amount' => (float) $result->summary->totalDiscount,
            'total_coupon_discount_amount' => (float) ($result->summary->productCouponDiscount + $result->summary->orderCouponDiscount),
            'total_product_coupon_discount_amount' => (float) $result->summary->productCouponDiscount,
            'total_order_coupon_discount_amount' => (float) $result->summary->orderCouponDiscount,
            'total_code_discount_amount' => (float) $result->summary->codeDiscount,
            'total_shipping_amount' => (float) $result->summary->totalShipping,
            'base_shipping_amount' => (float) $result->summary->baseShippingTotal,
            'extra_shipping_amount' => (float) $result->summary->extraShippingTotal,
            'shipping_discount_amount' => (float) $result->summary->shippingDiscount,
            'total_paid_amount' => (float) $result->summary->finalAmount,
            'total_points_used_amount' => (float) $result->summary->pointsUsed,
            'total_deposit_used_amount' => 0,
            'total_tax_amount' => $taxableAmount,
            'total_tax_free_amount' => (float) $result->summary->taxFreeAmount,
            'total_vat_amount' => $taxableAmount > 0 ? round($taxableAmount * 10 / 110) : 0,
            'total_earned_points_amount' => (float) ($result->summary->pointsEarning ?? 0),
            'item_count' => $itemCount,
        ];
    }

    /**
     * 옵션별 업데이트 정보를 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $excludedMap  제외 맵
     * @param  \Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult  $recalcResult  재계산 결과
     * @return array [order_option_id => update_data]
     */
    private function buildOptionUpdates(Order $order, array $excludedMap, $recalcResult): array
    {
        $updates = [];

        // 재계산 결과에서 옵션별 금액 반영
        foreach ($recalcResult->items as $item) {
            foreach ($order->options as $option) {
                if ($option->product_option_id == $item->productOptionId) {
                    $cancelQty = $excludedMap[$option->id] ?? 0;
                    $remainingQty = $option->quantity - $cancelQty;

                    // 전량 취소된 옵션은 제외 (잔여 수량 있는 부분취소는 포함)
                    if ($remainingQty <= 0) {
                        continue;
                    }

                    $updates[$option->id] = [
                        'subtotal_paid_amount' => (float) $item->finalAmount,
                        'coupon_discount_amount' => (float) $item->productCouponDiscountAmount,
                        'code_discount_amount' => (float) $item->codeDiscountAmount,
                        'subtotal_points_used_amount' => (float) $item->pointsUsedShare,
                        'subtotal_tax_amount' => (float) $item->taxableAmount,
                        'subtotal_tax_free_amount' => (float) $item->taxFreeAmount,
                        'promotions_applied_snapshot' => $item->appliedPromotions?->toArray(),
                    ];
                }
            }
        }

        return $updates;
    }

    /**
     * 배송비 업데이트 정보를 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  \Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult  $recalcResult  재계산 결과
     * @return array [order_shipping_id => update_data]
     */
    private function buildShippingUpdates(Order $order, $recalcResult): array
    {
        // 배송비 재계산 결과를 기존 배송 레코드에 매핑
        $updates = [];

        foreach ($order->shippings as $shipping) {
            $updates[$shipping->id] = [
                'base_shipping_amount' => (float) ($recalcResult->summary->baseShippingTotal ?? 0),
                'extra_shipping_amount' => (float) ($recalcResult->summary->extraShippingTotal ?? 0),
                'total_shipping_amount' => (float) ($recalcResult->summary->totalShipping ?? 0),
                'shipping_discount_amount' => (float) ($recalcResult->summary->shippingDiscount ?? 0),
            ];
        }

        return $updates;
    }

    /**
     * 주문 테이블 업데이트 정보를 생성합니다.
     *
     * @param  \Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult  $recalcResult  재계산 결과
     * @return array 업데이트 데이터
     */
    private function buildOrderUpdates($recalcResult): array
    {
        // 재계산된 프로모션 스냅샷 (부분취소 후 남은 아이템 기준 할인 배분)
        $promotionsSnapshot = $recalcResult->promotions?->toArray() ?? [];

        // 플러그인이 스냅샷에 자체 할인 데이터를 추가할 수 있는 훅
        $promotionsSnapshot = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.filter_promotions_snapshot',
            $promotionsSnapshot,
            $recalcResult
        );

        return [
            'subtotal_amount' => (float) $recalcResult->summary->subtotal,
            'total_discount_amount' => (float) $recalcResult->summary->totalDiscount,
            'total_coupon_discount_amount' => (float) ($recalcResult->summary->productCouponDiscount + $recalcResult->summary->orderCouponDiscount),
            'total_product_coupon_discount_amount' => (float) $recalcResult->summary->productCouponDiscount,
            'total_order_coupon_discount_amount' => (float) $recalcResult->summary->orderCouponDiscount,
            'total_code_discount_amount' => (float) $recalcResult->summary->codeDiscount,
            'base_shipping_amount' => (float) $recalcResult->summary->baseShippingTotal,
            'extra_shipping_amount' => (float) $recalcResult->summary->extraShippingTotal,
            'shipping_discount_amount' => (float) $recalcResult->summary->shippingDiscount,
            'total_shipping_amount' => (float) $recalcResult->summary->totalShipping,
            'total_amount' => (float) $recalcResult->summary->paymentAmount,
            'total_paid_amount' => (float) $recalcResult->summary->finalAmount,
            'total_points_used_amount' => (float) $recalcResult->summary->pointsUsed,
            'total_tax_amount' => (float) $recalcResult->summary->taxableAmount,
            'total_tax_free_amount' => (float) $recalcResult->summary->taxFreeAmount,
            'promotions_applied_snapshot' => $promotionsSnapshot,
        ];
    }

    /**
     * 취소 대상 아이템 정보를 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $excludedMap  제외 맵
     * @return array [{order_option_id, cancel_quantity, cancel_amount}]
     */
    private function buildAdjustedItems(Order $order, array $excludedMap): array
    {
        $items = [];
        foreach ($excludedMap as $optionId => $cancelQty) {
            $option = $order->options->find($optionId);
            if ($option) {
                $unitPrice = (float) $option->unit_price;
                $items[] = [
                    'order_option_id' => $optionId,
                    'cancel_quantity' => $cancelQty,
                    'cancel_amount' => $unitPrice * $cancelQty,
                ];
            }
        }

        return $items;
    }

    /**
     * 쿠폰 사용 상태를 일시적으로 리셋합니다.
     *
     * 재계산 시 OrderCalculationService의 validateCoupon()이
     * used_at !== null인 쿠폰을 'alreadyUsed'로 거부하므로,
     * 원 주문에서 사용된 쿠폰을 일시적으로 미사용 상태로 변경합니다.
     *
     * @param  int[]  $couponIssueIds  쿠폰 발급 ID 배열
     * @return array 원래 상태 [{id, status, used_at}]
     */
    private function temporarilyResetCouponUsage(array $couponIssueIds): array
    {
        if (empty($couponIssueIds)) {
            return [];
        }

        $originalStates = [];
        $couponIssues = $this->couponIssueRepository->findByIds($couponIssueIds);

        foreach ($couponIssues as $couponIssue) {
            $originalStates[] = [
                'id' => $couponIssue->id,
                'status' => $couponIssue->status,
                'used_at' => $couponIssue->used_at,
                'order_id' => $couponIssue->order_id,
            ];

            $this->couponIssueRepository->update($couponIssue->id, [
                'status' => CouponIssueRecordStatus::AVAILABLE,
                'used_at' => null,
                'order_id' => null,
            ]);
        }

        return $originalStates;
    }

    /**
     * 쿠폰 사용 상태를 원래대로 복원합니다.
     *
     * @param  array  $originalStates  원래 상태 배열
     * @return void
     */
    private function restoreCouponUsage(array $originalStates): void
    {
        foreach ($originalStates as $state) {
            $this->couponIssueRepository->update($state['id'], [
                'status' => $state['status'],
                'used_at' => $state['used_at'],
                'order_id' => $state['order_id'],
            ]);
        }
    }

    /**
     * 복원 대상 쿠폰을 감지합니다.
     *
     * 부분취소로 최소주문금액 미달 시 해당 쿠폰을 복원 대상으로 반환합니다.
     *
     * @param  Order  $order  주문
     * @param  \Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult  $recalcResult  재계산 결과
     * @return array 복원 대상 쿠폰 발급 ID 배열
     */
    private function detectRestoredCoupons(Order $order, $recalcResult): array
    {
        $originalCoupons = $order->promotions_applied_snapshot['coupon_issue_ids'] ?? [];
        $recalcCoupons = $recalcResult->getAppliedCouponIds();

        return array_values(array_diff($originalCoupons, $recalcCoupons));
    }

    /**
     * 배송정책 스냅샷을 product_option_id 키 맵으로 변환합니다.
     *
     * OrderProcessingService::buildShippingPolicyAppliedSnapshot()은 인덱스 배열로 저장하지만,
     * OrderCalculationService::groupByShippingPolicy()는 $snapshots[$optionId]로 접근하므로 변환이 필요합니다.
     *
     * @param  array  $shippingSnapshot  주문의 shipping_policy_applied_snapshot
     * @return array product_option_id => AppliedShippingPolicy 직렬화 데이터
     */
    private function buildShippingPolicySnapshotMap(array $shippingSnapshot): array
    {
        $map = [];

        foreach ($shippingSnapshot as $key => $entry) {
            // 이미 product_option_id를 키로 사용하는 경우 (숫자 키 + policy 데이터)
            if (is_int($key) && isset($entry['product_option_id'], $entry['policy'])) {
                $map[$entry['product_option_id']] = $entry['policy'];
            } elseif (is_int($key) && isset($entry['policy_id'])) {
                // product_option_id 키 맵 형태 (테스트 헬퍼 등에서 직접 구성한 경우)
                $map[$key] = $entry;
            } elseif ($key === 'address') {
                // address 키는 스킵 (배송지 정보)
                continue;
            } else {
                // 기타 (이미 product_option_id => data 형태)
                $map[$key] = $entry;
            }
        }

        return $map;
    }

    /**
     * 주문의 프로모션 스냅샷에서 쿠폰 규칙 스냅샷을 구성합니다.
     *
     * @param  Order  $order  주문
     * @return array 쿠폰 발급 ID => 규칙 배열
     */
    private function buildCouponSnapshotsFromOrder(Order $order): array
    {
        $snapshots = [];
        $promoSnapshot = $order->promotions_applied_snapshot ?? [];

        // product_promotions.coupons + order_promotions.coupons 모두 탐색
        $couponSources = [
            $promoSnapshot['product_promotions']['coupons'] ?? [],
            $promoSnapshot['order_promotions']['coupons'] ?? [],
        ];

        foreach ($couponSources as $coupons) {
            foreach ($coupons as $coupon) {
                $couponIssueId = $coupon['coupon_issue_id'] ?? null;
                if ($couponIssueId === null) {
                    continue;
                }
                $snapshots[$couponIssueId] = [
                    'discount_type' => $coupon['discount_type'] ?? null,
                    'discount_value' => $coupon['discount_value'] ?? 0,
                    'min_order_amount' => $coupon['min_order_amount'] ?? 0,
                    'max_discount_amount' => $coupon['max_discount_amount'] ?? 0,
                    'target_type' => $coupon['target_type'] ?? null,
                    'target_scope' => $coupon['target_scope'] ?? null,
                    'applied_items' => $coupon['applied_items'] ?? null,
                ];
            }
        }

        return $snapshots;
    }

    /**
     * 복원 쿠폰 상세 정보를 구성합니다.
     *
     * @param  array  $restoredCouponIssueIds  복원 대상 쿠폰 발급 ID 배열
     * @return array [{coupon_name, discount_amount}]
     */
    private function buildRestoredCouponsInfo(array $restoredCouponIssueIds): array
    {
        if (empty($restoredCouponIssueIds)) {
            return [];
        }

        $couponIssues = $this->couponIssueRepository->findByIdsWithRelations($restoredCouponIssueIds, ['coupon']);

        $restoredCoupons = [];
        foreach ($couponIssues as $issue) {
            $restoredCoupons[] = [
                'coupon_name' => $issue->coupon?->getLocalizedName() ?? '',
                'discount_amount' => (float) ($issue->discount_amount ?? 0),
            ];
        }

        return $restoredCoupons;
    }

    /**
     * 배송비 정책별 상세 차이를 구성합니다.
     *
     * @param  Order  $order  주문
     * @param  OrderCalculationResult  $recalcResult  재계산 결과
     * @return array [{policy_name, base_difference, extra_difference, total_difference}]
     */
    private function buildShippingDetails(Order $order, OrderCalculationResult $recalcResult): array
    {
        // 원 주문의 배송비를 정책별로 집계
        $originalByPolicy = [];
        foreach ($order->shippings as $shipping) {
            $policyId = $shipping->shipping_policy_id;
            if (! isset($originalByPolicy[$policyId])) {
                $originalByPolicy[$policyId] = [
                    'base' => 0,
                    'extra' => 0,
                    'name' => $shipping->shippingPolicy?->getLocalizedName() ?? __('sirsoft-ecommerce::order.default_shipping'),
                ];
            }
            $originalByPolicy[$policyId]['base'] += (float) $shipping->base_shipping_amount;
            $originalByPolicy[$policyId]['extra'] += (float) $shipping->extra_shipping_amount;
        }

        // 재계산 결과의 배송비를 정책별로 집계
        $recalcByPolicy = $recalcResult->getShippingByPolicy();

        // 차이 계산
        $details = [];
        foreach ($originalByPolicy as $policyId => $original) {
            $recalc = $recalcByPolicy[$policyId] ?? ['base' => 0, 'extra' => 0];
            $baseDiff = $original['base'] - ($recalc['base'] ?? 0);
            $extraDiff = $original['extra'] - ($recalc['extra'] ?? 0);
            if ($baseDiff != 0 || $extraDiff != 0) {
                $details[] = [
                    'policy_name' => $original['name'],
                    'base_difference' => $baseDiff,
                    'extra_difference' => $extraDiff,
                    'total_difference' => $baseDiff + $extraDiff,
                ];
            }
        }

        return $details;
    }

    /**
     * 프로모션 스냅샷에서 쿠폰 상세 배열을 추출합니다.
     *
     * @param  array  $promotionsSnapshot  promotions_applied_snapshot 또는 PromotionsSummary::toArray()
     * @return array [{name, target_type, discount_amount}]
     */
    private function extractCouponDetails(array $promotionsSnapshot): array
    {
        $details = [];

        $sections = ['product_promotions', 'order_promotions'];
        foreach ($sections as $section) {
            $coupons = $promotionsSnapshot[$section]['coupons'] ?? [];
            foreach ($coupons as $coupon) {
                $details[] = [
                    'name' => $coupon['name'] ?? '',
                    'target_type' => $coupon['target_type'] ?? '',
                    'discount_amount' => (float) ($coupon['total_discount'] ?? 0),
                ];
            }
        }

        return $details;
    }

    /**
     * 원 주문의 다통화 스냅샷을 생성합니다.
     *
     * @param  Order  $order  주문
     * @return array|null {mc_subtotal_amount, mc_total_paid_amount, mc_subtotal_amount_formatted, mc_total_paid_amount_formatted}
     */
    private function buildMcOriginalSnapshot(Order $order): ?array
    {
        if (! $order->currency_snapshot) {
            return null;
        }

        $formattedSubtotal = [];
        foreach ($order->mc_subtotal_amount ?? [] as $code => $amount) {
            $formattedSubtotal[$code] = $this->currencyService->formatPrice($amount, $code);
        }

        $formattedTotalPaid = [];
        foreach ($order->mc_total_paid_amount ?? [] as $code => $amount) {
            $formattedTotalPaid[$code] = $this->currencyService->formatPrice($amount, $code);
        }

        // 정가 합계 다통화 변환
        $listPriceTotal = $this->calculateListPriceTotal($order);
        $convertedListPrice = $this->currencyService->convertMultipleAmountsWithSnapshot(
            ['mc_total_list_price_amount' => $listPriceTotal],
            $order->currency_snapshot
        );

        $mcListPrice = [];
        $mcListPriceFormatted = [];
        foreach ($convertedListPrice ?? [] as $currencyCode => $amounts) {
            if (is_array($amounts) && isset($amounts['mc_total_list_price_amount'])) {
                $mcListPrice[$currencyCode] = $amounts['mc_total_list_price_amount'];
                $mcListPriceFormatted[$currencyCode] = $amounts['mc_total_list_price_amount_formatted'] ?? '';
            }
        }

        return [
            'mc_subtotal_amount' => $order->mc_subtotal_amount,
            'mc_total_paid_amount' => $order->mc_total_paid_amount,
            'mc_total_list_price_amount' => $mcListPrice,
            'mc_subtotal_amount_formatted' => $formattedSubtotal,
            'mc_total_paid_amount_formatted' => $formattedTotalPaid,
            'mc_total_list_price_amount_formatted' => $mcListPriceFormatted,
        ];
    }

    /**
     * 재계산 결과의 다통화 스냅샷을 생성합니다.
     *
     * @param  array  $recalcSnapshot  재계산 스냅샷
     * @param  array|null  $currencySnapshot  주문 시점 환율 스냅샷
     * @return array|null {mc_subtotal_amount, mc_total_paid_amount, mc_subtotal_amount_formatted, mc_total_paid_amount_formatted}
     */
    private function buildMcRecalcSnapshot(array $recalcSnapshot, ?array $currencySnapshot): ?array
    {
        if (! $currencySnapshot) {
            return null;
        }

        $converted = $this->currencyService->convertMultipleAmountsWithSnapshot(
            [
                'mc_subtotal_amount' => $recalcSnapshot['subtotal_amount'] ?? 0,
                'mc_total_paid_amount' => $recalcSnapshot['total_paid_amount'] ?? 0,
                'mc_total_list_price_amount' => $recalcSnapshot['total_list_price_amount'] ?? 0,
            ],
            $currencySnapshot
        );

        if (empty($converted)) {
            return null;
        }

        // {통화코드 => {mc_subtotal_amount, mc_total_paid_amount, mc_subtotal_amount_formatted, ...}} →
        // {mc_subtotal_amount => {통화코드 => 금액}, mc_subtotal_amount_formatted => {통화코드 => 포맷}, ...}
        $result = [
            'mc_subtotal_amount' => [],
            'mc_total_paid_amount' => [],
            'mc_total_list_price_amount' => [],
            'mc_subtotal_amount_formatted' => [],
            'mc_total_paid_amount_formatted' => [],
            'mc_total_list_price_amount_formatted' => [],
        ];

        foreach ($converted as $currencyCode => $amounts) {
            if (is_array($amounts)) {
                foreach (['mc_subtotal_amount', 'mc_total_paid_amount', 'mc_total_list_price_amount'] as $key) {
                    if (isset($amounts[$key])) {
                        $result[$key][$currencyCode] = $amounts[$key];
                        $result[$key.'_formatted'][$currencyCode] = $amounts[$key.'_formatted'] ?? '';
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 다통화 변환 결과에서 통화별 금액을 추출합니다.
     *
     * @param  array|null  $conversionResult  변환 결과
     * @param  string  $amountKey  금액 키
     * @return array|null {통화코드 => 금액}
     */
    private function extractCurrencyAmounts(?array $conversionResult, string $amountKey): ?array
    {
        if ($conversionResult === null) {
            return null;
        }

        $result = [];
        foreach ($conversionResult as $currencyCode => $amounts) {
            if (is_array($amounts) && isset($amounts[$amountKey])) {
                $result[$currencyCode] = $amounts[$amountKey];
            } elseif (is_numeric($amounts)) {
                $result[$currencyCode] = $amounts;
            }
        }

        return ! empty($result) ? $result : null;
    }
}
