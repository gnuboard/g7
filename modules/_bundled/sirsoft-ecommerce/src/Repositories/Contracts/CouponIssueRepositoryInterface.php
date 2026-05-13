<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Models\CouponIssue;

/**
 * 쿠폰 발급 Repository 인터페이스
 */
interface CouponIssueRepositoryInterface
{
    /**
     * ID로 쿠폰 발급 조회
     *
     * @param  int  $id  쿠폰 발급 ID
     */
    public function findById(int $id): ?CouponIssue;

    /**
     * ID 목록으로 쿠폰 발급 조회 (관계 포함)
     *
     * @param  array  $ids  쿠폰 발급 ID 배열
     * @param  array  $with  Eager loading 관계
     * @return array CouponIssue 배열
     */
    public function findByIdsWithRelations(array $ids, array $with = []): array;

    /**
     * 사용자의 사용 가능한 쿠폰 목록 조회
     *
     * 유효기간 내이며 미사용 상태인 쿠폰만 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $productIds  상품 ID 배열 (해당 상품에 적용 가능한 쿠폰 필터링)
     * @return array CouponIssue 배열
     */
    public function getAvailableCouponsForUser(int $userId, array $productIds = []): array;

    /**
     * 사용자의 전체 쿠폰함 목록 조회 (마이페이지용)
     *
     * 상태별로 분류하여 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string|null  $status  필터 상태 (available, used, expired)
     * @param  int  $perPage  페이지당 항목 수
     */
    public function getUserCoupons(int $userId, ?string $status = null, int $perPage = 10): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /**
     * 특정 사용자가 소유한 쿠폰만 조회 (소유권 검증용)
     *
     * 주어진 쿠폰 ID 중 해당 사용자가 소유한 쿠폰만 필터링하여 반환합니다.
     * 체크아웃 시 타인의 쿠폰 사용을 방지하는 보안 검증에 사용됩니다.
     *
     * @param  array  $couponIssueIds  쿠폰 발급 ID 배열
     * @param  int  $userId  사용자 ID
     * @return \Illuminate\Support\Collection CouponIssue 컬렉션
     */
    public function findByIdsForUser(array $couponIssueIds, int $userId): \Illuminate\Support\Collection;

    /**
     * 쿠폰 발급 레코드 생성
     *
     * @param array $data 발급 데이터
     * @return CouponIssue
     */
    public function create(array $data): CouponIssue;

    /**
     * 특정 사용자의 특정 쿠폰 발급 횟수 조회
     *
     * @param int $userId 사용자 ID
     * @param int $couponId 쿠폰 ID
     * @return int 발급 횟수
     */
    public function getUserIssuedCountForCoupon(int $userId, int $couponId): int;

    /**
     * 쿠폰 발급 레코드를 업데이트합니다.
     *
     * @param  int  $id  쿠폰 발급 ID
     * @param  array  $data  업데이트 데이터
     * @return bool 성공 여부
     */
    public function update(int $id, array $data): bool;

    /**
     * ID 목록으로 쿠폰 발급 레코드를 조회합니다.
     *
     * @param  int[]  $ids  쿠폰 발급 ID 배열
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByIds(array $ids): \Illuminate\Database\Eloquent\Collection;
}
