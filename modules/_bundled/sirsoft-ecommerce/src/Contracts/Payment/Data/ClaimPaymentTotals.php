<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 클레임 결제 건의 결제 통화 합계와 환급 합계 (공개 계약 DTO)
 *
 * @since 1.4.0
 */
final readonly class ClaimPaymentTotals
{
    /**
     * ClaimPaymentTotals 생성자
     *
     * @param  float  $paymentCurrencyTotal  결제 통화 기준 결제 총액(포인트 포함)
     * @param  float  $refundedPaymentCurrencyTotal  결제 통화 기준 누적 환급액
     * @param  float  $refundedAmount  기준 통화 누적 환급액
     */
    public function __construct(
        public float $paymentCurrencyTotal,
        public float $refundedPaymentCurrencyTotal,
        public float $refundedAmount,
    ) {}
}
