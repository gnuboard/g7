<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\Http\Middleware\ResolveShippingCountry;
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
        protected UserMileageService $userMileageService,
        protected EcommerceSettingsService $settings,
        protected CurrencyConversionService $currencyConversion,
    ) {}

    /**
     * 체크아웃 응답 데이터 구성
     *
     * TempOrder와 계산 결과를 기반으로 쿠폰, 마일리지, 상품 정보를 조합합니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  OrderCalculationResult|array  $calculation  계산 결과 (DTO 또는 배열)
     * @param  int|null  $userId  사용자 ID (비회원인 경우 null)
     * @param  array  $unavailableItems  구매불가 상품 목록 (선택)
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

        // 현재 선택된 상품 쿠폰 (optionId => [발급ID, ...]) — per_user_limit 중복 비활성화 계산용
        $selectedItemCoupons = $tempOrder->getPromotions()['item_coupons'] ?? [];

        // 상품 정보 enrichment
        $enrichedItems = CheckoutItemResource::collectionFromArray(
            $tempOrder->items ?? [],
            $calculationArray['items'] ?? [],
            $productCoupons,
            $selectedItemCoupons
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
            // 쿠폰 검증 오류 소프트 표면화 (U14/MP06): 위반 쿠폰은 할인에서 자동 제외되되
            // 프론트가 사유(min_amount/per_user_limit/not_combinable 등)를 안내할 수 있도록 최상위 노출.
            'validation_errors' => $calculationArray['validation_errors'] ?? [],
            // 선택된 배송국가로 배송 불가한 상품이 1개라도 있으면 주문하기 차단 플래그 (D1 — layer 2)
            'has_unshippable_items' => collect($enrichedItems)
                ->contains(fn ($i) => ($i['is_shippable_to_selected_country'] ?? true) === false),
            'selected_shipping_country' => ResolveShippingCountry::getCountry(),
            // 무료배송 기준액 결제통화 환산 (B7 — 다통화 정합)
            'free_shipping' => $this->buildFreeShippingInfo($calculationArray),
        ];

        // 구매불가 상품이 있는 경우에만 포함
        if (! empty($unavailableItems)) {
            $response['unavailable_items'] = $unavailableItems;
            $response['has_stock_issue'] = collect($unavailableItems)->contains('reason', 'stock');
            $response['has_status_issue'] = collect($unavailableItems)->contains('reason', 'status');
        }

        // 필터 훅: 체크아웃 응답 데이터 변환 (외부 확장이 본인인증 hint 등 추가 가능)
        $response = HookManager::applyFilters(
            'sirsoft-ecommerce.checkout.filter_response_data',
            $response,
            $tempOrder,
            $userId,
        );

        return is_array($response) ? $response : [];
    }

    /**
     * 무료배송 기준액 정보를 구성합니다. (B7 — 결제통화 환산)
     *
     * free_shipping_threshold 는 base 통화 정수이므로, 다통화 환경에서 결제통화 환산값을 함께
     * 노출해 체크아웃 안내("X원 더 담으면 무료배송")가 결제통화로 표시되도록 한다.
     *
     * @param  array  $calculationArray  계산 결과 배열 (현재 소계 기준 잔여액 산출용)
     * @return array{enabled: bool, threshold_base: int, threshold_multi_currency: array, remaining_base: int, remaining_multi_currency: array}
     */
    protected function buildFreeShippingInfo(array $calculationArray): array
    {
        $shipping = $this->settings->getSettings('shipping');
        $enabled = (bool) ($shipping['free_shipping_enabled'] ?? false);
        $threshold = (int) ($shipping['free_shipping_threshold'] ?? 0);

        $subtotal = (int) ($calculationArray['summary']['subtotal'] ?? 0);
        $remaining = max(0, $threshold - $subtotal);

        return [
            'enabled' => $enabled,
            'threshold_base' => $threshold,
            'threshold_multi_currency' => $threshold > 0
                ? $this->currencyConversion->convertToMultiCurrency($threshold)
                : [],
            'remaining_base' => $remaining,
            'remaining_multi_currency' => $remaining > 0
                ? $this->currencyConversion->convertToMultiCurrency($remaining)
                : [],
        ];
    }

    /**
     * 쿠폰 및 마일리지 데이터 조회
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  array  $calculationArray  계산 결과 배열
     * @param  int  $userId  사용자 ID
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
        // 기본 통화 사용 규칙 미설정 시 사용 불가(M2) — 적립(enabled)과 분리된 "사용 가능" 플래그
        $mileageInfo['usable'] = $this->userMileageService->isMileageUsable();

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
     * @param  array  $calculationItems  계산 결과의 items 배열
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
