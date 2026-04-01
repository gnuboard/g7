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
            'order_device' => $this->order_device,
            'order_device_label' => $this->order_device ? $this->order_device->label() : null,
            'is_first_order' => $this->is_first_order,

            // 금액
            'subtotal_amount' => $this->subtotal_amount,
            'subtotal_amount_formatted' => number_format($this->subtotal_amount).'원',
            'total_discount_amount' => $this->total_discount_amount,
            'total_discount_amount_formatted' => number_format($this->total_discount_amount).'원',
            'total_shipping_amount' => $this->total_shipping_amount,
            'total_shipping_amount_formatted' => number_format($this->total_shipping_amount).'원',
            'total_amount' => $this->total_amount,
            'total_amount_formatted' => number_format($this->total_amount).'원',
            'total_paid_amount' => $this->total_paid_amount,
            'total_paid_amount_formatted' => number_format($this->total_paid_amount).'원',
            'total_cancelled_amount' => $this->total_cancelled_amount,
            'total_cancelled_amount_formatted' => number_format($this->total_cancelled_amount).'원',
            'total_refunded_amount' => $this->total_refunded_amount,
            'total_refunded_amount_formatted' => number_format($this->total_refunded_amount).'원',

            // 할인 상세
            'total_product_coupon_discount_amount' => $this->total_product_coupon_discount_amount,
            'total_product_coupon_discount_amount_formatted' => number_format($this->total_product_coupon_discount_amount).'원',
            'total_order_coupon_discount_amount' => $this->total_order_coupon_discount_amount,
            'total_order_coupon_discount_amount_formatted' => number_format($this->total_order_coupon_discount_amount).'원',
            'total_coupon_discount_amount' => $this->total_coupon_discount_amount,
            'total_coupon_discount_amount_formatted' => number_format($this->total_coupon_discount_amount).'원',
            'total_code_discount_amount' => $this->total_code_discount_amount,
            'total_code_discount_amount_formatted' => number_format($this->total_code_discount_amount).'원',

            // 마일리지/예치금
            'total_points_used_amount' => $this->total_points_used_amount,
            'total_points_used_amount_formatted' => number_format($this->total_points_used_amount).'원',
            'total_deposit_used_amount' => $this->total_deposit_used_amount,
            'total_deposit_used_amount_formatted' => number_format($this->total_deposit_used_amount).'원',
            'total_earned_points_amount' => $this->total_earned_points_amount,
            'total_earned_points_amount_formatted' => number_format($this->total_earned_points_amount).'원',

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
            'total_list_price' => $this->whenLoaded('options', fn () => $this->options->sum(function ($opt) {
                $listPrice = $opt->option_snapshot['list_price'] ?? $opt->product_snapshot['list_price'] ?? $opt->unit_price;
                return $listPrice * $opt->quantity;
            }), 0),
            'total_list_price_formatted' => $this->whenLoaded('options', fn () => number_format($this->options->sum(function ($opt) {
                $listPrice = $opt->option_snapshot['list_price'] ?? $opt->product_snapshot['list_price'] ?? $opt->unit_price;
                return $listPrice * $opt->quantity;
            })).'원', '0원'),

            // 일시
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'ordered_at_formatted' => $this->formatDateTimeStringForUser($this->ordered_at),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_at_formatted' => $this->formatDateTimeStringForUser($this->paid_at),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confirmed_at_formatted' => $this->formatDateTimeStringForUser($this->confirmed_at),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),

            // 세금
            'total_tax_amount' => $this->total_tax_amount,
            'total_tax_amount_formatted' => number_format($this->total_tax_amount).'원',
            'total_vat_amount' => $this->total_vat_amount,
            'total_vat_amount_formatted' => number_format($this->total_vat_amount).'원',
            'total_tax_free_amount' => $this->total_tax_free_amount,
            'total_tax_free_amount_formatted' => number_format($this->total_tax_free_amount).'원',

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

            // 주문 옵션 (품목)
            'options' => OrderOptionResource::collection($this->whenLoaded('options')),

            // 배송지 정보
            'shipping_address' => new OrderAddressResource($this->whenLoaded('shippingAddress')),
            'billing_address' => new OrderAddressResource($this->whenLoaded('billingAddress')),

            // 결제 정보
            'payment' => new OrderPaymentResource($this->whenLoaded('payment')),
            'payments' => OrderPaymentResource::collection($this->whenLoaded('payments')),

            // 배송 정보
            'shippings' => OrderShippingResource::collection($this->whenLoaded('shippings')),

            // 프로모션/배송정책 스냅샷
            'promotions_applied_snapshot' => $this->promotions_applied_snapshot,
            'shipping_policy_applied_snapshot' => $this->shipping_policy_applied_snapshot,

            // 메모
            'admin_memo' => $this->admin_memo,
            'customer_memo' => $this->customer_memo,

            // 시스템
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

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
     * @param Request $request HTTP 요청 객체
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
