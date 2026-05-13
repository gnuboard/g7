<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 상품 옵션 리소스
 */
class ProductOptionResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     */
    public function toArray(Request $request): array
    {
        $listPrice = $this->getListPrice();
        $sellingPrice = $this->getSellingPrice();

        return [
            'id' => $this->id,
            'option_code' => $this->option_code,

            // 옵션값 (다국어)
            'option_values' => $this->option_values,
            'option_values_localized' => $this->resource->getLocalizedOptionValues(),

            // 옵션명 (다국어)
            'option_name' => $this->option_name,
            'option_name_localized' => $this->resource->getLocalizedOptionName(),

            'price_adjustment' => $this->price_adjustment,
            'price_adjustment_formatted' => ($this->price_adjustment >= 0 ? '+' : '').$this->formatCurrencyPrice(abs($this->price_adjustment), $this->getDefaultCurrencyCode()),
            'price_adjustment_type' => $this->price_adjustment >= 0 ? 'increase' : 'decrease',

            // 정가/판매가 분리
            'list_price' => $listPrice,
            'list_price_formatted' => $this->formatCurrencyPrice($listPrice, $this->getDefaultCurrencyCode()),
            'selling_price' => $sellingPrice,
            'selling_price_formatted' => $this->formatCurrencyPrice($sellingPrice, $this->getDefaultCurrencyCode()),

            // 기존 호환성 유지
            'final_price' => $sellingPrice,
            'final_price_formatted' => $this->formatCurrencyPrice($sellingPrice, $this->getDefaultCurrencyCode()),

            // 다중 통화 가격
            'multi_currency_list_price' => $this->buildMultiCurrencyPrices($listPrice),
            'multi_currency_selling_price' => $this->buildMultiCurrencyPrices($sellingPrice),

            // 재고
            'stock_quantity' => $this->stock_quantity,
            'safe_stock_quantity' => $this->safe_stock_quantity,
            'is_below_safe_stock' => $this->isBelowSafeStock(),

            // 상태
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sku' => $this->sku,
            'sort_order' => $this->sort_order,
        ];
    }
}
