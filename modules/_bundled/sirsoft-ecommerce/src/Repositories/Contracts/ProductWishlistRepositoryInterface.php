<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;

/**
 * 상품 찜 Repository 인터페이스
 */
interface ProductWishlistRepositoryInterface
{
    /**
     * 찜 토글 (존재하면 삭제, 없으면 생성)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $productId  상품 ID
     * @return array{added: bool, wishlist: ProductWishlist|null}
     */
    public function toggle(int $userId, int $productId): array;

    /**
     * 찜 여부 확인
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $productId  상품 ID
     * @return bool
     */
    public function isWishlisted(int $userId, int $productId): bool;

    /**
     * 사용자의 찜 목록 조회 (페이지네이션)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getByUser(int $userId, int $perPage = 20): LengthAwarePaginator;

    /**
     * 찜 삭제
     *
     * @param  int  $id  찜 ID
     * @param  int  $userId  사용자 ID (소유권 확인)
     * @return bool
     */
    public function deleteByIdAndUser(int $id, int $userId): bool;

    /**
     * 사용자의 찜한 상품 ID 목록 조회
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $productIds  확인할 상품 ID 배열
     * @return array 찜한 상품 ID 배열
     */
    public function getWishlistedProductIds(int $userId, array $productIds): array;

    /**
     * 관리자용 전 회원 찜 목록을 페이지네이션합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (search_field, search_keyword, user_id, 기간, 정렬)
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이션된 목록
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 찜 행을 ID 로 삭제합니다 (소유자 무관 — 관리자 전용).
     *
     * @param  int  $id  행 ID
     * @return bool 삭제 성공 여부
     */
    public function deleteById(int $id): bool;

    /**
     * 찜 행을 ID 목록으로 조회합니다 (일괄 삭제 전 스냅샷용).
     *
     * 찜은 소프트 삭제가 아니라 지우면 행이 사라진다 — 활동 로그가 「누구의 무엇을
     * 지웠는가」를 남기려면 삭제 «전» 에 회원·상품을 함께 읽어 둬야 한다.
     *
     * @param  array<int, int>  $ids  행 ID 목록
     * @return Collection<int, ProductWishlist>
     */
    public function findByIds(array $ids): Collection;

    /**
     * 찜 행을 ID 목록으로 일괄 삭제합니다 (소유자 무관 — 관리자 전용).
     *
     * @param  array<int, int>  $ids  행 ID 목록
     * @return int 실제로 삭제된 건수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 회원의 찜 건수를 조회합니다 (회원 상세 요약용).
     *
     * @param  int  $userId  회원 ID
     * @return int 건수
     */
    public function countByUser(int $userId): int;
}
