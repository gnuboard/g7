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
            // 소계의 보조 통화. 클레임 수량은 주문 수량과 다를 수 있으므로(부분 반품) 주문옵션의
            // `mc_subtotal_price`(주문 수량 기준)가 아니라 `mc_unit_price × 클레임 수량` 으로 만든다.
            // 이 값이 없으면 화면의 보조 통화 줄이 다른 필드로 흘러간다 — 교환 차액을 소계 자리에
            // 그리면 동일상품 교환이 `28,000원 / $0.00` 이 되고, 차액 필드가 없는 반품·취소·환불은
            // 그 줄이 통째로 빈다(2026-09-01 실측). 둘 다 서식은 정상이라 흔적이 남지 않는다.
            'mc_subtotal_amount' => $this->formatStoredMultiCurrency(
                $this->multiplyMultiCurrency($orderOption?->mc_unit_price, $quantity)
            ),
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
                // 스냅샷이 실제로 담는 키는 `option_value`(항목명까지: "색상: 화이트, 사이즈: XL")와
                // `option_name`(짧은 표기: "화이트/XL")이다. 둘 다 다국어 배열이라 형제 필드와 같이
                // `localize()` 를 태운다 — 예전에는 존재하지 않는 `option_label`/`name` 을 읽어
                // **항상 null** 이었고, 목록 카드의 `⇄` 줄과 상세의 교환 대상 줄이 옵션명 없이
                // 차액만 그렸다(2026-09-01 4차 계획 검증 실측). 동일상품 교환에는 이 블록이
                // 서지 않아 옵션변경 교환이 생기기 전에는 드러나지 않았다.
                'new_option_label' => $this->localize($this->new_option_snapshot['option_value'] ?? null, $locale)
                    ?? $this->localize($this->new_option_snapshot['option_name'] ?? null, $locale),
                // 교환에 얹은 추가옵션 — 신청 시점에 이름과 추가금이 동결된다(주문과 같은 형태).
                // 운영자가 나중에 선택지를 고치거나 지워도 신청서에 남은 표기는 바뀌지 않는다.
                'new_additional_options' => $this->formatAdditionalOptionsSnapshot(
                    $this->new_option_snapshot['additional_options_snapshot'] ?? [],
                    $locale,
                    $currency
                ),
                'new_additional_options_total' => $this->roundToOrderCurrency(
                    (float) ($this->new_option_snapshot['additional_options_total'] ?? 0),
                    $currency
                ),
                'new_additional_options_total_formatted' => $this->formatOrderCurrency(
                    (float) ($this->new_option_snapshot['additional_options_total'] ?? 0),
                    $currency
                ),
                'new_unit_price' => $this->roundToOrderCurrency($newUnitPrice, $currency),
                'new_unit_price_formatted' => $this->formatOrderCurrency($newUnitPrice, $currency),
                // 원 금액 자리가 소계(`subtotal_amount`)이므로 교환 후 금액도 소계로 낸다.
                // 화면이 단가를 그리면 수량 1 에서는 같아 보이고 수량 2 이상에서만 값이
                // 어긋나, 같은 줄의 좌우가 서로 다른 단위를 말한다(S17 B8).
                'new_subtotal_amount' => $this->roundToOrderCurrency($newUnitPrice * $quantity, $currency),
                'new_subtotal_amount_formatted' => $this->formatOrderCurrency($newUnitPrice * $quantity, $currency),
                'price_difference' => $this->roundToOrderCurrency($difference, $currency),
                'price_difference_formatted' => $this->formatOrderCurrency($difference, $currency),
                'mc_price_difference' => $this->formatStoredMultiCurrency($this->mc_price_difference),
                'new_order_option_id' => $this->new_order_option_id,
            ];
        }

        return $row;
    }

    /**
     * 추가옵션 스냅샷을 표시용으로 변환합니다.
     *
     * 신청 시점 동결된 이름·추가금을 현재 로케일로 해석한다 — 형제인 주문 상품 행
     * (`OrderOptionResource::formatAdditionalOptionsSnapshot`)과 같은 형태여야 화면이
     * 두 자리에서 같은 것을 그린다.
     *
     * @param  mixed  $snapshot  동결 스냅샷 [{additional_option_id, value_id, name, price_adjustment, custom_text?}]
     * @param  string  $locale  현재 로케일
     * @param  string|null  $currency  주문 시점 기준 통화
     * @return array<int, array<string, mixed>> 표시용 배열
     */
    protected function formatAdditionalOptionsSnapshot(mixed $snapshot, string $locale, ?string $currency): array
    {
        if (! is_array($snapshot) || $snapshot === []) {
            return [];
        }

        $result = [];

        foreach ($snapshot as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $priceAdjustment = (float) ($entry['price_adjustment'] ?? 0);

            $result[] = [
                'additional_option_id' => (int) ($entry['additional_option_id'] ?? 0),
                'value_id' => (int) ($entry['value_id'] ?? 0),
                'name' => (string) ($this->localize($entry['name'] ?? null, $locale) ?? ''),
                'price_adjustment' => $this->roundToOrderCurrency($priceAdjustment, $currency),
                'price_adjustment_formatted' => $this->formatOrderCurrency($priceAdjustment, $currency),
                'custom_text' => (string) ($entry['custom_text'] ?? ''),
            ];
        }

        return $result;
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
