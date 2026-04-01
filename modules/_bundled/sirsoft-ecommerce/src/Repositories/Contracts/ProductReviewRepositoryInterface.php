<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;

/**
 * 상품 리뷰 Repository 인터페이스
 */
interface ProductReviewRepositoryInterface
{
    /**
     * ID로 리뷰 조회
     *
     * @param  int  $id  리뷰 ID
     * @return ProductReview|null
     */
    public function findById(int $id): ?ProductReview;

    /**
     * 관리자용 필터링된 리뷰 목록 조회 (페이지네이션)
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 상품별 리뷰 목록 조회 (공개용, 페이지네이션)
     *
     * @param  int  $productId  상품 ID
     * @param  array  $filters  필터 조건 (sort, photo_only)
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator
     */
    public function findByProduct(int $productId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    /**
     * 상품별 별점 통계 조회
     *
     * @param  int  $productId  상품 ID
     * @return array 별점별 개수 및 비율
     */
    public function getRatingStats(int $productId): array;

    /**
     * 상품별 전체 리뷰 수 조회 (필터 무관)
     *
     * @param  int  $productId  상품 ID
     * @return int
     */
    public function getTotalCount(int $productId): int;

    /**
     * 상품 옵션 전체 기준 옵션 필터 목록 조회 (키별 고유값 + 리뷰 건수)
     *
     * product_options에서 모든 옵션값을 집계하고, 각 값에 해당하는 리뷰 건수를 포함합니다.
     *
     * @param  int  $productId  상품 ID
     * @return array [['key' => '색상', 'values' => [['value' => '블랙', 'count' => 12], ...]], ...]
     */
    public function getOptionFilters(int $productId): array;

    /**
     * 주문 옵션 ID로 리뷰 조회 (중복 작성 방지)
     *
     * @param  int  $orderOptionId  주문 옵션 ID
     * @return ProductReview|null
     */
    public function findByOrderOptionId(int $orderOptionId): ?ProductReview;

    /**
     * 리뷰 생성
     *
     * @param  array  $data  리뷰 데이터
     * @return ProductReview
     */
    public function create(array $data): ProductReview;

    /**
     * 리뷰 수정
     *
     * @param  ProductReview  $review  리뷰 모델
     * @param  array  $data  수정 데이터
     * @return ProductReview
     */
    public function update(ProductReview $review, array $data): ProductReview;

    /**
     * 리뷰 삭제 (소프트 삭제)
     *
     * @param  ProductReview  $review  리뷰 모델
     * @return bool
     */
    public function delete(ProductReview $review): bool;

    /**
     * 리뷰 일괄 상태 변경
     *
     * @param  array  $ids  리뷰 ID 배열
     * @param  string  $status  변경할 상태값
     * @return int 변경된 건수
     */
    public function bulkUpdateStatus(array $ids, string $status): int;

    /**
     * 리뷰 이미지를 포함한 일괄 조회 (일괄 삭제용)
     *
     * @param  array  $ids  리뷰 ID 배열
     * @return Collection
     */
    public function getByIdsWithImages(array $ids): Collection;
}
