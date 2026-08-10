<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
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

        // OrderCollection 은 이 배열을 `toArray()` 로 직접 받아 응답에 싣는다 — Laravel 이 응답
        // 직전에 수행하는 MissingValue 제거를 거치지 않으므로 여기서 걸러낸다.
        return $this->withoutMissing([
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
            'is_partially_cancelled' => $this->resolveIsPartiallyCancelled(),

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
            'first_option' => $this->whenFirstOptionAvailable(function ($firstOption) {
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
            'options_count' => $this->resolveOptionsCount(),

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
                'payment_method_label' => $this->payment->payment_method ? $this->payment->paymentMethodLabel() : null,
            ]),

            // 배송 (대표 1건)
            //
            // 목록 조회는 `firstShipping` 만 로드한다. 종전 호출자(전체 `shippings` 를 로드하는
            // 경로)도 그대로 동작하도록 두 관계를 모두 본다 — 어느 쪽도 로드돼 있지 않으면
            // 필드 자체를 싣지 않는다(빈 객체 누출 방지).
            'shipping' => $this->whenAnyShippingLoaded(function () {
                $shipping = $this->resolveRepresentativeShipping();

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
        ]);
    }

    /**
     * 대표 옵션이 확보된 경우에만 콜백 결과를 반환합니다.
     *
     * 목록 경로는 `firstOption`(대표 1건)만 로드한다. 상세/일괄 경로처럼 `options` 전량이
     * 로드된 컬렉션도 같은 필드를 그리므로 두 경로 모두 받는다. 어느 쪽도 로드되지 않았으면
     * 필드 자체를 내보내지 않는다.
     *
     * @param  callable  $callback  대표 옵션을 받아 표시용 배열을 만드는 콜백
     * @return array<string, mixed>|MissingValue 표시용 배열 또는 미충족 표식
     */
    private function whenFirstOptionAvailable(callable $callback): array|MissingValue
    {
        if ($this->resource->relationLoaded('firstOption')) {
            return $callback($this->resource->firstOption);
        }

        if ($this->resource->relationLoaded('options')) {
            return $callback($this->resource->options->first());
        }

        return new MissingValue;
    }

    /**
     * 배송 관계가 어느 형태로든 로드돼 있을 때만 콜백 결과를 싣습니다.
     *
     * 목록은 대표 1건(`firstShipping`)만 로드하고, 전체를 로드하는 종전 경로도 남아 있다.
     * 둘 다 없으면 `MissingValue` 를 돌려 필드를 통째로 뺀다 — 로드하지 않은 관계를 빈 값으로
     * 채우면 "배송 정보가 없다" 는 사실이 아닌 단언이 응답에 실린다.
     *
     * @param  callable  $callback  배송 표현을 만드는 콜백
     * @return array<string, mixed>|MissingValue 배송 표현 또는 미충족 표식
     */
    private function whenAnyShippingLoaded(callable $callback): array|MissingValue
    {
        if (
            $this->resource->relationLoaded('firstShipping')
            || $this->resource->relationLoaded('shippings')
        ) {
            return $callback();
        }

        return new MissingValue;
    }

    /**
     * 대표 배송 레코드를 반환합니다.
     *
     * @return OrderShipping|null 대표 배송 (없으면 null)
     */
    private function resolveRepresentativeShipping(): ?OrderShipping
    {
        if ($this->resource->relationLoaded('firstShipping')) {
            return $this->resource->firstShipping;
        }

        return $this->resource->relationLoaded('shippings')
            ? $this->resource->shippings->first()
            : null;
    }

    /**
     * 전체 옵션 수를 반환합니다.
     *
     * 목록은 DB 집계(`options_count`)를 쓰고, 옵션이 전량 로드된 경로는 컬렉션 길이를 쓴다.
     * 집계 별칭 유무는 값이 아니라 속성 키로 판정한다 — 옵션 0건의 `0` 과 "집계 안 함" 은
     * 값으로 구분되지 않는다.
     *
     * @return int|MissingValue 옵션 수 또는 미충족 표식
     */
    private function resolveOptionsCount(): int|MissingValue
    {
        if ($this->hasAggregate('options_count')) {
            return (int) $this->aggregate('options_count', 0);
        }

        if ($this->resource->relationLoaded('options')) {
            return $this->resource->options->count();
        }

        return new MissingValue;
    }

    /**
     * 부분취소 여부를 반환합니다.
     *
     * 취소된 옵션이 있으면서 남은 옵션도 있는 상태를 뜻한다. 목록은 옵션을 순회하지 않고
     * 집계 두 개(전체 수 / 취소 수)로 같은 판정을 얻는다.
     *
     * @return bool 부분취소 여부
     */
    private function resolveIsPartiallyCancelled(): bool
    {
        if ($this->hasAggregate('options_count') && $this->hasAggregate('cancelled_options_count')) {
            $cancelled = (int) $this->aggregate('cancelled_options_count', 0);

            return $cancelled > 0 && (int) $this->aggregate('options_count', 0) > $cancelled;
        }

        return $this->resource->relationLoaded('options')
            ? $this->resource->isPartiallyCancelled()
            : false;
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
