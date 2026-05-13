<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;

/**
 * 배송사 API 리소스
 */
class ShippingCarrierResource extends BaseApiResource
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
            'code' => $this->code,
            'name' => $this->name,
            'localized_name' => $this->getLocalizedName(),
            'type' => $this->type,
            'type_label' => $this->type === 'domestic'
                ? __('sirsoft-ecommerce::messages.shipping_carriers.type_domestic')
                : __('sirsoft-ecommerce::messages.shipping_carriers.type_international'),
            'tracking_url' => $this->tracking_url,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 관계
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ];
            }),
            'updater' => $this->whenLoaded('updater', function () {
                return [
                    'id' => $this->updater->id,
                    'name' => $this->updater->name,
                ];
            }),

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
            'can_create' => 'sirsoft-ecommerce.settings.update',
            'can_update' => 'sirsoft-ecommerce.settings.update',
            'can_delete' => 'sirsoft-ecommerce.settings.update',
        ];
    }
}
