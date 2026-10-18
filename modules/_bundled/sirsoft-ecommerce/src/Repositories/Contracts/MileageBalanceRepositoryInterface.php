<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;

/**
 * 마일리지 잔액 캐시 Repository 인터페이스 (파생 캐시)
 *
 * 캐시는 표시 전용 단방향 파생(캐시→원장 역기록 없음). 금전 경로는 원장 FOR UPDATE 재검증.
 */
interface MileageBalanceRepositoryInterface
{
    /**
     * 캐시된 잔액을 조회합니다 (O(1), 화면 주입용).
     *
     * 단일/통화별 행을 조회하며, 통화 미지정 시 전체 통화 합산 + by_currency 배열을 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string|null  $currency  통화 코드 (null 시 전체 통화)
     * @return array 잔액 배열 (available/pending/total_earned/total_used/expiring_soon/expiring_date/by_currency)
     */
    public function getCachedBalance(int $userId, ?string $currency = null): array;

    /**
     * 원장 SUM 으로 캐시 행의 available/total_earned/total_used 를 재계산합니다 (거래 트랜잭션 내 호출).
     *
     * @param  int  $userId  회원 ID
     * @param  string|null  $currency  통화 코드 (null 시 회원의 모든 통화)
     * @return void 반환값 없음
     */
    public function recalculateForUser(int $userId, ?string $currency = null): void;

    /**
     * pending(적립 예정) 컬럼을 재계산합니다.
     *
     * 원장이 아닌 ecommerce_order_options.subtotal_earned_points_amount(미취소·earn ledger 부재 옵션) 합으로 upsert.
     *
     * @param  int  $userId  회원 ID
     * @param  string|null  $currency  통화 코드 (null 시 회원의 모든 통화)
     * @return void 반환값 없음
     */
    public function recalculatePending(int $userId, ?string $currency = null): void;

    /**
     * 소멸 예정 윈도우(expiring_soon/expiring_date)를 재계산합니다 (일배치용).
     *
     * @param  int  $daysBefore  소멸 전 알림 일수
     * @return void 반환값 없음
     */
    public function recalculateExpiringWindow(int $daysBefore): void;

    /**
     * 전 회원 캐시를 원장/옵션 기준으로 전체 재산출합니다 (drift 교정 일배치용).
     *
     * @return void 반환값 없음
     */
    public function recalculateAll(): void;

    /**
     * 소멸 예정 잔액 보유 회원 캐시 행을 조회합니다 (알림 대상).
     *
     * @param  int|null  $limit  최대 건수
     * @return Collection<int, MileageBalance> 캐시 행
     */
    public function getExpiringTargets(?int $limit = null): Collection;

    /**
     * 회원의 모든 잔액 캐시 행을 삭제합니다 (탈퇴/삭제 정리 — CASCADE 의존 금지).
     *
     * @param  int  $userId  회원 ID
     * @return int 삭제된 행 수
     */
    public function deleteForUser(int $userId): int;

    /**
     * 사용 가능 잔액 조건에 맞는 회원 ID 서브쿼리를 만듭니다 (회원 목록 확장 필터용, #705).
     *
     * 캐시 행이 있는 회원만 대상이다 — 행이 없는 회원은 잔액 0 으로 보므로, 호출자가
     * 하한(>0)에는 `whereIn`, 상한 초과 배제에는 `whereNotIn` 으로 이 서브쿼리를 쓴다.
     *
     * @param  string  $currency  통화 코드
     * @param  string  $operator  비교 연산자 ('>=' 또는 '>')
     * @param  float  $amount  기준 금액
     * @return Builder user_id 한 열을 고르는 서브쿼리
     */
    public function userIdsByAvailableQuery(string $currency, string $operator, float $amount): Builder;
}
