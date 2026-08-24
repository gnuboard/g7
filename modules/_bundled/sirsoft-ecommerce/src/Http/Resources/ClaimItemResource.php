<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 클레임 신청 상품 행 리소스 (교환·반품·환불·취소 공용)
 *
 * 네 유형의 상품 행은 컬럼명이 다르다(`exchange_quantity` / `return_quantity` / `quantity` / `cancel_quantity`).
 * 화면은 상품 단위 행을 같은 모양으로 그리므로(D24) 여기서 공통 키(`quantity` / `status`)로 정규화하고,
 * 교환 전용 축(교환 대상 옵션·차액)은 있을 때만 싣는다. 상품명·옵션명은 주문옵션 스냅샷에서 읽는다 —
 * `orderOption` 관계는 Repository 가 함께 싣는다.
 */
class ClaimItemResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 응답 배열
     */
    public function toArray(Request $request): array
    {
        $currency = $this->orderCurrencyCode;
        $orderOption = $this->whenLoaded('orderOption', fn () => $this->orderOption, null);
        $locale = app()->getLocale();
        $status = $this->option_status;
        $quantity = (int) ($this->exchange_quantity ?? $this->return_quantity ?? $this->cancel_quantity ?? $this->quantity ?? 0);
        $unitPrice = (float) ($this->unit_price ?? 0);

        $row = [
            'id' => $this->id,
            'order_option_id' => $this->order_option_id,
            'quantity' => $quantity,
            'original_quantity' => $this->original_quantity ?? null,
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'status_label' => $status instanceof \BackedEnum && method_exists($status, 'label') ? $status->label() : null,
            'status_variant' => $status instanceof \BackedEnum && method_exists($status, 'variant') ? $status->variant() : null,
            'unit_price' => $this->roundToOrderCurrency($unitPrice, $currency),
            'unit_price_formatted' => $this->formatOrderCurrency($unitPrice, $currency),
            'subtotal_amount' => $this->roundToOrderCurrency($unitPrice * $quantity, $currency),
            'subtotal_amount_formatted' => $this->formatOrderCurrency($unitPrice * $quantity, $currency),
            'product_name' => $this->localize($orderOption?->product_name, $locale),
            'product_option_name' => $this->localize($orderOption?->product_option_name, $locale),
            'option_name' => $this->localize($orderOption?->option_name, $locale),
            'thumbnail_url' => $orderOption?->product_snapshot['thumbnail_url'] ?? null,
            'product_id' => $orderOption?->product_id,
            'product_option_id' => $orderOption?->product_option_id,
            'completed_at' => $this->formatDateTimeStringForUser($this->completed_at ?? null),
        ];

        // 별칭 존재 판정은 `array_key_exists` 단독이어야 한다 — 값이 NULL 인 행에서 `!== null` 을
        // 앞에 두면 키가 통째로 사라져 "그 컬럼을 싣지 않는 유형" 과 구분되지 않는다(아래 블록이 표준형).
        if (array_key_exists('refund_amount', $this->resource->getAttributes())) {
            $row['refund_amount'] = $this->roundToOrderCurrency((float) $this->refund_amount, $currency);
            $row['refund_amount_formatted'] = $this->formatOrderCurrency((float) $this->refund_amount, $currency);
        }

        if (array_key_exists('new_product_option_id', $this->resource->getAttributes())) {
            $newUnitPrice = (float) ($this->new_unit_price ?? $unitPrice);
            $difference = (float) ($this->price_difference ?? 0);

            $row += [
                'is_option_change' => $this->new_product_option_id !== null,
                'new_product_option_id' => $this->new_product_option_id,
                'new_option_snapshot' => $this->new_option_snapshot,
                'new_option_label' => $this->new_option_snapshot['option_label'] ?? $this->new_option_snapshot['name'] ?? null,
                'new_unit_price' => $this->roundToOrderCurrency($newUnitPrice, $currency),
                'new_unit_price_formatted' => $this->formatOrderCurrency($newUnitPrice, $currency),
                'price_difference' => $this->roundToOrderCurrency($difference, $currency),
                'price_difference_formatted' => $this->formatOrderCurrency($difference, $currency),
                'mc_price_difference' => $this->formatStoredMultiCurrency($this->mc_price_difference),
                'new_order_option_id' => $this->new_order_option_id,
            ];
        }

        return $row;
    }

    /**
     * 다국어 배열 값을 현재 로케일 문자열로 풀어냅니다.
     *
     * @param  mixed  $value  다국어 배열 또는 문자열
     * @param  string  $locale  로케일
     * @return string|null 현지화 문자열
     */
    protected function localize(mixed $value, string $locale): ?string
    {
        if (is_array($value)) {
            $value = $value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? (reset($value) ?: null);
        }

        return $value === null ? null : (string) $value;
    }
}
