<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;

/**
 * 상품 찜 Repository 구현체
 */
class ProductWishlistRepository implements ProductWishlistRepositoryInterface
{
    public function __construct(
        protected ProductWishlist $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function toggle(int $userId, int $productId): array
    {
        $existing = $this->model
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if ($existing) {
            $existing->delete();

            return ['added' => false, 'wishlist' => null];
        }

        $wishlist = $this->model->create([
            'user_id' => $userId,
            'product_id' => $productId,
        ]);

        return ['added' => true, 'wishlist' => $wishlist];
    }

    /**
     * {@inheritDoc}
     */
    public function isWishlisted(int $userId, int $productId): bool
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function getByUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->where('user_id', $userId)
            // 상품이 소프트 삭제되면 eager load 결과가 null 이 되어 카드가 렌더 실패한다.
            // 목록에서 아예 제외해 "빈 셀 0" 을 성립시킨다 (찜 행 자체는 보존).
            ->whereHas('product')
            // 대표 이미지는 로드된 컬렉션에서 고른다. 미로드 시 `getThumbnailUrl()` 이 행마다
            // 관계를 최대 2회 재조회하므로(대표 지정 조회 → 첫 이미지 폴백) 행 수에 비례해 늘어난다.
            // 썸네일 URL 조립에 필요한 컬럼만 읽어 페이로드는 늘리지 않는다.
            ->with([
                'product.brand',
                'product.categories',
                'product.activeLabelAssignments.label',
                'product.images:id,product_id,hash,is_thumbnail,sort_order',
                // 찜 카드도 상품 카드와 같은 컴포넌트라 별점을 그린다. 집계를 붙이지 않으면
                // 목록 표현이 평점·리뷰 수를 싣지 못해 리뷰가 달린 상품도 별 0 개로 보인다.
                'product' => fn ($q) => $q
                    ->withCount(['visibleReviews as review_count'])
                    ->withAvg('visibleReviews as rating_avg', 'rating'),
            ])
            ->orderByDesc('created_at')
            // 전순서 보장 — 한 번에 여러 건을 담았을 때의 created_at 동률 대비
            ->orderByDesc('id')
            // audit:allow repository-paginate-column-pruning reason: 사용자 1명에 종속된 찜 목록 —
            // where(user_id) 로 이미 좁혀지고, 피벗 성격의 테이블이라 넓은 컬럼이 없다
            ->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByIdAndUser(int $id, int $userId): bool
    {
        return (bool) $this->model
            ->where('id', $id)
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function getWishlistedProductIds(int $userId, array $productIds): array
    {
        return $this->model
            ->where('user_id', $userId)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->all();
    }
}
