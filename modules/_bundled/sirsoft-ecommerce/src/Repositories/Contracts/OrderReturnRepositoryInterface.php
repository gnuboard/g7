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
     * 주문의 진행 중(비터미널)이면서 환불이 아직 연결되지 않은 상품 행이 남은 반품 신청서를 조회합니다.
     *
     * 취소 가능 금액 산정(D19)의 선점액 축이 쓰는 조회다. 환불신청서는 완료 회차마다 상품 행에
     * 연결되므로(S7), 연결된 행의 몫은 그 환불신청서 쪽에서 이미 선점액으로 잡힌다 — 여기서 또 세면
     * 두 배가 된다. 호출부는 연결되지 않은 행만 재계산한다.
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
     * 특정 배송사를 회수 택배사로 지정한 반품 신청 건수를 셉니다.
     *
     * 배송사를 삭제해도 되는지 판단할 때 사용합니다. 반품 신청서는 배송사를 `order_shippings`
     * 가 아니라 자기 **상품 행**의 `collection_carrier_id` 로 붙잡으므로(R12), 일반 배송 건수만 세면
     * **회수 전용으로만 쓰인 배송사가 "안 쓰임" 으로 판정된다.** 그 외래키는 `nullOnDelete`
     * 라 그 상태로 삭제가 통과하면 접수된 신청서의 택배사가 오류도 로그도 없이 비워진다.
     *
     * @param  int  $carrierId  배송사 ID
     * @return int 해당 배송사를 지정한 반품 신청 건수
     */
    public function countByCarrierId(int $carrierId): int;

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
     * @param  array<string, mixed>  $filters  목록과 같은 필터 (status / from / to)
     * @return int 건수
     */
    public function countForOrder(int $orderId, array $filters = []): int;

    /**
     * 이 회원이 접수한 반품 건수를 셉니다. (신청내역 서브탭 뱃지 — F12)
     *
     * @param  int  $userId  회원 ID
     * @param  array<string, mixed>  $filters  목록과 같은 필터 (status / from / to)
     * @return int 건수
     */
    public function countForUser(int $userId, array $filters = []): int;

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
     * 지정한 신청서 중 열람자의 권한 스코프 안에 있는 건수를 셉니다. (주문 스코프 상속 — 일괄 액션 가드)
     *
     * @param  array<int, int>  $ids  신청서 ID 목록
     * @param  string|null  $permission  스코프를 판정할 권한 식별자 (null 이면 스코프 없이 존재 건수만)
     * @return int 스코프 안의 건수
     */
    public function countWithinAdminScope(array $ids, ?string $permission): int;

    /**
     * 여러 주문옵션에 활성 반품 신청이 잡아 둔 수량을 한 번에 합산합니다. (목록 N+1 방지)
     *
     * `sumActiveQuantityByOrderOption()` 의 일괄판이다 — 같은 조건을 한 쿼리로 묶는다.
     *
     * @param  array<int, int>  $orderOptionIds  주문옵션 ID 목록
     * @return array<int, int> 주문옵션 ID => 점유 수량 (점유가 없으면 키가 없다)
     */
    public function sumActiveQuantitiesByOrderOptions(array $orderOptionIds): array;

    /**
     * 수정 중인 반품 신청서가 이 주문옵션을 코스 상태로 옮기기 전의 원 상태를 반환합니다.
     *
     * 승인 전 수정 화면의 미리보기에서 상태 가드가 자기 신청서의 코스 상태에 막히지 않도록
     * 쓴다 — 활성(비터미널) 상품 행이 박제한 `original_option_status` 를 돌려준다.
     *
     * @param  int  $returnId  반품 신청서 ID
     * @param  int  $orderOptionId  주문옵션 ID
     * @return string|null 원 상태 값 (이 신청서의 활성 상품 행이 없으면 null)
     */
    public function findOriginalOptionStatus(int $returnId, int $orderOptionId): ?string;

    /**
     * 반품 신청서에 그 통화가 기록돼 있는지 확인합니다. (관리자 목록 통화 필터 어휘의 마지막 근거)
     *
     * @param  string  $code  통화 코드
     * @return bool 기록 존재 여부
     */
    public function hasRecordedCurrency(string $code): bool;
}
