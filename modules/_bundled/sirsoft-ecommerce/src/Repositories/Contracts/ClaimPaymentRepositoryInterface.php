<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;

/**
 * 클레임 추가결제 리포지토리 인터페이스
 */
interface ClaimPaymentRepositoryInterface
{
    /**
     * 추가결제 건을 생성합니다.
     *
     * @param  array  $data  추가결제 데이터
     * @return ClaimPayment 생성된 추가결제
     */
    public function create(array $data): ClaimPayment;

    /**
     * 추가결제 건을 수정합니다.
     *
     * @param  ClaimPayment  $payment  추가결제
     * @param  array  $data  수정 데이터
     * @return ClaimPayment 수정된 추가결제
     */
    public function update(ClaimPayment $payment, array $data): ClaimPayment;

    /**
     * ID로 추가결제 건을 조회합니다.
     *
     * @param  int  $id  추가결제 ID
     * @return ClaimPayment|null 추가결제 (없으면 null)
     */
    public function findById(int $id): ?ClaimPayment;

    /**
     * 결제번호로 추가결제 건을 조회합니다.
     *
     * PG 콜백은 merchant_order_id(=payment_number)로 건을 되찾습니다.
     *
     * @param  string  $paymentNumber  결제번호
     * @return ClaimPayment|null 추가결제 (없으면 null)
     */
    public function findByPaymentNumber(string $paymentNumber): ?ClaimPayment;

    /**
     * PG 거래 ID로 추가결제 건을 조회합니다.
     *
     * 재시도 멱등 판정의 축입니다 (transaction_id UNIQUE).
     *
     * @param  string  $transactionId  PG 거래 ID
     * @return ClaimPayment|null 추가결제 (없으면 null)
     */
    public function findByTransactionId(string $transactionId): ?ClaimPayment;

    /**
     * 클레임의 추가결제 목록을 조회합니다.
     *
     * @param  string  $payableType  클레임 유형 (exchange, return)
     * @param  int  $payableId  클레임 ID
     * @return Collection 추가결제 목록
     */
    public function listByPayable(string $payableType, int $payableId): Collection;

    /**
     * 클레임의 수납 완료 금액 합계(PG + 마일리지)를 반환합니다.
     *
     * @param  string  $payableType  클레임 유형
     * @param  int  $payableId  클레임 ID
     * @return float 수납 완료 금액 합계
     */
    public function sumPaidAmount(string $payableType, int $payableId): float;

    /**
     * 잠금(SELECT ... FOR UPDATE)과 함께 추가결제 건을 조회합니다.
     *
     * @param  int  $id  추가결제 ID
     * @return ClaimPayment|null 추가결제 (없으면 null)
     */
    public function findForUpdate(int $id): ?ClaimPayment;
}
