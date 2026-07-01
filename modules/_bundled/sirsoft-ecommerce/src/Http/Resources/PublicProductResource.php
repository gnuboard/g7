<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Http\Middleware\ResolveShippingCountry;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyResolver;

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
     * @return array 공개 상품 리소스 배열 (배송 가능 여부 포함)
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
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'name_localized' => $cat->getLocalizedName(),
                'breadcrumb' => $cat->getLocalizedBreadcrumbString(),
                'path' => $cat->path,
                'is_primary' => $cat->pivot->is_primary,
            ])),
            'category_name' => $this->whenLoaded(
                'categories',
                fn () => $this->categories->firstWhere('pivot.is_primary', true)?->getLocalizedName()
            ),

            // 가격
            'list_price' => $this->roundToBaseCurrency($this->list_price),
            'list_price_formatted' => $this->formatBaseCurrency($this->list_price),
            'selling_price' => $this->roundToBaseCurrency($this->selling_price),
            'selling_price_formatted' => $this->formatBaseCurrency($this->selling_price),
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
            'brand_name' => $this->whenLoaded('brand', fn () => $this->brand?->getLocalizedName()),

            // 라벨
            'labels' => $this->whenLoaded('activeLabelAssignments', fn () => $this->activeLabelAssignments->map(fn ($a) => [
                'name' => $a->label?->getLocalizedName(),
                'color' => $a->label?->color,
            ])->filter(fn ($l) => $l['name'])->values()),

            // 추가옵션 (그룹 + 선택지)
            'additional_options' => $this->whenLoaded('additionalOptions', fn () => $this->additionalOptions->sortBy('sort_order')->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->getLocalizedName(),
                'is_required' => $o->is_required,
                'values' => ($o->relationLoaded('activeValues') ? $o->activeValues : $o->values->where('is_active', true))
                    ->sortBy('sort_order')
                    ->map(fn ($v) => [
                        'id' => $v->id,
                        'name' => $v->getLocalizedName(),
                        'price_adjustment' => $this->roundToBaseCurrency($v->getPriceAdjustment()),
                        // 추가금 표시 문자열 — 통화 기호 하드코딩 없이 기본 통화 기호로 포맷 (옵션 선택 UI의 '+N원' 대체)
                        'price_adjustment_formatted' => ($v->getPriceAdjustment() >= 0 ? '+' : '-')
                            .$this->formatCurrencyPrice(abs($v->getPriceAdjustment()), $this->getDefaultCurrencyCode()),
                        'is_default' => $v->is_default,
                        // 직접입력 허용 — 유저단이 이 선택지 선택 시 텍스트 입력칸 노출 판정
                        'allow_custom_text' => $v->allow_custom_text,
                    ])->values(),
            ])->values()),

            // 배송
            'shipping_policy_id' => $this->shipping_policy_id,
            // 선택된 배송국가로 배송 가능한지 + 해당 국가 배송비 요약 (B8 — 상품상세 사전 표시)
            // 상품에 정책이 없으면(shipping_policy_id=null) 기본 배송정책으로 폴백해 표시한다.
            ...$this->buildShippingDisplay(),

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
            'notice' => $this->whenLoaded('notice', fn () => $this->notice ? [
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
            'common_info' => $this->whenLoaded('commonInfo', fn () => $this->commonInfo ? [
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
     */
    private function resolveLocalizedField(?array $field): ?string
    {
        if (empty($field)) {
            return null;
        }

        $locale = app()->getLocale();

        return $field[$locale] ?? $field[config('app.fallback_locale', 'ko')] ?? $field[array_key_first($field)] ?? null;
    }

    /**
     * 배송 표시 필드를 구성합니다 (배송가능 여부 + 배송비 요약 + 정책 상세).
     *
     * 상품에 정책이 부여되어 있으면 그 정책을, 없으면(shipping_policy_id=null)
     * 기본 배송정책으로 폴백해 표시한다. 적용 가능한 정책이 전혀 없을 때만
     * 국내(KR) 기본 배송으로 간주한다.
     *
     * @return array 배송 표시 필드 (is_shippable_to_selected_country 등)
     */
    private function buildShippingDisplay(): array
    {
        $country = ResolveShippingCountry::getCountry();
        $resolver = app(ShippingPolicyResolver::class);

        /** @var Product $product */
        $product = $this->resource;
        $policy = $resolver->resolveForProduct($product);

        return [
            'is_shippable_to_selected_country' => $policy !== null
                ? $policy->getCountrySetting($country) !== null
                : ($country === 'KR'),
            'selected_shipping_country' => $country,
            'free_shipping' => $policy?->charge_policy === ChargePolicyEnum::FREE,
            'shipping_fee_formatted' => $policy?->getFeeSummary() ?? '',
            'shipping_policy' => $policy ? [
                'name' => $policy->getLocalizedName(),
                'charge_policy' => $policy->charge_policy?->value,
                'charge_policy_label' => $policy->charge_policy?->label(),
                'base_fee' => $this->roundToBaseCurrency($policy->base_fee),
                'base_fee_formatted' => $this->formatBaseCurrency($policy->base_fee),
                'free_threshold' => $policy->free_threshold !== null
                    ? $this->roundToBaseCurrency($policy->free_threshold) : null,
                'free_threshold_formatted' => $policy->free_threshold
                    ? $this->formatBaseCurrency($policy->free_threshold) : null,
                'fee_summary' => $policy->getFeeSummary(),
            ] : null,
        ];
    }
}
