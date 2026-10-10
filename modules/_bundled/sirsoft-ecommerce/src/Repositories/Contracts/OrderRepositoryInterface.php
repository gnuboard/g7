<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderTaxInvoice;

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
     * ID로 주문을 배송지와 함께 조회합니다.
     *
     * 클레임 신청 검증(재발송지 국가 비교 등)처럼 주문 헤더와 배송지만 필요한 경로용이다.
     * `findWithRelations()` 는 상품·배송·리뷰까지 싣는 상세 화면용이라 여기에 쓰면 과하고,
     * `find()` 뒤에 배송지를 지연 로드하면 조회 시점이 호출부로 새어 나간다 — 관계를
     * 불러올지는 Repository 가 정한다.
     *
     * @param  int  $id  주문 ID
     * @return Order|null 배송지(shippingAddress)가 로드된 주문 (없으면 null)
     */
    public function findWithShippingAddress(int $id): ?Order;

    /**
     * ID로 주문을 배타적 잠금(행 잠금)과 함께 조회합니다.
     *
     * 클레임 신청·완료 처리처럼 "이 주문의 잔여 수량" 을 읽고 그 위에 쓰는 흐름이
     * 동시에 들어오면, 두 요청이 같은 잔여를 보고 각각 통과해 합계가 잔여를 넘는다.
     * 수량 잠금(D15) 검증과 신청서 생성을 한 트랜잭션 안에서 직렬화하기 위한 잠금이다.
     *
     * 취소 총액·취소 횟수처럼 현재 값을 읽어 더하는 누적 컬럼도 같은 잠금을 쓴다 —
     * 두 요청이 같은 값을 읽으면 후행이 선행을 덮어쓰므로, 트랜잭션 안에서 이 메서드로
     * 행을 잠근 뒤 갱신하면 뒤따르는 요청이 앞선 커밋을 본 뒤에 진행한다.
     *
     * @param  int  $id  주문 ID
     * @return Order|null 잠긴 주문 모델 (없으면 null)
     */
    public function findForUpdate(int $id): ?Order;

    /**
     * ID로 주문을 배타적 잠금과 함께 조회하고, 없으면 예외를 던집니다.
     *
     * 잠금이 전제인 흐름(클레임 접수 등)에서 `findForUpdate($id) ?? $order` 처럼 잠기지 않은
     * 모델로 떨어지면 **잠금 없이 계속 진행**한다 — 두 요청이 같은 잔여 수량을 보고 각각 통과하는
     * 바로 그 경합이 조용히 되살아난다. 그 자리에는 이 메서드를 쓴다.
     *
     * @param  int  $id  주문 ID
     * @return Order 잠긴 주문 모델
     *
     * @throws ModelNotFoundException 주문이 없을 때
     */
    public function findForUpdateOrFail(int $id): Order;

    /**
     * ID로 주문을 배타적 잠금과 함께 조회합니다. (호환 별칭)
     *
     * 1.3.0 에서 `findForUpdate()` 로 이름을 바꿨다. 이 인터페이스를 쓰는 외부 확장이 옛 이름을
     * 부르면 치명 오류가 나므로 별칭을 남긴다.
     *
     * @deprecated 1.3.0 `findForUpdate()` 를 쓴다.
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
     * 관리자 주문 목록과 같은 권한 범위·필터로 주문 수를 셉니다. (목록과 같은 출발 쿼리·필터 단일 출처를 그대로 쓴다)
     *
     * 대시보드 「오늘」 배지처럼 「이 숫자 = 그 조건으로 연 목록의 총 건수」여야 하는 값이 쓴다.
     * 목록처럼 현재 인증 사용자의 주문 조회 권한 범위(`orders.read` 본인·역할 범위)를 건다 — 전체 권한이면 조건 없음.
     *
     * @param  array  $filters  목록 필터 조건 (OrderListRequest 검증값과 같은 키)
     * @return int 조건에 맞는 주문 수
     */
    public function countWithListFilters(array $filters): int;

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
     * 쓰기만 한다 — 전이 훅(`order_option.after_sync_status`)은 호출한 Service 가 발행한다.
     * 전/후 내역은 갱신 **전에** `collectOptionStatusSyncChanges()` 로 읽어 둔다.
     *
     * @param  array  $ids  주문 ID 배열
     * @param  string  $status  변경할 상태값
     * @return int 변경된 옵션 개수
     */
    public function bulkUpdateOptionStatus(array $ids, string $status): int;

    /**
     * 주문 옵션 상태 일괄 변경이 옮길 상품의 전/후 내역을 읽습니다. (쓰기 없음)
     *
     * `bulkUpdateOptionStatus()` 와 같은 대상 조건(동기화 제외 상태 빼기)으로 읽고, 이미 목표
     * 상태인 행은 뺀다. 반드시 갱신 **전에** 부른다.
     *
     * @param  array<int, int>  $ids  주문 ID 배열
     * @param  string  $status  변경할 상태값
     * @return array<int, array{order_option_id: int, order_id: int, previous: string, new: string}> 상품별 전이 내역
     */
    public function collectOptionStatusSyncChanges(array $ids, string $status): array;

    /**
     * 주문에 딸린 클레임 신청 상품 행(교환·반품·취소·환불)을 모두 삭제합니다.
     *
     * 주문 삭제 경로 전용이다. 신청서 헤더를 지우기 **전에** 부른다 — 상품 행은 헤더 FK CASCADE 로도
     * 지워지지만 CASCADE 에 기대지 않는다(외래키가 꺼진 환경에서 헤더 없는 상품 행이 남는다).
     *
     * @param  int  $orderId  주문 ID
     * @return int 삭제된 행 수
     */
    public function deleteClaimItemRows(int $orderId): int;

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
     *
     * @return void 반환값 없음
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
     * 기한이 지난 미결제 주문 조회
     *
     * 두 부류를 함께 조회합니다.
     *  - vbank/dbank 결제의 입금 기한이 지난 결제대기 주문 (진행 중인 환불신청서가 있는 주문은 제외 — 취소가 이미 접수됐다)
     *  - 결제창까지 갔으나 결제가 성립하지 않은 주문대기 주문 (PG 카드 등, 경과 시간 기준)
     *
     * 후자는 입금 기한이라는 개념이 없어 어떤 정리 주체도 없이 남던 부류다.
     * `$pendingOrderExpireMinutes` 가 null 이거나 0 이하면 후자는 조회하지 않는다.
     *
     * @param  int  $limit  최대 조회 개수
     * @param  int|null  $pendingOrderExpireMinutes  주문대기 주문의 만료 기준(분)
     * @return Collection 기한이 지난 미결제 주문 컬렉션
     */
    public function getExpiredPendingPaymentOrders(int $limit = 100, ?int $pendingOrderExpireMinutes = null): Collection;

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

    /**
     * 주문에 그 결제 통화가 기록돼 있는지 확인합니다. (환불·취소 목록 통화 필터 어휘의 마지막 근거)
     *
     * @param  string  $code  통화 코드
     * @return bool 기록 존재 여부
     */
    public function hasRecordedCurrency(string $code): bool;

    /**
     * 주문의 발급 완료 세금계산서를 조회합니다. (취소 뒤 누락 경고용 — P15)
     *
     * @param  int  $orderId  주문 ID
     * @return Collection<int, OrderTaxInvoice> 발급 완료 계산서 (id · invoice_number · total_amount)
     */
    public function listIssuedTaxInvoices(int $orderId): Collection;
}
