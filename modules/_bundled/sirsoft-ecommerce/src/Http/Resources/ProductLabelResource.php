<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;

/**
 * 상품 라벨 API 리소스
 */
class ProductLabelResource extends BaseApiResource
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
            'color' => $this->color,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 통계 (withCount 또는 whenLoaded 지원)
            'assignments_count' => $this->assignments_count ?? ($this->whenLoaded('assignments', fn () => $this->assignments->count(), 0)),

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
            'can_create' => 'sirsoft-ecommerce.product-labels.create',
            'can_update' => 'sirsoft-ecommerce.product-labels.update',
            'can_delete' => 'sirsoft-ecommerce.product-labels.delete',
        ];
    }
}
