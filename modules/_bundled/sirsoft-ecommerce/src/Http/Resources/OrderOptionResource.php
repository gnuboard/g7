<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;

/**
 * 주문 옵션 리소스
 */
class OrderOptionResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 주문 옵션 배열
     */
    public function toArray(Request $request): array
    {
        $listPrice = $this->option_snapshot['list_price'] ?? $this->product_snapshot['list_price'] ?? null;
        $finalAmount = $this->getActualPaymentAmount();

        // reset() 은 참조 인자를 요구하므로 Eloquent overloaded property 를 바로 전달하면
        // "Indirect modification" 경고가 에러로 승격됨. 로컬 변수로 복사 후 사용.
        $productName = $this->product_name;
        $productOptionName = $this->product_option_name;
        $optionName = $this->option_name;
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'option_status' => $this->option_status,
            'option_status_label' => $this->option_status ? $this->option_status->label() : null,
            'option_status_variant' => $this->option_status ? $this->option_status->variant() : null,
            'product_id' => $this->product_id,
            'product_option_id' => $this->product_option_id,
            'sku' => $this->sku,
            'product_name' => is_array($productName)
                ? ($productName[$locale] ?? reset($productName) ?: '')
                : ($productName ?? ''),
            'product_option_name' => is_array($productOptionName)
                ? ($productOptionName[$locale] ?? reset($productOptionName) ?: '')
                : ($productOptionName ?? ''),
            'option_name' => is_array($optionName)
                ? ($optionName[$locale] ?? reset($optionName) ?: '')
                : ($optionName ?? ''),
            'quantity' => $this->quantity,
            'shipped_quantity' => $this->shipped_quantity,

            // 재고 적용 여부 (재고 차감 완료 시 true)
            'is_stock_deducted' => (bool) $this->is_stock_deducted,
            // 실제 적립 발생 여부 (해당 옵션에 구매 적립 거래가 발행되었으면 true)
            // withExists('purchaseEarnTransactions') 로 로드된 집계 컬럼 사용 (미로드 경로에서는 false)
            'is_points_earned' => (bool) ($this->purchase_earn_transactions_exists ?? false),
            'unit_price' => $this->roundToOrderCurrency($this->unit_price),
            'unit_price_formatted' => $this->formatOrderCurrency($this->unit_price),
            'subtotal_price' => $this->roundToOrderCurrency($this->subtotal_price),
            'subtotal_price_formatted' => $this->formatOrderCurrency($this->subtotal_price),
            'subtotal_discount_amount' => $this->roundToOrderCurrency($this->subtotal_discount_amount),
            // 비교용 숫자 보조 필드 — decimal:2 캐스트는 JSON 에 문자열("0.00")로 직렬화되어
            // 레이아웃의 `=== 0` strict 비교가 항상 거짓이 된다(무할인 항목 소계 미표시 U19②).
            // 레이아웃이 숫자로 비교하도록 float 값을 함께 노출한다(기존 문자열 필드는 호환 유지).
            'subtotal_discount_amount_value' => (float) $this->subtotal_discount_amount,
            'subtotal_discount_amount_formatted' => $this->formatOrderCurrency($this->subtotal_discount_amount),

            // 정가 (스냅샷 기준)
            'list_price' => $listPrice !== null ? $this->roundToOrderCurrency($listPrice) : null,
            'list_price_formatted' => $listPrice !== null ? $this->formatOrderCurrency($listPrice) : null,

            // 실결제 금액 (할인 후)
            'final_amount' => $this->roundToOrderCurrency($finalAmount),
            'final_amount_formatted' => $this->formatOrderCurrency($finalAmount),

            // 할인 상세
            'product_coupon_discount_amount' => $this->roundToOrderCurrency($this->product_coupon_discount_amount),
            'product_coupon_discount_amount_formatted' => $this->formatOrderCurrency($this->product_coupon_discount_amount),
            'order_coupon_discount_amount' => $this->roundToOrderCurrency($this->order_coupon_discount_amount),
            'order_coupon_discount_amount_formatted' => $this->formatOrderCurrency($this->order_coupon_discount_amount),
            'coupon_discount_amount' => $this->roundToOrderCurrency($this->coupon_discount_amount),
            'coupon_discount_amount_formatted' => $this->formatOrderCurrency($this->coupon_discount_amount),
            'code_discount_amount' => $this->roundToOrderCurrency($this->code_discount_amount),
            'code_discount_amount_formatted' => $this->formatOrderCurrency($this->code_discount_amount),

            // 마일리지/예치금
            'subtotal_points_used_amount' => $this->roundToOrderCurrency($this->subtotal_points_used_amount),
            'subtotal_points_used_amount_formatted' => $this->formatOrderCurrency($this->subtotal_points_used_amount),
            'subtotal_deposit_used_amount' => $this->roundToOrderCurrency($this->subtotal_deposit_used_amount),
            'subtotal_deposit_used_amount_formatted' => $this->formatOrderCurrency($this->subtotal_deposit_used_amount),
            'subtotal_earned_points_amount' => $this->roundToOrderCurrency($this->subtotal_earned_points_amount),
            'subtotal_earned_points_amount_formatted' => $this->formatOrderCurrency($this->subtotal_earned_points_amount),

            // 프로모션 스냅샷
            'promotions_applied_snapshot' => $this->promotions_applied_snapshot,

            'product_snapshot' => $this->product_snapshot,
            'option_snapshot' => $this->option_snapshot,

            // 추가옵션 (주문 시점 스냅샷 — 관리자 수정/삭제와 무관하게 고정 표시 D8/D14)
            'additional_options' => $this->formatAdditionalOptionsSnapshot($locale),
            'additional_options_total' => $this->roundToOrderCurrency((float) $this->additional_options_total),
            'additional_options_total_formatted' => $this->formatOrderCurrency((float) $this->additional_options_total),
            'mc_additional_options_total' => $this->formatStoredMultiCurrency($this->mc_additional_options_total),

            'thumbnail_url' => $this->product_snapshot['thumbnail_url'] ?? null,
            'parent_option_id' => $this->parent_option_id,
            'source_option_id' => $this->source_option_id,
            'source_type' => $this->source_type,

            // 배송 정보 (첫 번째 배송 레코드 기준)
            'shipping_policy_name' => $this->whenLoaded('shippings', function () {
                return $this->shippings->first()?->delivery_policy_snapshot['policy_name'] ?? null;
            }),
            'shipping_type_label' => $this->whenLoaded('shippings', function () {
                $shippingType = $this->shippings->first()?->shipping_type;

                return $shippingType
                    ? ShippingType::getCachedByCode($shippingType)?->getLocalizedName()
                    : null;
            }),
            'shipping_method_label' => $this->whenLoaded('shippings', function () {
                $shipping = $this->shippings->first();
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
            }),
            'shipping_amount' => $this->whenLoaded('shippings', function () {
                return $this->roundToOrderCurrency($this->shippings->first()?->total_shipping_amount ?? 0);
            }),
            'shipping_amount_formatted' => $this->whenLoaded('shippings', function () {
                return $this->formatOrderCurrency($this->shippings->first()?->total_shipping_amount ?? 0);
            }),
            'carrier_name' => $this->whenLoaded('shippings', function () {
                return $this->shippings->first()?->carrier?->getLocalizedName();
            }),
            'tracking_number' => $this->whenLoaded('shippings', function () {
                return $this->shippings->first()?->tracking_number;
            }),
            'tracking_url' => $this->whenLoaded('shippings', function () {
                return $this->shippings->first()?->getTrackingUrl();
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
            // confirmed_at 은 화면 표시용이 아닌 클라이언트 계산용(리뷰 작성 기한 등) ISO8601 머신값
            'confirmed_at' => $this->confirmed_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601 for client-side calc, not a display field
            'can_confirm' => $this->calculateCanConfirm(),
            'can_write_review' => $this->calculateCanWriteReview(),
            // 구매확정 + 미작성 상태인데 작성 기한만 지나 리뷰를 쓸 수 없는 경우 true
            // (버튼이 사라진 이유를 사용자에게 안내하기 위한 플래그)
            'review_deadline_passed' => $this->calculateReviewDeadlinePassed(),
            'has_review' => $this->review !== null,
        ];
    }

    /**
     * 추가옵션 스냅샷을 표시용으로 변환합니다.
     *
     * 주문 시점 동결된 additional_options_snapshot 의 다국어 이름/추가금을
     * 현재 로케일로 해석합니다 (관리자 수정/삭제와 무관 — D8).
     *
     * @param  string  $locale  현재 로케일
     * @return array<int, array{additional_option_id: int, value_id: int, name: string, price_adjustment: int, custom_text: string}>
     */
    private function formatAdditionalOptionsSnapshot(string $locale): array
    {
        $snapshot = $this->additional_options_snapshot ?? [];

        if (! is_array($snapshot) || empty($snapshot)) {
            return [];
        }

        $result = [];
        foreach ($snapshot as $entry) {
            $name = $entry['name'] ?? '';
            if (is_array($name)) {
                $name = $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? (! empty($name) ? reset($name) : '');
            }

            $result[] = [
                'additional_option_id' => (int) ($entry['additional_option_id'] ?? 0),
                'value_id' => (int) ($entry['value_id'] ?? 0),
                'name' => (string) $name,
                'price_adjustment' => $this->roundToOrderCurrency((int) ($entry['price_adjustment'] ?? 0)),
                'price_adjustment_formatted' => $this->formatOrderCurrency((int) ($entry['price_adjustment'] ?? 0)),
                // 직접입력 텍스트 (주문 시점 동결 — 없으면 빈 문자열)
                'custom_text' => (string) ($entry['custom_text'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * 구매확정 가능 여부 계산
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
     * 마감일 계산은 ProductReviewService::canWrite 와 동일한 기준을 사용한다
     * (confirmed_at + N일 경계 비교, N=0 은 무제한). 버튼 노출과 제출 검증의
     * 판정이 어긋나지 않도록 단일 기준으로 통일.
     */
    private function calculateCanWriteReview(): bool
    {
        if ($this->option_status !== OrderStatusEnum::CONFIRMED) {
            return false;
        }

        if ($this->isReviewDeadlinePassed()) {
            return false;
        }

        if ($this->review !== null) {
            return false;
        }

        return PermissionHelper::check('sirsoft-ecommerce.user-reviews.write');
    }

    /**
     * 리뷰 작성 기한 만료 안내 노출 여부 계산
     *
     * 구매확정 + 미작성 상태에서 작성 기한만 지나 리뷰를 쓸 수 없는 경우 true.
     * (리뷰 작성 버튼이 사라진 이유를 사용자에게 안내하기 위한 플래그)
     */
    private function calculateReviewDeadlinePassed(): bool
    {
        if ($this->option_status !== OrderStatusEnum::CONFIRMED) {
            return false;
        }

        if ($this->review !== null) {
            return false;
        }

        return $this->isReviewDeadlinePassed();
    }

    /**
     * 구매확정 시점 기준 리뷰 작성 기한이 지났는지 판정
     *
     * ProductReviewService::canWrite 와 동일한 경계 비교를 사용한다
     * (confirmed_at + N일 < now). N(write_deadline_days)이 0 이하면 무제한으로 간주.
     */
    private function isReviewDeadlinePassed(): bool
    {
        $deadlineDays = (int) module_setting('sirsoft-ecommerce', 'review_settings.write_deadline_days', 90);

        if (! $this->confirmed_at || $deadlineDays <= 0) {
            return false;
        }

        return now()->gt($this->confirmed_at->copy()->addDays($deadlineDays));
    }
}
