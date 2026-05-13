<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 주문 배송 정보 리소스
 */
class OrderShippingResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;
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
            'order_option_id' => $this->order_option_id,
            'shipping_status' => $this->shipping_status,
            'shipping_status_label' => $this->shipping_status ? $this->shipping_status->label() : null,
            'shipping_status_variant' => $this->shipping_status ? $this->shipping_status->variant() : null,
            'shipping_type' => $this->shipping_type,
            'shipping_type_label' => $this->shipping_type
                ? \Modules\Sirsoft\Ecommerce\Models\ShippingType::getCachedByCode($this->shipping_type)?->getLocalizedName()
                : null,
            'shipping_method' => $this->delivery_policy_snapshot['shipping_method'] ?? null,
            'shipping_method_label' => $this->resolveSnapshotShippingMethodLabel(),
            'shipping_policy_id' => $this->shipping_policy_id,
            'base_shipping_amount' => $this->base_shipping_amount,
            'base_shipping_amount_formatted' => number_format($this->base_shipping_amount).'원',
            'extra_shipping_amount' => $this->extra_shipping_amount,
            'extra_shipping_amount_formatted' => number_format($this->extra_shipping_amount).'원',
            'total_shipping_amount' => $this->total_shipping_amount,
            'total_shipping_amount_formatted' => number_format($this->total_shipping_amount).'원',
            'shipping_discount_amount' => $this->shipping_discount_amount,
            'shipping_discount_amount_formatted' => number_format($this->shipping_discount_amount).'원',
            'is_remote_area' => $this->is_remote_area,
            'delivery_policy_snapshot' => $this->delivery_policy_snapshot,
            // 다중 통화
            'mc_base_shipping_amount' => $this->formatStoredMultiCurrency($this->mc_base_shipping_amount),
            'mc_extra_shipping_amount' => $this->formatStoredMultiCurrency($this->mc_extra_shipping_amount),
            'mc_total_shipping_amount' => $this->formatStoredMultiCurrency($this->mc_total_shipping_amount),
            'mc_shipping_discount_amount' => $this->formatStoredMultiCurrency($this->mc_shipping_discount_amount),
            'mc_return_shipping_amount' => $this->formatStoredMultiCurrency($this->mc_return_shipping_amount),
            'carrier_id' => $this->carrier_id,
            'carrier_name' => $this->carrier?->getLocalizedName(),
            'carrier_code' => $this->carrier_code,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'shipped_at_formatted' => $this->formatDateTimeStringForUser($this->shipped_at),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'delivered_at_formatted' => $this->formatDateTimeStringForUser($this->delivered_at),
            'package_group_id' => $this->package_group_id,
            'visit_pickup_store_id' => $this->visit_pickup_store_id,
            'visit_pickup_name' => $this->visit_pickup_name,
            'visit_pickup_phone' => $this->visit_pickup_phone,
        ];
    }

    /**
     * 스냅샷 기반 배송방법 라벨을 해석합니다.
     *
     * @return string|null
     */
    private function resolveSnapshotShippingMethodLabel(): ?string
    {
        $snapshot = $this->delivery_policy_snapshot;
        $method = $snapshot['shipping_method'] ?? null;

        if (! $method) {
            return null;
        }

        if ($method === 'custom') {
            $name = $snapshot['custom_shipping_name'] ?? null;
            if (is_array($name)) {
                $locale = app()->getLocale();

                return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? null;
            }

            return null;
        }

        return \Modules\Sirsoft\Ecommerce\Models\ShippingType::getCachedByCode($method)?->getLocalizedName();
    }
}
