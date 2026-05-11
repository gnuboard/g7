<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;

/**
 * 공개 상품 상세 리소스
 *
 * 사용자용 상품 상세 페이지(show)에서 사용하는 리소스입니다.
 * Admin용 ProductResource와 분리하여 프론트엔드 레이아웃이 기대하는 필드를 반환합니다.
 */
class PublicProductResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_localized' => $this->getLocalizedName(),
            'product_code' => $this->product_code,
            'sku' => $this->sku,

            // 카테고리
            'categories' => $this->whenLoaded('categories', fn() => $this->categories->map(fn($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'name_localized' => $cat->getLocalizedName(),
                'breadcrumb' => $cat->getLocalizedBreadcrumbString(),
                'path' => $cat->path,
                'is_primary' => $cat->pivot->is_primary,
            ])),
            'category_name' => $this->whenLoaded(
                'categories',
                fn() => $this->categories->firstWhere('pivot.is_primary', true)?->getLocalizedName()
            ),

            // 가격
            'list_price' => $this->list_price,
            'list_price_formatted' => number_format($this->list_price) . '원',
            'selling_price' => $this->selling_price,
            'selling_price_formatted' => number_format($this->selling_price) . '원',
            'discount_rate' => $this->getDiscountRate(),

            // 다중 통화 가격
            'multi_currency_list_price' => $this->buildMultiCurrencyPrices($this->list_price),
            'multi_currency_selling_price' => $this->buildMultiCurrencyPrices($this->selling_price),

            // 재고
            'stock_quantity' => $this->stock_quantity,

            // 수량 제한
            'min_purchase_qty' => $this->min_purchase_qty,
            'max_purchase_qty' => $this->max_purchase_qty,

            // 상태
            'sales_status' => $this->sales_status->value,
            'sales_status_label' => $this->sales_status->label(),

            // 브랜드
            'brand_name' => $this->whenLoaded('brand', fn() => $this->brand?->getLocalizedName()),

            // 라벨
            'labels' => $this->whenLoaded('activeLabelAssignments', fn() => $this->activeLabelAssignments->map(fn($a) => [
                'name' => $a->label?->getLocalizedName(),
                'color' => $a->label?->color,
            ])->filter(fn($l) => $l['name'])->values()),

            // 추가옵션
            'additional_options' => $this->whenLoaded('additionalOptions', fn() => $this->additionalOptions->sortBy('sort_order')->map(fn($o) => [
                'id' => $o->id,
                'name' => $o->getLocalizedName(),
                'is_required' => $o->is_required,
            ])->values()),

            // 배송
            'shipping_policy_id' => $this->shipping_policy_id,
            'free_shipping' => $this->whenLoaded('shippingPolicy', fn() => $this->shippingPolicy?->charge_policy === ChargePolicyEnum::FREE, false),
            'shipping_fee_formatted' => $this->whenLoaded('shippingPolicy', fn() => $this->shippingPolicy?->getFeeSummary() ?? '', ''),
            'shipping_policy' => $this->whenLoaded('shippingPolicy', fn() => $this->shippingPolicy ? [
                'name' => $this->shippingPolicy->getLocalizedName(),
                'charge_policy' => $this->shippingPolicy->charge_policy?->value,
                'charge_policy_label' => $this->shippingPolicy->charge_policy?->label(),
                'base_fee' => $this->shippingPolicy->base_fee,
                'base_fee_formatted' => number_format($this->shippingPolicy->base_fee) . '원',
                'free_threshold' => $this->shippingPolicy->free_threshold,
                'free_threshold_formatted' => $this->shippingPolicy->free_threshold
                    ? number_format($this->shippingPolicy->free_threshold) . '원' : null,
                'fee_summary' => $this->shippingPolicy->getFeeSummary(),
            ] : null),

            // 설명 (다국어)
            'short_description' => $this->short_description,
            'short_description_localized' => $this->resolveLocalizedField($this->short_description),
            'description' => $this->description,
            'description_localized' => $this->getLocalizedDescription(),
            'description_mode' => $this->description_mode,

            // 이미지
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'thumbnail_url' => $this->getThumbnailUrl(),

            // SEO
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,

            // 옵션
            'has_options' => $this->has_options,
            'option_groups' => $this->resource->getOptionGroupsForApi(),
            'options' => ProductOptionResource::collection($this->whenLoaded('activeOptions')),

            // 상품정보제공고시
            'notice' => $this->whenLoaded('notice', fn() => $this->notice ? [
                'template_name' => $this->notice->relationLoaded('template')
                    ? $this->notice->template?->getLocalizedName()
                    : null,
                'values' => collect($this->notice->values ?? [])->map(function ($item) {
                    $locale = app()->getLocale();
                    $fallback = config('app.fallback_locale', 'ko');

                    $name = is_array($item['name'] ?? null)
                        ? ($item['name'][$locale] ?? $item['name'][$fallback] ?? '')
                        : ($item['name'] ?? '');
                    $content = is_array($item['content'] ?? null)
                        ? ($item['content'][$locale] ?? $item['content'][$fallback] ?? '')
                        : ($item['content'] ?? '');

                    return ['label' => $name, 'value' => $content];
                })->values()->all(),
            ] : null),

            // 공통정보
            'common_info' => $this->whenLoaded('commonInfo', fn() => $this->commonInfo ? [
                'name' => $this->commonInfo->getLocalizedName(),
                'content' => $this->commonInfo->getLocalizedContent(),
                'content_mode' => $this->commonInfo->content_mode ?? 'text',
            ] : null),

            // 찜 여부 (currentUserWishlist 관계 eager load 활용)
            'is_wishlisted' => Auth::check()
                ? $this->relationLoaded('currentUserWishlist')
                ? $this->currentUserWishlist->isNotEmpty()
                : ProductWishlist::where('user_id', Auth::id())->where('product_id', $this->id)->exists()
                : false,
        ];
    }

    /**
     * 다국어 JSON 필드에서 현재 로케일 값을 반환합니다.
     *
     * @param  array|null  $field  다국어 JSON 필드
     * @return string|null
     */
    private function resolveLocalizedField(?array $field): ?string
    {
        if (empty($field)) {
            return null;
        }

        $locale = app()->getLocale();

        return $field[$locale] ?? $field[config('app.fallback_locale', 'ko')] ?? $field[array_key_first($field)] ?? null;
    }
}
