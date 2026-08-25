<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;

/**
 * 주문 환불 리포지토리 인터페이스
 *
 * 주문 환불 이력의 데이터 접근을 위한 인터페이스입니다.
 */
interface OrderRefundRepositoryInterface
{
    /**
     * 주문 환불 이력을 생성합니다.
     *
     * @param  array  $data  환불 이력 데이터
     * @return OrderRefund 생성된 환불 이력
     */
    public function create(array $data): OrderRefund;

    /**
     * ID로 환불신청서를 조회합니다.
     *
     * @param  int  $id  환불신청서 ID
     * @param  array  $relations  로드할 관계
     * @return OrderRefund|null 환불신청서 (없으면 null)
     */
    public function findById(int $id, array $relations = []): ?OrderRefund;

    /**
     * 주문의 진행 중(비터미널) 환불신청서를 조회합니다.
     *
     * 취소 가능 금액 산정(D19)의 선점액 축이 쓰는 조회다 — 아직 실행되지 않은 환불이
     * 잡아 둔 금액을 한도에서 빼기 위한 것이므로, 완료·반려·철회된 건은 제외한다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<int>  $excludeIds  집계에서 제외할 환불신청서 ID (실행 대상 자기 자신)
     * @return Collection 진행 중 환불신청서 목록
     */
    public function listPendingByOrderId(int $orderId, array $excludeIds = []): Collection;

    /**
     * 발생 출처(polymorphic 축)로 환불신청서 목록을 조회합니다.
     *
     * 교환 신청서는 반품과 달리 `refund_id` 역참조 컬럼이 없어, "이 교환에서 어떤 환불신청서가
     * 접수됐는가" 는 이 축으로만 되짚는다. 교환은 완료 라운드마다 그 라운드의 차액으로
     * 환불신청서를 접수할 수 있으므로 출처당 여러 건이 있을 수 있다(반려·철회된 건 포함).
     *
     * @param  string  $sourceType  발생 출처 유형 (RefundSourceTypeEnum 값)
     * @param  int  $sourceId  발생 출처 ID
     * @param  array<string>  $relations  함께 로드할 관계 (예: refundOptions)
     * @return Collection<int, OrderRefund> 환불신청서 목록 (ID 오름차순)
     */
    public function listBySource(string $sourceType, int $sourceId, array $relations = []): Collection;

    /**
     * 환불번호로 환불신청서를 조회합니다.
     *
     * @param  string  $refundNumber  환불번호
     * @param  array  $relations  함께 로드할 관계
     * @return OrderRefund|null 환불신청서
     */
    public function findByNumber(string $refundNumber, array $relations = []): ?OrderRefund;

    /**
     * 주문의 환불신청서 목록을 조회합니다. (최신순)
     *
     * @param  int  $orderId  주문 ID
     * @param  array  $relations  함께 로드할 관계
     * @return Collection<int, OrderRefund> 환불신청서 목록
     */
    public function listByOrderId(int $orderId, array $relations = []): Collection;

    /**
     * 해당 주문옵션에 살아 있는 직접 환불신청서(출처 direct)가 잡아 둔 수량을 반환합니다. (D15 환불 축)
     *
     * 취소·반품·교환 발 환불신청서는 원 주문옵션이 이미 취소·코스 상태라 세지 않는다 — 직접 환불신청서만
     * 원 주문옵션을 판매 상태 그대로 둔 채 수량을 선점한다.
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @param  int|null  $excludeRefundId  집계에서 뺄 환불신청서 ID (수정 중인 자기 자신)
     * @return int 선점 수량
     */
    public function sumActiveDirectQuantityByOrderOption(int $orderOptionId, ?int $excludeRefundId = null): int;

    /**
     * 회원의 환불신청서를 페이지네이션 조회합니다. (취소 발 환불은 제외 — 취소 탭이 담당)
     *
     * @param  int  $userId  회원 ID
     * @param  array  $filters  필터 (status / from / to)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginateForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 관리자 목록을 페이지네이션 조회합니다. (최신순, 취소 발 환불 제외 — 취소 서브탭이 담당)
     *
     * @param  array  $filters  필터 (statuses / from / to / fault_types / reason_code / requester_type / search_field / search_keyword)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 관리자 목록 필터 기준 건수를 셉니다. (서브탭 뱃지, 취소 발 환불 제외)
     *
     * @param  array  $filters  필터 (paginateForAdmin 과 동일 계약)
     * @return int 건수
     */
    public function countForAdmin(array $filters = []): int;

    /**
     * 환불신청서를 수정합니다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  array  $data  수정 데이터
     * @return OrderRefund 수정된 환불신청서
     */
    public function update(OrderRefund $refund, array $data): OrderRefund;

    /**
     * 환불신청서를 잠금 조회합니다.
     *
     * @param  int  $id  환불신청서 ID
     * @return OrderRefund|null 잠금된 환불신청서
     */
    public function findForUpdate(int $id): ?OrderRefund;

    /**
     * 사유 코드로 환불신청서 수를 셉니다.
     *
     * @param  string  $reasonCode  사유 코드
     * @return int 신청서 수
     */
    public function countByReasonCode(string $reasonCode): int;
}
