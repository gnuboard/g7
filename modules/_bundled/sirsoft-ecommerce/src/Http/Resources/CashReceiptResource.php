<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 현금영수증 이력 리소스
 *
 * 식별번호는 마스킹 값만 노출한다 — 원본 평문·암호문은 어떤 응답에도 포함되지 않는다.
 * 프로바이더 원응답(raw_response)도 노출하지 않는다.
 */
class CashReceiptResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /** 영수증 금액 표기 통화 (부모가 주입). 미주입 시 기본 통화로 폴백. */
    protected ?string $receiptCurrencyCode = null;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 현금영수증 정보 배열
     */
    public function toArray(Request $request): array
    {
        // 현금영수증 금액은 구매자가 실제로 낸 금액, 즉 **결제 통화(order_currency)** 기준이다
        // (CashReceiptService::calculateIssuableAmount 가 그 통화로 산출한다).
        // base 통화 기호로 포맷하면 값은 맞는데 단위만 틀린 증빙이 화면에 뜬다(¥310 vs 310원).
        $receiptCurrency = $this->resolveReceiptCurrencyCode();

        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'transaction_type' => $this->transaction_type,
            // 관리자 발급 이력 표는 원시값(issue/cancel, FAILED)이 아니라 표시명을 노출한다.
            'transaction_type_label' => $this->transaction_type?->label(),
            'receipt_type' => $this->receipt_type,
            'receipt_type_label' => $this->receipt_type?->label(),
            'amount' => $this->roundToCurrency($this->amount, $receiptCurrency),
            'amount_formatted' => $this->formatCurrencyPrice(
                $this->roundToCurrency($this->amount, $receiptCurrency),
                $receiptCurrency
            ),
            'tax_free_amount' => $this->roundToCurrency($this->tax_free_amount, $receiptCurrency),
            'tax_free_amount_formatted' => $this->formatCurrencyPrice(
                $this->roundToCurrency($this->tax_free_amount, $receiptCurrency),
                $receiptCurrency
            ),
            'identifier_masked' => $this->identifier_masked,
            'receipt_url' => $this->receipt_url,
            'issue_number' => $this->issue_number,
            'issue_status' => $this->issue_status,
            'issue_status_label' => $this->issue_status?->label(),
            // 이력 표의 결과 열 — 발급/취소 행이 같은 열을 공유하므로 중립 표현을 쓴다.
            // issue_status_label 은 발급 관점 표현("발급 완료")이라 취소 행에 쓰면 오해를 부른다.
            'result_label' => $this->issue_status
                ? __('sirsoft-ecommerce::cash_receipt.result_status.'.strtolower($this->issue_status->value))
                : null,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            // 머신 ISO8601 — 표시는 issued_at_formatted 사용
            'issued_at' => $this->issued_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601, display uses issued_at_formatted sibling
            'issued_at_formatted' => $this->formatDateTimeStringForUser($this->issued_at),
            // 이력 표의 시각 열 — 발급 실패 이력은 issued_at 이 null 이므로 기록 시각으로 대체한다.
            'occurred_at_formatted' => $this->formatDateTimeStringForUser($this->issued_at ?? $this->created_at),
        ];
    }

    /**
     * 영수증 금액의 표기 통화를 주입합니다.
     *
     * 부모 리소스(주문)가 이미 알고 있는 결제 통화를 내려 준다. 영수증마다 order 관계를
     * 되짚으면 발급 이력 목록에서 N+1 이 된다.
     *
     * @param  string|null  $currencyCode  결제 통화 코드
     * @return static
     */
    public function withReceiptCurrency(?string $currencyCode): static
    {
        $this->receiptCurrencyCode = $currencyCode;

        return $this;
    }

    /**
     * 영수증 금액의 표기 통화를 반환합니다.
     *
     * 발급액은 결제 통화 기준으로 산출·저장되므로 표기도 그 통화를 따른다.
     * 부모가 주입한 base 통화(withOrderCurrency)에 흔들리지 않는다.
     *
     * @return string 통화 코드 (미주입 시 기본 통화)
     */
    protected function resolveReceiptCurrencyCode(): string
    {
        if (is_string($this->receiptCurrencyCode) && $this->receiptCurrencyCode !== '') {
            return $this->receiptCurrencyCode;
        }

        // 관계가 이미 로드된 경우에만 참조 (추가 쿼리 금지)
        if ($this->resource->relationLoaded('order') && $this->resource->order !== null) {
            $order = $this->resource->order;

            return $order->currency
                ?: (data_get($order->currency_snapshot, 'order_currency') ?: $this->getDefaultCurrencyCode());
        }

        return $this->getDefaultCurrencyCode();
    }
}
