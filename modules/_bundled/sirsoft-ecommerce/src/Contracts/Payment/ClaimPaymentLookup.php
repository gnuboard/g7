<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment;

use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ChargeAmount;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ClaimPaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ClaimPaymentTotals;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;

/**
 * 클레임 추가 결제 조회 공개 계약 (결제사 플러그인용)
 *
 * @since 1.4.0
 */
interface ClaimPaymentLookup
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * 결제사 콜백의 주문번호가 클레임 결제 번호인지 확인합니다.
     *
     * @param  string  $number  콜백 주문번호
     * @return bool 클레임 결제 번호면 true
     */
    public function isClaimPaymentNumber(string $number): bool;

    /**
     * 결제 번호로 클레임 결제 건을 찾습니다(자기 결제사 건이거나 결제수단을 고르기 전 건만).
     *
     * @param  string  $paymentNumber  결제 번호
     * @param  string|array<int, string>  $pg  결제사 id
     * @return ClaimPaymentRecord|null 없으면 null
     */
    public function findClaimPayment(string $paymentNumber, string|array $pg): ?ClaimPaymentRecord;

    /**
     * 클레임 결제 건 Model 을 찾습니다 — 공개 Support(ClaimPaymentCallback, ClaimPaymentRedirect) 인자 호환용이며 읽기 전용이다.
     *
     * 이 Model 로 쓰기나 조회를 하지 않는다. 일반 조회는 findClaimPayment() 를 쓴다.
     *
     * @param  string  $paymentNumber  결제 번호
     * @param  string|array<int, string>  $pg  결제사 id
     * @return ClaimPayment|null 없으면 null
     */
    public function findClaimPaymentModel(string $paymentNumber, string|array $pg): ?ClaimPayment;

    /**
     * 훅이나 위 조회로 받은 결제 건을 DTO 로 바꿉니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return ClaimPaymentRecord DTO
     */
    public function describeClaimPayment(ClaimPayment $payment): ClaimPaymentRecord;

    /**
     * 결제 건의 결제 통화 청구액을 계산합니다. Model 을 넘기면 그 Model 로 계산한다(훅의 잠긴 행 보존).
     *
     * @param  ClaimPayment|string  $payment  결제 건 또는 결제 번호
     * @return ChargeAmount|null 통화를 해석할 수 없으면 null
     */
    public function resolveClaimCharge(ClaimPayment|string $payment): ?ChargeAmount;

    /**
     * 결제 건의 결제 통화 합계와 환급 합계를 돌려줍니다.
     *
     * @param  ClaimPayment|string  $payment  결제 건 또는 결제 번호
     * @return ClaimPaymentTotals 합계
     *
     * @throws Exceptions\PaymentNotFound 결제 번호로 찾지 못했을 때
     */
    public function claimPaymentTotals(ClaimPayment|string $payment): ClaimPaymentTotals;
}
