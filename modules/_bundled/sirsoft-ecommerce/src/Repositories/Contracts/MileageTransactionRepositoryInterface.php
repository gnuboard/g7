<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;

/**
 * 마일리지 거래(원장) Repository 인터페이스
 *
 * 잔액 SSoT = SUM(remaining_amount WHERE remaining_amount > 0 AND 미만료).
 * getBalance 는 금전 검증(차감/검증) 경로의 SSoT 로만 사용하고 화면 주입엔 미사용(캐시 사용).
 */
interface MileageTransactionRepositoryInterface
{
    /**
     * 회원의 전체 통화 합산 사용 가능 잔액을 조회합니다 (원장 SUM).
     *
     * @param  int  $userId  회원 ID
     * @return float 사용 가능 잔액
     */
    public function getBalance(int $userId): float;

    /**
     * 회원의 통화별 사용 가능 잔액을 조회합니다 (원장 SUM).
     *
     * @param  int  $userId  회원 ID
     * @param  string  $currency  통화 코드
     * @return float 사용 가능 잔액
     */
    public function getBalanceByCurrency(int $userId, string $currency): float;

    /**
     * FIFO 차감용 활성 lot 을 FOR UPDATE 로 잠그고 조회합니다 (만료 임박 순).
     *
     * @param  int  $userId  회원 ID
     * @param  string  $currency  통화 코드
     * @return Collection<int, MileageTransaction> 활성 lot 컬렉션
     */
    public function getActiveLotsForUpdate(int $userId, string $currency): Collection;

    /**
     * 거래를 생성합니다.
     *
     * @param  array  $data  거래 데이터
     * @return MileageTransaction 생성된 거래
     */
    public function createTransaction(array $data): MileageTransaction;

    /**
     * 적립건(lot)의 잔여 금액을 차감합니다.
     *
     * @param  MileageTransaction  $lot  대상 적립건
     * @param  float  $amount  차감액
     * @return void 반환값 없음
     */
    public function decrementRemaining(MileageTransaction $lot, float $amount): void;

    /**
     * 회원 uuid 를 정수 user_id 로 해석합니다.
     *
     * 코어 UserResource 가 보안상 정수 id 를 노출하지 않으므로(uuid 만 노출), 관리자 수동
     * 지급/차감·유효기간 연장은 회원 식별자를 uuid 로 전달받아 이 메서드로 내부 id 로 변환합니다.
     *
     * @param  string  $uuid  회원 uuid
     * @return int|null 정수 user_id (미존재 시 null)
     */
    public function resolveUserIdByUuid(string $uuid): ?int;

    /**
     * 해당 주문옵션에 대한 적립 거래가 이미 존재하는지 확인합니다 (멱등 가드).
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @return bool 존재 여부
     */
    public function existsEarnForOption(int $orderOptionId): bool;

    /**
     * 해당 주문옵션의 구매 적립(purchase_earn) 총액을 반환합니다 (금액 델타 멱등 기준).
     *
     * 관리자 수동 지급(admin_earn)·복원(refund_restore/order_cancel_restore)은 제외하고
     * 구매확정/배송 적립(purchase_earn)만 합산한다. 나눠 확정·병합으로 옵션의 적립 예정액이
     * 늘었을 때, 목표액과 이 합계의 차액(델타)만 추가 적립하기 위한 기준값이다.
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @return float 기적립 purchase_earn 총액
     */
    public function sumPurchaseEarnedForOption(int $orderOptionId): float;

    /**
     * 피흡수 옵션의 구매 적립(purchase_earn) 거래를 생존 옵션으로 이전합니다 (병합 정합).
     *
     * 옵션 병합 시 shipping·review 처럼 마일리지 적립 거래의 order_option_id 도 생존 레코드로
     * 옮겨, 병합 후 생존 옵션 기준의 적립 합계·델타 계산이 정합하도록 한다.
     *
     * @param  int  $fromOrderOptionId  피흡수 옵션 ID
     * @param  int  $toOrderOptionId  생존 옵션 ID
     * @return int 이전된 거래 수
     */
    public function transferPurchaseEarnByOrderOptionId(int $fromOrderOptionId, int $toOrderOptionId): int;

