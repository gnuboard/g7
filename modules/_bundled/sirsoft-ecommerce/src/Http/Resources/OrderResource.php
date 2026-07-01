<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 주문 상세 리소스
 */
class OrderResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 직렬화된 주문 리소스 배열
     */
    public function toArray(Request $request): array
    {
        // 주문 시점 기준 통화 — 과거 주문의 *_formatted 는 설정 변경과 무관하게 이 통화로 고정 표기한다.
        $orderCurrency = $this->resolveOrderBaseCurrencyCode($this->resource);

        // 결제 통화(order_currency) — 유저가 선택·결제한 통화. base 통화와 다를 때 화면에 함께 표기한다.
        $paymentCurrency = $this->currency
            ?: (data_get($this->currency_snapshot, 'order_currency') ?: $orderCurrency);

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            // 주문 금액 표기 기준 통화(base_currency) — 모든 *_formatted 의 통화. 다통화 병기 시 base 제외 필터에 사용.
            'base_currency' => $orderCurrency,
            // 결제 통화(order_currency) + base≠결제 통화 여부.
            'payment_currency' => $paymentCurrency,
            'is_cross_currency' => $paymentCurrency !== $orderCurrency,
            'order_status' => $this->order_status,
            'order_status_label' => $this->order_status ? $this->order_status->label() : null,
            'order_status_variant' => $this->order_status ? $this->order_status->variant() : null,
            // 부분취소 파생 플래그 — 일부 옵션만 취소된 주문(취소옵션 ∧ 잔여 활성옵션). 별도 order_status 아님.
            // 주문 상태는 잔여 활성 옵션 기준으로 결정되고, "일부 취소됨" 표시는 이 플래그로 한다 (partial_cancelled 제거).
            'is_partially_cancelled' => $this->isPartiallyCancelled(),
            'order_device' => $this->order_device,
            'order_device_label' => $this->order_device ? $this->order_device->label() : null,
            'is_first_order' => $this->is_first_order,

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
            // 입금 필요액(무통장 입금확인 모달의 기본 입금액·결제예정금액 표시 SSoT)
            'total_due_amount' => $this->roundToOrderCurrency((float) $this->total_due_amount, $orderCurrency),
            'total_due_amount_formatted' => $this->formatOrderCurrency((float) $this->total_due_amount, $orderCurrency),
            // 입금자명(무통장 입금확인 모달 기본값) — payment 관계 로드 시에만 노출 (N+1 방지)
            'depositor_name' => $this->whenLoaded('payment', fn () => $this->payment?->depositor_name),
            'total_cancelled_amount' => $this->roundToOrderCurrency($this->total_cancelled_amount, $orderCurrency),
            'total_cancelled_amount_formatted' => $this->formatOrderCurrency($this->total_cancelled_amount, $orderCurrency),
            'total_refunded_amount' => $this->roundToOrderCurrency($this->total_refunded_amount, $orderCurrency),
            'total_refunded_amount_formatted' => $this->formatOrderCurrency($this->total_refunded_amount, $orderCurrency),
            'total_refunded_points_amount' => $this->roundToOrderCurrency($this->total_refunded_points_amount, $orderCurrency),
            'total_refunded_points_amount_formatted' => $this->formatOrderCurrency($this->total_refunded_points_amount, $orderCurrency),

            // 할인 상세
            'total_product_coupon_discount_amount' => $this->roundToOrderCurrency($this->total_product_coupon_discount_amount, $orderCurrency),
            'total_product_coupon_discount_amount_formatted' => $this->formatOrderCurrency($this->total_product_coupon_discount_amount, $orderCurrency),
            'total_order_coupon_discount_amount' => $this->roundToOrderCurrency($this->total_order_coupon_discount_amount, $orderCurrency),
            'total_order_coupon_discount_amount_formatted' => $this->formatOrderCurrency($this->total_order_coupon_discount_amount, $orderCurrency),
            'total_coupon_discount_amount' => $this->roundToOrderCurrency($this->total_coupon_discount_amount, $orderCurrency),
            'total_coupon_discount_amount_formatted' => $this->formatOrderCurrency($this->total_coupon_discount_amount, $orderCurrency),
            'total_code_discount_amount' => $this->roundToOrderCurrency($this->total_code_discount_amount, $orderCurrency),
            'total_code_discount_amount_formatted' => $this->formatOrderCurrency($this->total_code_discount_amount, $orderCurrency),

            // 마일리지/예치금
            'total_points_used_amount' => $this->roundToOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_points_used_amount_formatted' => $this->formatOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_deposit_used_amount' => $this->roundToOrderCurrency($this->total_deposit_used_amount, $orderCurrency),
            'total_deposit_used_amount_formatted' => $this->formatOrderCurrency($this->total_deposit_used_amount, $orderCurrency),
            'total_earned_points_amount' => $this->roundToOrderCurrency($this->total_earned_points_amount, $orderCurrency),
            'total_earned_points_amount_formatted' => $this->formatOrderCurrency($this->total_earned_points_amount, $orderCurrency),

            // 다중 통화 금액 (주문 시점 스냅샷)
            'mc_subtotal_amount' => $this->formatStoredMultiCurrency($this->mc_subtotal_amount),
            'mc_total_discount_amount' => $this->formatStoredMultiCurrency($this->mc_total_discount_amount),
            'mc_total_shipping_amount' => $this->formatStoredMultiCurrency($this->mc_total_shipping_amount),
            'mc_total_amount' => $this->formatStoredMultiCurrency($this->mc_total_amount),
            'mc_total_product_coupon_discount_amount' => $this->formatStoredMultiCurrency($this->mc_total_product_coupon_discount_amount),
            'mc_total_order_coupon_discount_amount' => $this->formatStoredMultiCurrency($this->mc_total_order_coupon_discount_amount),
            'mc_total_coupon_discount_amount' => $this->formatStoredMultiCurrency($this->mc_total_coupon_discount_amount),
            'mc_total_code_discount_amount' => $this->formatStoredMultiCurrency($this->mc_total_code_discount_amount),
            'mc_total_points_used_amount' => $this->formatStoredMultiCurrency($this->mc_total_points_used_amount),
            'mc_total_deposit_used_amount' => $this->formatStoredMultiCurrency($this->mc_total_deposit_used_amount),

            // 수량
            'item_count' => $this->item_count,
            'total_quantity' => $this->whenLoaded('options', fn () => $this->options->sum('quantity'), 0),

            // 정가 합계 (스냅샷 기준)
            'total_list_price' => $this->whenLoaded('options', fn () => $this->roundToOrderCurrency($this->options->sum(function ($opt) {
                $listPrice = $opt->option_snapshot['list_price'] ?? $opt->product_snapshot['list_price'] ?? $opt->unit_price;

                return $listPrice * $opt->quantity;
            }), $orderCurrency), 0),
            'total_list_price_formatted' => $this->whenLoaded('options', fn () => $this->formatOrderCurrency($this->options->sum(function ($opt) {
                $listPrice = $opt->option_snapshot['list_price'] ?? $opt->product_snapshot['list_price'] ?? $opt->unit_price;

                return $listPrice * $opt->quantity;
            }), $orderCurrency), $this->formatOrderCurrency(0, $orderCurrency)),

            // 일시 — raw ISO 와 사용자 타임존 변환된 *_formatted 를 함께 제공 (프론트 raw 처리 + 백엔드 사전 포맷 양쪽 지원)
            'ordered_at' => $this->ordered_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'ordered_at_formatted' => $this->formatDateTimeStringForUser($this->ordered_at),
            'paid_at' => $this->paid_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'paid_at_formatted' => $this->formatDateTimeStringForUser($this->paid_at),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'confirmed_at_formatted' => $this->formatDateTimeStringForUser($this->confirmed_at),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'cancelled_at_formatted' => $this->formatDateTimeStringForUser($this->cancelled_at),
            'delivered_at' => $this->delivered_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: raw ISO consumed by FE (legacy field, no *_formatted pair)

            // 세금
            'total_tax_amount' => $this->roundToOrderCurrency($this->total_tax_amount, $orderCurrency),
            'total_tax_amount_formatted' => $this->formatOrderCurrency($this->total_tax_amount, $orderCurrency),
            'total_vat_amount' => $this->roundToOrderCurrency($this->total_vat_amount, $orderCurrency),
            'total_vat_amount_formatted' => $this->formatOrderCurrency($this->total_vat_amount, $orderCurrency),
            // 과세 공급가액(과세금액 − 부가세) — 영수증의 "과세금액" 표시 SSoT (PG 영수증 레이아웃 인라인 계산 대체)
            'total_taxable_supply_amount' => $this->roundToOrderCurrency((float) $this->total_tax_amount - (float) $this->total_vat_amount, $orderCurrency),
            'total_taxable_supply_amount_formatted' => $this->formatOrderCurrency((float) $this->total_tax_amount - (float) $this->total_vat_amount, $orderCurrency),
            'total_tax_free_amount' => $this->roundToOrderCurrency($this->total_tax_free_amount, $orderCurrency),
            'total_tax_free_amount_formatted' => $this->formatOrderCurrency($this->total_tax_free_amount, $orderCurrency),

            // 회원 정보
            'user' => $this->whenLoaded('user', fn () => [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'user_id' => $this->user?->uuid,
            'user_login_id' => $this->user?->login_id,

            // 주문자 정보 (배송지에서 플래튼)
            'orderer_name' => $this->shippingAddress?->orderer_name,
            'orderer_phone' => $this->shippingAddress?->orderer_phone,
            'orderer_tel' => $this->shippingAddress?->orderer_tel,
            'orderer_email' => $this->shippingAddress?->orderer_email,

            // 수취인 정보 (배송지에서 플래튼)
            'recipient_name' => $this->shippingAddress?->recipient_name,
            'recipient_phone' => $this->shippingAddress?->recipient_phone,
            'recipient_tel' => $this->shippingAddress?->recipient_tel,
            'recipient_zipcode' => $this->shippingAddress?->zipcode,
            'recipient_address' => $this->shippingAddress?->address,
            'recipient_detail_address' => $this->shippingAddress?->address_detail,
            'delivery_memo' => $this->shippingAddress?->delivery_memo,
            'delivery_memo_label' => $this->shippingAddress?->delivery_memo_label,

            // 주문 옵션 (품목) — 주문 시점 통화를 자식에 전파
            'options' => $this->whenLoaded('options', fn () => OrderOptionResource::collection($this->options)->each(
                fn ($r) => $r->withOrderCurrency($orderCurrency)
            )),

            // 배송지 정보
            'shipping_address' => new OrderAddressResource($this->whenLoaded('shippingAddress')),
            'billing_address' => new OrderAddressResource($this->whenLoaded('billingAddress')),

            // 결제 정보
            'payment' => $this->whenLoaded('payment', fn () => (new OrderPaymentResource($this->payment))->withOrderCurrency($orderCurrency)),
            'payments' => $this->whenLoaded('payments', fn () => OrderPaymentResource::collection($this->payments)->each(
                fn ($r) => $r->withOrderCurrency($orderCurrency)
            )),

            // 배송 정보
            'shippings' => $this->whenLoaded('shippings', fn () => OrderShippingResource::collection($this->shippings)->each(
                fn ($r) => $r->withOrderCurrency($orderCurrency)
            )),

            // 취소 이력 (취소 사유·상세 사유·취소일시 표시용) — 최근 취소가 먼저 오도록 정렬
            'cancels' => OrderCancelResource::collection($this->whenLoaded('cancels')),

            // 프로모션/배송정책 스냅샷
            'promotions_applied_snapshot' => $this->promotions_applied_snapshot,
            'shipping_policy_applied_snapshot' => $this->shipping_policy_applied_snapshot,

            // 메모
            'admin_memo' => $this->admin_memo,
            'customer_memo' => $this->customer_memo,

            // 시스템 — raw ISO 메타 (프론트가 자체 타임존 처리)
            'created_at' => $this->created_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: system meta raw ISO consumed by FE
            'updated_at' => $this->updated_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: system meta raw ISO consumed by FE

            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 소유자 필드명을 반환합니다.
     *
     * @return string|null 소유자 필드명
     */
    protected function ownerField(): ?string
    {
        return 'user_id';
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
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
     * 능력 맵에 주문 취소 가능 여부를 추가합니다.
     *
     * can_cancel은 단순 권한이 아닌 "상태 + 환경설정 + 권한" 복합 조건이므로
     * resolveAbilities()를 override하여 동적으로 계산합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, bool> 능력 불리언 맵
     */
    protected function resolveAbilities(Request $request): array
    {
        $abilities = parent::resolveAbilities($request);

        $cancellableStatuses = module_setting(
            'sirsoft-ecommerce',
            'order_settings.cancellable_statuses',
            ['payment_complete']
        );

        $abilities['can_cancel'] = $this->resource->isCancellable($cancellableStatuses)
            && PermissionHelper::check('sirsoft-ecommerce.user-orders.cancel');

        return $abilities;
    }
}
