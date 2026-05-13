<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;

/**
 * 상품 1:1 문의 Repository 인터페이스
 */
interface ProductInquiryRepositoryInterface
{
    /**
     * ID로 문의 조회
     *
     * @param  int  $id  문의 ID
     * @return ProductInquiry|null
     */
    public function findById(int $id): ?ProductInquiry;

    /**
     * 상품 ID로 문의 피벗 목록 조회
     *
     * @param  int  $productId  상품 ID
     * @return Collection
     */
    public function findByProductId(int $productId): Collection;

    /**
     * 상품 ID로 문의 피벗 목록 조회 (페이지네이션)
     *
     * @param  int  $productId  상품 ID
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator
     */
    public function paginateByProductId(int $productId, int $perPage = 10): LengthAwarePaginator;

    /**
     * inquirable_id로 문의 조회 (단일)
     *
     * @param  string  $inquirableType  다형성 타입
     * @param  int  $inquirableId  다형성 ID
     * @return ProductInquiry|null
     */
    public function findByInquirable(string $inquirableType, int $inquirableId): ?ProductInquiry;

    /**
     * 사용자 ID로 문의 목록 조회 (마이페이지)
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator
     */
    public function findByUserId(int $userId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    /**
     * 관리자용 필터링된 문의 목록 조회 (페이지네이션)
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 문의 생성
     *
     * @param  array  $data  문의 데이터
     * @return ProductInquiry
     */
    public function create(array $data): ProductInquiry;

    /**
     * 문의 답변 상태 업데이트 (is_answered, answered_at)
     *
     * @param  ProductInquiry  $inquiry  문의 모델
     * @return ProductInquiry
     */
    public function markAsAnswered(ProductInquiry $inquiry): ProductInquiry;

    /**
     * 문의 답변 미완료 상태로 되돌리기 (is_answered=false, answered_at=null)
     *
     * @param  ProductInquiry  $inquiry  문의 모델
     * @return ProductInquiry
     */
    public function unmarkAnswered(ProductInquiry $inquiry): ProductInquiry;

    /**
     * ID로 문의 피벗 삭제
     *
     * @param  int  $id  문의 ID
     * @return bool
     */
    public function deleteById(int $id): bool;

    /**
     * inquirable_id 목록으로 문의 삭제
     *
     * @param  string  $inquirableType  다형성 타입
     * @param  array  $inquirableIds  다형성 ID 배열
     * @return int 삭제된 건수
     */
    public function deleteByInquirableIds(string $inquirableType, array $inquirableIds): int;
}
