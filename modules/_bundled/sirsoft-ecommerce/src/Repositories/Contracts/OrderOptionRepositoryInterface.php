<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * @throws ModelNotFoundException 조회 실패 시
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
     * 상품 옵션 ID 배열에 연결된 주문 옵션이 하나라도 존재하는지 확인합니다.
     *
     * 옵션 삭제 시 주문 이력 존재 여부 검증에 사용됩니다.
     *
     * @param  array  $productOptionIds  상품 옵션 ID 배열
     * @return bool 주문 이력 존재 여부
     */
    public function existsByProductOptionIds(array $productOptionIds): bool;

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
     * @return Collection<int, OrderOption> id => OrderOption 매핑
     */
    public function findByIdsKeyed(array $ids): Collection;

    /**
     * ID 목록으로 주문 옵션 스냅샷(배열)을 조회하고 ID 키 맵으로 반환합니다 (ChangeDetector용).
     *
     * @param  array<int, int>  $ids  주문 옵션 ID 목록
     * @return array<int, array> id => 주문 옵션 속성 배열 매핑
     */
    public function getSnapshotsByIds(array $ids): array;

    /**
     * 주문 옵션 ID 목록으로 고유한 주문 ID 목록을 조회합니다.
     *
     * @param  array<int, int>  $optionIds  주문 옵션 ID 목록
     * @return array<int, int> 고유 주문 ID 목록
     */
    public function getOrderIdsByOptionIds(array $optionIds): array;

    /**
     * 피흡수 옵션의 분할 자식 옵션들을 생존 옵션으로 이전합니다.
     *
     * @param  int  $fromParentId  기존 부모 옵션 ID
     * @param  int  $toParentId  이전 대상 부모 옵션 ID
     * @return int 업데이트된 레코드 수
     */
    public function transferChildren(int $fromParentId, int $toParentId): int;

    /**
     * 취소(복원)된 옵션의 재고 차감 플래그를 해제합니다.
     *
     * 주문 취소 재고 복원 후, 해당 주문·상품옵션의 CANCELLED 상태 행 중
     * is_stock_deducted=true 인 행을 false 로 정리합니다 (잔여 미취소 행은 유지).
     *
     * @param  int  $orderId  주문 ID
     * @param  int  $productOptionId  상품 옵션 ID
     * @return int 업데이트된 레코드 수
     */
    public function clearStockDeductedForCancelledOptions(int $orderId, int $productOptionId): int;

    /**
     * 특정 주문일의 상품 순매출을 합산합니다 (대시보드 집계).
     *
     * 매출 반영 상태(option_status)의 주문상품만 대상으로, 옵션별
     * unit_price × (quantity − cancelled_quantity) 를 합산합니다.
     * 날짜는 주문(orders.ordered_at) 기준으로 귀속합니다.
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return float 상품 순매출 합계
     */
    public function sumNetSalesOnDate(string $date): float;

    /**
     * 특정 주문일의 판매 수량을 합산합니다 (대시보드 집계).
     *
     * 매출 반영 상태(option_status)의 주문상품만 대상으로,
     * (quantity − cancelled_quantity) 를 합산합니다.
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return int 판매 수량 합계
     */
    public function sumNetQuantityOnDate(string $date): int;

    /**
     * 특정 주문일의 옵션 상태별 판매 수량을 집계합니다 (대시보드 배지).
     *
     * 당일 주문(orders.ordered_at)의 모든 주문상품을 option_status 로
     * 그룹화하여 상태별 수량(quantity 합)을 반환합니다.
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return array<string, int> option_status 값 => 수량 합 매핑
     */
    public function countByOptionStatusOnDate(string $date): array;
}
