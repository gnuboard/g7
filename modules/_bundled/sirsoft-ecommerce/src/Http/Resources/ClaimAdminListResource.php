<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Support\ClaimOutstanding;

/**
 * 관리자 클레임 목록 행 리소스 (교환·반품·환불·취소 공용 — 목록 전용 축소 표현)
 *
 * 목록은 화면이 그 행에서 실제로 그리는 것만 싣는다 — 상품은 전량이 아니라 요약
 * (`item_summary`: 첫 상품명 + 건수 + 수량 합계)으로, 첨부·결제 건 배열은 싣지 않는다.
 * 상세 전체 표현은 `ClaimResource` 가 담당한다 (사유 라벨 주입·마스킹·통화 해석을 상속).
 *
 * 미수납 판정은 상태 라벨이 아니라 `outstanding_amount` 로 한다 — 분할 수납으로 잔액이
 * 남은 건은 결제대기 라벨 그대로이므로 라벨만 보면 수납 여부를 구분할 수 없다(§4-5).
 * 값은 Repository 가 실어 준 `claimPayments` 에서 계산하고, 미로드면 null 로 둔다
 * (로드 여부는 Repository 가 결정한다 — 여기서 되읽지 않는다).
 */
class ClaimAdminListResource extends ClaimResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 응답 배열
     */
    public function toArray(Request $request): array
    {
        $claim = $this->resource;
        $type = $claim->claimType();
        $order = $this->whenLoaded('order', fn () => $this->order, null);
        $currency = $this->orderCurrencyCode ?: ($claim->currency_code ?? ($order ? $this->resolveOrderBaseCurrencyCode($order) : null));
        $snapshot = $this->currencySnapshot ?? $order?->currency_snapshot ?? [];
        $this->withOrderCurrency($currency)->withCurrencySnapshot($snapshot);

        [$numberField, $statusField] = match ($type) {
            ClaimTypeEnum::EXCHANGE => ['exchange_number', 'exchange_status'],
            ClaimTypeEnum::RETURN => ['return_number', 'return_status'],
            ClaimTypeEnum::REFUND => ['refund_number', 'refund_status'],
            ClaimTypeEnum::CANCEL => ['cancel_number', 'cancel_status'],
        };

        $status = $claim->{$statusField};

        $data = [
            'id' => $claim->id,
            'claim_number' => $claim->{$numberField},
            'claim_type' => $type->value,
            'claim_type_label' => $type->label(),
            'order_id' => $claim->order_id,
            'order_number' => $order?->order_number,
            'requester_name' => $order?->getOrdererName(),
            'requested_by' => $claim->requested_by ?? $claim->cancelled_by ?? null,
            'is_guest_request' => method_exists($claim, 'isGuestRequest') ? $claim->isGuestRequest() : ($claim->cancelled_by === null),
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'status_label' => $status instanceof \BackedEnum ? $status->label() : null,
            'status_variant' => $status instanceof \BackedEnum && method_exists($status, 'variant') ? $status->variant() : null,
            'is_final' => method_exists($claim, 'isFinal') ? $claim->isFinal() : true,
            'reason_code' => $claim->reason_code ?? $claim->cancel_reason_type ?? null,
            'reason_label' => $this->resolveReasonLabel($claim, $type),
            'fault_type' => $claim->fault_type?->value ?? null,
            'fault_type_label' => $claim->fault_type?->label() ?? null,
            'currency_code' => $currency,
            'item_summary' => $this->itemSummary($claim),
            'requested_at' => $claim->created_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'requested_at_formatted' => $this->formatDateTimeStringForUser($claim->created_at),
            'completed_at_formatted' => $this->formatDateTimeStringForUser($claim->completed_at ?? $claim->refunded_at ?? $claim->cancelled_at ?? null),
        ];

        if ($claim instanceof OrderExchange || $claim instanceof OrderReturn) {
            $outstanding = $this->outstandingFromLoadedPayments($claim);

            $data += [
                'charge_method' => $claim->charge_method?->value,
                'charge_method_label' => $claim->charge_method?->label(),
                'total_charge_amount' => $this->roundToOrderCurrency((float) $claim->total_charge_amount, $currency),
                'total_charge_amount_formatted' => $this->formatOrderCurrency((float) $claim->total_charge_amount, $currency),
                'outstanding_amount' => $outstanding !== null ? $this->roundToOrderCurrency($outstanding, $currency) : null,
                'outstanding_amount_formatted' => $outstanding !== null ? $this->formatOrderCurrency($outstanding, $currency) : null,
            ];
        }

        if ($claim instanceof OrderRefund) {
            $refundTotal = (float) $claim->refund_amount + (float) $claim->refund_points_amount;

            $data += [
                // 목록의 출처 뱃지가 읽는 값(#599 후속 §5-6). 취소 발 환불이 환불 탭에 서게
                // 되면서(D6) `고객 신청`/`운영자 접수`/`반품 발` 을 화면에서 구분해야 한다 —
                // 구분이 없으면 운영자가 자기가 건 취소를 고객 신청으로 읽는다.
                'refund_source_type' => $claim->refund_source_type?->value,
                'refund_source_type_label' => $claim->refund_source_type?->label(),
                'refund_total' => $this->roundToOrderCurrency($refundTotal, $currency),
                'refund_total_formatted' => $this->formatOrderCurrency($refundTotal, $currency),
            ];
        }

        if ($claim instanceof OrderCancel) {
            $refund = $this->whenLoaded('refund', fn () => $this->refund, null);

            $data += [
                'cancel_type' => $claim->cancel_type?->value,
                'cancel_type_label' => $claim->cancel_type?->label(),
                'refund_total' => $refund ? $this->roundToOrderCurrency((float) $refund->refund_amount + (float) $refund->refund_points_amount, $currency) : null,
                'refund_total_formatted' => $refund ? $this->formatOrderCurrency((float) $refund->refund_amount + (float) $refund->refund_points_amount, $currency) : null,
                'refund_status' => $refund?->refund_status?->value,
                'refund_status_label' => $refund?->refund_status?->label(),
            ];
        }

        return $data;
    }

    /**
     * 목록 행의 상품 요약을 만듭니다. (첫 상품명 · 건수 · 수량 합계)
     *
     * 교환·반품·취소는 헤더의 신청 시점 스냅샷(`items_snapshot`)에서 만든다 — 상품 행 관계를
     * 목록에 실을 필요가 없다. 환불 헤더에는 스냅샷이 없어 Repository 가 실어 준 상품 행
     * (`refundOptions.orderOption`)에서 만든다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array{product_name: ?string, item_count: int, total_quantity: int} 상품 요약
     */
    protected function itemSummary(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): array
    {
        if ($claim instanceof OrderRefund) {
            $items = $claim->relationLoaded('refundOptions') ? $claim->refundOptions : collect();
            $first = $items->first();

            return [
                'product_name' => $this->localizeName($first?->orderOption?->product_name),
                'item_count' => $items->count(),
                'total_quantity' => (int) $items->sum('quantity'),
            ];
        }

        $snapshot = is_array($claim->items_snapshot) ? $claim->items_snapshot : [];
        $first = $snapshot[0] ?? null;

        return [
            'product_name' => $this->localizeName($first['product_name'] ?? null),
            'item_count' => count($snapshot),
            'total_quantity' => (int) array_sum(array_map(
                fn (array $row) => (int) ($row['quantity'] ?? 0),
                $snapshot,
            )),
        ];
    }

    /**
     * 미수납 청구액을 실어 둔 결제 건 관계에서 계산합니다. (미로드면 null)
     *
     * `ClaimPaymentService::outstandingAmount()` 와 같은 식(청구액 − 실수납 잔액 합 — `ClaimOutstanding`)이되,
     * 행마다 쿼리를 치지 않도록 Repository 가 eager load 한 컬렉션을 쓴다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return float|null 미수납 청구액 (관계 미로드면 null)
     */
    protected function outstandingFromLoadedPayments(OrderExchange|OrderReturn $claim): ?float
    {
        if (! $claim->relationLoaded('claimPayments')) {
            return null;
        }

        if (! $claim->requiresAdditionalPayment()) {
            return 0.0;
        }

        $paid = $claim->claimPayments
            ->filter(fn (ClaimPayment $payment) => $payment->isPaid())
            ->sum(fn (ClaimPayment $payment) => $payment->netPaidAmount());

        return ClaimOutstanding::amount((float) $claim->total_charge_amount, (float) $paid);
    }

    /**
     * 다국어 배열 값을 현재 로케일 문자열로 풀어냅니다.
     *
     * @param  mixed  $value  다국어 배열 또는 문자열
     * @return string|null 현지화 문자열
     */
    protected function localizeName(mixed $value): ?string
    {
        if (is_array($value)) {
            $locale = app()->getLocale();
            $value = $value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? (reset($value) ?: null);
        }

        return $value === null ? null : (string) $value;
    }
}
