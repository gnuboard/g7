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
use Modules\Sirsoft\Ecommerce\Support\ClaimTrackingGroups;

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
                // 운송장 열 — 방향별 첫 운송장과 「외 N개」. 송장은 상품 행에 있다.
                'tracking_summary' => ClaimTrackingGroups::summary($claim),
                // 회수중·발송중 일괄 모달의 미리 채움 — 살아 있는 상품 행이 **모두 같은 송장**을 가질 때만
                // 그 값을 준다(P1). 행마다 다르면 비워 두어 운영자가 새로 적게 한다.
                'tracking_prefill' => $this->trackingPrefill($claim),
                'is_admin_requested' => $claim->isAdminRequested(),
                // 「환불 미접수」 뱃지(D9) — 목록 필터 `pending_refund` 와 같은 술어다(금액 0 판정은 상세가 한다).
                'has_pending_refund' => $this->hasPendingRefund($claim),
                // 최신 환불의 진행 상태(보완 5 · Q7) — 출처 축으로 페이지당 한 번 싣는다(Repository 의 with).
                'refund_progress' => $this->refundProgress($claim),
            ];

            if ($claim instanceof OrderExchange) {
                $overdue = ClaimTrackingGroups::overdueOf($claim);

                $data += [
                    'exchange_mode' => $claim->mode()->value,
                    'exchange_mode_label' => $claim->mode()->label(),
                    'is_advance_exchange' => $claim->isAdvanceExchange(),
                    'is_collection_overdue' => $overdue['is_overdue'],
                    'collection_overdue_days' => $overdue['days'],
                ];
            }
        }

        if ($claim instanceof OrderRefund) {
            // 차감된 적 없는 사용 마일리지는 돌려주지 않는다(Q15) — 상세와 같은 합계를 낸다.
            $refundTotal = (float) $claim->refund_amount + ($this->pointsNotDeducted($claim) ? 0.0 : (float) $claim->refund_points_amount);

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
            // 취소 발 환불도 같은 주문의 마일리지 차감 여부로 판정한다(Q15) — 환불 탭 행·상세와 같은 합계.
            $cancelRefundTotal = $refund
                ? (float) $refund->refund_amount + (self::orderPointsNotDeducted($order) ? 0.0 : (float) $refund->refund_points_amount)
                : null;

            $data += [
                'cancel_type' => $claim->cancel_type?->value,
                'cancel_type_label' => $claim->cancel_type?->label(),
                'refund_total' => $refund ? $this->roundToOrderCurrency($cancelRefundTotal, $currency) : null,
                'refund_total_formatted' => $refund ? $this->formatOrderCurrency($cancelRefundTotal, $currency) : null,
                'refund_status' => $refund?->refund_status?->value,
                'refund_status_label' => $refund?->refund_status?->label(),
                // 재활성 배지(Q29) — 서버가 취소신청서에 기록한 값만 읽는다. 주문 상태로 다시 판정하면 일부만
                // 되살린 주문에서 관계없는 취소까지 표시된다.
                'reactivated_at_formatted' => $claim->reactivated_at ? $this->formatDateTimeStringForUser($claim->reactivated_at) : null,
                'is_partially_reactivated' => $claim->isPartiallyReactivated(),
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

    /**
     * 일괄 송장 모달의 미리 채움 값을 만듭니다. (살아 있는 행이 모두 같은 송장일 때만)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서 (상품 행 로드)
     * @return array{collection: array{carrier_id: int, tracking_number: string}|null, redelivery: array{carrier_id: int, tracking_number: string}|null} 방향별 미리 채움
     */
    protected function trackingPrefill(OrderExchange|OrderReturn $claim): array
    {
        $items = $claim instanceof OrderExchange ? $claim->exchangeOptions : $claim->returnOptions;
        $prefill = ['collection' => null, 'redelivery' => null];

        foreach (array_keys($prefill) as $prefix) {
            if ($prefix === 'redelivery' && ! $claim instanceof OrderExchange) {
                continue;
            }

            $pairs = [];

            foreach ($items as $item) {
                if ($item->option_status->isFinal()) {
                    continue;
                }

                $pairs[] = $item->getAttribute($prefix.'_carrier_id').'|'.$item->getAttribute($prefix.'_tracking_number');
            }

            $unique = array_values(array_unique($pairs));

            if (count($unique) === 1 && ! str_ends_with($unique[0], '|')) {
                [$carrierId, $trackingNumber] = explode('|', $unique[0], 2);
                $prefill[$prefix] = ['carrier_id' => (int) $carrierId, 'tracking_number' => $trackingNumber];
            }
        }

        return $prefill;
    }

    /**
     * 완료됐는데 환불신청서가 연결되지 않은 상품이 있는지 판정합니다. (목록 뱃지 — D9)
     *
     * 판정은 모델 `pendingRefundItems()` 한 곳이다(목록 필터는 그 쿼리판 `scopePendingRefund`). 교환의 살아 있는
     * 환불 수는 목록 쿼리가 센 `live_refund_count` 를 읽어 행마다 조회하지 않는다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서 (목록 즉시 로드)
     * @return bool 환불 미접수면 true
     */
    protected function hasPendingRefund(OrderExchange|OrderReturn $claim): bool
    {
        return $claim->pendingRefundItems()->isNotEmpty();
    }

    /**
     * 이 교환·반품에서 생긴 최신 환불신청서의 진행 상태를 만듭니다. (보완 5 · Q7)
     *
     * 관계가 미리 실리지 않았으면 되읽지 않는다 — 목록에서 행마다 쿼리가 늘어난다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return array{claim_number: string, status: string, status_label: string, status_variant: string, status_icon: string, count: int}|null 진행 상태 (환불이 없으면 null)
     */
    protected function refundProgress(OrderExchange|OrderReturn $claim): ?array
    {
        $relation = $claim instanceof OrderReturn ? 'sourcedRefunds' : 'refunds';

        if (! $claim->relationLoaded($relation)) {
            return null;
        }

        $refunds = $claim->{$relation}->sortBy('id')->values();

        if ($refunds->isEmpty()) {
            return null;
        }

        $latest = $refunds->last();

        return [
            'claim_number' => (string) $latest->refund_number,
            'status' => $latest->refund_status->value,
            'status_label' => $latest->refund_status->label(),
            'status_variant' => $latest->refund_status->variant(),
            'status_icon' => $latest->refund_status->icon(),
            'count' => $refunds->count(),
        ];
    }
}