    /**
     * 기존 구매 적립건(lot)에 델타 금액을 증액합니다 (방식 A — 적립 내역 한 줄 유지).
     *
     * amount·remaining_amount 를 델타만큼 증가시킨다. expires_at 은 최초 적립 시점을 유지한다.
     *
     * @param  MileageTransaction  $lot  대상 적립건
     * @param  float  $delta  증액할 금액 (> 0)
     * @return void 반환값 없음
     */
    public function incrementEarnLotAmount(MileageTransaction $lot, float $delta): void;

    /**
     * 해당 주문취소에 대한 복원 거래가 이미 존재하는지 확인합니다 (멱등 가드).
     *
     * @param  int  $orderCancelId  주문취소 ID
     * @return bool 존재 여부
     */
    public function existsRestoreForCancel(int $orderCancelId): bool;

    /**
     * 해당 주문 환불에 대한 복원 거래가 이미 존재하는지 확인합니다 (클레임 환불 실행 멱등 가드).
     *
     * 취소 발 복원의 `order_cancel_id` 와 같은 역할을 클레임 발 환불 실행(S6b)에서
     * `order_refund_id` 가 담당한다 — 같은 환불의 실행이 재시도되어도 환급 lot 은 한 번만 발행된다.
     *
     * @param  int  $orderRefundId  주문 환불 ID
     * @return bool 존재 여부
     */
    public function existsRestoreForRefund(int $orderRefundId): bool;

    /**
     * 해당 주문 환불의 현금분 마일리지 지급(refund_credit) 거래가 이미 존재하는지 확인합니다.
     *
     * 사용 마일리지 환급(`existsRestoreForRefund`)과 **다른 축**이다 — 같은 환불에서 둘이 함께
     * 생길 수 있어(혼합 결제를 마일리지로 환불) 타입으로 판정을 가른다.
     *
     * @param  int  $orderRefundId  주문 환불 ID
     * @return bool 존재 여부
     */
    public function existsCreditForRefund(int $orderRefundId): bool;

    /**
     * 주문을 취소하며 돌려준 사용 마일리지 합계를 돌려줍니다. (재활성 회수 판정 — Q14)
     *
     * 취소 발 복원(`order_cancel_id` 있음 — 미결제·0원 취소)과, 취소 출처 환불의 복원(`order_refund_id`
     * 가 가리키는 환불의 출처가 취소)만 센다. 반품·교환 발 복원은 상품이 되살아나지 않으므로 빼고,
     * 클레임 추가결제 축(`claim_payment_id`)과 결제 실패 복원(두 ID 모두 없음)도 뺀다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<int, string>  $cancelSourceValues  취소 출처 값 (`RefundSourceTypeEnum::cancellationSourceValues()`)
     * @return float 복원 합계 (양수)
     */
    public function sumCancellationRestoredForOrder(int $orderId, array $cancelSourceValues): float;

    /**
     * 재활성으로 다시 뺀 사용 마일리지 합계를 돌려줍니다. (`order_use`·`metadata.reason=reactivation`)
     *
     * @param  int  $orderId  주문 ID
     * @return float 재차감 합계 (양수)
     */
    public function sumReactivationUsageForOrder(int $orderId): float;

    /**
     * 복원의 원 사용 거래에 동일 order_cancel_id 를 역주입합니다 (연결 거래 추적).
     *
     * 복원(ORDER_CANCEL_RESTORE)을 펼쳤을 때 그 주문에서 마일리지를 소비한 원 사용
     * 거래(ORDER_USE)가 연결 거래로 조회되도록, 아직 취소에 묶이지 않은 사용 거래에
     * cancel_id 를 채웁니다.
     *
     * @param  int  $orderId  주문 ID
     * @param  int  $orderCancelId  주문취소 ID
     * @return int 갱신된 사용 거래 수
     */
    public function linkUsageToCancel(int $orderId, int $orderCancelId): int;

