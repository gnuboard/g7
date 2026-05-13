<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;

/**
 * 주문 옵션 리포지토리 인터페이스
 *
 * 주문 옵션의 데이터 접근을 위한 인터페이스입니다.
 */
interface OrderOptionRepositoryInterface
{
    /**
     * ID로 주문 옵션을 조회합니다.
     *
     * @param  int  $id  주문 옵션 ID
     * @return OrderOption 조회된 주문 옵션
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 조회 실패 시
     */
    public function findOrFail(int $id): OrderOption;

    /**
     * 주문 옵션을 업데이트합니다.
     *
     * @param  OrderOption  $option  대상 옵션
     * @param  array  $data  업데이트 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(OrderOption $option, array $data): bool;

    /**
     * 주문 옵션을 저장합니다.
     *
     * @param  OrderOption  $option  대상 옵션
     * @return bool 저장 성공 여부
     */
    public function save(OrderOption $option): bool;

    /**
     * 상품 ID로 주문 옵션 개수를 조회합니다.
     *
     * @param  int  $productId  상품 ID
     * @return int 주문 옵션 개수
     */
    public function countByProductId(int $productId): int;

    /**
     * 병합 후보 옵션을 검색합니다.
     *
     * 동일 주문, 동일 상품, 동일 상품옵션, 동일 상태이며
     * 형제 관계(같은 parent_option_id 또는 부모-자식)인 레코드를 찾습니다.
     *
     * @param  OrderOption  $option  기준 옵션
     * @param  OrderStatusEnum  $status  대상 상태
     * @return OrderOption|null 병합 후보 (없으면 null)
     */
    public function findMergeCandidate(OrderOption $option, OrderStatusEnum $status): ?OrderOption;

    /**
     * 주문 옵션을 삭제합니다.
     *
     * @param  OrderOption  $option  삭제 대상
     * @return bool 삭제 성공 여부
     */
    public function delete(OrderOption $option): bool;

    /**
     * ID 목록으로 주문 옵션을 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  주문 옵션 ID 목록
     * @return \Illuminate\Database\Eloquent\Collection<int, OrderOption> id => OrderOption 매핑
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection;
}
