<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 주문 목록 리소스
 */
class OrderListResource extends BaseApiResource
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
            'order_number' => $this->order_number,
            'order_status' => $this->order_status,
            'order_status_label' => $this->order_status ? $this->order_status->label() : null,
            'order_status_variant' => $this->order_status ? $this->order_status->variant() : null,

            // 금액
            'total_amount' => $this->total_amount,
            'total_amount_formatted' => number_format($this->total_amount).'원',
            'total_shipping_amount' => $this->total_shipping_amount,
            'total_shipping_amount_formatted' => number_format($this->total_shipping_amount).'원',
            'total_paid_amount' => $this->total_paid_amount,
            'total_paid_amount_formatted' => number_format($this->total_paid_amount).'원',
            'total_unpaid_amount' => $this->total_amount - $this->total_paid_amount,
            'total_unpaid_amount_formatted' => number_format($this->total_amount - $this->total_paid_amount).'원',
            'total_cancelled_amount' => $this->total_cancelled_amount,
            'total_refunded_amount' => $this->total_refunded_amount,

            // 일시
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'ordered_at_formatted' => $this->formatDateTimeStringForUser($this->ordered_at),

            // 구매환경
            'order_device' => $this->order_device?->value,
            'order_device_label' => $this->order_device?->label(),

            // 첫구매 여부
            'is_first_order' => $this->is_first_order,

            // 회원 정보 (null 가능 - 비회원 주문)
            'user' => $this->whenLoaded('user', fn () => [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
            ]),

            // 첫 번째 옵션 (대표 상품 표시용)
            'first_option' => $this->whenLoaded('options', function () {
                $firstOption = $this->options->first();
                $productName = $firstOption?->product_name;

                return [
                    'product_name' => is_array($productName)
                        ? ($productName[app()->getLocale()] ?? reset($productName) ?: '')
                        : ($productName ?? ''),
                    'product_option_name' => is_array($firstOption?->product_option_name)
                        ? ($firstOption->product_option_name[app()->getLocale()] ?? reset($firstOption->product_option_name) ?: '')
                        : ($firstOption?->product_option_name ?? ''),
                    'product_code' => $firstOption?->product_snapshot['product_code'] ?? null,
                    'quantity' => $firstOption?->quantity,
                    'thumbnail_url' => $firstOption?->product_snapshot['thumbnail_url'] ?? null,
                ];
            }),
            'options_count' => $this->whenLoaded('options', fn () => $this->options->count()),

            // 주문자/수령인
            'address' => $this->whenLoaded('shippingAddress', fn () => [
                'orderer_name' => $this->shippingAddress->orderer_name,
                'recipient_name' => $this->shippingAddress->recipient_name,
                'recipient_country_code' => $this->shippingAddress->recipient_country_code,
                'recipient_country_name' => $this->getCountryLocalizedName($this->shippingAddress->recipient_country_code),
            ]),

            // 결제
            'payment' => $this->whenLoaded('payment', fn () => [
                'payment_method' => $this->payment->payment_method,
                'payment_method_label' => $this->payment->payment_method ? $this->payment->payment_method->label() : null,
            ]),

            // 배송 (첫 번째)
            'shipping' => $this->whenLoaded('shippings', fn () => [
                'shipping_type' => $this->shippings->first()?->shipping_type,
                'shipping_type_label' => $this->shippings->first()?->shipping_type?->label(),
                'carrier_name' => $this->shippings->first()?->carrier?->getLocalizedName(),
                'tracking_number' => $this->shippings->first()?->tracking_number,
            ]),

            // 권한 정보 (is_owner + abilities)
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 권한 체크 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_read' => 'sirsoft-ecommerce.orders.read',
            'can_update' => 'sirsoft-ecommerce.orders.update',
        ];
    }

    /**
     * 소유자 필드명을 반환합니다.
     *
     * @return string|null
     */
    protected function ownerField(): ?string
    {
        return 'user_id';
    }

    /**
     * 국가 코드를 다국어 국가명 객체로 변환합니다.
     *
     * @param  string|null  $countryCode  ISO alpha-2 국가 코드
     * @return array|null 다국어 객체 (예: {ko: '한국', en: 'South Korea'})
     */
    private function getCountryLocalizedName(?string $countryCode): ?array
    {
        if (! $countryCode) {
            return null;
        }

        $countryCode = strtoupper($countryCode);
        $localizedNames = config('countries.localized_names', []);

        $result = [];
        foreach ($localizedNames as $locale => $names) {
            if (isset($names[$countryCode])) {
                $result[$locale] = $names[$countryCode];
            }
        }

        return ! empty($result) ? $result : null;
    }
}
