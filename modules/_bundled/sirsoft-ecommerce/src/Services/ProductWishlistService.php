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
    /**
     * ProductWishlistService 생성자
     *
     * @param  ProductWishlistRepositoryInterface  $repository  찜 리포지토리
     */
    public function __construct(
        protected ProductWishlistRepositoryInterface $repository
    ) {}

    /**
     * 찜 토글 (추가/제거)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $productId  상품 ID
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
     * @param  int  $userId  사용자 ID
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getByUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getByUser($userId, $perPage);
    }

    /**
     * 찜 삭제
     *
     * @param  int  $id  찜 ID
     * @param  int  $userId  사용자 ID (소유권 확인)
     * @return bool
     */
    public function destroy(int $id, int $userId): bool
    {
        return $this->repository->deleteByIdAndUser($id, $userId);
    }

    /**
     * 찜 여부 확인
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $productId  상품 ID
     * @return bool
     */
    public function isWishlisted(int $userId, int $productId): bool
    {
        return $this->repository->isWishlisted($userId, $productId);
    }

    /**
     * 사용자가 찜한 상품 ID 목록 조회
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $productIds  확인할 상품 ID 배열
     * @return array 찜한 상품 ID 배열
     */
    public function getWishlistedProductIds(int $userId, array $productIds): array
    {
        return $this->repository->getWishlistedProductIds($userId, $productIds);
    }

    /**
     * 관리자용 전 회원 찜 목록을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (검색 대상·검색어·회원·기간·정렬)
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이션된 목록
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginateForAdmin($filters, $perPage);
    }

    /**
     * 관리자가 찜 한 건을 삭제합니다 (소유자 무관).
     *
     * @param  int  $id  행 ID
     * @return bool 삭제 성공 여부
     */
    public function deleteByAdmin(int $id): bool
    {
        return $this->repository->deleteById($id);
    }

    /**
     * 관리자가 찜를 일괄 삭제합니다 (소유자 무관).
     *
     * 훅을 루프 앞뒤로 한 번씩만 발화한다 — 항목마다 발화하면 활동 로그가 폭주하고,
     * IDV 같은 before 훅 소비자가 루프 중간에 끼어들어 앞부분만 지워진 상태가 된다.
     *
     * @param  array<int, int>  $ids  행 ID 목록
     * @return int 실제로 삭제된 건수
     */
    public function bulkDeleteByAdmin(array $ids): int
    {
        // 스냅샷은 삭제 «전» 에 뜬다 — 찜은 소프트 삭제가 아니라 지우고 나면 행이 사라져
        // 활동 로그에 남은 id 로는 「누구의 무엇」을 되짚을 수 없다(배송정책 선례와 동형).
        $snapshots = $this->repository->findByIds($ids)
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_uuid' => $row->user?->uuid,
                'user_name' => $row->user?->name,
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'product_name' => $row->product?->getLocalizedName(),
            ])
            ->all();

        HookManager::doAction('sirsoft-ecommerce.wishlist.before_bulk_delete', $ids);

        $count = $this->repository->bulkDelete($ids);

        HookManager::doAction('sirsoft-ecommerce.wishlist.after_bulk_delete', $ids, $count, $snapshots);

        return $count;
    }

    /**
     * 회원의 찜 건수를 조회합니다 (회원 상세 요약용).
     *
     * @param  int  $userId  회원 ID
     * @return int 건수
     */
    public function countByUserForAdmin(int $userId): int
    {
        return $this->repository->countByUser($userId);
    }
}
