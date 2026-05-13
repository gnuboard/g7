<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 배송정책 리소스
 */
class ShippingPolicyResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param Request $request 요청
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // 기본 정보
            'name' => $this->name,
            'name_localized' => $this->getLocalizedName(),

            // 국가별 설정
            'country_settings' => ShippingPolicyCountrySettingResource::collection(
                $this->whenLoaded('countrySettings')
            ),

            // 요약 정보
            'fee_summary' => $this->getFeeSummary(),
            'countries_display' => $this->getCountriesWithFlags(),

            // 상태
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'sort_order' => $this->sort_order,

            // 날짜
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
            'can_create' => 'sirsoft-ecommerce.shipping-policies.create',
            'can_update' => 'sirsoft-ecommerce.shipping-policies.update',
            'can_delete' => 'sirsoft-ecommerce.shipping-policies.delete',
        ];
    }
}
