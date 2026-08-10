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
     * @param  string  $triggerStatus  트리거 상태 값 (confirmed|delivered)
     * @param  int  $delayDays  적립 지연 일수
     * @param  Carbon  $now  기준 시각
     * @param  int|null  $limit  최대 건수
     * @return \Illuminate\Support\Collection<int, object> 적립 대상 [{option_id, order_id, user_id}]
     */
    public function getEarnableOptions(string $triggerColumn, string $triggerStatus, int $delayDays, Carbon $now, ?int $limit = null): \Illuminate\Support\Collection;

    /**
     * 해당 주문옵션의 구매 적립건을 조회합니다.
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @return MileageTransaction|null 적립건 또는 null
     */
    public function findEarnLotForOption(int $orderOptionId): ?MileageTransaction;

    /**
     * 회원의 활성 적립건(lot) 전부를 조회합니다 (FOR UPDATE 없음 — 탈퇴 정리용).
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, MileageTransaction> 활성 lot 컬렉션
     */
    public function getActiveLotsForUser(int $userId): Collection;

    /**
     * 회원의 잔여 있는 특정 lot 들을 조회합니다 (유효기간 연장 대상).
     *
     * @param  int  $userId  회원 ID
     * @param  array<int, int>  $lotIds  lot ID 목록
     * @return Collection<int, MileageTransaction> 대상 lot 컬렉션
     */
    public function getRemainingLotsByIds(int $userId, array $lotIds): Collection;

    /**
     * 적립건의 만료일을 갱신합니다.
     *
     * @param  MileageTransaction  $lot  대상 적립건
     * @param  Carbon  $expiresAt  새 만료일
     */
    public function updateExpiry(MileageTransaction $lot, Carbon $expiresAt): void;

    /**
     * 회원의 모든 거래 행을 삭제합니다 (탈퇴/삭제 정리 — CASCADE 의존 금지).
     *
     * @param  int  $userId  회원 ID
     * @return int 삭제된 행 수
     */
    public function deleteForUser(int $userId): int;
}
