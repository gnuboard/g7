<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Extension\HookManager;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\MasksRefundBankAccount;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;

/**
 * 주문 결제 정보 리소스
 */
class OrderPaymentResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;
    use MasksRefundBankAccount;

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
            'payment_method_label' => $this->payment_method ? $this->paymentMethodLabel() : null,
            'payment_type_label' => $this->payment_method ? $this->paymentMethodLabel() : null,
            // PG 환불(취소)이 필요한 결제수단인지 — 취소 모달의 'PG 함께 취소' 체크박스·'환불 우선순위' 라디오
            // 노출 여부 SSoT(무통장/포인트/예치금/무료는 false → 두 UI 숨김). U1·A28 공유 플래그.
            'requires_pg_cancellation' => $this->payment_method ? $this->needsPgProvider() : false,
            // 이 결제수단의 환불이 **사람 손 없이** 자동으로 진행되는가 (P16·P17).
            //
            // 관리자 [주문취소] 모달의 '자동 전이' 체크박스가 이 값을 읽어, 정지 수단(무통장·
            // 가상계좌 등) 주문이면 "체크해도 신청에서 멈춘다" 를 미리 알린다. 화면이 규칙을
            // 다시 유도하지 않고 서버 판정 하나를 읽게 한다 — 레이아웃 표현식에 목록 비교를
            // 박으면 설정이 바뀌거나 플러그인이 수단을 늘릴 때 안내와 실제 전이가 갈린다.
            'auto_approves_refund' => $this->payment_method
                ? app(PaymentMethodResolver::class)->autoApprovesRefund($this->paymentMethodId())
                : true,
            // 이 결제가 **부분취소를 지원하는가** (P6).
            //
            // PG 마다 제약이 다르고, 어떤 조합은 원천적으로 전액취소만 된다 — 예: NICE 가상계좌는
            // 코어 취소가 항상 거절되고 우회 API 는 전액취소 전용이다. 코어가 그 사실을 미리
            // 말하지 않으면 운영자는 수량을 줄여 [취소]를 누르고 원인 없는 실패를 받는다.
            // 판정은 PG 플러그인이 자기 제약을 아는 자리이므로 훅으로 위임한다 — 코어가 수단·PG
            // 조합표를 들고 있으면 플러그인이 늘 때마다 그 표가 낡는다.
            'supports_partial_cancel' => (bool) HookManager::applyFilters(
                'sirsoft-ecommerce.payment.supports_partial_cancel',
                true,
                $this->resource,
            ),
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
            // vbank_due_at 은 기계가 읽는 ISO8601 원시 값이다(API 소비자용). 화면은 템플릿·PG 플러그인 모두
            // vbank_due_at_formatted 를 그대로 쓰고 원시 값에 다시 서식을 입히지 않는다(docs/api/orders.md).
            'vbank_due_at' => $this->vbank_due_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601 for API consumers, every screen displays the *_formatted sibling
            'vbank_due_at_formatted' => $this->formatDateTimeStringForUser($this->vbank_due_at),

            // 에스크로 여부 — 결제 시점 스냅샷. 부분취소 차단(PaymentRefundListener)의 기준이자
            // 주문 상세(관리자/회원)에서 에스크로 거래임을 표시하는 값.
            'is_escrow' => (bool) $this->is_escrow,

            // 무통장입금 정보
            'dbank_name' => $this->dbank_name,
            'dbank_account' => $this->dbank_account,
            'dbank_holder' => $this->dbank_holder,
            'depositor_name' => $this->depositor_name,
            // 머신 ISO8601 — 표시는 deposit_due_at_formatted 사용
            'deposit_due_at' => $this->deposit_due_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601, display uses deposit_due_at_formatted sibling
            'deposit_due_at_formatted' => $this->formatDateTimeStringForUser($this->deposit_due_at),

            // 환불 계좌 — 주문 시 입력된 계좌. 관리자 취소 모달이 이 값을 프리필하고,
            // PG 환불(refundReceiveAccount 구성)이 같은 컬럼을 읽으므로 운영자에게는 원문을 준다.
            // 구매자 응답(회원 주문상세·비회원 주문상세)에는 마스킹된 값만 나간다 — 클레임 응답이
            // 가리는 것과 같은 규칙이어야 한 쪽이 다른 쪽의 우회로가 되지 않는다.
            'refund_bank_code' => $this->refund_bank_code,
            'refund_bank_name' => $this->refund_bank_name,
            'refund_bank_account' => $this->refundBankAccountForViewer($this->refund_bank_account),
            'refund_bank_holder' => $this->refundBankHolderForViewer($this->refund_bank_holder),

            // 현금영수증 (cash_receipt_identifier 는 마스킹 값 — 원본/암호문은 노출하지 않는다)
            'is_cash_receipt_requested' => (bool) $this->is_cash_receipt_requested,
            'is_cash_receipt_issued' => (bool) $this->is_cash_receipt_issued,
            'cash_receipt_type' => $this->cash_receipt_type,
            // 레거시 값(income_deduction 등)도 표시명으로 해석되도록 Enum 의 fromLegacy 를 경유한다.
            'cash_receipt_type_label' => $this->getCashReceiptType()?->label(),
            'cash_receipt_identifier_type' => $this->cash_receipt_identifier_type,
            'cash_receipt_identifier' => $this->cash_receipt_identifier,
            // 현재 설정된 발급 프로바이더 (미설정이면 null).
            // 주문상세의 현금영수증 카드가 이 리소스만 보고 렌더 여부를 판정할 수 있도록 함께 내린다
            // (프로바이더는 상점 전역 설정이지만 카드는 결제 정보 안에 있다).
            'cash_receipt_provider' => app(EcommerceSettingsService::class)->getCashReceiptProvider(),
            // 머신 ISO8601 — 표시는 cash_receipt_issued_at_formatted 사용
            'cash_receipt_issued_at' => $this->cash_receipt_issued_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: machine ISO8601, display uses cash_receipt_issued_at_formatted sibling
            'cash_receipt_issued_at_formatted' => $this->formatDateTimeStringForUser($this->cash_receipt_issued_at),

            // PG 영수증 URL (카드 매출전표 등) — 현금영수증 URL 과는 다른 값이며 서로 덮어쓰지 않는다.
            // 현금영수증 URL 은 주문의 cash_receipt.receipt_url 을 쓴다.
            // receipt_url 은 기존 소비자(KG이니시스 영수증 팝업)가 참조하므로 키를 유지하고,
            // 의미가 분명한 pg_receipt_url 을 함께 노출한다.
            'receipt_url' => $this->receipt_url,
            'pg_receipt_url' => $this->receipt_url,

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
        $method = $this->paymentMethodId();

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
