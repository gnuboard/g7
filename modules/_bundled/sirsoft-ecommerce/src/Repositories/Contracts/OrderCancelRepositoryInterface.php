<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;

/**
 * 주문 취소 리포지토리 인터페이스
 *
 * 주문 취소 이력의 데이터 접근을 위한 인터페이스입니다.
 */
interface OrderCancelRepositoryInterface
{
    /**
     * 주문 취소 이력을 생성합니다.
     *
     * @param  array  $data  취소 이력 데이터
     * @return OrderCancel 생성된 취소 이력
     */
    public function create(array $data): OrderCancel;

    /**
     * 주문의 가장 최근 취소 이력을 조회합니다.
     *
     * @param  int  $orderId  주문 ID
     * @return OrderCancel|null 최근 취소 이력 (없으면 null)
     */
    public function latestByOrderId(int $orderId): ?OrderCancel;

    /**
     * 특정 취소 사유 코드를 사용한 취소 이력 건수를 셉니다.
     *
     * 클레임 사유를 삭제해도 되는지 판단할 때 사용합니다 (사용 중이면 삭제 불가).
     *
     * @param  string  $reasonCode  클레임 사유 코드
     * @return int 해당 사유를 사용한 취소 이력 건수
     */
    public function countByCancelReasonType(string $reasonCode): int;

    /**
     * 취소번호로 취소 이력을 조회합니다.
     *
     * @param  string  $cancelNumber  취소번호
     * @param  array  $relations  함께 로드할 관계
     * @return OrderCancel|null 취소 이력
     */
    public function findByNumber(string $cancelNumber, array $relations = []): ?OrderCancel;

    /**
     * ID 로 취소 이력을 조회합니다. (관리자 상세 — 번호 해석 뒤 관계를 실어 다시 읽는 자리)
     *
     * @param  int  $id  취소 ID
     * @param  array  $relations  함께 로드할 관계
     * @return OrderCancel|null 취소 이력
     */
    public function findById(int $id, array $relations = []): ?OrderCancel;

    /**
     * 주문의 취소 이력 목록을 조회합니다. (최신순)
     *
     * @param  int  $orderId  주문 ID
     * @param  array  $relations  함께 로드할 관계
     * @return Collection<int, OrderCancel> 취소 이력 목록
     */
    public function listByOrderId(int $orderId, array $relations = []): Collection;

    /**
     * 회원의 취소 이력을 페이지네이션 조회합니다. (최신순)
     *
     * @param  int  $userId  회원 ID
     * @param  array  $filters  필터 (status / from / to)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginateForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 한 주문의 신청서를 페이지네이션 조회합니다. (최신순 — 비회원 주문상세 '이 주문의 클레임' 섹션)
     *
     * 비회원에게는 주문을 가로지르는 신청내역 목록이 없다(토큰이 주문 하나에 묶인다). 그래서 이
     * 조회가 비회원의 목록이며, 회원 목록(`paginateForUser`)과 같은 관계·컬럼·정렬을 쓴다 —
     * 한쪽만 다르면 같은 카드가 회원/비회원에서 다른 필드를 그린다.
     *
     * 상한 없는 조회(`listByOrderId`)를 목록에 쓰지 않는 이유는 건수가 주문의 상품 수에 묶이지
     * 않기 때문이다 — 철회는 행을 남기므로 같은 주문에서 신청↔철회를 반복하면 계속 쌓인다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array  $filters  필터 (status / from / to)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginateForOrder(int $orderId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 이 주문에서 접수된 이 유형의 건수를 셉니다. (주문상세 섹션의 서브탭 뱃지·기본 유형 판정)
     *
     * `paginateForOrder()` 와 같은 기준 쿼리를 세므로 유형 고유 스코프가 목록과 같은 근거로 걸린다.
     *
     * @param  int  $orderId  주문 ID
     * @return int 건수
     */
    public function countForOrder(int $orderId): int;

    /**
     * 관리자 목록을 페이지네이션 조회합니다. (최신순, 전 회원·비회원 대상)
     *
     * @param  array  $filters  필터 (statuses / from / to / reason_code / requester_type / search_field / search_keyword)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 관리자 목록 필터 기준 건수를 셉니다. (서브탭 뱃지)
     *
     * @param  array  $filters  필터 (paginateForAdmin 과 동일 계약)
     * @return int 건수
     */
    public function countForAdmin(array $filters = []): int;
}
