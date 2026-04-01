<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 배송정책 국가별 설정 리소스
 */
class ShippingPolicyCountrySettingResource extends BaseApiResource
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
            'country_code' => $this->country_code,

            // 배송방법
            'shipping_method' => $this->shipping_method?->value,
            'shipping_method_label' => $this->shipping_method?->label(),

            // 통화
            'currency_code' => $this->currency_code,

            // 부과정책
            'charge_policy' => $this->charge_policy?->value,
            'charge_policy_label' => $this->charge_policy?->label(),

            // 배송비 관련
            'base_fee' => (float) $this->base_fee,
            'free_threshold' => $this->free_threshold ? (float) $this->free_threshold : null,
            'ranges' => $this->ranges,

            // API 설정
            'api_endpoint' => $this->api_endpoint,
            'api_request_fields' => $this->api_request_fields,
            'api_response_fee_field' => $this->api_response_fee_field,

            // 도서산간
            'extra_fee_enabled' => $this->extra_fee_enabled,
            'extra_fee_settings' => $this->extra_fee_settings,
            'extra_fee_multiply' => $this->extra_fee_multiply,

            // 상태
            'is_active' => $this->is_active,
        ];
    }
}
