<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 체크아웃 아이템 리소스
 *
 * TempOrder의 items 배열을 기반으로 주문 아이템 정보를 반환합니다.
 * Product와 ProductOption을 조회하여 CartItemResource와 동일한 구조로 변환합니다.
 * calculation 데이터를 병합하여 쿠폰 할인, 총 할인, 최종 금액 등을 포함합니다.
 */
class CheckoutItemResource extends BaseOrderItemResource
{
    /**
     * 체크아웃 아이템 컬렉션 생성
     *
     * items 배열에서 Product/ProductOption을 한번에 조회하여 효율적으로 처리합니다.
     * calculationItems가 제공되면 금액 정보를 병합합니다.
     * productCoupons가 제공되면 각 상품에 적용 가능한 쿠폰 목록을 포함합니다.
     *
     * @param  array  $items  TempOrder의 items 배열
     * @param  array  $calculationItems  계산 결과의 items 배열 (product_option_id로 매칭)
     * @param  array  $productCoupons  상품별 적용 가능한 쿠폰 배열 (product_id => coupons)
     * @param  array  $selectedItemCoupons  현재 선택된 상품 쿠폰 (product_option_id => [발급ID, ...]) — per_user_limit 중복 비활성화 계산용
     * @return array 변환된 아이템 배열
     */
    public static function collectionFromArray(array $items, array $calculationItems = [], array $productCoupons = [], array $selectedItemCoupons = []): array
    {
        if (empty($items)) {
            return [];
        }

        // 발급ID => coupon 메타(coupon_id, per_user_limit) 맵 — 라인 간 per_user_limit 중복 판정용
        $couponMetaByIssueId = [];
        foreach ($productCoupons as $coupons) {
            foreach ($coupons as $coupon) {
                $issueId = $coupon['id'] ?? null;
                if ($issueId !== null) {
                    $couponMetaByIssueId[(int) $issueId] = [
                        'coupon_id' => (int) ($coupon['coupon_id'] ?? 0),
                        'per_user_limit' => (int) ($coupon['per_user_limit'] ?? 0),
                    ];
                }
            }
        }

        // 현재 선택을 coupon_id 별로, 어느 옵션 라인에서 몇 건 선택됐는지 집계
        // [coupon_id => [optionId => 선택수]]
        $selectionByCouponId = [];
        foreach ($selectedItemCoupons as $optionId => $issueIds) {
            foreach ((array) $issueIds as $issueId) {
                $meta = $couponMetaByIssueId[(int) $issueId] ?? null;
                if ($meta === null || $meta['coupon_id'] === 0) {
                    continue;
                }
                $cid = $meta['coupon_id'];
                $selectionByCouponId[$cid][(int) $optionId] = ($selectionByCouponId[$cid][(int) $optionId] ?? 0) + 1;
            }
        }

        // ID 추출
        $productIds = array_unique(array_column($items, 'product_id'));
        $optionIds = array_unique(array_column($items, 'product_option_id'));

        // 한번에 조회 (with images for thumbnail, 추가옵션 표시용 선택지, 배송정책 국가설정 포함)
        $products = Product::whereIn('id', $productIds)
            ->with(['images', 'additionalOptions.values', 'shippingPolicy.countrySettings'])
            ->get()
            ->keyBy('id');

        $options = ProductOption::whereIn('id', $optionIds)
            ->get()
            ->keyBy('id');

        // calculationItems를 product_option_id로 인덱싱
        $calculationByOptionId = [];
        foreach ($calculationItems as $calcItem) {
            $optionId = $calcItem['product_option_id'] ?? null;
            if ($optionId !== null) {
                $calculationByOptionId[$optionId] = $calcItem;
            }
        }

        // 각 아이템 변환
        $result = [];
        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $option = $options->get($item['product_option_id']);
            $calculation = $calculationByOptionId[$item['product_option_id']] ?? null;
            $coupons = $productCoupons[$item['product_id']] ?? [];
            $currentOptionId = (int) ($item['product_option_id'] ?? 0);

            // 이 라인에서 비활성화할 쿠폰 발급ID 목록 (per_user_limit 중복 — U13b/MP06)
            // 다른 옵션 라인에서 이미 선택된 per_user_limit 쿠폰과 같은 coupon_id 이고,
            // (다른 라인 선택수) >= per_user_limit 이면 이 라인에서 더 적용할 수 없다.
            $disabledCouponIds = [];
            foreach ($coupons as $coupon) {
                $limit = (int) ($coupon['per_user_limit'] ?? 0);
                if ($limit <= 0) {
                    continue; // 무제한 쿠폰은 비활성화 대상 아님
                }
                $cid = (int) ($coupon['coupon_id'] ?? 0);
                $byOption = $selectionByCouponId[$cid] ?? [];
                // 이 라인을 제외한 다른 라인들의 선택 누적
                $otherLinesUsed = 0;
                foreach ($byOption as $optId => $cnt) {
                    if ($optId !== $currentOptionId) {
                        $otherLinesUsed += $cnt;
                    }
                }
                if ($otherLinesUsed >= $limit) {
                    $disabledCouponIds[] = (int) $coupon['id'];
                }
            }

            $resource = new static([
                'item' => $item,
                'product' => $product,
                'product_option' => $option,
                'calculation' => $calculation,
                'available_coupons' => $coupons,
                'disabled_coupon_ids' => $disabledCouponIds,
            ]);

            $result[] = $resource->toArray(request());
        }

