<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 주문 결제 정보 리소스
 */
class OrderPaymentResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 결제 정보 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_status' => $this->payment_status,
            'payment_status_label' => $this->payment_status ? $this->payment_status->label() : null,
            'payment_status_variant' => $this->payment_status ? $this->payment_status->variant() : null,
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->payment_method ? $this->payment_method->label() : null,
            'payment_type_label' => $this->payment_method ? $this->payment_method->label() : null,
            // PG 환불(취소)이 필요한 결제수단인지 — 취소 모달의 'PG 함께 취소' 체크박스·'환불 우선순위' 라디오
            // 노출 여부 SSoT(무통장/포인트/예치금/무료는 false → 두 UI 숨김). U1·A28 공유 플래그.
            'requires_pg_cancellation' => (bool) $this->payment_method?->needsPgProvider(),
            'pg_provider' => $this->pg_provider,
            'transaction_id' => $this->transaction_id,
            'merchant_order_id' => $this->merchant_order_id,
            'payment_number' => $this->merchant_order_id,
            // 결제 통화(order_currency) 실청구액. paid_amount_local 은 PG 가 실제 청구한 결제 통화
            // 금액이므로 결제 통화 기호로 포맷한다(base≠결제 통화일 때 base 기호로 표기하면 단위 불일치).
            'paid_amount_local' => $this->roundToCurrency($this->paid_amount_local, $this->paymentCurrencyCode()),
            'paid_amount_formatted' => $this->formatCurrencyPrice(
                $this->roundToCurrency($this->paid_amount_local, $this->paymentCurrencyCode()),
                $this->paymentCurrencyCode()
            ),
            // base 통화(주문 시점 기준 통화) 환산 실청구액 — 결제 통화와 다를 때 병기용.
            'paid_amount_base' => $this->roundToOrderCurrency($this->paid_amount_base),
            'paid_amount_base_formatted' => $this->formatOrderCurrency($this->paid_amount_base),
            'vat_amount' => $this->roundToOrderCurrency($this->vat_amount),
            'vat_amount_formatted' => $this->formatOrderCurrency($this->vat_amount),
            // 결제 통화 코드(order_currency) — 화면 "결제 통화" 표기 SSoT. base 통화는 base_currency.
            'currency' => $this->currency,
            'payment_currency' => $this->paymentCurrencyCode(),
            'base_currency' => $this->orderBaseCurrencyCode(),
            'is_cross_currency' => $this->paymentCurrencyCode() !== $this->orderBaseCurrencyCode(),

            // 카드 정보
            'card_name' => $this->card_name,
            'card_number_masked' => $this->card_number_masked,
            'card_approval_number' => $this->card_approval_number,
            'card_installment_months' => $this->card_installment_months,
            'is_interest_free' => $this->is_interest_free,

            // 가상계좌 정보
            'vbank_name' => $this->vbank_name,
            'vbank_number' => $this->vbank_number,
            'vbank_holder' => $this->vbank_holder,
            // vbank_due_at 은 PG 플러그인(kginicis/nicepayments) JS injector 가 new Date() 로
            // 파싱·재포맷하는 머신 ISO8601 값이므로 raw 유지. 화면 표시는 vbank_due_at_formatted 사용.
            'vbank_due_at' => $this->vbank_due_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601 parsed by PG plugin JS injectors, display uses *_formatted sibling
            'vbank_due_at_formatted' => $this->formatDateTimeStringForUser($this->vbank_due_at),

            // 무통장입금 정보
            'dbank_name' => $this->dbank_name,
            'dbank_account' => $this->dbank_account,
            'dbank_holder' => $this->dbank_holder,
            'depositor_name' => $this->depositor_name,
            // 머신 ISO8601 — 표시는 deposit_due_at_formatted 사용
            'deposit_due_at' => $this->deposit_due_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601, display uses deposit_due_at_formatted sibling
            'deposit_due_at_formatted' => $this->formatDateTimeStringForUser($this->deposit_due_at),

            // 현금영수증
            'cash_receipt_type' => $this->cash_receipt_type,
            'cash_receipt_identifier' => $this->cash_receipt_identifier,

            // 결제수단별 계좌/카드 요약 정보
            'account_info' => $this->getAccountInfo(),

            // 일시
            // 머신 ISO8601 — 표시는 requested_at_formatted / paid_at_formatted 사용
            'payment_started_at' => $this->payment_started_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601, display uses requested_at_formatted sibling
            'requested_at_formatted' => $this->formatDateTimeStringForUser($this->payment_started_at),
            // paid_at 은 PG 플러그인 레이아웃 if 조건(truthiness)·JS 에서 참조하는 머신 ISO8601 값
            'paid_at' => $this->paid_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601 used in layout if conditions, display uses paid_at_formatted sibling
            'paid_at_formatted' => $this->formatDateTimeStringForUser($this->paid_at),
            'due_date_formatted' => $this->formatDateTimeStringForUser($this->vbank_due_at ?? $this->deposit_due_at),

            // 취소
            // 머신 ISO8601 — 표시 미사용(현 화면 노출 없음)
            'cancelled_at' => $this->cancelled_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601, not a display field
            'cancelled_amount' => $this->roundToOrderCurrency($this->cancelled_amount),
            'cancel_reason' => $this->cancel_reason,
        ];
    }

    /**
     * 결제 통화(order_currency) 코드를 반환합니다.
     *
     * 결제 레코드의 currency 컬럼(주문 시점 order_currency)을 우선 사용하고,
     * 없으면 스냅샷 order_currency → 기본 통화 순으로 폴백합니다.
     *
     * @return string 결제 통화 코드
     */
    private function paymentCurrencyCode(): string
    {
        return $this->currency
            ?: (data_get($this->currency_snapshot, 'order_currency')
                ?: $this->orderBaseCurrencyCode());
    }

    /**
     * 주문 시점 기준 통화(base_currency) 코드를 반환합니다.
     *
     * @return string base 통화 코드
     */
    private function orderBaseCurrencyCode(): string
    {
        return data_get($this->currency_snapshot, 'base_currency')
            ?: $this->getDefaultCurrencyCode();
    }

    /**
     * 결제수단별 계좌/카드 요약 정보를 반환합니다.
     *
     * @return string|null 계좌/카드 요약 문자열
     */
    private function getAccountInfo(): ?string
    {
        $method = $this->payment_method?->value ?? null;

        if ($method === PaymentMethodEnum::CARD->value) {
            $info = $this->card_name ?? '';
            if ($this->card_number_masked) {
                $info .= ' '.$this->card_number_masked;
            }
            if ($this->card_installment_months && $this->card_installment_months > 0) {
                $info .= ' ('.$this->card_installment_months.'개월)';
            } elseif ($this->card_installment_months === 0) {
                $info .= ' (일시불)';
            }

            return trim($info) ?: null;
        }

        if ($method === PaymentMethodEnum::VBANK->value) {
            $info = $this->vbank_name ?? '';
            if ($this->vbank_number) {
                $info .= ' '.$this->vbank_number;
            }

            return trim($info) ?: null;
        }

        // 무통장입금(dbank): 은행명 + 계좌번호 (+ 예금주) 요약.
        if ($method === PaymentMethodEnum::DBANK->value) {
            $info = $this->dbank_name ?? '';
            if ($this->dbank_account) {
                $info .= ' '.$this->dbank_account;
            }
            if ($this->dbank_holder) {
                $info .= ' ('.$this->dbank_holder.')';
            }

            return trim($info) ?: null;
        }

        return null;
    }
}
