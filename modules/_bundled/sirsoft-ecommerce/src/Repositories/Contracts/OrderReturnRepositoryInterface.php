<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 반품 신청서 리포지토리 인터페이스
 */
interface OrderReturnRepositoryInterface
{
    /**
     * 반품 신청서를 생성합니다.
     *
     * @param  array  $data  신청서 데이터
     * @return OrderReturn 생성된 반품 신청서
     */
    public function create(array $data): OrderReturn;

    /**
     * 반품 신청서를 수정합니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  array  $data  수정 데이터
     * @return OrderReturn 수정된 반품 신청서
     */
    public function update(OrderReturn $return, array $data): OrderReturn;

    /**
     * ID로 반품 신청서를 조회합니다.
     *
     * @param  int  $id  반품 신청서 ID
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return OrderReturn|null 반품 신청서 (없으면 null)
     */
    public function findById(int $id, array $relations = []): ?OrderReturn;

    /**
     * 반품번호로 반품 신청서를 조회합니다.
     *
     * @param  string  $returnNumber  반품번호
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return OrderReturn|null 반품 신청서 (없으면 null)
     */
    public function findByNumber(string $returnNumber, array $relations = []): ?OrderReturn;

    /**
     * 주문의 반품 신청서 목록을 조회합니다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return Collection 반품 신청서 목록
     */
    public function listByOrderId(int $orderId, array $relations = []): Collection;

    /**
     * 주문의 진행 중(비터미널)이면서 환불신청서가 아직 없는 반품 신청서를 조회합니다.
     *
     * 취소 가능 금액 산정(D19)의 선점액 축이 쓰는 조회다. 환불신청서가 생긴 반품은
     * 그 환불신청서 쪽에서 이미 선점액으로 잡히므로 여기서 또 세면 두 배가 된다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<int>  $excludeIds  집계에서 제외할 반품 신청서 ID (실행 대상 자기 자신)
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return Collection 선점 대상 반품 신청서 목록
     */
    public function listPendingWithoutRefundByOrderId(
        int $orderId,
        array $excludeIds = [],
        array $relations = [],
    ): Collection;

    /**
     * 잠금(SELECT ... FOR UPDATE)과 함께 반품 신청서를 조회합니다.
     *
     * @param  int  $id  반품 신청서 ID
     * @return OrderReturn|null 반품 신청서 (없으면 null)
     */
    public function findForUpdate(int $id): ?OrderReturn;

    /**
     * 주문옵션에 걸린 진행 중(비터미널) 반품 신청 수량 합계를 반환합니다.
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @param  int|null  $excludeReturnId  집계에서 제외할 반품 신청서 ID (수정 시 자기 자신)
     * @return int 진행 중 신청 수량 합계
     */
    public function sumActiveQuantityByOrderOption(int $orderOptionId, ?int $excludeReturnId = null): int;

    /**
     * 특정 사유 코드를 사용한 반품 신청 건수를 셉니다.
     *
     * @param  string  $reasonCode  클레임 사유 코드
     * @return int 해당 사유를 사용한 신청 건수
     */
    public function countByReasonCode(string $reasonCode): int;

    /**
     * 회원의 신청서를 페이지네이션 조회합니다. (최신순)
     *
     * @param  int  $userId  회원 ID
     * @param  array  $filters  필터 (status / from / to)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginateForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 관리자 목록을 페이지네이션 조회합니다. (최신순, 전 회원·비회원 대상)
     *
     * @param  array  $filters  필터 (statuses / from / to / fault_types / charge_methods / reason_code / requester_type / search_field / search_keyword)
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

    /**
     * 수정 중인 반품 신청서가 이 주문옵션을 코스 상태로 옮기기 전의 원 상태를 반환합니다.
     *
     * 승인 전 수정 화면의 미리보기에서 상태 가드가 자기 신청서의 코스 상태에 막히지 않도록
     * 쓴다 — 활성(비터미널) 상품 행이 박제한 `original_option_status` 를 돌려준다.
     *
     * @param  int  $exchangeId  반품 신청서 ID
     * @param  int  $orderOptionId  주문옵션 ID
     * @return string|null 원 상태 값 (이 신청서의 활성 상품 행이 없으면 null)
     */
    public function findOriginalOptionStatus(int $returnId, int $orderOptionId): ?string;
}
