<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;

/**
 * 공통정보 API 리소스
 */
class ProductCommonInfoResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'localized_name' => $this->getLocalizedName(),
            'content' => $this->content ?? [],
            'localized_content' => $this->getLocalizedContent(),
            'content_mode' => $this->content_mode ?? 'text',
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'icon' => 'info-circle', // SortableMenuList 컴포넌트용 아이콘
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 통계 (withCount 또는 whenLoaded 지원)
            'products_count' => $this->products_count ?? ($this->whenLoaded('products', fn () => $this->products->count(), 0)),

            // 언어 개수 (Accessor 사용)
            'language_count' => $this->language_count,

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
            'can_create' => 'sirsoft-ecommerce.product-common-infos.create',
            'can_update' => 'sirsoft-ecommerce.product-common-infos.update',
            'can_delete' => 'sirsoft-ecommerce.product-common-infos.delete',
        ];
    }
}
