<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;

/**
 * 클레임 추가결제 리소스
 *
 * 금액은 결제 건의 기준통화(`currency_code`)로 표기한다. 주문 통화 스냅샷은 호출부가
 * `withOrderCurrency()`/`withCurrencySnapshot()` 로 전파하고, 주입이 없으면 결제 건의 주문에서
 * 직접 읽는다 — 자릿수·절사 규칙의 SSoT 는 거래 시점 스냅샷이다.
 */
class ClaimPaymentResource extends BaseApiResource
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
        // 주입이 없으면 결제 건의 주문에서 스냅샷을 직접 읽는다 (ClaimResource 와 같은 주입-우선 형태).
        // 목록·상세는 부모가 주입하므로 추가 쿼리가 없고, 액션 응답(신청·수정·결제·승인)처럼 결제 건이
        // 단독으로 실릴 때만 관계를 확보한다. 스냅샷이 없으면 자릿수가 현재 설정으로 폴백해,
        // 같은 결제 행이 한 응답 안에서도 자리마다 다르게 표기된다.
        if ($this->currencySnapshot === null) {
            $this->resource->loadMissing('order');
            $this->withCurrencySnapshot($this->resource->order?->currency_snapshot);
        }

        $currency = $this->orderCurrencyCode ?: ($this->currency_code ?: null);
        $net = $this->resource->netPaidAmount();

        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'payable_type' => $this->payable_type,
            'payable_id' => $this->payable_id,
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->payment_method ? app(PaymentMethodResolver::class)->label((string) $this->payment_method) : null,
            'pg_provider' => $this->pg_provider,
            'payment_status' => $this->payment_status,
            'payment_status_label' => $this->payment_status?->label(),
            'payment_status_variant' => $this->payment_status?->variant(),
            'amount' => $this->roundToOrderCurrency((float) $this->amount, $currency),
            'amount_formatted' => $this->formatOrderCurrency((float) $this->amount, $currency),
            'points_amount' => $this->roundToOrderCurrency((float) $this->points_amount, $currency),
            'points_amount_formatted' => $this->formatOrderCurrency((float) $this->points_amount, $currency),
            'total_amount' => $this->roundToOrderCurrency($this->resource->getTotalAmount(), $currency),
            'total_amount_formatted' => $this->formatOrderCurrency($this->resource->getTotalAmount(), $currency),
            'refunded_amount' => $this->roundToOrderCurrency((float) $this->refunded_amount, $currency),
            'refunded_amount_formatted' => $this->formatOrderCurrency((float) $this->refunded_amount, $currency),
            'net_paid_amount' => $this->roundToOrderCurrency($net, $currency),
            'net_paid_amount_formatted' => $this->formatOrderCurrency($net, $currency),
            'currency_code' => $this->currency_code,
            'mc_amount' => $this->formatStoredMultiCurrency($this->mc_amount),
            'transaction_id' => $this->transaction_id,
            // 사유 **코드**만 내보낸다. `pg_error_message` 는 PG 가 준 원문이라 상점 MID·PG 내부 상태를
            // 담을 수 있고 이 리소스는 비회원(비인증) 응답에도 실린다 — 원문은 컬럼과 로그에만 남긴다.
            'pg_error_code' => $this->pg_error_code,
            'is_pending' => $this->resource->isPending(),
            'is_paid' => $this->resource->isPaid(),
            'paid_at' => $this->paid_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'paid_at_formatted' => $this->formatDateTimeStringForUser($this->paid_at),
            'refunded_at' => $this->refunded_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'refunded_at_formatted' => $this->formatDateTimeStringForUser($this->refunded_at),
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }
}
