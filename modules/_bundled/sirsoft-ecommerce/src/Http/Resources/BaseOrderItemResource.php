<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 주문 아이템 기본 리소스 (공통 구조)
 *
 * CartItemResource와 CheckoutItemResource의 공통 출력 구조를 정의합니다.
 */
abstract class BaseOrderItemResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 상품 정보를 배열로 변환
     *
     * @param Product $product 상품 모델
     * @return array 상품 정보 배열
     */
    protected function formatProductInfo(Product $product): array
    {
        // eager loaded images에서 썸네일 URL 추출
        $thumbnailImage = $product->relationLoaded('images')
            ? ($product->images->firstWhere('is_thumbnail', true) ?? $product->images->first())
            : null;

        return [
            'id' => $product->id,
            'name' => $product->getLocalizedName(),
            'product_code' => $product->product_code,
            'thumbnail_url' => $thumbnailImage?->download_url,
            'sales_status' => $product->sales_status?->value,
            'display_status' => $product->display_status?->value,
        ];
    }

    /**
     * 옵션 정보를 배열로 변환
     *
     * @param ProductOption $option 상품 옵션 모델
     * @return array 옵션 정보 배열
     */
    protected function formatOptionInfo(ProductOption $option): array
    {
        $sellingPrice = $option->getSellingPrice();

        return [
            'id' => $option->id,
            'option_code' => $option->option_code,
            'option_name' => $option->option_name,
            'option_name_localized' => $option->getLocalizedOptionName(),
            'option_values' => $option->option_values,
            'option_values_localized' => $option->getLocalizedOptionValues(),
            'selling_price' => $sellingPrice,
            'selling_price_formatted' => $this->formatCurrencyPrice($sellingPrice, $this->getDefaultCurrencyCode()),
            'multi_currency_selling_price' => $this->buildMultiCurrencyPrices($sellingPrice),
            'stock_quantity' => $option->stock_quantity,
            'is_active' => $option->is_active,
        ];
    }

    /**
     * 소계 정보를 배열로 변환
     *
     * @param int $sellingPrice 판매가
     * @param int $quantity 수량
     * @return array 소계 정보 배열
     */
    protected function formatSubtotalInfo(int $sellingPrice, int $quantity): array
    {
        $subtotal = $sellingPrice * $quantity;

        return [
            'subtotal' => $subtotal,
            'subtotal_formatted' => $this->formatCurrencyPrice($subtotal, $this->getDefaultCurrencyCode()),
            'multi_currency_subtotal' => $this->buildMultiCurrencyPrices($subtotal),
        ];
    }
}