    /**
     * 환급의 원 사용 거래에 동일 order_refund_id 를 역주입합니다 (연결 거래 추적).
     *
     * 취소 발(`linkUsageToCancel`)과 대칭인 환불 발 축이다. 이 역주입이 없으면 클레임 환불
     * 발 환급(REFUND_RESTORE)은 `source_transaction_id` 도 NULL 이라 연결 거래가 항상
     * 비어, 취소 발 환급만 원 사용 거래가 보이는 비대칭이 된다.
     *
     * @param  int  $orderId  주문 ID
     * @param  int  $orderRefundId  주문 환불 ID
     * @return int 갱신된 사용 거래 수
     */
    public function linkUsageToRefund(int $orderId, int $orderRefundId): int;

    /**
     * 관리자 내역 화면용 필터링된 목록을 조회합니다 (페이지네이션).
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이터
     */
    public function paginateWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 마이페이지용 회원 거래 목록을 조회합니다 (페이지네이션).
     *
     * @param  int  $userId  회원 ID
     * @param  array  $filters  필터 조건 (category 등)
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이터
     */
    public function paginateForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 소멸 대상 적립건(lot)을 조회합니다.
     *
     * @param  Carbon  $now  기준 시각
     * @param  int|null  $limit  최대 건수 (청크 처리)
     * @return Collection<int, MileageTransaction> 소멸 대상 컬렉션
     */
    public function getExpiringLots(Carbon $now, ?int $limit = null): Collection;

    /**
     * 적립건을 소멸 처리합니다 (remaining_amount=0, expired_at 기록).
     *
     * @param  MileageTransaction  $lot  대상 적립건
     * @param  Carbon  $now  소멸 처리 시각
     * @return void 반환값 없음
     */
    public function markExpired(MileageTransaction $lot, Carbon $now): void;

    /**
     * 관리자 편집 필드(memo / expires_at)를 갱신합니다.
     *
     * @param  MileageTransaction  $transaction  대상 거래
     * @param  array<string, mixed>  $fields  갱신 필드 (memo / expires_at 키만 반영)
     * @return MileageTransaction 갱신된 거래
     */
    public function updateAdminFields(MileageTransaction $transaction, array $fields): MileageTransaction;

    /**
     * 행 확장(연결 거래)용 거래를 조회합니다 (source_transaction_id / order_cancel_id 연결).
     *
     * @param  MileageTransaction  $transaction  기준 거래
     * @return Collection<int, MileageTransaction> 연결 거래 컬렉션
     */
    public function getLinkedTransactions(MileageTransaction $transaction): Collection;

    /**
     * ID 로 거래를 조회합니다.
     *
     * @param  int  $id  거래 ID
     * @return MileageTransaction|null 거래 또는 null
     */
    public function findById(int $id): ?MileageTransaction;

    /**
     * 적립 대상 주문옵션을 조회합니다 (스케줄러용).
     *
     * 조건: trigger 상태 도달 + {trigger}_at + delay <= now + earn ledger 부재 + 미취소 + 적립액 > 0.
     *
     * @param  string  $triggerColumn  트리거 시점 컬럼 (confirmed_at|delivered_at)
     * @param  string|array<int, string>  $triggerStatus  대상 상품 상태 (트리거 상태 또는 트리거 이후 상태 목록 — F4)
     * @param  int  $delayDays  적립 지연 일수
     * @param  Carbon  $now  기준 시각
     * @param  int|null  $limit  최대 건수
     * @return \Illuminate\Support\Collection<int, object> 적립 대상 [{option_id, order_id, user_id}]
     */
    public function getEarnableOptions(string $triggerColumn, string|array $triggerStatus, int $delayDays, Carbon $now, ?int $limit = null): \Illuminate\Support\Collection;

    /**
     * 해당 주문옵션의 구매 적립건을 조회합니다.
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @return MileageTransaction|null 적립건 또는 null
     */
    public function findEarnLotForOption(int $orderOptionId): ?MileageTransaction;

