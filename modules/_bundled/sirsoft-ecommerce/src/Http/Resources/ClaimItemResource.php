<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Services\ClaimItemSplitter;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemCollectionState;
use Modules\Sirsoft\Ecommerce\Support\ClaimTransitionVocabulary;

/**
 * 클레임 신청 상품 행 리소스 (교환·반품·환불·취소 공용)
 *
 * 네 유형의 상품 행은 컬럼명이 다르다(`exchange_quantity` / `return_quantity` / `quantity` / `cancel_quantity`).
 * 화면은 상품 단위 행을 같은 모양으로 그리므로(D24) 여기서 공통 키(`quantity` / `status`)로 정규화하고,
 * 교환 전용 축(교환 대상 옵션·차액)은 있을 때만 싣는다. 상품명·옵션명은 주문옵션 스냅샷에서 읽는다 —
 * `orderOption` 관계는 Repository 가 함께 싣는다.
 *
 * 송장은 상품 행마다 있다 — 교환·반품 행은 회수 송장(교환은 재발송 송장도)과 도착 시각·회수 진행
 * (`collection_state`)을 싣는다. 택배사 이름·조회 URL 은 행의 택배사 관계(`collectionCarrier`·
 * `redeliveryCarrier`)에서 만든다 — 저장소가 함께 싣는다(N+1 방지).
 */
class ClaimItemResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 관리자 상세의 전이 선택지를 함께 실을지 여부
     */
    protected bool $forAdminTransitions = false;

    /**
     * 교환 진행 방식 (부모 신청서가 주입 — 선교환이면 전이표·회수 진행 판정이 다르다)
     */
    protected ?ExchangeModeEnum $exchangeMode = null;

    /**
     * 부모 교환의 진행 방식을 주입합니다. (행마다 신청서를 다시 읽지 않는다)
     *
     * @param  ExchangeModeEnum  $mode  진행 방식
     * @return static 자기 자신
     */
    public function withExchangeMode(ExchangeModeEnum $mode): static
    {
        $this->exchangeMode = $mode;

        return $this;
    }

    /**
     * 행별 전이 선택지를 싣도록 표시합니다. (관리자 **상세** 응답 전용)
     *
     * 목록에는 싣지 않는다 — 50행 × 200상품마다 Enum 전이표를 계산하게 된다. 유저 응답에도
     * 싣지 않는다: `withAdminFields()` 가 규정한 "유저 응답의 기존 키는 바꾸지 않고 추가만
     * 한다" 축을 상세·관리자 두 조건이 함께 지킨다.
     *
     * @return static 자기 자신
     */
    public function forAdminTransitions(): static
    {
        $this->forAdminTransitions = true;

        return $this;
    }

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
            // 단가의 보조 통화. 없으면 화면의 단가 줄이 **다른 필드의 보조 통화로 흘러간다** —
            // 교환 신청서 수정 화면에서 `28,000원` 줄에 교환 차액(-3,000)의 `$-3.00` 이 붙었다
            // (2026-09-15 실측). 아래 `mc_subtotal_amount` 가 같은 이유로 추가됐고 단가 자리만
            // 남아 있었다. 소계와 달리 수량을 곱하지 않는다.
            'mc_unit_price' => $this->formatStoredMultiCurrency($orderOption?->mc_unit_price),
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

        // 수량 분할 가능 여부 — 화면이 이 값으로 그 행의 수량 입력을 잠근다. 판정은 서버가
        // 단독 소유한다(`ClaimItemSplitter`): 화면에 조건을 복제하면 교환 후속 옵션 규칙이
        // 두 벌이 되고, 한쪽만 고쳐졌을 때 운영자는 입력해 놓고 [확인] 에서야 422 를 본다.
        // 취소신청서 행에는 싣지 않는다 — 전이 축 자체가 없다.
        if (
            $this->resource instanceof OrderExchangeOption
            || $this->resource instanceof OrderReturnOption
            || $this->resource instanceof OrderRefundOption
        ) {
            $row['is_splittable'] = ClaimItemSplitter::isSplittable($this->resource);

            // 행이 읽는 값은 행에 싣는다(`status_label`·`status_variant` 와 같은 규율) —
            // 봉투 별도 키(맵)로 두면 DataGrid `cellChildren` 안에서 행↔맵 조인이 필요하고,
            // 그 조인이 어긋나면 어떤 행은 남의 선택지를 그린다.
            if ($this->forAdminTransitions) {
                $row['transition_options'] = ClaimTransitionVocabulary::optionsFrom(
                    $status instanceof \BackedEnum ? $status : null,
                    true,
                    $this->exchangeMode,
                );
            }

            // 상품 단위 반려 사유(S3) — 상품 B 의 반려가 상품 A 의 사유를 덮지 않게 행에 둔다.
            $row['reject_reason'] = $this->reject_reason ?? null;
        }

        if ($this->resource instanceof OrderExchangeOption || $this->resource instanceof OrderReturnOption) {
            $row += $this->trackingFields();
        }

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
     * 행 송장·도착·회수 진행 필드를 만듭니다. (교환·반품 행)
     *
     * @return array<string, mixed> 송장 필드
     */
    protected function trackingFields(): array
    {
        $item = $this->resource;
        $state = ClaimItemCollectionState::for($item, $this->exchangeMode);

        $fields = $this->directionFields('collection') + [
            'collected_at_formatted' => $this->formatDateTimeStringForUser($item->collected_at),
            'collection_state' => $state->value,
            'collection_state_label' => $state->label(),
            'collection_state_variant' => $state->variant(),
            'is_stock_restored' => (bool) $item->is_stock_restored,
            // 뒤늦은 재고 복구(S13) 대상인가 — 완료 · 미복구 · 미회수 아님. 판정은 복구 서비스와 같은 조건이다
            // (`AdminClaimService::isStockRestorable()`) — 화면이 상태 값으로 다시 판정하지 않는다.
            'is_stock_restorable' => $item->option_status instanceof \BackedEnum
                && $item->option_status->value === 'completed'
                && ! (bool) $item->is_stock_restored
                && ($item->unreturned_at ?? null) === null,
            'return_address_snapshot' => $item->return_address_snapshot,
        ];

        if ($item instanceof OrderExchangeOption) {
            $fields += $this->directionFields('redelivery') + [
                'redelivered_at_formatted' => $this->formatDateTimeStringForUser($item->redelivered_at),
                'is_unreturned' => $item->unreturned_at !== null,
                'unreturned_at_formatted' => $this->formatDateTimeStringForUser($item->unreturned_at),
                'unreturned_reason' => $item->unreturned_reason,
            ];
        }

        return $fields;
    }

    /**
     * 한 방향의 송장 필드를 만듭니다. (택배사·운송장·조회 링크·등록 시각)
     *
     * @param  string  $prefix  방향 접두 (collection|redelivery)
     * @return array<string, mixed> 방향 송장 필드
     */
    protected function directionFields(string $prefix): array
    {
        $item = $this->resource;
        $relation = $prefix.'Carrier';
        $number = $item->getAttribute($prefix.'_tracking_number');
        $carrier = $item->getAttribute($prefix.'_carrier_id') === null
            ? null
            : ($item->relationLoaded($relation) ? $item->getRelation($relation) : $item->{$relation}()->first());

        return [
            $prefix.'_carrier_id' => $item->getAttribute($prefix.'_carrier_id'),
            $prefix.'_carrier_name' => $carrier?->getLocalizedName(),
            $prefix.'_tracking_number' => $number,
            // 추적 URL 은 배송사 템플릿에서 만든다 — 화면이 규칙을 재조립하면 주문 배송 조회와 두 벌이 된다.
            $prefix.'_tracking_url' => $number ? $carrier?->buildTrackingUrl((string) $number) : null,
            $prefix.'_shipped_at_formatted' => $this->formatDateTimeStringForUser($item->getAttribute($prefix.'_shipped_at')),
        ];
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
