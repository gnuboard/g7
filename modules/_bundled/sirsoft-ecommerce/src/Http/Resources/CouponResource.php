<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;

/**
 * 쿠폰 API 리소스
 */
class CouponResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'localized_name' => $this->getLocalizedName(),
            'description' => $this->description,
            'localized_description' => $this->getLocalizedDescription(),

            // 적용 대상
            'target_type' => $this->target_type?->value,
            'target_type_label' => $this->target_type?->label(),
            'target_type_badge_color' => $this->target_type?->badgeColor(),

            // 할인 정보
            'discount_type' => $this->discount_type?->value,
            'discount_type_label' => $this->discount_type?->label(),
            'discount_value' => $this->discount_value,
            'discount_max_amount' => $this->discount_max_amount,
            'min_order_amount' => $this->min_order_amount,
            'benefit_formatted' => $this->benefit_formatted,

            // 발급 설정
            'issue_method' => $this->issue_method?->value,
            'issue_method_label' => $this->issue_method?->label(),
            'issue_method_badge_color' => $this->issue_method?->badgeColor(),
            'issue_condition' => $this->issue_condition?->value,
            'issue_condition_label' => $this->issue_condition?->label(),
            'issue_condition_badge_color' => $this->issue_condition?->badgeColor(),
            'issue_status' => $this->issue_status?->value,
            'issue_status_label' => $this->issue_status?->label(),
            'issue_status_badge_color' => $this->issue_status?->badgeColor(),

            // 발급 수량
            'total_quantity' => $this->total_quantity,
            'issued_count' => $this->issued_count,
            'per_user_limit' => $this->per_user_limit,
            'issue_count_formatted' => $this->issue_count_formatted,

            // 유효기간
            'valid_type' => $this->valid_type,
            'valid_days' => $this->valid_days,
            'valid_from' => $this->formatDateStringForSite($this->valid_from),
            'valid_to' => $this->formatDateStringForSite($this->valid_to),
            'valid_period_formatted' => $this->valid_period_formatted,

            // 발급기간 (datetime-local 입력 호환)
            'issue_from' => $this->formatDateTimeLocalStringForSite($this->issue_from),
            'issue_to' => $this->formatDateTimeLocalStringForSite($this->issue_to),
            'issue_period_formatted' => $this->issue_period_formatted,

            // 기타 설정
            'is_combinable' => $this->is_combinable,
            'target_scope' => $this->target_scope?->value,
            'target_scope_label' => $this->target_scope?->label(),

            // 상태
            'is_issuable' => $this->isIssuable(),

            // 날짜
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 등록자 정보 (플랫 필드 — 레이아웃에서 등록자 컬럼 표시용)
            'created_by' => $this->created_by ? $this->whenLoaded('creator', fn () => $this->creator->uuid) : null,
            'created_by_name' => $this->created_by ? $this->whenLoaded('creator', fn () => $this->creator->name, '-') : '-',
            'created_by_email' => $this->created_by ? $this->whenLoaded('creator', fn () => $this->creator->email) : null,

            // 관계
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->name,
                ];
            }),

            // 적용 상품/카테고리
            'included_products' => $this->whenLoaded('includedProducts', function () {
                return $this->includedProducts->map(fn ($product) => [
                    'id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->getLocalizedName(),
                    'name_localized' => $product->getLocalizedName(),
                    'selling_price_formatted' => number_format($product->selling_price ?? 0).'원',
                ]);
            }),
            'excluded_products' => $this->whenLoaded('excludedProducts', function () {
                return $this->excludedProducts->map(fn ($product) => [
                    'id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->getLocalizedName(),
                    'name_localized' => $product->getLocalizedName(),
                    'selling_price_formatted' => number_format($product->selling_price ?? 0).'원',
                ]);
            }),
            'included_categories' => $this->whenLoaded('includedCategories', function () {
                return $this->includedCategories->map(fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->getLocalizedName(),
                    'path' => $category->getLocalizedBreadcrumbString(separator: ' › '),
                    'products_count' => $category->products_count ?? 0,
                ]);
            }),
            'excluded_categories' => $this->whenLoaded('excludedCategories', function () {
                return $this->excludedCategories->map(fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->getLocalizedName(),
                    'path' => $category->getLocalizedBreadcrumbString(separator: ' › '),
                    'products_count' => $category->products_count ?? 0,
                ]);
            }),

            // 통계
            'issues_count' => $this->issues_count ?? null,

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
            'can_create' => 'sirsoft-ecommerce.promotion-coupon.create',
            'can_update' => 'sirsoft-ecommerce.promotion-coupon.update',
            'can_delete' => 'sirsoft-ecommerce.promotion-coupon.delete',
        ];
    }
}
