<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;

/**
 * 클레임 추가 결제 건 요약 (공개 계약 DTO)
 *
 * @since 1.4.0
 */
final readonly class ClaimPaymentRecord
{
    /**
     * ClaimPaymentRecord 생성자
     *
     * @param  int  $id  결제 건 id
     * @param  string  $paymentNumber  결제 번호(CP 접두)
     * @param  string|null  $orderNumber  부모 주문번호(주문이 없거나 삭제됐으면 null)
     * @param  string|null  $pgProvider  결제사 id(결제수단을 고르기 전이면 null)
     * @param  string|null  $paymentMethod  결제수단 id
     * @param  ClaimPaymentStatusEnum|null  $paymentStatus  결제 상태
     * @param  string|null  $transactionId  결제사 거래번호
     * @param  float  $amount  기준 통화 결제액(포인트 제외)
     * @param  float  $refundedAmount  기준 통화 누적 환급액
     * @param  array<string, mixed>  $rawResponse  결제사 원응답
     * @param  bool  $isPaid  결제(정산) 완료인지
     * @param  bool  $isPending  결제 대기인지
     */
    public function __construct(
        public int $id,
        public string $paymentNumber,
        public ?string $orderNumber,
        public ?string $pgProvider,
        public ?string $paymentMethod,
        public ?ClaimPaymentStatusEnum $paymentStatus,
        public ?string $transactionId,
        public float $amount,
        public float $refundedAmount,
        public array $rawResponse,
        public bool $isPaid,
        public bool $isPending,
    ) {}

    /**
     * 결제 건 Model 과 계약 구현이 미리 읽어 둔 부모 주문번호에서 DTO 를 만듭니다. 이 메서드는 조회하지 않는다.
     *
     * @internal 이커머스 계약 구현만 쓴다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  string|null  $orderNumber  부모 주문번호(계약 구현이 읽어 둔 값, 주문이 없으면 null)
     * @return self DTO
     */
    public static function fromModel(ClaimPayment $payment, ?string $orderNumber): self
    {
        $status = $payment->payment_status;

        return new self(
            id: (int) $payment->id,
            paymentNumber: (string) $payment->payment_number,
            orderNumber: $orderNumber === null ? null : (string) $orderNumber,
            pgProvider: $payment->pg_provider === null ? null : (string) $payment->pg_provider,
            paymentMethod: $payment->payment_method === null ? null : (string) $payment->payment_method,
            paymentStatus: $status instanceof ClaimPaymentStatusEnum ? $status : ClaimPaymentStatusEnum::tryFrom((string) $status),
            transactionId: $payment->transaction_id === null ? null : (string) $payment->transaction_id,
            amount: (float) $payment->amount,
            refundedAmount: (float) $payment->refunded_amount,
            rawResponse: is_array($payment->raw_response) ? $payment->raw_response : [],
            isPaid: $payment->isPaid(),
            isPending: $payment->isPending(),
        );
    }
}
