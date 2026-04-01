<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;

/**
 * 상품정보제공고시 템플릿 API 리소스
 */
class ProductNoticeTemplateResource extends BaseApiResource
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
            'category' => $this->category,
            'fields' => $this->fields ?? [],
            'fields_count' => is_array($this->fields) ? count($this->fields) : 0,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'icon' => 'file-alt', // SortableMenuList 컴포넌트용 아이콘
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
            'can_create' => 'sirsoft-ecommerce.product-notice-templates.create',
            'can_update' => 'sirsoft-ecommerce.product-notice-templates.update',
            'can_delete' => 'sirsoft-ecommerce.product-notice-templates.delete',
        ];
    }
}
