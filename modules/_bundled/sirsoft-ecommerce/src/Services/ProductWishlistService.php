<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;
/**
 * 상품 찜 서비스
 */
class ProductWishlistService
{
    public function __construct(
        protected ProductWishlistRepositoryInterface $repository
    ) {}

    /**
     * 찜 토글 (추가/제거)
     *
     * @param int $userId 사용자 ID
     * @param int $productId 상품 ID
     * @return array{added: bool}
     */
    public function toggle(int $userId, int $productId): array
    {
        HookManager::doAction('sirsoft-ecommerce.wishlist.before_toggle', $userId, $productId);

        $result = $this->repository->toggle($userId, $productId);

        HookManager::doAction('sirsoft-ecommerce.wishlist.after_toggle', $userId, $productId, $result['added']);

        return $result;
    }

    /**
     * 사용자의 찜 목록 조회
     *
     * @param int $userId 사용자 ID
     * @param int $perPage 페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getByUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getByUser($userId, $perPage);
    }

    /**
     * 찜 삭제
     *
     * @param int $id 찜 ID
     * @param int $userId 사용자 ID (소유권 확인)
     * @return bool
     */
    public function destroy(int $id, int $userId): bool
    {
        return $this->repository->deleteByIdAndUser($id, $userId);
    }

    /**
     * 찜 여부 확인
     *
     * @param int $userId 사용자 ID
     * @param int $productId 상품 ID
     * @return bool
     */
    public function isWishlisted(int $userId, int $productId): bool
    {
        return $this->repository->isWishlisted($userId, $productId);
    }

    /**
     * 사용자가 찜한 상품 ID 목록 조회
     *
     * @param int $userId 사용자 ID
     * @param array $productIds 확인할 상품 ID 배열
     * @return array 찜한 상품 ID 배열
     */
    public function getWishlistedProductIds(int $userId, array $productIds): array
    {
        return $this->repository->getWishlistedProductIds($userId, $productIds);
    }
}