    /**
     * 주문옵션의 적립 lot 을 행 잠금과 함께 조회합니다.
     *
     * 적립 증액·취소 회수는 lot 의 현재 값을 읽어 더하거나 빼는 경로라, 두 요청이 같은
     * 값을 읽으면 한쪽 반영이 사라집니다. 갱신 트랜잭션 안에서 이 메서드로 잠급니다.
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @return MileageTransaction|null 잠긴 적립 lot (없으면 null)
     */
    public function findEarnLotForOptionForUpdate(int $orderOptionId): ?MileageTransaction;

    /**
     * 회원의 활성 적립건(lot) 전부를 조회합니다 (FOR UPDATE 없음 — 탈퇴 정리용).
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, MileageTransaction> 활성 lot 컬렉션
     */
    public function getActiveLotsForUser(int $userId): Collection;

    /**
     * 회원의 활성 적립건(lot)을 잠가 조회합니다. (탈퇴 소멸 — 동시 사용·소멸 배치와 경합 차단)
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, MileageTransaction> 활성 lot 컬렉션
     */
    public function getActiveLotsForUserForUpdate(int $userId): Collection;

    /**
     * 회원의 특정 유형 lot 중 아직 남은 금액 합을 구합니다. (소멸되지 않은 것만)
     *
     * @param  int  $userId  회원 ID
     * @param  string  $type  거래 유형 값
     * @param  string  $currency  통화 코드
     * @return float 남은 금액 합
     */
    public function sumRemainingByType(int $userId, string $type, string $currency): float;

    /**
     * 잔여 있는 특정 lot 들을 조회합니다 (유효기간 연장 대상).
     *
     * 회원을 지정하면 그 회원의 lot 만, null 이면 회원과 무관하게 지정한 lot 전부를 대상으로 한다
     * (관리자 마일리지 내역에서 여러 회원의 적립건을 함께 고르는 일괄 연장).
     *
     * @param  int|null  $userId  회원 ID (null 이면 회원 제한 없음)
     * @param  array<int, int>  $lotIds  lot ID 목록
     * @return Collection<int, MileageTransaction> 대상 lot 컬렉션
     */
    public function getRemainingLotsByIds(?int $userId, array $lotIds): Collection;

    /**
     * 적립건의 만료일을 갱신합니다.
     *
     * @param  MileageTransaction  $lot  대상 적립건
     * @param  Carbon  $expiresAt  새 만료일
     * @return void 반환값 없음
     */
    public function updateExpiry(MileageTransaction $lot, Carbon $expiresAt): void;

    /**
     * 회원의 거래 행에서 회원 연결만 끊습니다. (회원 완전 삭제 — 거래 기록은 보존, E3)
     *
     * @param  int  $userId  회원 ID
     * @return int 연결을 끊은 행 수
     */
    public function detachUser(int $userId): int;

    /**
     * 클레임 추가결제 건의 마일리지 환급 거래가 이미 있는지 확인합니다. (환급 멱등 축)
     *
     * @param  int  $claimPaymentId  클레임 추가결제 ID
     * @return bool 환급 거래가 있으면 true
     */
    public function existsRestoreForClaimPayment(int $claimPaymentId): bool;

    /**
     * 클레임 추가결제 건의 마일리지 환급 누적액(양수)을 반환합니다.
     *
     * @param  int  $claimPaymentId  클레임 추가결제 ID
     * @return float 환급 누적액
     */
    public function sumRestoredForClaimPayment(int $claimPaymentId): float;

    /**
     * 클레임 추가결제 건으로 이미 기록된 마일리지 사용(차감) 거래를 찾습니다. (차감 멱등 축)
     *
     * @param  int  $claimPaymentId  클레임 추가결제 ID
     * @return MileageTransaction|null 사용 거래 (없으면 null)
     */
    public function findUsageForClaimPayment(int $claimPaymentId): ?MileageTransaction;
}
