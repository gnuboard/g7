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
     * @param  int|null  $page  페이지 번호 (null 이면 요청 파라미터에서 해석)
     * @return LengthAwarePaginator
     */
    public function paginateByProductId(int $productId, int $perPage = 10, ?int $page = null): LengthAwarePaginator;

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

    /**
     * 상품 ID 로 피벗 전건 조회 (소프트 삭제 포함)
     *
     * 상품 삭제 시 문의 스레드 정리 경로에서 사용합니다.
     *
     * @param  int  $productId  상품 ID
     * @return Collection<int, ProductInquiry> 피벗 컬렉션
     */
    public function findByProductIdWithTrashed(int $productId): Collection;

    /**
     * 상품 ID 로 피벗 전건 영구 삭제 (소프트 삭제 포함)
     *
     * 상품이 forceDelete 되는 경로 전용 — 그 외 삭제는 소프트 삭제를 유지합니다.
     *
     * @param  int  $productId  상품 ID
     * @return int 삭제된 피벗 수
     */
    public function forceDeleteByProductId(int $productId): int;

    /**
     * 대상(inquirable) 기준으로 소프트 삭제된 피벗을 복원합니다.
     *
     * 게시판에서 질문 글이 삭제→복원될 때 피벗을 함께 되살리는 경로입니다.
     *
     * @param  string  $inquirableType  대상 모델 클래스명
     * @param  int  $inquirableId  대상 ID
     * @return int 복원된 피벗 수
     */
    public function restoreByInquirable(string $inquirableType, int $inquirableId): int;

    /**
     * 전체 상품의 최신 미답변 문의를 조회합니다 (대시보드 미답변 문의).
     *
     * is_answered=false 문의를 작성일 최신순으로 상위 N건 조회하며,
     * 상품/작성자를 eager load 합니다.
     *
     * @param  int  $limit  조회 건수
     * @return Collection 미답변 문의 컬렉션
     */
    public function getPendingRecent(int $limit): Collection;

    /**
     * 전체 미답변 문의 총 건수를 반환합니다 (대시보드 배지).
     *
     * @return int 미답변 문의 총 건수
     */
    public function countPending(): int;
}
