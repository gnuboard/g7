<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 주문 옵션 리소스
 */
class OrderOptionResource extends BaseApiResource
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
        $listPrice = $this->option_snapshot['list_price'] ?? $this->product_snapshot['list_price'] ?? null;
        $finalAmount = $this->getActualPaymentAmount();

        return [
            'id' => $this->id,
            'option_status' => $this->option_status,
            'option_status_label' => $this->option_status ? $this->option_status->label() : null,
            'option_status_variant' => $this->option_status ? $this->option_status->variant() : null,
            'product_id' => $this->product_id,
            'product_option_id' => $this->product_option_id,
            'sku' => $this->sku,
            'product_name' => is_array($this->product_name)
                ? ($this->product_name[app()->getLocale()] ?? reset($this->product_name) ?: '')
                : ($this->product_name ?? ''),
            'product_option_name' => is_array($this->product_option_name)
                ? ($this->product_option_name[app()->getLocale()] ?? reset($this->product_option_name) ?: '')
                : ($this->product_option_name ?? ''),
            'option_name' => is_array($this->option_name)
                ? ($this->option_name[app()->getLocale()] ?? reset($this->option_name) ?: '')
                : ($this->option_name ?? ''),
            'quantity' => $this->quantity,
            'shipped_quantity' => $this->shipped_quantity,
            'unit_price' => $this->unit_price,
            'unit_price_formatted' => number_format($this->unit_price).'원',
            'subtotal_price' => $this->subtotal_price,
            'subtotal_price_formatted' => number_format($this->subtotal_price).'원',
            'subtotal_discount_amount' => $this->subtotal_discount_amount,
            'subtotal_discount_amount_formatted' => number_format($this->subtotal_discount_amount).'원',

            // 정가 (스냅샷 기준)
            'list_price' => $listPrice,
            'list_price_formatted' => $listPrice !== null ? number_format($listPrice).'원' : null,

            // 실결제 금액 (할인 후)
            'final_amount' => $finalAmount,
            'final_amount_formatted' => number_format($finalAmount).'원',

            // 할인 상세
            'product_coupon_discount_amount' => $this->product_coupon_discount_amount,
            'product_coupon_discount_amount_formatted' => number_format($this->product_coupon_discount_amount).'원',
            'order_coupon_discount_amount' => $this->order_coupon_discount_amount,
            'order_coupon_discount_amount_formatted' => number_format($this->order_coupon_discount_amount).'원',
            'coupon_discount_amount' => $this->coupon_discount_amount,
            'coupon_discount_amount_formatted' => number_format($this->coupon_discount_amount).'원',
            'code_discount_amount' => $this->code_discount_amount,
            'code_discount_amount_formatted' => number_format($this->code_discount_amount).'원',

            // 마일리지/예치금
            'subtotal_points_used_amount' => $this->subtotal_points_used_amount,
            'subtotal_points_used_amount_formatted' => number_format($this->subtotal_points_used_amount).'원',
            'subtotal_deposit_used_amount' => $this->subtotal_deposit_used_amount,
            'subtotal_deposit_used_amount_formatted' => number_format($this->subtotal_deposit_used_amount).'원',
            'subtotal_earned_points_amount' => $this->subtotal_earned_points_amount,
            'subtotal_earned_points_amount_formatted' => number_format($this->subtotal_earned_points_amount).'원',

            // 프로모션 스냅샷
            'promotions_applied_snapshot' => $this->promotions_applied_snapshot,

            'product_snapshot' => $this->product_snapshot,
            'option_snapshot' => $this->option_snapshot,
            'thumbnail_url' => $this->product_snapshot['thumbnail_url'] ?? null,
            'parent_option_id' => $this->parent_option_id,
            'source_option_id' => $this->source_option_id,
            'source_type' => $this->source_type,

            // 배송 정보 (첫 번째 배송 레코드 기준)
            'shipping_policy_name' => $this->whenLoaded('shippings', function () {
                return $this->shippings->first()?->delivery_policy_snapshot['policy_name'] ?? null;
            }),
            'shipping_type_label' => $this->whenLoaded('shippings', function () {
                return $this->shippings->first()?->shipping_type?->label();
            }),
            'shipping_amount' => $this->whenLoaded('shippings', function () {
                return $this->shippings->first()?->total_shipping_amount ?? 0;
            }),
            'shipping_amount_formatted' => $this->whenLoaded('shippings', function () {
                return number_format($this->shippings->first()?->total_shipping_amount ?? 0).'원';
            }),
            'carrier_name' => $this->whenLoaded('shippings', function () {
                return $this->shippings->first()?->carrier?->getLocalizedName();
            }),
            'tracking_number' => $this->whenLoaded('shippings', function () {
                return $this->shippings->first()?->tracking_number;
            }),

            // 다중 통화 (주문 시점 스냅샷)
            'mc_unit_price' => $this->formatStoredMultiCurrency($this->mc_unit_price),
            'mc_subtotal_price' => $this->formatStoredMultiCurrency($this->mc_subtotal_price),
            'mc_final_amount' => $this->formatStoredMultiCurrency($this->mc_final_amount),
            'mc_product_coupon_discount_amount' => $this->formatStoredMultiCurrency($this->mc_product_coupon_discount_amount),
            'mc_order_coupon_discount_amount' => $this->formatStoredMultiCurrency($this->mc_order_coupon_discount_amount),
            'mc_coupon_discount_amount' => $this->formatStoredMultiCurrency($this->mc_coupon_discount_amount),
            'mc_code_discount_amount' => $this->formatStoredMultiCurrency($this->mc_code_discount_amount),
            'mc_subtotal_points_used_amount' => $this->formatStoredMultiCurrency($this->mc_subtotal_points_used_amount),
            'mc_subtotal_deposit_used_amount' => $this->formatStoredMultiCurrency($this->mc_subtotal_deposit_used_amount),

            // 구매확정/리뷰 관련
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'can_confirm' => $this->calculateCanConfirm(),
            'can_write_review' => $this->calculateCanWriteReview(),
            'has_review' => $this->review !== null,
        ];
    }

    /**
     * 구매확정 가능 여부 계산
     *
     * @return bool
     */
    private function calculateCanConfirm(): bool
    {
        $confirmableStatuses = module_setting(
            'sirsoft-ecommerce',
            'order_settings.confirmable_statuses',
            ['shipping', 'delivered']
        );

        return in_array($this->option_status->value, $confirmableStatuses)
            && PermissionHelper::check('sirsoft-ecommerce.user-orders.confirm');
    }

    /**
     * 리뷰 작성 가능 여부 계산
     *
     * @return bool
     */
    private function calculateCanWriteReview(): bool
    {
        if ($this->option_status !== OrderStatusEnum::CONFIRMED) {
            return false;
        }

        $deadlineDays = module_setting('sirsoft-ecommerce', 'review_settings.write_deadline_days', 90);
        if ($this->confirmed_at && $this->confirmed_at->diffInDays(now()) > $deadlineDays) {
            return false;
        }

        if ($this->review !== null) {
            return false;
        }

        return PermissionHelper::check('sirsoft-ecommerce.user-reviews.write');
    }
}
