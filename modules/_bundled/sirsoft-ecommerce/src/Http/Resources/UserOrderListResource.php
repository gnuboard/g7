<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 사용자 주문 목록 리소스
 *
 * 마이페이지 주문내역에서 사용되는 유저 전용 목록 리소스입니다.
 * 관리자용 OrderListResource와 달리 admin_memo, user 정보 등을 제외합니다.
 */
class UserOrderListResource extends BaseApiResource
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
            'status' => $this->order_status?->value,
            'status_label' => $this->order_status?->label(),
            'status_variant' => $this->order_status?->variant(),

            // 일시
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'ordered_at_formatted' => $this->formatDateTimeStringForUser($this->ordered_at),

            // 금액
            'total_amount' => $this->total_amount,
            'total_amount_formatted' => number_format($this->total_amount).'원',
            'mc_total_amount' => $this->formatStoredMultiCurrency($this->mc_total_amount),

            // 배송비
            'total_shipping_amount' => $this->total_shipping_amount,
            'total_shipping_amount_formatted' => number_format($this->total_shipping_amount).'원',
            'mc_total_shipping_amount' => $this->formatStoredMultiCurrency($this->mc_total_shipping_amount),

            // 주문 옵션 (상품 정보)
            'items' => $this->whenLoaded('options', fn () => $this->options->map(fn ($option) => [
                'product_name' => is_array($option->product_name)
                    ? ($option->product_name[app()->getLocale()] ?? reset($option->product_name) ?: '')
                    : ($option->product_name ?? ''),
                'product_option_name' => is_array($option->product_option_name)
                    ? ($option->product_option_name[app()->getLocale()] ?? reset($option->product_option_name) ?: '')
                    : ($option->product_option_name ?? ''),
                'thumbnail_url' => $option->product_snapshot['thumbnail_url'] ?? null,
                'quantity' => $option->quantity,
                'unit_price_formatted' => number_format($option->unit_price).'원',
                'mc_unit_price' => $this->formatStoredMultiCurrency($option->mc_unit_price),
                'subtotal_price' => $option->subtotal_price,
                'subtotal_price_formatted' => number_format($option->subtotal_price).'원',
                'mc_subtotal_price' => $this->formatStoredMultiCurrency($option->mc_subtotal_price),
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
