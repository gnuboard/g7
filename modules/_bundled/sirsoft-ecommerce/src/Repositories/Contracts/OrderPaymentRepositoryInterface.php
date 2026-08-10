<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * 주문 결제 Repository 인터페이스
 */
interface OrderPaymentRepositoryInterface
{
    /**
     * 현금영수증 발급 성공 시 결제의 요약 컬럼을 갱신합니다.
     *
     * 원본 식별번호는 encrypted cast 로 암호화되어 저장되고, 표시용으로는 마스킹 값만 남는다.
     *
     * @param  OrderPayment  $payment  결제 모델
     * @param  OrderCashReceipt  $receipt  발급 이력
     * @param  CashReceiptType  $type  발급 용도
     * @param  string  $identifier  식별번호 원본
     * @param  string  $identifierMasked  마스킹된 식별번호
     * @param  CashReceiptIdentifierType|null  $identifierType  식별번호 종류
     * @return OrderPayment 갱신된 결제 모델
     */
    public function markCashReceiptIssued(
        OrderPayment $payment,
        OrderCashReceipt $receipt,
        CashReceiptType $type,
        string $identifier,
        string $identifierMasked,
        ?CashReceiptIdentifierType $identifierType,
    ): OrderPayment;

    /**
     * 현금영수증 전액취소 성공 시 결제의 요약 컬럼을 초기화합니다.
     *
     * @param  OrderPayment  $payment  결제 모델
     * @return OrderPayment 갱신된 결제 모델
     */
    public function markCashReceiptCancelled(OrderPayment $payment): OrderPayment;

    /**
     * 재발급용 식별번호 암호문을 폐기합니다. (구매확정 시점)
     *
     * 이력·영수증 URL·마스킹 값은 유지된다.
     *
     * @param  OrderPayment  $payment  결제 모델
     * @return OrderPayment 갱신된 결제 모델
     */
    public function purgeCashReceiptIdentifier(OrderPayment $payment): OrderPayment;

    /**
     * 암호화된 식별번호 원본을 복호화해 반환합니다.
     *
     * APP_KEY 로테이션 등으로 복호화가 실패하면 null 을 반환한다.
     *
     * @param  OrderPayment  $payment  결제 모델
     * @return string|null 식별번호 원본 (부재/복호화 실패 시 null)
     */
    public function resolveCashReceiptIdentifier(OrderPayment $payment): ?string;

    /**
     * 식별번호 암호문이 저장되어 있는지 확인합니다.
     *
     * @param  OrderPayment  $payment  결제 모델
     * @return bool 암호문 존재 여부
     */
    public function hasCashReceiptIdentifier(OrderPayment $payment): bool;

    /**
     * 환불 계좌 정보를 갱신합니다.
     *
     * 주문 취소 시점에 관리자가 입력·수정한 계좌를 반영한다. PG 환불(executePgRefund)이
     * 이 컬럼을 읽어 refundReceiveAccount 를 구성하므로 환불 실행 전에 갱신되어야 한다.
     *
     * @param  OrderPayment  $payment  결제 모델
     * @param  string  $bankCode  은행코드
     * @param  string  $bankName  은행명 (표시용)
     * @param  string  $accountNumber  계좌번호
     * @param  string  $holder  예금주
     * @return OrderPayment 갱신된 결제 모델
     */
    public function updateRefundBank(
        OrderPayment $payment,
        string $bankCode,
        string $bankName,
        string $accountNumber,
        string $holder,
    ): OrderPayment;

    /**
     * 동일 PG 거래 ID 가 이미 결제완료(PAID) 상태로 저장되어 있는지 확인합니다.
     *
     * PG 웹훅/콜백의 리플레이(중복 통보) 멱등 처리에 사용한다. transaction_id 컬럼에는
     * DB unique 제약이 없으므로, 중복 콜백이 completePayment 를 두 번 실행해 중복
     * 적립·알림이 발생하는 것을 방지하기 위해 콜백 진입 시점에 조회한다.
     *
     * @param  string|null  $transactionId  PG 거래 ID (빈 값이면 false)
     * @return bool 이미 PAID 로 저장된 거래이면 true
     */
    public function isTransactionPaid(?string $transactionId): bool;
}
