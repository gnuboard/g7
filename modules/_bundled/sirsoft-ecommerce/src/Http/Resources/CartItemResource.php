<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Request;

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
     * @param Request $request 요청
     * @return array 장바구니 아이템 정보
     */
    public function toArray(Request $request): array
    {
        $product = $this->relationLoaded('product') ? $this->product : null;
        $productOption = $this->relationLoaded('productOption') ? $this->productOption : null;

        $result = [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_option_id' => $this->product_option_id,
            'quantity' => $this->quantity,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // 상품 정보
            'product' => $product ? $this->formatProductInfo($product) : null,

            // 옵션 정보
            'product_option' => $productOption ? $this->formatOptionInfo($productOption) : null,
        ];

        // 계산된 값 (옵션이 로드된 경우에만)
        if ($productOption) {
            $sellingPrice = $productOption->getSellingPrice();
            $subtotalInfo = $this->formatSubtotalInfo($sellingPrice, $this->quantity);

            $result['subtotal'] = $subtotalInfo['subtotal'];
            $result['subtotal_formatted'] = $subtotalInfo['subtotal_formatted'];
            $result['multi_currency_subtotal'] = $subtotalInfo['multi_currency_subtotal'];
        }

        return $result;
    }
}
