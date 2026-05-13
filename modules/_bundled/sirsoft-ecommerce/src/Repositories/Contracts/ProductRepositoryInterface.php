<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 Repository 인터페이스
 */
interface ProductRepositoryInterface
{
    /**
     * ID로 상품 조회 (옵션 포함)
     *
     * @param  int  $id  상품 ID
     * @param  bool  $includeInactive  비활성 옵션 포함 여부
     * @return Product|null
     */
    public function findWithOptions(int $id, bool $includeInactive = false): ?Product;

    /**
     * 필터링된 상품 목록 조회 (페이지네이션)
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 상품 생성
     *
     * @param  array  $data  상품 데이터
     * @return Product
     */
    public function create(array $data): Product;

    /**
     * 상품 수정
     *
     * @param  Product  $product  상품 모델
     * @param  array  $data  수정 데이터
     * @return Product
     */
    public function update(Product $product, array $data): Product;

    /**
     * 상품 삭제 (소프트 삭제)
     *
     * @param  Product  $product  상품 모델
     * @return bool
     */
    public function delete(Product $product): bool;

    /**
     * 상품 일괄 상태 변경
     *
     * @param  array  $ids  상품 ID 배열
     * @param  string  $field  필드명 (sales_status, display_status)
     * @param  string  $value  변경 값
     * @return int  변경된 개수
     */
    public function bulkUpdateStatus(array $ids, string $field, string $value): int;

    /**
     * 상품 일괄 가격 변경
     *
     * @param  array  $ids  상품 ID 배열
     * @param  string  $method  변경 방식 (increase, decrease, set)
     * @param  int  $value  변경 값
     * @param  string  $unit  단위 (won, percent)
     * @return int  변경된 개수
     */
    public function bulkUpdatePrice(array $ids, string $method, int $value, string $unit): int;

    /**
     * 상품 일괄 재고 변경
     *
     * @param  array  $ids  상품 ID 배열
     * @param  string  $method  변경 방식 (increase, decrease, set)
     * @param  int  $value  변경 값
     * @return int  변경된 개수
     */
    public function bulkUpdateStock(array $ids, string $method, int $value): int;

    /**
     * 상품 코드 중복 확인
     *
     * @param string $productCode 상품 코드
     * @param int|null $excludeId 제외할 상품 ID
     * @return bool
     */
    public function existsByProductCode(string $productCode, ?int $excludeId = null): bool;

    /**
     * 상품 통계 조회
     *
     * @return array 상품 통계 데이터
     */
    public function getStatistics(): array;

    /**
     * 상품 코드로 상품 조회
     *
     * @param string $productCode 상품 코드
     * @return Product|null
     */
    public function findByProductCode(string $productCode): ?Product;

    /**
     * 모든 관계를 포함하여 상품 조회 (폼 상세용)
     *
     * @param int $id 상품 ID
     * @return Product|null
     */
    public function findWithAllRelations(int $id): ?Product;

    /**
     * ID로 상품 조회
     *
     * @param int $id 상품 ID
     * @return Product|null
     */
    public function find(int $id): ?Product;

    /**
     * 상품 다중 필드 일괄 업데이트
     *
     * @param array $ids 상품 ID 배열
     * @param array $fields 업데이트할 필드와 값
     * @return int 업데이트된 개수
     */
    public function bulkUpdateFields(array $ids, array $fields): int;

    /**
     * 공개 상품 목록 조회 (판매순 정렬 포함)
     *
     * 전시상태 visible, 판매상태 on_sale/coming_soon 필터를 적용하고
     * sales 정렬 시 OrderOption 판매량 집계 서브쿼리를 사용합니다.
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getPublicList(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 인기 상품 조회 (최근 30일 판매량 기준)
     *
     * @param int $limit 조회 개수
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPopularProducts(int $limit = 10): \Illuminate\Database\Eloquent\Collection;

    /**
     * 신상품 조회 (최신 등록순)
     *
     * @param int $limit 조회 개수
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNewProducts(int $limit = 10): \Illuminate\Database\Eloquent\Collection;

    /**
     * ID 목록으로 상품 조회
     *
     * @param array $ids 상품 ID 배열
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByIds(array $ids): \Illuminate\Database\Eloquent\Collection;

    /**
     * ID 목록으로 상품을 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  상품 ID 목록
     * @return \Illuminate\Database\Eloquent\Collection<int, Product> id => Product 매핑
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection;

    /**
     * 상품의 stock_quantity 컬럼만 갱신합니다 (옵션 재고 합계 동기화 전용).
     *
     * @param  int  $productId  상품 ID
     * @param  int  $stock  새 재고 수량
     * @return int 업데이트된 행 수 (0 또는 1)
     */
    public function updateStockQuantity(int $productId, int $stock): int;

    /**
     * 키워드로 공개 상품을 검색합니다.
     *
     * @param string $keyword 검색 키워드
     * @param string $orderBy 정렬 컬럼
     * @param string $direction 정렬 방향 (asc, desc)
     * @param int|null $categoryId 카테고리 필터 (null이면 전체)
     * @param int $offset 오프셋
     * @param int $limit 조회할 최대 항목 수
     * @return array{total: int, items: \Illuminate\Database\Eloquent\Collection}
     */
    public function searchByKeyword(string $keyword, string $orderBy = 'created_at', string $direction = 'desc', ?int $categoryId = null, int $offset = 0, int $limit = 10): array;

    /**
     * 키워드와 일치하는 공개 상품 수를 조회합니다.
     *
     * @param string $keyword 검색 키워드
     * @param int|null $categoryId 카테고리 필터 (null이면 전체)
     * @return int 일치하는 상품 수
     */
    public function countByKeyword(string $keyword, ?int $categoryId = null): int;

    /**
     * 상품 재고를 옵션 재고 합계와 동기화
     *
     * Product.stock_quantity = SUM(ProductOption.stock_quantity) 규칙 적용
     *
     * @param int $productId 상품 ID
     * @return bool 성공 여부
     */
    public function syncStockFromOptions(int $productId): bool;
}
