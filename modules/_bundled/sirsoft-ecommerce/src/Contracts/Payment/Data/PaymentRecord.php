<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * 다른 확장에 건네는 주문 결제 행 (공개 계약 DTO)
 *
 * 결제 Model 대신 이 값만 넘긴다. 판정 값(isPaid 등)은 이커머스 Model 의 판정 메서드와 같은 결과다.
 *
 * @since 1.4.0
 */
final readonly class PaymentRecord
{
    /**
     * PaymentRecord 생성자
     *
     * @param  int  $id  결제 행 id
     * @param  int  $orderId  주문 id
     * @param  string|null  $orderNumber  주문번호(목록·거래 조회는 항상 채움, 그 밖에는 알 때만)
     * @param  string|null  $pgProvider  결제사 id
     * @param  string|null  $paymentMethod  결제수단 id(문자열)
     * @param  PaymentStatusEnum  $paymentStatus  결제 상태
     * @param  string|null  $transactionId  결제사 거래번호
     * @param  string|null  $merchantOrderId  가맹점 주문번호
     * @param  bool  $isEscrow  에스크로 여부
     * @param  array<string, mixed>  $paymentMeta  결제 meta 원본
     * @param  string|null  $embeddedPgProvider  간편결제 제공자
     * @param  string|null  $receiptUrl  영수증 주소
     * @param  string|null  $paymentDevice  결제 기기(pc, mobile)
     * @param  string|null  $paymentName  결제명
     * @param  string|null  $currency  결제 통화
     * @param  float  $paidAmountLocal  결제 통화 기준 결제액
     * @param  float  $cancelledAmount  기준 통화 누적 취소액
     * @param  array<string, float>  $mcCancelledAmount  통화별 누적 취소액
     * @param  array<int, array<string, mixed>>  $cancelHistory  취소 이력
     * @param  CarbonImmutable|null  $paidAt  결제 시각
     * @param  CarbonImmutable|null  $cancelledAt  취소 시각
     * @param  string|null  $cardName  카드사명
     * @param  string|null  $cardNumberMasked  가린 카드번호
     * @param  string|null  $cardApprovalNumber  카드 승인번호
     * @param  int|null  $cardInstallmentMonths  할부 개월
     * @param  bool|null  $isInterestFree  무이자 여부
     * @param  string|null  $vbankCode  가상계좌 은행 코드
     * @param  string|null  $vbankName  가상계좌 은행명
     * @param  string|null  $vbankNumber  가상계좌 번호
     * @param  string|null  $vbankHolder  가상계좌 예금주
     * @param  CarbonImmutable|null  $vbankDueAt  입금 기한
     * @param  CarbonImmutable|null  $vbankIssuedAt  발급 시각
     * @param  string|null  $buyerName  구매자명
     * @param  string|null  $buyerEmail  구매자 이메일
     * @param  string|null  $buyerPhone  구매자 전화
     * @param  string|null  $refundBankCode  환불 계좌 은행 코드
     * @param  string|null  $refundBankAccount  환불 계좌 번호
     * @param  string|null  $refundBankHolder  환불 계좌 예금주
     * @param  bool  $isPaid  결제 완료 상태인지
     * @param  bool  $isWaitingDeposit  입금 대기 상태인지
     * @param  bool  $isVirtualAccount  가상계좌 결제인지
     * @param  bool  $isFullyCancelled  전액 취소됐는지
     * @param  float  $cancellableAmount  결제 통화 기준 남은 취소 가능액
     * @param  float  $cancelledLocalAmount  결제 통화 기준 누적 취소액
     * @param  CarbonImmutable|null  $createdAt  결제 행 생성 시각
     */
    public function __construct(
        public int $id,
        public int $orderId,
        public ?string $orderNumber,
        public ?string $pgProvider,
        public ?string $paymentMethod,
        public PaymentStatusEnum $paymentStatus,
        public ?string $transactionId,
        public ?string $merchantOrderId,
        public bool $isEscrow,
        public array $paymentMeta,
        public ?string $embeddedPgProvider,
        public ?string $receiptUrl,
        public ?string $paymentDevice,
        public ?string $paymentName,
        public ?string $currency,
        public float $paidAmountLocal,
        public float $cancelledAmount,
        public array $mcCancelledAmount,
        public array $cancelHistory,
        public ?CarbonImmutable $paidAt,
        public ?CarbonImmutable $cancelledAt,
        public ?string $cardName,
        public ?string $cardNumberMasked,
        public ?string $cardApprovalNumber,
        public ?int $cardInstallmentMonths,
        public ?bool $isInterestFree,
        public ?string $vbankCode,
        public ?string $vbankName,
        public ?string $vbankNumber,
        public ?string $vbankHolder,
        public ?CarbonImmutable $vbankDueAt,
        public ?CarbonImmutable $vbankIssuedAt,
        public ?string $buyerName,
        public ?string $buyerEmail,
        public ?string $buyerPhone,
        public ?string $refundBankCode,
        public ?string $refundBankAccount,
        public ?string $refundBankHolder,
        public bool $isPaid,
        public bool $isWaitingDeposit,
        public bool $isVirtualAccount,
        public bool $isFullyCancelled,
        public float $cancellableAmount,
        public float $cancelledLocalAmount,
        public ?CarbonImmutable $createdAt,
    ) {}

    /**
     * 결제 Model 에서 DTO 를 만듭니다.
     *
     * @internal 이커머스 계약 구현만 쓴다.
     *
     * @param  OrderPayment  $payment  결제 행
     * @param  string|null  $orderNumber  주문번호(알면)
     * @return self DTO
     */
    public static function fromModel(OrderPayment $payment, ?string $orderNumber = null): self
    {
        $string = static fn ($value): ?string => $value === null ? null : (string) $value;

        return new self(
            id: (int) $payment->id,
            orderId: (int) $payment->order_id,
            orderNumber: $orderNumber,
            pgProvider: $string($payment->pg_provider),
            paymentMethod: $payment->payment_method === null ? null : $payment->paymentMethodId(),
            paymentStatus: $payment->payment_status instanceof PaymentStatusEnum
                ? $payment->payment_status
                : PaymentStatusEnum::from((string) $payment->payment_status),
            transactionId: $string($payment->transaction_id),
            merchantOrderId: $string($payment->merchant_order_id),
            isEscrow: (bool) $payment->is_escrow,
            paymentMeta: is_array($payment->payment_meta) ? $payment->payment_meta : [],
            embeddedPgProvider: $string($payment->embedded_pg_provider),
            receiptUrl: $string($payment->receipt_url),
            paymentDevice: $string($payment->payment_device),
            paymentName: $string($payment->payment_name),
            currency: $string($payment->currency),
            paidAmountLocal: (float) $payment->paid_amount_local,
            cancelledAmount: (float) $payment->cancelled_amount,
            mcCancelledAmount: array_map('floatval', is_array($payment->mc_cancelled_amount) ? $payment->mc_cancelled_amount : []),
            cancelHistory: is_array($payment->cancel_history) ? $payment->cancel_history : [],
            paidAt: self::immutable($payment->paid_at),
            cancelledAt: self::immutable($payment->cancelled_at),
            cardName: $string($payment->card_name),
            cardNumberMasked: $string($payment->card_number_masked),
            cardApprovalNumber: $string($payment->card_approval_number),
            cardInstallmentMonths: $payment->card_installment_months === null ? null : (int) $payment->card_installment_months,
            isInterestFree: $payment->is_interest_free === null ? null : (bool) $payment->is_interest_free,
            vbankCode: $string($payment->vbank_code),
            vbankName: $string($payment->vbank_name),
            vbankNumber: $string($payment->vbank_number),
            vbankHolder: $string($payment->vbank_holder),
            vbankDueAt: self::immutable($payment->vbank_due_at),
            vbankIssuedAt: self::immutable($payment->vbank_issued_at),
            buyerName: $string($payment->buyer_name),
            buyerEmail: $string($payment->buyer_email),
            buyerPhone: $string($payment->buyer_phone),
            refundBankCode: $string($payment->refund_bank_code),
            refundBankAccount: $string($payment->refund_bank_account),
            refundBankHolder: $string($payment->refund_bank_holder),
            isPaid: $payment->isPaid(),
            isWaitingDeposit: $payment->isWaitingDeposit(),
            isVirtualAccount: $payment->isVirtualAccount(),
            isFullyCancelled: $payment->isFullyCancelled(),
            cancellableAmount: (float) $payment->getCancellableAmount(),
            cancelledLocalAmount: (float) $payment->cancelledLocalAmount(),
            createdAt: self::immutable($payment->created_at),
        );
    }

    /**
     * 시각 값을 불변 Carbon 으로 바꿉니다.
     *
     * @param  mixed  $value  시각(Carbon, 문자열, null)
     * @return CarbonImmutable|null 변환 결과
     */
    private static function immutable(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);
    }
}
