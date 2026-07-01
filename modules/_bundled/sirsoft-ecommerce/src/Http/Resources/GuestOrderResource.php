<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 비회원 주문 상세 리소스
 *
 * 조회 인증 토큰으로 보호되는 비회원 주문 상세 응답 전용 리소스.
 * 회원용 OrderResource 와 달리 다음을 노출하지 않는다.
 * - admin_memo / customer_memo (관리자·내부 메모)
 * - user / user_id / user_login_id (회원 정보)
 * - promotions_applied_snapshot / shipping_policy_applied_snapshot (내부 계산 스냅샷)
 * - 회원 권한 메타(resourceMeta/abilityMap)
 *
 * 허용 액션(abilities)은 회원 권한이 아니라 주문 상태로만 판정한다.
 */
class GuestOrderResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 비회원 주문 상세 응답 배열
     */
    public function toArray(Request $request): array
    {
        // 주문 시점 기준 통화 — 과거 주문의 *_formatted 는 설정 변경과 무관하게 이 통화로 고정 표기한다.
        $orderCurrency = $this->resolveOrderBaseCurrencyCode($this->resource);

        return [
            'order_number' => $this->order_number,
            'order_status' => $this->order_status,
            'order_status_label' => $this->order_status ? $this->order_status->label() : null,
            'order_status_variant' => $this->order_status ? $this->order_status->variant() : null,
            // 부분취소 파생 플래그 — 일부 옵션만 취소된 주문(별도 order_status 아님). 보조 뱃지 표시용.
            'is_partially_cancelled' => $this->whenLoaded('options', fn () => $this->resource->isPartiallyCancelled(), false),

            // 금액
            'subtotal_amount' => $this->roundToOrderCurrency($this->subtotal_amount, $orderCurrency),
            'subtotal_amount_formatted' => $this->formatOrderCurrency($this->subtotal_amount, $orderCurrency),
            'total_discount_amount' => $this->roundToOrderCurrency($this->total_discount_amount, $orderCurrency),
            'total_discount_amount_formatted' => $this->formatOrderCurrency($this->total_discount_amount, $orderCurrency),
            'total_shipping_amount' => $this->roundToOrderCurrency($this->total_shipping_amount, $orderCurrency),
            'total_shipping_amount_formatted' => $this->formatOrderCurrency($this->total_shipping_amount, $orderCurrency),
            'total_amount' => $this->roundToOrderCurrency($this->total_amount, $orderCurrency),
            'total_amount_formatted' => $this->formatOrderCurrency($this->total_amount, $orderCurrency),
            'total_paid_amount' => $this->roundToOrderCurrency($this->total_paid_amount, $orderCurrency),
            'total_paid_amount_formatted' => $this->formatOrderCurrency($this->total_paid_amount, $orderCurrency),
            'total_cancelled_amount' => $this->roundToOrderCurrency($this->total_cancelled_amount, $orderCurrency),
            'total_cancelled_amount_formatted' => $this->formatOrderCurrency($this->total_cancelled_amount, $orderCurrency),
            'total_refunded_amount' => $this->roundToOrderCurrency($this->total_refunded_amount, $orderCurrency),
            'total_refunded_amount_formatted' => $this->formatOrderCurrency($this->total_refunded_amount, $orderCurrency),
            'total_refunded_points_amount' => $this->roundToOrderCurrency($this->total_refunded_points_amount, $orderCurrency),
            'total_refunded_points_amount_formatted' => $this->formatOrderCurrency($this->total_refunded_points_amount, $orderCurrency),

            // 마일리지/예치금 — 회원 OrderResource 와 동일 노출. 비회원 주문상세도 회원과 같은 결제정보 partial 을
            // 재사용하므로 사용/적립 마일리지를 동등하게 표시할 수 있도록 보강 (마일리지는 base_currency 단일 정산).
            'total_points_used_amount' => $this->roundToOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_points_used_amount_formatted' => $this->formatOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_deposit_used_amount' => $this->roundToOrderCurrency($this->total_deposit_used_amount, $orderCurrency),
            'total_deposit_used_amount_formatted' => $this->formatOrderCurrency($this->total_deposit_used_amount, $orderCurrency),
            'total_earned_points_amount' => $this->roundToOrderCurrency($this->total_earned_points_amount, $orderCurrency),
            'total_earned_points_amount_formatted' => $this->formatOrderCurrency($this->total_earned_points_amount, $orderCurrency),

            // 다중 통화 금액 (주문 시점 스냅샷) — 회원 OrderResource 와 동일 노출.
            // 통화 선택 UI 가 추후 노출되어도 비회원 화면이 회원과 동등하게 다중 통화를 표시할 수 있도록 보강.
            'mc_subtotal_amount' => $this->formatStoredMultiCurrency($this->mc_subtotal_amount),
            'mc_total_discount_amount' => $this->formatStoredMultiCurrency($this->mc_total_discount_amount),
            'mc_total_shipping_amount' => $this->formatStoredMultiCurrency($this->mc_total_shipping_amount),
            'mc_total_amount' => $this->formatStoredMultiCurrency($this->mc_total_amount),
            'mc_total_points_used_amount' => $this->formatStoredMultiCurrency($this->mc_total_points_used_amount),
            'mc_total_deposit_used_amount' => $this->formatStoredMultiCurrency($this->mc_total_deposit_used_amount),

            // 수량
            'item_count' => $this->item_count,
            'total_quantity' => $this->whenLoaded('options', fn () => $this->options->sum('quantity'), 0),

            // 일시 — raw ISO 와 사용자 타임존 변환된 *_formatted 를 함께 제공 (회원 OrderResource 와 동일 패턴)
            'ordered_at' => $this->ordered_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'ordered_at_formatted' => $this->formatDateTimeStringForUser($this->ordered_at),
            'paid_at' => $this->paid_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'paid_at_formatted' => $this->formatDateTimeStringForUser($this->paid_at),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'confirmed_at_formatted' => $this->formatDateTimeStringForUser($this->confirmed_at),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'cancelled_at_formatted' => $this->formatDateTimeStringForUser($this->cancelled_at),

            // 주문자 정보 (본인이 입력한 정보 — 배송지에서 플래튼)
            'orderer_name' => $this->shippingAddress?->orderer_name,
            'orderer_phone' => $this->shippingAddress?->orderer_phone,
            'orderer_email' => $this->shippingAddress?->orderer_email,

            // 수취인/배송지 정보
            'recipient_name' => $this->shippingAddress?->recipient_name,
            'recipient_phone' => $this->shippingAddress?->recipient_phone,
            'recipient_zipcode' => $this->shippingAddress?->zipcode,
            'recipient_address' => $this->shippingAddress?->address,
            'recipient_detail_address' => $this->shippingAddress?->address_detail,
            'delivery_memo' => $this->shippingAddress?->delivery_memo,
            'delivery_memo_label' => $this->shippingAddress?->delivery_memo_label,

            // 주문 옵션 (품목) — 주문 시점 통화를 자식에 전파
            'options' => $this->whenLoaded('options', fn () => OrderOptionResource::collection($this->options)->each(
                fn ($r) => $r->withOrderCurrency($orderCurrency)
            )),

            // 배송지/결제/배송 정보
            'shipping_address' => new OrderAddressResource($this->whenLoaded('shippingAddress')),
            'payment' => $this->whenLoaded('payment', fn () => (new OrderPaymentResource($this->payment))->withOrderCurrency($orderCurrency)),
            'shippings' => $this->whenLoaded('shippings', fn () => OrderShippingResource::collection($this->shippings)->each(
                fn ($r) => $r->withOrderCurrency($orderCurrency)
            )),

            // 취소 이력 (취소 사유·상세 사유·취소일시 표시용) — 최근 취소가 먼저 오도록 정렬
            'cancels' => OrderCancelResource::collection($this->whenLoaded('cancels')),

            // 상태 기반 허용 액션 (회원 권한이 아닌 주문 상태로만 판정)
            'abilities' => $this->guestAbilities(),
        ];
    }

    /**
     * 비회원에게 허용되는 액션을 주문 상태 기준으로 계산합니다.
     *
     * 토큰 인증으로 본인 확인이 이미 끝났으므로 회원 권한 체크는 적용하지 않고,
     * 주문 상태/환경설정으로만 가능 여부를 판정합니다.
     *
     * @return array<string, bool>
     */
    private function guestAbilities(): array
    {
        $cancellableStatuses = module_setting(
            'sirsoft-ecommerce',
            'order_settings.cancellable_statuses',
            ['payment_complete']
        );

        return [
            'can_cancel' => $this->resource->isCancellable($cancellableStatuses),
        ];
    }
}