        return $result;
    }

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 체크아웃 아이템 정보
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource['item'] ?? [];
        $product = $this->resource['product'] ?? null;
        $productOption = $this->resource['product_option'] ?? null;
        $calculation = $this->resource['calculation'] ?? null;
        $availableCoupons = $this->resource['available_coupons'] ?? [];

        // 선택된 추가옵션 해석 (장바구니와 동일 표시 계약 — group_name/name/price_adjustment)
        $additionalOptions = $this->resolveAdditionalOptions($product, $item['additional_option_selections'] ?? []);
        $additionalOptionsUnitTotal = array_sum(array_column($additionalOptions, 'price_adjustment'));

        $result = [
            'id' => $item['cart_id'] ?? null,
            'product_id' => $item['product_id'] ?? null,
            'product_option_id' => $item['product_option_id'] ?? null,
            'quantity' => $item['quantity'] ?? 0,
            'additional_options' => $additionalOptions,
            'additional_options_total' => $additionalOptionsUnitTotal,

            // 상품 정보
            'product' => $product ? $this->formatProductInfo($product) : null,

            // 옵션 정보
            'product_option' => $productOption ? $this->formatOptionInfo($productOption) : null,

            // 선택된 배송국가로 배송 가능한지 (D1 — layer 2, 체크아웃 표시/주문하기 차단 플래그)
            'is_shippable_to_selected_country' => $product
                ? $this->isShippableToSelectedCountry($product)
                : false,
        ];

        // 계산된 값 (옵션이 있는 경우에만)
        // 안B: 표시 소계 = (원옵션가 + 추가옵션 단위 합계) × 수량 (D6) — 장바구니와 동일
        if ($productOption) {
            $sellingPrice = $productOption->getSellingPrice() + $additionalOptionsUnitTotal;
            $quantity = $item['quantity'] ?? 0;
            $subtotalInfo = $this->formatSubtotalInfo($sellingPrice, $quantity);

            $result['subtotal'] = $subtotalInfo['subtotal'];
            $result['subtotal_formatted'] = $subtotalInfo['subtotal_formatted'];
            $result['multi_currency_subtotal'] = $subtotalInfo['multi_currency_subtotal'];
        }

        // calculation 데이터 병합 (할인, 최종 금액 등)
        if ($calculation !== null) {
            $result = array_merge($result, $this->formatCalculationInfo($calculation));
        }

        // 상품별 적용 가능한 쿠폰 목록
        $result['available_coupons'] = $availableCoupons;

        // 이 라인에서 비활성화할 쿠폰 발급ID 목록 (per_user_limit 다른 라인 중복 — U13b/MP06)
        $result['disabled_coupon_ids'] = $this->resource['disabled_coupon_ids'] ?? [];

        return $result;
    }

    /**
     * calculation 데이터에서 금액 정보를 포맷
     *
     * @param  array  $calculation  계산 결과 배열
     * @return array 포맷된 금액 정보
     */
    protected function formatCalculationInfo(array $calculation): array
    {
        $result = [
            // 단가 정보
            'unit_price' => $this->roundToBaseCurrency($calculation['unit_price'] ?? 0),
            'unit_price_formatted' => $this->formatCurrencyPrice($calculation['unit_price'] ?? 0, $this->getDefaultCurrencyCode()),

            // 할인 정보
            'product_coupon_discount_amount' => $this->roundToBaseCurrency($calculation['product_coupon_discount_amount'] ?? 0),
            'product_coupon_discount_formatted' => $this->formatCurrencyPrice($calculation['product_coupon_discount_amount'] ?? 0, $this->getDefaultCurrencyCode()),
            'code_discount_amount' => $this->roundToBaseCurrency($calculation['code_discount_amount'] ?? 0),
            'code_discount_formatted' => $this->formatCurrencyPrice($calculation['code_discount_amount'] ?? 0, $this->getDefaultCurrencyCode()),
            'order_coupon_discount_share' => $this->roundToBaseCurrency($calculation['order_coupon_discount_share'] ?? 0),
            'order_coupon_discount_share_formatted' => $this->formatCurrencyPrice($calculation['order_coupon_discount_share'] ?? 0, $this->getDefaultCurrencyCode()),
            'total_discount' => $this->roundToBaseCurrency($calculation['total_discount'] ?? 0),
            'total_discount_formatted' => $this->formatCurrencyPrice($calculation['total_discount'] ?? 0, $this->getDefaultCurrencyCode()),

            // 마일리지 정보
            'points_used_share' => $this->roundToBaseCurrency($calculation['points_used_share'] ?? 0),
            'points_used_share_formatted' => $this->formatCurrencyPrice($calculation['points_used_share'] ?? 0, $this->getDefaultCurrencyCode()),
            'points_earning' => $calculation['points_earning'] ?? 0,

            // 최종 금액
            'final_amount' => $this->roundToBaseCurrency($calculation['final_amount'] ?? 0),
            'final_amount_formatted' => $this->formatCurrencyPrice($calculation['final_amount'] ?? 0, $this->getDefaultCurrencyCode()),
        ];

        // 다중 통화 정보 (있는 경우)
        if (isset($calculation['multi_currency'])) {
            $multiCurrency = $calculation['multi_currency'];

            // 통화별로 금액 필드 추출 (통화코드가 키인 구조로 변환)
            $result['multi_currency_unit_price'] = $this->extractMultiCurrencyField($multiCurrency, 'unit_price');
            $result['multi_currency_product_coupon_discount'] = $this->extractMultiCurrencyField($multiCurrency, 'product_coupon_discount_amount');
            $result['multi_currency_total_discount'] = $this->extractMultiCurrencyField($multiCurrency, 'total_discount');
            $result['multi_currency_final_amount'] = $this->extractMultiCurrencyField($multiCurrency, 'final_amount');
        }

        return $result;
    }

    /**
     * 다중 통화 데이터에서 특정 필드 추출
     *
     * multi_currency 구조: ['KRW' => ['final_amount' => 94000, 'final_amount_formatted' => '94,000원'], ...]
     * 결과 구조: ['KRW' => ['amount' => 94000, 'formatted' => '94,000원'], ...]
     *
     * @param  array  $multiCurrency  다중 통화 데이터
     * @param  string  $field  추출할 필드명
     * @return array 통화코드별 금액/포맷 배열
     */
    protected function extractMultiCurrencyField(array $multiCurrency, string $field): array
    {
        $result = [];

        foreach ($multiCurrency as $currencyCode => $amounts) {
            // _meta 키는 건너뛰기
            if ($currencyCode === '_meta' || ! is_array($amounts)) {
                continue;
            }

            $result[$currencyCode] = [
                'amount' => $amounts[$field] ?? 0,
                'formatted' => $amounts[$field.'_formatted'] ?? '',
            ];
        }

        return $result;
    }
}
