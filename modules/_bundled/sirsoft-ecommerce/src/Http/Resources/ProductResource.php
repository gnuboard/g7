<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 상품 상세 리소스
 */
class ProductResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 상품 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_localized' => $this->getLocalizedName(),
            'product_code' => $this->product_code,
            'sku' => $this->sku,

            // 카테고리 (다대다)
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'name_localized' => $cat->getLocalizedName(),
                'breadcrumb' => $cat->getLocalizedBreadcrumbString(),
                'path' => $cat->path,
                'is_primary' => $cat->pivot->is_primary,
            ])),
            'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')),
            'primary_category_id' => $this->whenLoaded('categories', fn () => $this->categories->firstWhere('pivot.is_primary', true)?->id
            ),

            // 브랜드
            'brand_id' => $this->brand_id,

            // 가격 (기본 통화 자릿수로 정규화 — JPY/KRW 0자리는 정수)
            'list_price' => $this->roundToBaseCurrency($this->list_price),
            'selling_price' => $this->roundToBaseCurrency($this->selling_price),
            'discount_rate' => $this->getDiscountRate(),

            // 재고
            'stock_quantity' => $this->stock_quantity,
            'safe_stock_quantity' => $this->safe_stock_quantity,
            'is_below_safe_stock' => $this->isBelowSafeStock(),
            'is_stock_consistent' => $this->isStockConsistent(),

            // 상태
            'sales_status' => $this->sales_status->value,
            'sales_status_label' => $this->sales_status->label(),
            'display_status' => $this->display_status->value,
            'display_status_label' => $this->display_status->label(),
            'tax_status' => $this->tax_status->value,
            'tax_status_label' => $this->tax_status->label(),
            'tax_rate' => $this->tax_rate,

            // 배송
            'shipping_policy_id' => $this->shipping_policy_id,
            // 현재 부여된 배송정책 객체 (비활성 포함) — 수정폼에서 활성 목록에 없을 때 union 표시용
            'shipping_policy' => $this->whenLoaded('shippingPolicy', fn () => $this->shippingPolicy ? [
                'id' => $this->shippingPolicy->id,
                'name' => $this->shippingPolicy->name,
                'is_active' => $this->shippingPolicy->is_active,
                'is_default' => $this->shippingPolicy->is_default,
                'fee_summary' => $this->shippingPolicy->getFeeSummary(),
                'country_settings' => $this->shippingPolicy->country_settings,
            ] : null),

            // 공통정보
            'common_info_id' => $this->common_info_id,

            // 설명 (다국어)
            'description' => $this->description,
            'description_localized' => $this->getLocalizedDescription(),
            'description_mode' => $this->description_mode,

            // 구매제한
            'min_purchase_qty' => $this->min_purchase_qty,
            'max_purchase_qty' => $this->max_purchase_qty,
            'purchase_restriction' => $this->purchase_restriction,
            'allowed_roles' => $this->allowed_roles,

            // 기타정보
            'barcode' => $this->barcode,
            'hs_code' => $this->hs_code,

            // 라벨
            'label_assignments' => $this->whenLoaded('activeLabelAssignments', fn () => $this->activeLabelAssignments->map(fn ($assignment) => [
                'id' => $assignment->id,
                'label_id' => $assignment->label_id,
                'label' => $assignment->label ? [
                    'id' => $assignment->label->id,
                    'name' => $assignment->label->name,
                    'name_localized' => $assignment->label->getLocalizedName(),
                    'color' => $assignment->label->color,
                ] : null,
                'started_at' => $assignment->started_at,
                'ended_at' => $assignment->ended_at,
            ])),

            // 상품정보제공고시
            'notice_items' => $this->whenLoaded('notice', fn () => collect($this->notice->values ?? [])->map(fn ($item, $index) => array_merge($item, [
                'key' => $item['key'] ?? 'item_'.($index + 1).'_'.time(),
            ]))->values()->toArray()),

            // 이미지 (별도 테이블)
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'thumbnail_hash' => $this->relationLoaded('images')
                ? $this->images->firstWhere('is_thumbnail', true)?->hash
                : null,
            'thumbnail_url' => $this->getThumbnailUrl(),

            // SEO
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            // SEO 동기화 플래그 (EDIT 폼 form.seo_sync_* 바인딩용 — 동기화 토글 상태 복원)
            'seo_sync_title' => (bool) $this->seo_sync_title,
            'seo_sync_description' => (bool) $this->seo_sync_description,

            // 옵션
            'has_options' => $this->has_options,
            'option_groups' => $this->resource->getOptionGroupsForApi(),
            'options' => ProductOptionResource::collection(
                $this->relationLoaded('options') ? $this->options : $this->whenLoaded('activeOptions')
            ),

            // 추가옵션 (EDIT 폼 form.additional_options 바인딩용 — getDetailForForm 과 동일 형식)
            'additional_options' => $this->relationLoaded('additionalOptions')
                ? $this->additionalOptions->sortBy('sort_order')->map(fn ($opt) => [
                    'id' => $opt->id,
                    'name' => $opt->name,
                    'is_required' => $opt->is_required,
                    'sort_order' => $opt->sort_order,
                    'values' => $opt->relationLoaded('values')
                        ? $opt->values->sortBy('sort_order')->map(fn ($val) => [
                            'id' => $val->id,
                            'name' => $val->name,
                            'price_adjustment' => $this->roundToBaseCurrency($val->price_adjustment),
                            'is_default' => $val->is_default,
                            'is_active' => $val->is_active,
                            'allow_custom_text' => $val->allow_custom_text,
                            'sort_order' => $val->sort_order,
                        ])->values()->toArray()
                        : [],
                ])->values()->toArray()
                : [],

            // 시스템
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
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
}
