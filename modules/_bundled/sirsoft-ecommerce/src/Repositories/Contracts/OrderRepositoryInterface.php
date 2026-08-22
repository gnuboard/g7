<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 주문 Repository 인터페이스
 */
interface OrderRepositoryInterface
{
    /**
     * ID로 주문 조회
     *
     * @param  int  $id  주문 ID
     * @return Order|null 주문 모델 (없으면 null)
     */
    public function find(int $id): ?Order;

    /**
     * ID로 주문을 행 잠금과 함께 조회합니다.
     *
     * 취소 총액·취소 횟수처럼 현재 값을 읽어 더하는 컬럼은 두 요청이 같은 값을 읽으면
     * 후행이 선행을 덮어씁니다. 트랜잭션 안에서 이 메서드로 행을 잠근 뒤 갱신하면
     * 뒤따르는 요청이 앞선 커밋을 본 뒤에 진행합니다.
     *
     * @param  int  $id  주문 ID
     * @return Order|null 잠긴 주문 모델 (없으면 null)
     */
    public function findByIdForUpdate(int $id): ?Order;

    /**
     * 주문이 1건이라도 존재하는지 확인합니다. (A2 base 통화 변경 가드)
     *
     * 소프트삭제된 주문도 과거 base 로 생성된 이력이므로 포함(withTrashed)해 판정한다.
     *
     * @return bool 1건이라도 존재하면 true
     */
    public function existsAny(): bool;

    /**
     * ID로 주문 조회 (관계 포함)
     *
     * @param  int  $id  주문 ID
     * @return Order|null 주문 모델 (없으면 null)
     */
    public function findWithRelations(int $id): ?Order;

    /**
     * 주문번호로 조회
     *
     * @param  string  $orderNumber  주문번호
     * @return Order|null 주문 모델 (없으면 null)
     */
    public function findByOrderNumber(string $orderNumber): ?Order;

    /**
     * 필터링된 주문 목록 조회 (페이지네이션)
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이션된 주문 목록
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 주문 생성
     *
     * @param  array  $data  주문 데이터
     * @return Order 생성된 주문 모델
     */
    public function create(array $data): Order;

    /**
     * 주문 수정
     *
     * @param  Order  $order  주문 모델
     * @param  array  $data  수정 데이터
     * @return Order 수정된 주문 모델
     */
    public function update(Order $order, array $data): Order;

    /**
     * 주문 삭제 (소프트 삭제)
     *
     * @param  Order  $order  주문 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Order $order): bool;

    /**
     * 주문 일괄 상태 변경
     *
     * @param  array  $ids  주문 ID 배열
     * @param  string  $status  변경할 상태값
     * @return int 변경된 개수
     */
    public function bulkUpdateStatus(array $ids, string $status): int;

    /**
     * 주문 일괄 배송 정보 업데이트
     *
     * @param  array  $ids  주문 ID 배열
     * @param  int|null  $courierId  택배사 ID
     * @param  string|null  $trackingNumber  운송장 번호
     * @return int 변경된 개수
     */
    public function bulkUpdateShipping(array $ids, ?int $courierId, ?string $trackingNumber): int;

    /**
     * 주문의 옵션 상태 일괄 변경
     *
     * @param  array  $ids  주문 ID 배열
     * @param  string  $status  변경할 상태값
     * @return int 변경된 옵션 개수
     */
    public function bulkUpdateOptionStatus(array $ids, string $status): int;

    /**
     * 주문 통계 조회
     *
     * @return array 주문 통계 데이터
     */
    public function getStatistics(): array;

    /**
     * 주문 통계 캐시를 무효화합니다.
     *
     * 주문이 바뀌면 통계도 곧바로 달라져야 하므로, 저장소 밖에서 주문을 변경하는
     * 경로도 이 메서드로 캐시를 비웁니다.
     */
    public function forgetStatisticsCache(): void;

    /**
     * 사용자별 주문상태 통계 조회
     *
     * @param  int  $userId  회원 ID
     * @return array 상태별 주문 건수
     */
    public function getUserStatistics(int $userId): array;

    /**
     * 엑셀 내보내기용 데이터 조회
     *
     * @param  array  $filters  필터 조건
     * @param  array  $ids  특정 ID 배열 (선택 항목)
     * @return Collection 내보내기 대상 주문 컬렉션
     */
    public function getForExport(array $filters, array $ids = []): Collection;

    /**
     * 주문번호 존재 여부 확인
     *
     * @param  string  $orderNumber  주문번호
     * @return bool 존재 여부
     */
    public function existsByOrderNumber(string $orderNumber): bool;

    /**
     * 회원의 주문 존재 여부 확인
     *
     * @param  int  $userId  회원 ID
     * @return bool 주문 존재 여부
     */
    public function hasOrderByUser(int $userId): bool;

    /**
     * 입금 기한 만료된 결제대기 주문 조회
     *
     * vbank/dbank 결제의 입금 기한이 지난 주문들을 조회합니다.
     *
     * @param  int  $limit  최대 조회 개수
     * @return Collection 입금 기한 만료된 결제대기 주문 컬렉션
     */
    public function getExpiredPendingPaymentOrders(int $limit = 100): Collection;

    /**
     * ID 목록으로 주문을 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  주문 ID 목록
     * @return Collection<int, Order> id => Order 매핑
     */
    public function findByIdsKeyed(array $ids): Collection;

    /**
     * ID 목록으로 주문을 관계와 함께 조회하고 ID 키 맵으로 반환합니다.
     *
     * 주문 ID 를 하나씩 돌며 조회하면 대상 수만큼 쿼리가 납니다.
     *
     * @param  array<int, int>  $ids  주문 ID 목록
     * @param  array<int, string>  $with  eager load 할 관계
     * @return Collection<int, Order> 주문 ID ⇒ 주문
     */
    public function findByIdsWithRelationsKeyed(array $ids, array $with = []): Collection;

    /**
     * ID 목록으로 주문 스냅샷(배열)을 조회하고 ID 키 맵으로 반환합니다 (ChangeDetector용).
     *
     * @param  array<int, int>  $ids  주문 ID 목록
     * @return array<int, array> id => 주문 속성 배열 매핑
     */
    public function getSnapshotsByIds(array $ids): array;

    /**
     * 주문 + 주문옵션 + 배송지의 활동 로그를 합쳐서 페이지네이션 조회합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array{per_page?: int, sort_order?: string}  $filters  페이지 크기·정렬 옵션
     * @return LengthAwarePaginator 페이지네이션된 활동 로그
     */
    public function getActivityLogsForOrder(Order $order, array $filters = []): LengthAwarePaginator;
}
