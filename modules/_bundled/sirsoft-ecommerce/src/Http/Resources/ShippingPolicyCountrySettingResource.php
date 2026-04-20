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
            'shipping_method' => $this->shipping_method,
            'shipping_method_label' => $this->resolveShippingMethodLabel(),
            'custom_shipping_name' => $this->custom_shipping_name,

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

    /**
     * 배송방법 라벨을 해석합니다.
     *
     * custom인 경우 custom_shipping_name에서 현재 로케일 값을 반환합니다.
     *
     * @return string|null
     */
    private function resolveShippingMethodLabel(): ?string
    {
        if (! $this->shipping_method) {
            return null;
        }

        if ($this->shipping_method === 'custom') {
            $name = $this->custom_shipping_name;
            if (is_array($name)) {
                $locale = app()->getLocale();

                return $name[$locale] ?? $name['ko'] ?? $name[array_key_first($name)] ?? null;
            }

            return null;
        }

        return \Modules\Sirsoft\Ecommerce\Models\ShippingType::getCachedByCode($this->shipping_method)?->getLocalizedName();
    }
}
