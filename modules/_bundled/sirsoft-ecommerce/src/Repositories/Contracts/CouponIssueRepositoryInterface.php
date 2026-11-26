<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
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
     * @return CouponIssue|null 쿠폰 발급 내역 (없으면 null)
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
     * @return LengthAwarePaginator 쿠폰함 페이지네이터
     */
    public function getUserCoupons(int $userId, ?string $status = null, int $perPage = 10): LengthAwarePaginator;

    /**
     * 특정 사용자가 소유한 쿠폰만 조회 (소유권 검증용)
     *
     * 주어진 쿠폰 ID 중 해당 사용자가 소유한 쿠폰만 필터링하여 반환합니다.
     * 체크아웃 시 타인의 쿠폰 사용을 방지하는 보안 검증에 사용됩니다.
     *
     * @param  array  $couponIssueIds  쿠폰 발급 ID 배열
     * @param  int  $userId  사용자 ID
     * @return Collection CouponIssue 컬렉션
     */
    public function findByIdsForUser(array $couponIssueIds, int $userId): Collection;

    /**
     * 쿠폰 발급 레코드 생성
     *
     * @param  array  $data  발급 데이터
     * @return CouponIssue
     */
    public function create(array $data): CouponIssue;

    /**
     * 특정 사용자의 특정 쿠폰 발급 횟수 조회
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $couponId  쿠폰 ID
     * @return int 발급 횟수
     */
    public function getUserIssuedCountForCoupon(int $userId, int $couponId): int;

    /**
     * 특정 사용자의 여러 쿠폰 발급 횟수를 한 번에 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array<int, int>  $couponIds  쿠폰 ID 목록
     * @return array<int, int> 쿠폰 ID => 발급 횟수 (발급 이력이 없는 쿠폰은 키가 없다)
     */
    public function getUserIssuedCountsForCoupons(int $userId, array $couponIds): array;

    /**
     * 특정 사용자가 특정 쿠폰을 사용 완료(used_at 세팅)한 횟수를 조회합니다.
     *
     * per_user_limit 주문 단계 검증의 "과거 사용"(축1) 기준입니다.
     * 발급 횟수(getUserIssuedCountForCoupon)와 의미가 분리됩니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $couponId  쿠폰 ID
     * @return int 사용 완료 건수
     */
    public function getUserUsedCountForCoupon(int $userId, int $couponId): int;

    /**
     * 쿠폰 발급 레코드를 업데이트합니다.
     *
     * @param  int  $id  쿠폰 발급 ID
     * @param  array  $data  업데이트 데이터
     * @return bool 성공 여부
     */
    public function update(int $id, array $data): bool;

    /**
     * 현재 상태가 기대값과 같을 때만 쿠폰 발급 레코드를 갱신합니다.
     *
     * 조회 후 갱신하는 방식은 두 요청이 같은 상태를 읽어 서로를 덮어쓰는 lost update 를
     * 허용하므로, 상태 판정과 갱신을 하나의 UPDATE 문에서 원자적으로 수행합니다.
     * 갱신된 행 수가 0 이면 다른 요청이 이미 상태를 바꾼 것입니다.
     *
     * @param  int  $id  쿠폰 발급 ID
     * @param  CouponIssueRecordStatus  $expected  기대하는 현재 상태
     * @param  array  $data  업데이트 데이터
     * @return int 갱신된 행 수 (0 이면 경쟁에서 밀렸거나 상태 불일치)
     */
    public function updateIfStatus(int $id, CouponIssueRecordStatus $expected, array $data): int;

    /**
     * ID 목록으로 쿠폰 발급 레코드를 조회합니다.
     *
     * @param  int[]  $ids  쿠폰 발급 ID 배열
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByIds(array $ids): \Illuminate\Database\Eloquent\Collection;

    /**
     * 회원의 발급 건 가운데 회원 삭제 때 발급 수량에서 되돌릴(쓰지 않은) 건수를 쿠폰별로 셉니다 (회원 삭제 정리용).
     *
     * 발급 취소(`cancelled`)는 이미 쿠폰의 발급 수량에서 빠졌고, 사용(`used`)한 발급은 한정 수량을 실제로 소진했으므로 세지 않는다.
     *
     * @param  int  $userId  회원 ID
     * @return array<int, int> 쿠폰 ID => 건수
     */
    public function countIssuedByCouponForUser(int $userId): array;

    /**
     * 회원의 쿠폰 발급 내역을 모두 삭제합니다 (회원 삭제 정리용).
     *
     * @param  int  $userId  회원 ID
     * @return int 삭제된 행 수
     */
    public function deleteByUserId(int $userId): int;

    /**
     * 회원이 그 쿠폰을 그 발급 키로 이미 받았는지 확인합니다.
     *
     * @param  int  $couponId  쿠폰 ID
     * @param  int  $userId  회원 ID
     * @param  string  $autoIssueKey  발급 키 (signup·first_purchase·birthday:YYYY)
     * @return bool 이미 있으면 true
     */
    public function existsByAutoIssueKey(int $couponId, int $userId, string $autoIssueKey): bool;

    /**
     * 그 주문이 원천인 미사용 첫구매 발급 건을 반환합니다.
     *
     * @param  int  $orderId  원천 주문 ID
     * @return Collection<int, CouponIssue> 사용가능 상태의 발급 건 (쿠폰 관계 로드)
     */
    public function getAvailableAutoIssuesBySourceOrder(int $orderId): Collection;

    /**
     * 만료 임박 알림 대상 발급 건을 반환합니다.
     *
     * 사용가능 상태 + 만료일이 구간 안 + 아직 알리지 않은 건이다. 회원 ID·만료일 순이다.
     *
     * @param  \DateTimeInterface  $from  구간 시작 (포함)
     * @param  \DateTimeInterface  $to  구간 끝 (포함)
     * @param  int  $limit  최대 건수
     * @return Collection<int, CouponIssue> 발급 건 (쿠폰·회원 관계 로드)
     */
    public function getExpiringUnnotifiedIssues(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 5000): Collection;

    /**
     * 발급 건에 만료 임박 알림 시각을 기록합니다.
     *
     * @param  array<int, int>  $issueIds  발급 건 ID
     * @return int 갱신한 행 수
     */
    public function markExpiryNotified(array $issueIds): int;
}
