<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;

/**
 * 배송정책 국가별 설정 리소스
 */
class ShippingPolicyCountrySettingResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 배송정책 국가별 설정 리소스 배열
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

            // 배송비 관련 (행의 통화 코드 자릿수로 정규화 — 미설정 시 기본 통화)
            'base_fee' => $this->roundToCurrency($this->base_fee, $this->currency_code ?: $this->getDefaultCurrencyCode()),
            'free_threshold' => $this->free_threshold !== null
                ? $this->roundToCurrency($this->free_threshold, $this->currency_code ?: $this->getDefaultCurrencyCode())
                : null,
            'ranges' => $this->ranges,

            // API 설정
            'api_endpoint' => $this->api_endpoint,
            'api_request_fields' => $this->api_request_fields,
            'api_response_fee_field' => $this->api_response_fee_field,
            'api_config' => $this->maskApiConfig(),

            // 도서산간
            'extra_fee_enabled' => $this->extra_fee_enabled,
            'extra_fee_settings' => $this->extra_fee_settings,
            'extra_fee_multiply' => $this->extra_fee_multiply,

            // 상태
            'is_active' => $this->is_active,
        ];
    }

    /**
     * 계산 API 연동 설정을 응답용으로 변환합니다 (인증 토큰 마스킹).
     *
     * auth_token 평문은 응답에 노출하지 않습니다. 대신 설정 여부(has_auth_token)만
     * 내려, 수정 폼이 토큰 재입력 없이 기존 토큰 유지 여부를 판단할 수 있게 합니다.
     *
     * @return array<string, mixed>|null
     */
    private function maskApiConfig(): ?array
    {
        $config = $this->api_config;

        if (! is_array($config)) {
            return null;
        }

        $hasToken = ! empty($config['auth_token']);
        unset($config['auth_token']);
        $config['has_auth_token'] = $hasToken;

        return $config;
    }

    /**
     * 배송방법 라벨을 해석합니다.
     *
     * custom인 경우 custom_shipping_name에서 현재 로케일 값을 반환합니다.
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

                return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? null;
            }

            return null;
        }

        return ShippingType::getCachedByCode($this->shipping_method)?->getLocalizedName();
    }
}
