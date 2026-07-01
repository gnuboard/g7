<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Concerns\LocalizesCountryName;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\SummarizesAdditionalOptions;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;

/**
 * 주문 목록 리소스
 */
class OrderListResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;
    use LocalizesCountryName;
    use SummarizesAdditionalOptions;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 관리자 주문 목록 리소스 배열
     */
    public function toArray(Request $request): array
    {
        // 주문 시점 기준 통화 — 과거 주문의 *_formatted 는 설정 변경과 무관하게 이 통화로 고정 표기한다.
        $orderCurrency = $this->resolveOrderBaseCurrencyCode($this->resource);

        // 결제 통화(order_currency) — 유저가 선택·결제한 통화. base 통화와 다를 때 함께 표기.
        $paymentCurrency = $this->currency
            ?: (data_get($this->currency_snapshot, 'order_currency') ?: $orderCurrency);

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_status' => $this->order_status,
            'order_status_label' => $this->order_status ? $this->order_status->label() : null,
            'order_status_variant' => $this->order_status ? $this->order_status->variant() : null,
            // 금액 표기 기준 통화(base) + 결제 통화(order_currency) + 교차 통화 여부.
            'base_currency' => $orderCurrency,
            'payment_currency' => $paymentCurrency,
            'is_cross_currency' => $paymentCurrency !== $orderCurrency,
            // 부분취소 파생 플래그 — 일부 옵션만 취소된 주문(별도 order_status 아님). 보조 뱃지 표시용.
            'is_partially_cancelled' => $this->whenLoaded('options', fn () => $this->resource->isPartiallyCancelled(), false),

            // 금액
            'total_amount' => $this->roundToOrderCurrency($this->total_amount, $orderCurrency),
            'total_amount_formatted' => $this->formatOrderCurrency($this->total_amount, $orderCurrency),
            'total_shipping_amount' => $this->roundToOrderCurrency($this->total_shipping_amount, $orderCurrency),
            'total_shipping_amount_formatted' => $this->formatOrderCurrency($this->total_shipping_amount, $orderCurrency),
            'total_paid_amount' => $this->roundToOrderCurrency($this->total_paid_amount, $orderCurrency),
            'total_paid_amount_formatted' => $this->formatOrderCurrency($this->total_paid_amount, $orderCurrency),
            'total_unpaid_amount' => $this->roundToOrderCurrency($this->total_amount - $this->total_paid_amount, $orderCurrency),
            'total_unpaid_amount_formatted' => $this->formatOrderCurrency($this->total_amount - $this->total_paid_amount, $orderCurrency),
            'total_cancelled_amount' => $this->roundToOrderCurrency($this->total_cancelled_amount, $orderCurrency),
            'total_refunded_amount' => $this->roundToOrderCurrency($this->total_refunded_amount, $orderCurrency),

            // 마일리지 (목록 표시용 — 사용/적립). 마일리지는 base_currency 단일 정산.
            'total_points_used_amount' => $this->roundToOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_points_used_amount_formatted' => $this->formatOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_earned_points_amount' => $this->roundToOrderCurrency($this->total_earned_points_amount, $orderCurrency),
            'total_earned_points_amount_formatted' => $this->formatOrderCurrency($this->total_earned_points_amount, $orderCurrency),

            // 일시 — raw ISO 와 사용자 타임존 변환된 *_formatted 를 함께 제공 (OrderResource 와 동일 패턴)
            'ordered_at' => $this->ordered_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
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
                // 매직 프로퍼티(Eloquent accessor)를 reset() 에 직접 넘기면 PHP 8.3 에서
                // "Indirect modification of overloaded property" 경고가 발생하므로 지역 변수로 받는다.
                $optionName = $firstOption?->product_option_name;

                return [
                    'product_name' => is_array($productName)
                        ? ($productName[app()->getLocale()] ?? reset($productName) ?: '')
                        : ($productName ?? ''),
                    'product_option_name' => is_array($optionName)
                        ? ($optionName[app()->getLocale()] ?? reset($optionName) ?: '')
                        : ($optionName ?? ''),
                    'product_code' => $firstOption?->product_snapshot['product_code'] ?? null,
                    'quantity' => $firstOption?->quantity,
                    'thumbnail_url' => $firstOption?->product_snapshot['thumbnail_url'] ?? null,
                    // 추가옵션 요약 (스냅샷 기반 — 첫 1건 + "외 N건", custom_text 병기)
                    'additional_options_summary' => $this->summarizeAdditionalOptions($firstOption),
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
            'shipping' => $this->whenLoaded('shippings', function () {
                $shipping = $this->shippings->first();

                return [
                    'shipping_type' => $shipping?->shipping_type,
                    'shipping_type_label' => $shipping?->shipping_type
                        ? ShippingType::getCachedByCode($shipping->shipping_type)?->getLocalizedName()
                        : null,
                    'shipping_method_label' => $this->resolveSnapshotMethodLabel($shipping),
                    'carrier_name' => $shipping?->carrier?->getLocalizedName(),
                    'tracking_number' => $shipping?->tracking_number,
                ];
            }),

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
     */
    protected function ownerField(): ?string
    {
        return 'user_id';
    }

    /**
     * 스냅샷 기반 배송방법 라벨을 해석합니다.
     *
     * @param  OrderShipping|null  $shipping  배송 레코드
     */
    private function resolveSnapshotMethodLabel(?OrderShipping $shipping): ?string
    {
        if (! $shipping) {
            return null;
        }

        $snapshot = $shipping->delivery_policy_snapshot;
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

        return ShippingType::getCachedByCode($method)?->getLocalizedName();
    }
}
