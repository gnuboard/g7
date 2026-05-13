<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 주문 결제 정보 리소스
 */
class OrderPaymentResource extends BaseApiResource
{
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
            'payment_status' => $this->payment_status,
            'payment_status_label' => $this->payment_status ? $this->payment_status->label() : null,
            'payment_status_variant' => $this->payment_status ? $this->payment_status->variant() : null,
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->payment_method ? $this->payment_method->label() : null,
            'payment_type_label' => $this->payment_method ? $this->payment_method->label() : null,
            'pg_provider' => $this->pg_provider,
            'transaction_id' => $this->transaction_id,
            'merchant_order_id' => $this->merchant_order_id,
            'payment_number' => $this->merchant_order_id,
            'paid_amount_local' => $this->paid_amount_local,
            'paid_amount_formatted' => number_format($this->paid_amount_local).'원',
            'vat_amount' => $this->vat_amount,
            'vat_amount_formatted' => number_format($this->vat_amount).'원',
            'currency' => $this->currency,

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
            'vbank_due_at' => $this->vbank_due_at?->toIso8601String(),

            // 무통장입금 정보
            'dbank_name' => $this->dbank_name,
            'dbank_account' => $this->dbank_account,
            'dbank_holder' => $this->dbank_holder,
            'depositor_name' => $this->depositor_name,
            'deposit_due_at' => $this->deposit_due_at?->toIso8601String(),
            'deposit_due_at_formatted' => $this->formatDateTimeStringForUser($this->deposit_due_at),

            // 현금영수증
            'cash_receipt_type' => $this->cash_receipt_type,
            'cash_receipt_identifier' => $this->cash_receipt_identifier,

            // 결제수단별 계좌/카드 요약 정보
            'account_info' => $this->getAccountInfo(),

            // 일시
            'payment_started_at' => $this->payment_started_at?->toIso8601String(),
            'requested_at_formatted' => $this->formatDateTimeStringForUser($this->payment_started_at),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_at_formatted' => $this->formatDateTimeStringForUser($this->paid_at),
            'due_date_formatted' => $this->formatDateTimeStringForUser($this->vbank_due_at ?? $this->deposit_due_at),

            // 취소
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_amount' => $this->cancelled_amount,
            'cancel_reason' => $this->cancel_reason,
        ];
    }

    /**
     * 결제수단별 계좌/카드 요약 정보를 반환합니다.
     *
     * @return string|null 계좌/카드 요약 문자열
     */
    private function getAccountInfo(): ?string
    {
        $method = $this->payment_method?->value ?? null;

        if ($method === 'card') {
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

        if ($method === 'vbank') {
            $info = $this->vbank_name ?? '';
            if ($this->vbank_number) {
                $info .= ' '.$this->vbank_number;
            }

            return trim($info) ?: null;
        }

        if ($method === 'bank_transfer') {
            $info = $this->dbank_name ?? '';
            if ($this->dbank_account) {
                $info .= ' '.$this->dbank_account;
            }

            return trim($info) ?: null;
        }

        return null;
    }
}
