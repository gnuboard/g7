<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Http\Middleware\ResolveShippingCountry;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 장바구니 아이템 리소스
 *
 * Cart 모델을 기반으로 주문 아이템 정보를 반환합니다.
 */
class CartItemResource extends BaseOrderItemResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 장바구니 아이템 정보
     */
    public function toArray(Request $request): array
    {
        $product = $this->relationLoaded('product') ? $this->product : null;
        $productOption = $this->relationLoaded('productOption') ? $this->productOption : null;

        $additionalOptions = $this->resolveAdditionalOptions($product, $this->additional_option_selections ?? []);
        $additionalOptionsUnitTotal = array_sum(array_column($additionalOptions, 'price_adjustment'));

        $result = [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_option_id' => $this->product_option_id,
            'quantity' => $this->quantity,
            'additional_options' => $additionalOptions,
            'additional_options_total' => $additionalOptionsUnitTotal,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 상품 정보
            'product' => $product ? $this->formatProductInfo($product) : null,

            // 옵션 정보
            'product_option' => $productOption ? $this->formatOptionInfo($productOption) : null,

            // 판매 가능 여부 플래그 (U13②/U4 — 비정상 항목은 합계 제외되며 프론트가 구분 표시)
            'available' => $product ? $product->isPurchasable() : false,
            'unavailable_reason' => $product ? $this->resolveUnavailableReason($product) : null,

            // 선택된 배송국가로 배송 가능한지 (D1 — layer 1, 카트 표시/주문 차단 플래그)
            'is_shippable_to_selected_country' => $product
                ? $this->isShippableToSelectedCountry($product)
                : false,
            'selected_shipping_country' => ResolveShippingCountry::getCountry(),
        ];

        // 계산된 값 (옵션이 로드된 경우에만)
        // 안B: 표시 소계 = (원옵션가 + 추가옵션 단위 합계) × 수량 (D6)
        if ($productOption) {
            $sellingPrice = $productOption->getSellingPrice() + $additionalOptionsUnitTotal;
            $subtotalInfo = $this->formatSubtotalInfo($sellingPrice, $this->quantity);

            $result['subtotal'] = $subtotalInfo['subtotal'];
            $result['subtotal_formatted'] = $subtotalInfo['subtotal_formatted'];
            $result['multi_currency_subtotal'] = $subtotalInfo['multi_currency_subtotal'];
        }

        return $result;
    }

    /**
     * 구매 불가 사유를 반환합니다.
     *
     * 판매중이 아니면 판매상태 값(suspended/sold_out/coming_soon), 판매중이지만
     * 전시중지(hidden)면 전시상태 값(hidden), 구매 가능하면 null 을 반환합니다.
     *
     * @param  Product  $product  상품 모델
     * @return string|null 구매 불가 사유 (구매 가능 시 null)
     */
    protected function resolveUnavailableReason($product): ?string
    {
        if ($product->isPurchasable()) {
            return null;
        }

        if ($product->sales_status !== ProductSalesStatus::ON_SALE) {
            return $product->sales_status->value;
        }

        return $product->display_status->value;
    }
}
