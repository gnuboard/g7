<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Modules\Sirsoft\Ecommerce\Http\Middleware\ResolveShippingCountry;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyResolver;

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
     * @param  Product  $product  상품 모델
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
     * 상품이 현재 선택된 배송국가로 배송 가능한지 판정합니다. (D1)
     *
     * 상품에 배송정책이 부여되어 있으면 그 정책을, 없으면(shipping_policy_id=null)
     * 기본 배송정책으로 폴백해 해당 국가 설정 유무로 판정한다. 적용 가능한 정책이
     * 전혀 없을 때만 국내(KR) 기본 배송으로 간주. 미들웨어가 결정한
     * 배송국가(ResolveShippingCountry::getCountry)를 기준으로 한다.
     *
     * @param  Product  $product  상품 모델
     * @return bool 배송 가능 여부
     */
    protected function isShippableToSelectedCountry(Product $product): bool
    {
        $country = ResolveShippingCountry::getCountry();

        return app(ShippingPolicyResolver::class)->isShippableToCountry($product, $country);
    }

    /**
     * 옵션 정보를 배열로 변환
     *
     * @param  ProductOption  $option  상품 옵션 모델
     * @return array 옵션 정보 배열
     */
    protected function formatOptionInfo(ProductOption $option): array
    {
        $listPrice = $option->getListPrice();        // 정가 (product->list_price + price_adjustment)
        $sellingPrice = $option->getSellingPrice();  // 판매가 (product->selling_price + price_adjustment)
        $defaultCurrency = $this->getDefaultCurrencyCode();

        return [
            'id' => $option->id,
            'option_code' => $option->option_code,
            'option_name' => $option->option_name,
            'option_name_localized' => $option->getLocalizedOptionName(),
            'option_values' => $option->option_values,
            'option_values_localized' => $option->getLocalizedOptionValues(),

            // 정가 (U5·U4② — ProductOptionResource 와 동일 키. 베이스에 추가 → Cart·Checkout 양쪽 자동)
            'list_price' => $this->roundToCurrency($listPrice, $defaultCurrency),
            'list_price_formatted' => $this->formatCurrencyPrice($listPrice, $defaultCurrency),
            'multi_currency_list_price' => $this->buildMultiCurrencyPrices($listPrice),

            // 판매가 (기존)
            'selling_price' => $this->roundToCurrency($sellingPrice, $defaultCurrency),
            'selling_price_formatted' => $this->formatCurrencyPrice($sellingPrice, $defaultCurrency),
            'multi_currency_selling_price' => $this->buildMultiCurrencyPrices($sellingPrice),

            // 할인율 (U5·U4② — 옵션 레벨 정가 대비. list_price<=0 가드)
            'discount_rate' => $listPrice > 0
                ? round((1 - $sellingPrice / $listPrice) * 100, 1)
                : 0,

            'stock_quantity' => $option->stock_quantity,
            'is_active' => $option->is_active,
        ];
    }

    /**
     * 소계 정보를 배열로 변환
     *
     * @param  int  $sellingPrice  판매가
     * @param  int  $quantity  수량
     * @return array 소계 정보 배열
     */
    protected function formatSubtotalInfo(int $sellingPrice, int $quantity): array
    {
        $subtotal = $sellingPrice * $quantity;

        return [
            'subtotal' => $this->roundToBaseCurrency($subtotal),
            'subtotal_formatted' => $this->formatCurrencyPrice($subtotal, $this->getDefaultCurrencyCode()),
            'multi_currency_subtotal' => $this->buildMultiCurrencyPrices($subtotal),
        ];
    }

    /**
     * 선택된 추가옵션(value_id)을 표시용으로 해석합니다.
     *
     * 저장된 selection(value_id) 을 로드된 상품의 활성 선택지와 매칭하여
     * 그룹명·선택지명·추가금을 노출합니다. 가격은 항상 서버 DB 값을 사용합니다(클라 신뢰 금지).
     * Cart/Checkout 양 리소스가 동일 표시 계약을 공유합니다.
     *
     * @param  Product|null  $product  로드된 상품 모델 (additionalOptions.values eager-load 필요)
     * @param  array<int, array{additional_option_id?: int, value_id?: int, custom_text?: string}>  $selections  선택된 추가옵션 배열
     * @return array<int, array{additional_option_id: int, value_id: int, group_name: string, name: string, price_adjustment: int, custom_text: string}>
     */
    protected function resolveAdditionalOptions(?Product $product, array $selections): array
    {
        if (empty($selections) || ! $product || ! $product->relationLoaded('additionalOptions')) {
            return [];
        }

        // value_id => value, group_id => group 매핑 구성
        $valueMap = [];
        $groupMap = [];
        foreach ($product->additionalOptions as $group) {
            $groupMap[$group->id] = $group;
            if ($group->relationLoaded('values')) {
                foreach ($group->values as $value) {
                    $valueMap[$value->id] = $value;
                }
            }
        }

        $resolved = [];
        foreach ($selections as $selection) {
            $valueId = (int) ($selection['value_id'] ?? 0);
            $value = $valueMap[$valueId] ?? null;

            if (! $value || ! $value->is_active) {
                continue;
            }

            $group = $groupMap[$value->additional_option_id] ?? null;

            // 직접입력 텍스트: allow_custom_text 선택지에 한해 선택값에서 추출 (E3)
            $customText = '';
            if ($value->allow_custom_text) {
                $customText = trim((string) ($selection['custom_text'] ?? ''));
            }

            $priceAdjustment = $value->getPriceAdjustment();

            $resolved[] = [
                'additional_option_id' => (int) $value->additional_option_id,
                'value_id' => (int) $value->id,
                'group_name' => $group ? $group->getLocalizedName() : '',
                'name' => $value->getLocalizedName(),
                'price_adjustment' => $this->roundToBaseCurrency($priceAdjustment),
                // 추가금 표시 문자열 — 통화 기호를 하드코딩하지 않고 기본 통화 기호로 포맷 (레이아웃의 '+N원' 하드코딩 대체)
                'price_adjustment_formatted' => ($priceAdjustment >= 0 ? '+' : '-')
                    .$this->formatCurrencyPrice(abs($priceAdjustment), $this->getDefaultCurrencyCode()),
                'custom_text' => $customText,
            ];
        }

        return $resolved;
    }
}
