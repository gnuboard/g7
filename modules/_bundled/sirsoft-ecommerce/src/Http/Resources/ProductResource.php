<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 상품 상세 리소스
 */
class ProductResource extends BaseApiResource
{
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

            // 가격
            'list_price' => $this->list_price,
            'selling_price' => $this->selling_price,
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

            // 옵션
            'has_options' => $this->has_options,
            'option_groups' => $this->resource->getOptionGroupsForApi(),
            'options' => ProductOptionResource::collection(
                $this->relationLoaded('options') ? $this->options : $this->whenLoaded('activeOptions')
            ),

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
