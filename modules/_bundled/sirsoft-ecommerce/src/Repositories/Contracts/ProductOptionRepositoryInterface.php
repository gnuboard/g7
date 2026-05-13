<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 상품 옵션 Repository 인터페이스
 */
interface ProductOptionRepositoryInterface
{
    /**
     * ID로 옵션 조회
     *
     * @param int $id 옵션 ID
     * @return ProductOption|null
     */
    public function findById(int $id): ?ProductOption;

    /**
     * ID로 옵션 조회 (관계 포함)
     *
     * @param int $id 옵션 ID
     * @param array $with Eager loading 관계
     * @return ProductOption|null
     */
    public function findWithRelations(int $id, array $with = []): ?ProductOption;

    /**
     * 상품 ID로 옵션 목록 조회
     *
     * @param int $productId 상품 ID
     * @return Collection
     */
    public function getByProductId(int $productId): Collection;

    /**
     * 옵션 판매가 일괄 변경
     *
     * @param array $optionIds 옵션 ID 배열
     * @param string $method 변경 방식 (increase, decrease, fixed)
     * @param int $value 변경 값
     * @param string $unit 단위 (won, percent)
     * @return int 업데이트된 레코드 수
     */
    public function bulkUpdatePrice(array $optionIds, string $method, int $value, string $unit): int;

    /**
     * 옵션 재고 일괄 변경
     *
     * @param array $optionIds 옵션 ID 배열
     * @param string $method 변경 방식 (increase, decrease, set)
     * @param int $value 변경 값
     * @return int 업데이트된 레코드 수
     */
    public function bulkUpdateStock(array $optionIds, string $method, int $value): int;

    /**
     * 옵션 수정
     *
     * @param int $id 옵션 ID
     * @param array $data 수정 데이터
     * @return ProductOption|null
     */
    public function update(int $id, array $data): ?ProductOption;

    /**
     * 옵션 다중 필드 일괄 업데이트
     *
     * @param array $ids 옵션 ID 배열
     * @param array $fields 업데이트할 필드와 값
     * @return int 업데이트된 개수
     */
    public function bulkUpdateFields(array $ids, array $fields): int;

    /**
     * 배타적 락(FOR UPDATE)과 함께 옵션 조회
     *
     * 재고 차감/복원 시 동시성 문제 방지를 위한 비관적 락
     *
     * @param int $id 옵션 ID
     * @return ProductOption|null
     */
    public function findWithLock(int $id): ?ProductOption;

    /**
     * 재고 원자적 차감
     *
     * @param int $id 옵션 ID
     * @param int $quantity 차감할 수량
     * @return bool 성공 여부
     */
    public function decrementStock(int $id, int $quantity): bool;

    /**
     * 재고 원자적 증가
     *
     * @param int $id 옵션 ID
     * @param int $quantity 증가할 수량
     * @return bool 성공 여부
     */
    public function incrementStock(int $id, int $quantity): bool;

    /**
     * 옵션 ID 배열로 조회
     *
     * @param array $optionIds 옵션 ID 배열
     * @return Collection 옵션 컬렉션
     */
    public function findByIds(array $optionIds): Collection;

    /**
     * 복수 상품 ID로 옵션 ID 배열 조회
     *
     * @param array $productIds 상품 ID 배열
     * @return array 옵션 ID 배열
     */
    public function getIdsByProductIds(array $productIds): array;

    /**
     * 옵션 ID 배열로 상품 정보 포함 조회
     *
     * 재고/판매상태 검증을 위해 상품과 이미지 관계를 함께 로드합니다.
     *
     * @param array $optionIds 옵션 ID 배열
     * @return Collection 옵션 컬렉션 (product, product.images 관계 포함)
     */
    public function findByIdsWithProduct(array $optionIds): Collection;

    /**
     * 옵션 ID 목록으로부터 고유 product_id 목록을 추출합니다.
     *
     * SyncOptionGroupsListener 의 일괄 업데이트 후 동기화 진입점.
     *
     * @param  array<int, int>  $optionIds  옵션 ID 목록
     * @return array<int, int> 고유 product_id 배열
     */
    public function pluckProductIds(array $optionIds): array;

    /**
     * 특정 상품의 활성 옵션 stock 합계를 반환합니다.
     *
     * SyncProductFromOptionListener 가 상품 재고 동기화에 사용.
     *
     * @param  int  $productId  상품 ID
     * @return int 합계 stock
     */
    public function sumStockByProduct(int $productId): int;

    /**
     * ID 목록으로 옵션을 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  옵션 ID 목록
     * @return \Illuminate\Database\Eloquent\Collection<int, ProductOption>
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection;

    /**
     * 특정 상품의 옵션 stock 합계를 반환하되, 지정한 옵션 ID 들은 제외합니다.
     *
     * SyncProductFromOptionListener 가 스냅샷 외 옵션 합산에 사용.
     *
     * @param  int  $productId  상품 ID
     * @param  array<int, int>  $excludedOptionIds  제외할 옵션 ID 목록
     * @return int 합계 stock
     */
    public function sumStockByProductExcluding(int $productId, array $excludedOptionIds): int;
}
