<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\Http\Resources\CheckoutItemResource;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;

/**
 * 체크아웃 데이터 서비스
 *
 * 체크아웃 응답에 필요한 부가 데이터(쿠폰, 마일리지, 상품 정보)를 조합합니다.
 * CheckoutController의 show/update 공통 로직을 담당합니다.
 */
class CheckoutDataService
{
    public function __construct(
        protected UserCouponService $userCouponService,
        protected UserMileageService $userMileageService
    ) {}

    /**
     * 체크아웃 응답 데이터 구성
     *
     * TempOrder와 계산 결과를 기반으로 쿠폰, 마일리지, 상품 정보를 조합합니다.
     *
     * @param TempOrder $tempOrder 임시 주문
     * @param OrderCalculationResult|array $calculation 계산 결과 (DTO 또는 배열)
     * @param int|null $userId 사용자 ID (비회원인 경우 null)
     * @param array $unavailableItems 구매불가 상품 목록 (선택)
     * @return array 체크아웃 응답 데이터
     */
    public function buildResponseData(TempOrder $tempOrder, OrderCalculationResult|array $calculation, ?int $userId, array $unavailableItems = []): array
    {
        // 계산 결과를 배열로 통일
        $calculationArray = $calculation instanceof OrderCalculationResult
            ? $calculation->toArray()
            : $calculation;

        // 회원인 경우 쿠폰/마일리지 조회
        $availableCoupons = [];
        $productCoupons = [];
        $mileageInfo = null;

        if ($userId !== null) {
            $couponData = $this->buildCouponData($tempOrder, $calculationArray, $userId);
            $availableCoupons = $couponData['available_coupons'];
            $productCoupons = $couponData['product_coupons'];
            $mileageInfo = $couponData['mileage_info'];
        }

        // 상품 정보 enrichment
        $enrichedItems = CheckoutItemResource::collectionFromArray(
            $tempOrder->items ?? [],
            $calculationArray['items'] ?? [],
            $productCoupons
        );

        $response = [
            'temp_order_id' => $tempOrder->id,
            'items' => $enrichedItems,
            'calculation' => $calculationArray,
            'promotions' => $tempOrder->getPromotions(),
            'use_points' => $tempOrder->getUsedPoints(),
            'shipping_address' => $tempOrder->getShippingAddress(),
            'expires_at' => $tempOrder->expires_at->toIso8601String(),
            'available_coupons' => $availableCoupons,
            'mileage' => $mileageInfo,
        ];

        // 구매불가 상품이 있는 경우에만 포함
        if (! empty($unavailableItems)) {
            $response['unavailable_items'] = $unavailableItems;
            $response['has_stock_issue'] = collect($unavailableItems)->contains('reason', 'stock');
            $response['has_status_issue'] = collect($unavailableItems)->contains('reason', 'status');
        }

        return $response;
    }

    /**
     * 쿠폰 및 마일리지 데이터 조회
     *
     * @param TempOrder $tempOrder 임시 주문
     * @param array $calculationArray 계산 결과 배열
     * @param int $userId 사용자 ID
     * @return array 쿠폰/마일리지 데이터
     */
    protected function buildCouponData(TempOrder $tempOrder, array $calculationArray, int $userId): array
    {
        $productIds = array_map('intval', array_column($tempOrder->items ?? [], 'product_id'));
        $subtotal = (float) ($calculationArray['summary']['subtotal'] ?? 0);
        $totalShipping = (float) ($calculationArray['summary']['total_shipping'] ?? 0);

        // 상품별 소계 계산
        $itemSubtotals = $this->calculateItemSubtotals($calculationArray['items'] ?? []);

        // 쿠폰 조회
        $availableCoupons = $this->userCouponService->getCheckoutCoupons(
            $userId,
            $productIds,
            $subtotal,
            $totalShipping
        );

        $productCoupons = $this->userCouponService->getProductCouponsGrouped(
            $userId,
            $productIds,
            $itemSubtotals
        );

        // 마일리지 조회
        $mileageInfo = $this->userMileageService->getBalance($userId);
        $mileageInfo['max_usable'] = $this->userMileageService->getMaxUsable($userId, $subtotal);

        return [
            'available_coupons' => $availableCoupons,
            'product_coupons' => $productCoupons,
            'mileage_info' => $mileageInfo,
        ];
    }

    /**
     * 상품별 소계 계산
     *
     * 동일 상품이 여러 옵션으로 존재할 수 있으므로 product_id 기준으로 합산합니다.
     *
     * @param array $calculationItems 계산 결과의 items 배열
     * @return array 상품별 소계 (product_id => subtotal)
     */
    protected function calculateItemSubtotals(array $calculationItems): array
    {
        $itemSubtotals = [];

        foreach ($calculationItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0) {
                $subtotalValue = (float) ($item['subtotal'] ?? 0);
                $itemSubtotals[$productId] = ($itemSubtotals[$productId] ?? 0) + $subtotalValue;
            }
        }

        return $itemSubtotals;
    }
}
