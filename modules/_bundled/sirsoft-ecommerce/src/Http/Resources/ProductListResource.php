<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 상품 목록 리소스
 */
class ProductListResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_localized' => $this->getLocalizedName(),
            'product_code' => $this->product_code,
            'sku' => $this->sku,
            'thumbnail_url' => $this->getThumbnailUrl(),

            // 가격
            'list_price' => $this->list_price,
            'list_price_formatted' => number_format($this->list_price).'원',
            'selling_price' => $this->selling_price,
            'selling_price_formatted' => number_format($this->selling_price).'원',
            'discount_rate' => $this->getDiscountRate(),

            // 다중 통화 가격
            'multi_currency_list_price' => $this->buildMultiCurrencyPrices($this->list_price),
            'multi_currency_selling_price' => $this->buildMultiCurrencyPrices($this->selling_price),

            // 재고
            'stock_quantity' => $this->stock_quantity,
            'safe_stock_quantity' => $this->safe_stock_quantity,
            'is_below_safe_stock' => $this->isBelowSafeStock(),
            'option_stock_sum' => $this->relationLoaded('options')
                ? $this->options->where('is_active', true)->sum('stock_quantity')
                : $this->whenLoaded('activeOptions', fn () => $this->activeOptions->sum('stock_quantity')),

            // 상태
            'sales_status' => $this->sales_status->value,
            'sales_status_label' => $this->sales_status->label(),
            'sales_status_variant' => $this->sales_status->variant(),
            'display_status' => $this->display_status->value,
            'display_status_label' => $this->display_status->label(),
            'display_status_variant' => $this->display_status->variant(),

            // 카테고리 (다대다)
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->getLocalizedName(),
                'is_primary' => $cat->pivot->is_primary,
            ])),
            'primary_category' => $this->whenLoaded('categories', fn () => $this->categories->firstWhere('pivot.is_primary', true)?->getLocalizedName()
            ),
            'categories_with_path' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($cat) => [
                'id' => $cat->id,
                'path' => $cat->getBreadcrumb(),
                'path_string' => collect($cat->getBreadcrumb())->pluck('name')->implode(' > '),
                'is_primary' => $cat->pivot->is_primary,
            ])),

            // 브랜드 (다국어)
            'brand_name' => $this->whenLoaded('brand', fn () => $this->brand?->getLocalizedName()),

            // 배송 정책
            'shipping_policy_id' => $this->shipping_policy_id,
            'shipping_policy_name' => $this->whenLoaded('shippingPolicy', fn () => $this->shippingPolicy?->getLocalizedName()),

            // 구매 수량 제한
            'min_purchase_qty' => $this->min_purchase_qty,
            'max_purchase_qty' => $this->max_purchase_qty,

            // 옵션
            'has_options' => $this->has_options,
            'options_count' => $this->relationLoaded('options')
                ? $this->options->where('is_active', true)->count()
                : $this->whenLoaded('activeOptions', fn () => $this->activeOptions->count()),
            'options' => ProductOptionResource::collection(
                $this->relationLoaded('options') ? $this->options : $this->whenLoaded('activeOptions')
            ),

            // 라벨
            'labels' => $this->whenLoaded('activeLabelAssignments', fn () => $this->activeLabelAssignments
                ->filter(fn ($a) => $a->label && $a->label->is_active)
                ->sortBy(fn ($a) => $a->label->sort_order)
                ->map(fn ($a) => [
                    'name' => $a->label->name[app()->getLocale()]
                        ?? $a->label->name[config('app.fallback_locale')]
                        ?? array_values($a->label->name ?? [])[0] ?? '',
                    'color' => $a->label->color,
                ])->values()
            ),

            // 리뷰 통계 (visibleReviews withCount/withAvg eager loading 필요)
            'review_count' => (int) ($this->review_count ?? 0),
            'rating_avg' => $this->rating_avg !== null ? round((float) $this->rating_avg, 1) : 0.0,

            // 날짜
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 권한 정보 (is_owner + abilities)
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 권한 체크 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'sirsoft-ecommerce.products.update',
            'can_delete' => 'sirsoft-ecommerce.products.delete',
        ];
    }

    /**
     * 소유자 필드명을 반환합니다.
     *
     * @return string|null
     */
    protected function ownerField(): ?string
    {
        return 'created_by';
    }
}
