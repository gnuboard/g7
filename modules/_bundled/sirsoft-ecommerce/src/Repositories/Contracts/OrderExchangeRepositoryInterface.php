<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;

/**
 * 교환 신청서 리포지토리 인터페이스
 */
interface OrderExchangeRepositoryInterface
{
    /**
     * 교환 신청서를 생성합니다.
     *
     * @param  array  $data  신청서 데이터
     * @return OrderExchange 생성된 교환 신청서
     */
    public function create(array $data): OrderExchange;

    /**
     * 교환 신청서를 수정합니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  array  $data  수정 데이터
     * @return OrderExchange 수정된 교환 신청서
     */
    public function update(OrderExchange $exchange, array $data): OrderExchange;

    /**
     * ID로 교환 신청서를 조회합니다.
     *
     * @param  int  $id  교환 신청서 ID
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return OrderExchange|null 교환 신청서 (없으면 null)
     */
    public function findById(int $id, array $relations = []): ?OrderExchange;

    /**
     * 교환번호로 교환 신청서를 조회합니다.
     *
     * @param  string  $exchangeNumber  교환번호
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return OrderExchange|null 교환 신청서 (없으면 null)
     */
    public function findByNumber(string $exchangeNumber, array $relations = []): ?OrderExchange;

    /**
     * 주문의 교환 신청서 목록을 조회합니다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return Collection 교환 신청서 목록
     */
    public function listByOrderId(int $orderId, array $relations = []): Collection;

    /**
     * 주문의 진행 중(비터미널) 교환 신청서를 조회합니다.
     *
     * 취소 가능 금액 산정(D19)의 선점액 축이 쓰는 조회다 — 교환에서 선점되는 것은
     * 저가 교환의 차액 환불분(`refund_product_amount`)뿐이며, 청구액은 받는 돈이라
     * 선점 대상이 아니다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<int>  $excludeIds  집계에서 제외할 교환 신청서 ID (실행 대상 자기 자신)
     * @return Collection 진행 중 교환 신청서 목록
     */
    public function listPendingByOrderId(int $orderId, array $excludeIds = []): Collection;

    /**
     * 잠금(SELECT ... FOR UPDATE)과 함께 교환 신청서를 조회합니다.
     *
     * 상태 전이·수량 검증처럼 동시 요청이 겹치면 안 되는 경로에서 사용합니다.
     *
     * @param  int  $id  교환 신청서 ID
     * @return OrderExchange|null 교환 신청서 (없으면 null)
     */
    public function findForUpdate(int $id): ?OrderExchange;

    /**
     * 주문옵션에 걸린 진행 중(비터미널) 교환 신청 수량 합계를 반환합니다.
     *
     * 이미 클레임에 묶인 수량을 다시 신청하는 것을 막는 수량 잠금의 근거다.
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @param  int|null  $excludeExchangeId  집계에서 제외할 교환 신청서 ID (수정 시 자기 자신)
     * @return int 진행 중 신청 수량 합계
     */
    public function sumActiveQuantityByOrderOption(int $orderOptionId, ?int $excludeExchangeId = null): int;

    /**
     * 특정 사유 코드를 사용한 교환 신청 건수를 셉니다.
     *
     * 클레임 사유를 삭제해도 되는지 판단할 때 사용합니다 (사용 중이면 삭제 불가).
     *
     * @param  string  $reasonCode  클레임 사유 코드
     * @return int 해당 사유를 사용한 신청 건수
     */
    public function countByReasonCode(string $reasonCode): int;
}
