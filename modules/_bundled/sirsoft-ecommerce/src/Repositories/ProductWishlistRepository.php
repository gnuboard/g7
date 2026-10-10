<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;

/**
 * 상품 찜 Repository 구현체
 */
class ProductWishlistRepository implements ProductWishlistRepositoryInterface
{
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;

    /**
     * ProductWishlistRepository 생성자
     *
     * @param  ProductWishlist  $model  찜 모델
     */
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
            //
            // 「숨김」 상품도 같은 이유로 목록에서 뺀다 — 운영자가 사용자 표면에서 내린 상품인데
            // 찜 목록에는 정상 카드로 남아 클릭까지 되던 결함(#524 §4.3)의 처치다.
            // 품절·판매중지(sales_status)는 카드 오버레이가 이미 표시하므로 그대로 남긴다.
            ->whereHas('product', fn ($q) => $q->visible())
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

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $table = $this->model->getTable();
        $query = $this->model->newQuery();

        $this->applyAdminMemberFilter($query, $table, $filters);
        $this->applyViewerDateRangeFilter($query, "{$table}.created_at", $filters['start_date'] ?? null, $filters['end_date'] ?? null);
        $this->applyAdminSearch($query, (string) ($filters['search_field'] ?? 'member'), $filters['search_keyword'] ?? null);

        $direction = ($filters['sort'] ?? 'created_at_desc') === 'created_at_asc' ? 'asc' : 'desc';

        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ["{$table}.*"],
            sort: [['column' => 'created_at', 'direction' => $direction]],
            perPage: $perPage,
            relations: [
                'user:id,uuid,name,email,status',
                'product:id,name,display_status,sales_status',
            ],
            resultCap: PaginationLimits::resultCap('admin.wishlists'),
        );
    }

    /**
     * 관리자 목록의 검색 조건을 적용합니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $field  검색 대상
     * @param  string|null  $keyword  검색어
     * @return void
     */
    private function applyAdminSearch(Builder $query, string $field, ?string $keyword): void
    {
        if ($keyword === null || trim($keyword) === '') {
            return;
        }

        $needle = '%'.trim($keyword).'%';

        match ($field) {
            'member_id' => $query->whereHas('user', fn (Builder $u) => $u->where('uuid', trim($keyword))),
            'email' => $query->whereHas('user', fn (Builder $u) => $u->where('email', 'like', $needle)),
            // 상품명은 로케일별 JSON 컬럼이라 활성 로케일을 모두 훑는다
            'product' => $query->whereHas('product', function (Builder $p) use ($needle) {
                $p->where(function (Builder $inner) use ($needle) {
                    foreach (config('app.translatable_locales', ['ko', 'en']) as $locale) {
                        $inner->orWhere("name->{$locale}", 'like', $needle);
                    }
                });
            }),
            default => $query->whereHas('user', fn (Builder $u) => $u->where(
                fn (Builder $w) => $w->where('name', 'like', $needle)->orWhere('email', 'like', $needle)
            )),
        };
    }

    /**
     * 회원 한정 필터를 적용합니다 (회원 상세에서 넘어온 user_id).
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $table  테이블명
     * @param  array<string, mixed>  $filters  필터
     * @return void
     */
    private function applyAdminMemberFilter(Builder $query, string $table, array $filters): void
    {
        if (! empty($filters['user_id'])) {
            $query->where("{$table}.user_id", (int) $filters['user_id']);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteById(int $id): bool
    {
        return (bool) $this->model->where('id', $id)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): Collection
    {
        return $this->model
            ->with(['user:id,uuid,name,email,status', 'product:id,name'])
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDelete(array $ids): int
    {
        return (int) $this->model->whereIn('id', $ids)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function countByUser(int $userId): int
    {
        return (int) $this->model->where('user_id', $userId)->count();
    }
}
