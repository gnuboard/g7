<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
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

    /**
     * 결제 대상의 결제 대기 건을 조회합니다. (최신 1건)
     *
     * @param  string  $payableType  결제 대상 유형
     * @param  int  $payableId  결제 대상 ID
     * @return ClaimPayment|null 결제 대기 건
     */
    public function findPendingByPayable(string $payableType, int $payableId): ?ClaimPayment;

    /**
     * 기준 시각보다 오래된 결제 대기 건을 조회합니다. (정리 배치용, 오래된 순)
     *
     * @param  Carbon  $cutoff  기준 시각 (이보다 오래된 것만)
     * @param  int  $limit  최대 건수
     * @return Collection<int, ClaimPayment> 결제 대기 건 목록
     */
    public function listPendingOlderThan(Carbon $cutoff, int $limit): Collection;

    /**
     * 결제 대상의 실수납 건(결제 완료 + 환급 잔액 > 0)을 조회합니다. (오래된 순)
     *
     * @param  string  $payableType  결제 대상 유형
     * @param  int  $payableId  결제 대상 ID
     * @return Collection<int, ClaimPayment> 실수납 건
     */
    public function listPaidByPayable(string $payableType, int $payableId): Collection;

    /**
     * 주문의 클레임 추가결제 실수납 합계(부분 환급 차감)를 반환합니다.
     *
     * 주문 상세 Resource 의 `claim_additional_paid_total` 의 SSoT 다 — 결제 완료 건의 청구 총액에서
     * 부분 환급 누적액을 뺀 값이며, 전액 환급 건은 0 으로 센다.
     *
     * @param  int  $orderId  주문 ID
     * @return array{amount: float, mc: array<string, float>} 기준통화 합계 + 다통화 합계
     */
    public function sumNetPaidByOrder(int $orderId): array;

    /**
     * 결제번호로 잠금 조회합니다. (결제 확정·환급의 경합 직렬화)
     *
     * @param  string  $paymentNumber  결제번호
     * @return ClaimPayment|null 잠금된 결제 건
     */
    public function findByPaymentNumberForUpdate(string $paymentNumber): ?ClaimPayment;

    /**
     * 결제 건이 **기대한 상태일 때만** 갱신합니다. (행 잠금 후 재판정 — 조건부 갱신)
     *
     * 승인 경로는 PG 왕복 동안 행을 잠그지 않으므로, 그 사이 다른 요청이 같은 건을 확정할 수
     * 있다. 무조건 갱신하면 실패 기록이 이미 결제 완료된 행의 원응답을 덮거나, 같은 건을 두 번
     * 확정한다. 이 메서드는 행을 잠그고 상태를 다시 본 뒤에만 쓴다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  ClaimPaymentStatusEnum  $expected  기대 상태
     * @param  array  $data  갱신 데이터
     * @return ClaimPayment|null 갱신된 결제 건 (상태가 달라 갱신하지 않았으면 null)
     */
    public function updateIfStatus(ClaimPayment $payment, ClaimPaymentStatusEnum $expected, array $data): ?ClaimPayment;

    /**
     * 클레임 추가결제에 그 결제수단이 기록돼 있는지 확인합니다. (관리자 목록 청구 결제수단 어휘의 마지막 근거)
     *
     * @param  string  $method  결제수단
     * @return bool 기록 존재 여부
     */
    public function hasRecordedPaymentMethod(string $method): bool;
}
