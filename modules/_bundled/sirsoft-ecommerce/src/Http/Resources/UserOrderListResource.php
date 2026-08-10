<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Concerns\LocalizesCountryName;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\SummarizesAdditionalOptions;

/**
 * 사용자 주문 목록 리소스
 *
 * 마이페이지 주문내역에서 사용되는 유저 전용 목록 리소스입니다.
 * 관리자용 OrderListResource와 달리 admin_memo, user 정보 등을 제외합니다.
 */
class UserOrderListResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;
    use LocalizesCountryName;
    use SummarizesAdditionalOptions;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 마이페이지 주문 목록 리소스 배열 (배송국가 포함)
     */
    public function toArray(Request $request): array
    {
        // 주문 시점 기준 통화 — 과거 주문의 *_formatted 는 설정 변경과 무관하게 이 통화로 고정 표기한다.
        $orderCurrency = $this->resolveOrderBaseCurrencyCode($this->resource);

        // 컬렉션(UserOrderCollection)이 이 배열을 그대로 응답에 실어 Laravel 의 MissingValue
        // 제거 단계를 거치지 않는다 — 미충족 조건부 필드를 직접 걸러낸다. 걸러내지 않으면
        // 아이템을 싣지 않은 경로에서 `"items": {}` 가 남는다.
        return $this->withoutMissing([
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->order_status?->value,
            'status_label' => $this->order_status?->label(),
            'status_variant' => $this->order_status?->variant(),
            // 부분취소 파생 플래그 — 일부 옵션만 취소된 주문(별도 order_status 아님). 보조 뱃지 표시용.
            'is_partially_cancelled' => $this->resolveIsPartiallyCancelled(),

            // 배송국가 (마이페이지 주문 목록 표시 — D9, 항상 표시 KR 포함)
            'recipient_country_code' => $this->whenLoaded('shippingAddress', fn () => $this->shippingAddress?->recipient_country_code),
            'recipient_country_name' => $this->whenLoaded('shippingAddress', fn () => $this->getCountryLocalizedName($this->shippingAddress?->recipient_country_code)),

            // 일시 — raw ISO 와 사용자 타임존 변환된 *_formatted 를 함께 제공 (OrderResource 와 동일 패턴)
            'ordered_at' => $this->ordered_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'ordered_at_formatted' => $this->formatDateTimeStringForUser($this->ordered_at),

            // 금액
            'total_amount' => $this->roundToOrderCurrency($this->total_amount, $orderCurrency),
            'total_amount_formatted' => $this->formatOrderCurrency($this->total_amount, $orderCurrency),
            'mc_total_amount' => $this->formatStoredMultiCurrency($this->mc_total_amount),

            // 배송비
            'total_shipping_amount' => $this->roundToOrderCurrency($this->total_shipping_amount, $orderCurrency),
            'total_shipping_amount_formatted' => $this->formatOrderCurrency($this->total_shipping_amount, $orderCurrency),
            'mc_total_shipping_amount' => $this->formatStoredMultiCurrency($this->mc_total_shipping_amount),

            // 마일리지 (목록 표시용 — 사용/적립). 마일리지는 base_currency 단일 정산.
            'total_points_used_amount' => $this->roundToOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_points_used_amount_formatted' => $this->formatOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_earned_points_amount' => $this->roundToOrderCurrency($this->total_earned_points_amount, $orderCurrency),
            'total_earned_points_amount_formatted' => $this->formatOrderCurrency($this->total_earned_points_amount, $orderCurrency),

            // 주문 옵션 (상품 정보)
            //
            // 기본은 대표 1건만 싣는다. 마이페이지 주문내역처럼 한 주문의 아이템을 전부
            // 나열하는 화면은 `?with_items=1` 로 켜서 전량을 받는다 — 화면이 켜는 쪽이지
            // 서버가 모두에게 전량을 안기는 쪽이 아니다.
            'items' => $this->resolveItems($orderCurrency),
            'item_count' => $this->resolveItemCount(),

            // 권한 메타
            'abilities' => [
                'can_view' => true,
                'can_cancel' => $this->resource->isCancellable(
                    module_setting(
                        'sirsoft-ecommerce',
                        'order_settings.cancellable_statuses',
                        ['payment_complete']
                    )
                ) && PermissionHelper::check('sirsoft-ecommerce.user-orders.cancel'),
            ],
        ]);
    }

    /**
     * 목록에 실을 아이템 배열을 반환합니다.
     *
     * 전량이 로드된 경로(`?with_items=1`)는 전부, 대표 1건만 로드된 기본 경로는 그 1건만
     * 담는다. 어느 쪽도 로드되지 않았으면 키를 내보내지 않는다 — 빈 배열은 "아이템이 없는
     * 주문" 이라는 사실 아닌 단언이 된다.
     *
     * @param  string|null  $orderCurrency  주문 시점 기준 통화
     * @return array<int, array<string, mixed>>|\Illuminate\Http\Resources\MissingValue 아이템 배열
     */
    private function resolveItems(?string $orderCurrency)
    {
        if ($this->resource->relationLoaded('options')) {
            return $this->resource->options
                ->map(fn ($option) => $this->formatItem($option, $orderCurrency))
                ->values()
                ->all();
        }

        if ($this->resource->relationLoaded('firstOption')) {
            $representative = $this->resource->firstOption;

            return $representative
                ? [$this->formatItem($representative, $orderCurrency)]
                : [];
        }

        return new \Illuminate\Http\Resources\MissingValue;
    }

    /**
     * 주문 아이템 1건을 표시용 배열로 변환합니다.
     *
     * @param  \Modules\Sirsoft\Ecommerce\Models\OrderOption  $option  주문 옵션
     * @param  string|null  $orderCurrency  주문 시점 기준 통화
     * @return array<string, mixed> 표시용 아이템 배열
     */
    private function formatItem($option, ?string $orderCurrency): array
    {
        return [
            // array_values()[0] 사용 — reset() 은 Eloquent 매직 프로퍼티에 직접 쓰면
            // PHP 8.3 "Indirect modification of overloaded property" 경고를 유발한다.
            'product_name' => is_array($option->product_name)
                ? ($option->product_name[app()->getLocale()] ?? array_values($option->product_name)[0] ?? '')
                : ($option->product_name ?? ''),
            'product_option_name' => is_array($option->product_option_name)
                ? ($option->product_option_name[app()->getLocale()] ?? array_values($option->product_option_name)[0] ?? '')
                : ($option->product_option_name ?? ''),
            'thumbnail_url' => $option->product_snapshot['thumbnail_url'] ?? null,
            'quantity' => $option->quantity,
            'unit_price_formatted' => $this->formatOrderCurrency($option->unit_price, $orderCurrency),
            'mc_unit_price' => $this->formatStoredMultiCurrency($option->mc_unit_price),
            'subtotal_price' => $this->roundToOrderCurrency($option->subtotal_price, $orderCurrency),
            'subtotal_price_formatted' => $this->formatOrderCurrency($option->subtotal_price, $orderCurrency),
            'mc_subtotal_price' => $this->formatStoredMultiCurrency($option->mc_subtotal_price),
            // 추가옵션 요약 (스냅샷 기반 — 첫 1건 + "외 N건", custom_text 병기)
            'additional_options_summary' => $this->summarizeAdditionalOptions($option),
        ];
    }

    /**
     * 전체 아이템 수를 반환합니다.
     *
     * 대표 1건만 실은 경로에서도 "외 N건" 을 그릴 수 있도록 DB 집계를 우선 쓴다. 집계 별칭
     * 유무는 값이 아니라 속성 키로 판정한다 — 0건의 `0` 과 "집계 안 함" 은 값으로 구분되지
     * 않는다.
     *
     * @return int|\Illuminate\Http\Resources\MissingValue 아이템 수
     */
    private function resolveItemCount()
    {
        if ($this->hasAggregate('options_count')) {
            return (int) $this->aggregate('options_count', 0);
        }

        if ($this->resource->relationLoaded('options')) {
            return $this->resource->options->count();
        }

        return new \Illuminate\Http\Resources\MissingValue;
    }

    /**
     * 부분취소 여부를 반환합니다.
     *
     * 대표 1건만 실은 경로는 옵션을 순회할 수 없으므로 DB 집계 두 개(전체 수 / 취소 수)로
     * 같은 판정을 얻는다.
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
}
