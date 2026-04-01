<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;

/**
 * 상품 찜 Repository 인터페이스
 */
interface ProductWishlistRepositoryInterface
{
    /**
     * 찜 토글 (존재하면 삭제, 없으면 생성)
     *
     * @param int $userId 사용자 ID
     * @param int $productId 상품 ID
     * @return array{added: bool, wishlist: ProductWishlist|null}
     */
    public function toggle(int $userId, int $productId): array;

    /**
     * 찜 여부 확인
     *
     * @param int $userId 사용자 ID
     * @param int $productId 상품 ID
     * @return bool
     */
    public function isWishlisted(int $userId, int $productId): bool;

    /**
     * 사용자의 찜 목록 조회 (페이지네이션)
     *
     * @param int $userId 사용자 ID
     * @param int $perPage 페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getByUser(int $userId, int $perPage = 20): LengthAwarePaginator;

    /**
     * 찜 삭제
     *
     * @param int $id 찜 ID
     * @param int $userId 사용자 ID (소유권 확인)
     * @return bool
     */
    public function deleteByIdAndUser(int $id, int $userId): bool;

    /**
     * 사용자의 찜한 상품 ID 목록 조회
     *
     * @param int $userId 사용자 ID
     * @param array $productIds 확인할 상품 ID 배열
     * @return array 찜한 상품 ID 배열
     */
    public function getWishlistedProductIds(int $userId, array $productIds): array;
}
