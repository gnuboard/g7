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

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->order_status?->value,
            'status_label' => $this->order_status?->label(),
            'status_variant' => $this->order_status?->variant(),
            // 부분취소 파생 플래그 — 일부 옵션만 취소된 주문(별도 order_status 아님). 보조 뱃지 표시용.
            'is_partially_cancelled' => $this->whenLoaded('options', fn () => $this->resource->isPartiallyCancelled(), false),

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
            'items' => $this->whenLoaded('options', fn () => $this->options->map(fn ($option) => [
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
            ])->toArray()),
            'item_count' => $this->whenLoaded('options', fn () => $this->options->count()),

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
        ];
    }
}
