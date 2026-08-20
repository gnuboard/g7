<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

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
}
