<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\Coupon;

/**
 * 쿠폰 Repository 인터페이스
 */
interface CouponRepositoryInterface
{
    /**
     * 쿠폰 목록 조회 (페이지네이션)
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 항목 수
     * @param array $with Eager loading 관계
     * @return LengthAwarePaginator
     */
    public function paginate(array $filters = [], int $perPage = 10, array $with = []): LengthAwarePaginator;

    /**
     * ID로 쿠폰 조회
     *
     * @param int $id 쿠폰 ID
     * @param array $with Eager loading 관계
     * @return Coupon|null
     */
    public function findById(int $id, array $with = []): ?Coupon;

    /**
     * 쿠폰 생성
     *
     * @param array $data 쿠폰 데이터
     * @return Coupon
     */
    public function create(array $data): Coupon;

    /**
     * 쿠폰 수정
     *
     * @param int $id 쿠폰 ID
     * @param array $data 수정 데이터
     * @return Coupon
     */
    public function update(int $id, array $data): Coupon;

    /**
     * 쿠폰 삭제
     *
     * @param int $id 쿠폰 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 일괄 발급상태 변경
     *
     * @param array $ids 쿠폰 ID 배열
     * @param string $issueStatus 발급상태
     * @return int 변경된 레코드 수
     */
    public function bulkUpdateIssueStatus(array $ids, string $issueStatus): int;

    /**
     * 발급 내역 조회 (페이지네이션)
     *
     * @param int $couponId 쿠폰 ID
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 항목 수
     * @return LengthAwarePaginator
     */
    public function getIssues(int $couponId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    /**
     * 발급 수량 증가
     *
     * @param int $couponId 쿠폰 ID
     * @param int $count 증가 수량
     * @return void
     */
    public function incrementIssuedCount(int $couponId, int $count = 1): void;

    /**
     * 적용 상품 동기화
     *
     * @param Coupon $coupon 쿠폰
     * @param array $products 상품 데이터 [['id' => 1, 'type' => 'include'], ...]
     * @return void
     */
    public function syncProducts(Coupon $coupon, array $products): void;

    /**
     * 적용 카테고리 동기화
     *
     * @param Coupon $coupon 쿠폰
     * @param array $categories 카테고리 데이터 [['id' => 1, 'type' => 'include'], ...]
     * @return void
     */
    public function syncCategories(Coupon $coupon, array $categories): void;

    /**
     * 다운로드 가능한 쿠폰 목록 조회
     *
     * issue_method=download, issue_condition=manual, issue_status=issuing,
     * 발급기간 내, 수량 남음, valid_to 미만료 조건으로 필터링합니다.
     *
     * @param int|null $perPage 페이지당 항목 수 (null이면 전체 조회)
     * @return LengthAwarePaginator|Collection
     */
    public function getDownloadableCoupons(?int $perPage = null): LengthAwarePaginator|Collection;

    /**
     * 잠금과 함께 쿠폰 조회 (동시성 처리용)
     *
     * @param int $id 쿠폰 ID
     * @return Coupon|null
     */
    public function findByIdForUpdate(int $id): ?Coupon;

    /**
     * ID 목록으로 쿠폰을 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  쿠폰 ID 목록
     * @return \Illuminate\Database\Eloquent\Collection<int, Coupon>
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection;
}
