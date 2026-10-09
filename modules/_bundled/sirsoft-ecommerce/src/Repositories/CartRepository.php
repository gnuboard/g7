<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;

/**
 * 장바구니 Repository 구현체
 */
class CartRepository implements CartRepositoryInterface
{
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;

    /**
     * CartRepository 생성자
     *
     * @param  Cart  $model  장바구니 모델
     */
    public function __construct(
        protected Cart $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?Cart
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Cart
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(Cart $cart, array $data): Cart
    {
        $cart->update($data);

        return $cart->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Cart $cart): bool
    {
        return $cart->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserId(int $userId): Collection
    {
        // audit:allow query-unbounded-get reason: 한 회원(또는 한 비회원 cart_key)의 장바구니 행만 고른다 — 행 수가 그 사람의 담기 행위에 묶여 데이터 증가에 비례하지 않는다
        return $this->model
            ->with(Cart::displayRelations())
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByCartKeyWithoutUser(string $cartKey): Collection
    {
        // audit:allow query-unbounded-get reason: 한 회원(또는 한 비회원 cart_key)의 장바구니 행만 고른다 — 행 수가 그 사람의 담기 행위에 묶여 데이터 증가에 비례하지 않는다
        return $this->model
            ->with(Cart::displayRelations())
            ->where('cart_key', $cartKey)
            ->whereNull('user_id')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserAndOption(int $userId, int $productOptionId): ?Cart
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('product_option_id', $productOptionId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByCartKeyAndOption(string $cartKey, int $productOptionId): ?Cart
    {
        return $this->model
            ->where('cart_key', $cartKey)
            ->whereNull('user_id')
            ->where('product_option_id', $productOptionId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findAllByUserAndOption(int $userId, int $productOptionId): Collection
    {
        // audit:allow query-unbounded-get reason: 한 회원(또는 한 비회원 cart_key)의 장바구니 행만 고른다 — 행 수가 그 사람의 담기 행위에 묶여 데이터 증가에 비례하지 않는다
        return $this->model
            ->where('user_id', $userId)
            ->where('product_option_id', $productOptionId)
            ->get();
    }

    /**
     * 회원 장바구니에서 여러 옵션의 라인을 한 번에 조회해 옵션별로 묶어 반환합니다.
     *
     * 비회원 장바구니 병합처럼 여러 항목을 한꺼번에 다루는 경로에서, 항목마다 조회하면
     * 로그인 한 번에 항목 수만큼 쿼리가 난다.
     *
     * @param  int  $userId  회원 ID
     * @param  array<int, int>  $productOptionIds  옵션 ID 목록
     * @return SupportCollection<int, Collection> 옵션 ID ⇒ 장바구니 라인 컬렉션
     */
    public function findAllByUserAndOptions(int $userId, array $productOptionIds): SupportCollection
    {
        $productOptionIds = array_values(array_unique(array_filter($productOptionIds)));

        if (empty($productOptionIds)) {
            return new SupportCollection;
        }

        // groupBy 는 Support\Collection 을 돌려준다 (Eloquent\Collection 이 아니다).
        return $this->model
            ->where('user_id', $userId)
            ->whereIn('product_option_id', $productOptionIds)
            ->get()
            ->groupBy('product_option_id');
    }

    /**
     * {@inheritDoc}
     */
    public function findAllByCartKeyAndOption(string $cartKey, int $productOptionId): Collection
    {
        // audit:allow query-unbounded-get reason: 한 회원(또는 한 비회원 cart_key)의 장바구니 행만 고른다 — 행 수가 그 사람의 담기 행위에 묶여 데이터 증가에 비례하지 않는다
        return $this->model
            ->where('cart_key', $cartKey)
            ->whereNull('user_id')
            ->where('product_option_id', $productOptionId)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): Collection
    {
        return $this->model
            ->with(Cart::displayRelations())
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByIds(array $ids): int
    {
        return $this->model->whereIn('id', $ids)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByUserId(int $userId): int
    {
        return $this->model->where('user_id', $userId)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByCartKey(string $cartKey): int
    {
        return $this->model
            ->where('cart_key', $cartKey)
            ->whereNull('user_id')
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function countItems(?int $userId, ?string $cartKey): int
    {
        $query = $this->model->newQuery();

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } elseif ($cartKey !== null) {
            $query->where('cart_key', $cartKey)->whereNull('user_id');
        } else {
            return 0;
        }

        return $query->count();
    }

    /**
     * {@inheritDoc}
     */
    public function existsByCartKey(string $cartKey): bool
    {
        return $this->model->where('cart_key', $cartKey)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function sumQuantityByProduct(int $productId, ?int $userId, ?string $cartKey, ?int $excludeCartId = null): int
    {
        $query = $this->model->where('product_id', $productId);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } elseif ($cartKey !== null) {
            $query->where('cart_key', $cartKey)->whereNull('user_id');
        } else {
            return 0;
        }

        if ($excludeCartId !== null) {
            $query->where('id', '!=', $excludeCartId);
        }

        return (int) $query->sum('quantity');
    }

    /**
     * {@inheritDoc}
     */
    public function sumQuantityByProducts(array $productIds, int $userId): array
    {
        if (empty($productIds)) {
            return [];
        }

        return $this->model
            ->whereIn('product_id', $productIds)
            ->where('user_id', $userId)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as total_quantity')
            ->pluck('total_quantity', 'product_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function pruneExpiredItems(int $days, ?int $limit = null): int
    {
        // 만료 비활성 정책 — days < 1 이면 한 건도 삭제하지 않음 (전체 삭제 사고 차단)
        if ($days < 1) {
            return 0;
        }

        $threshold = Carbon::now()->subDays($days);

        // limit 미지정 시 단일 delete (정각/직전 보존 위해 '<' 비교)
        if ($limit === null) {
            return $this->model->where('updated_at', '<', $threshold)->delete();
        }

        // limit 지정 시 대상 id 를 청크 조회 후 위임 삭제 (대량 삭제 안전)
        $ids = $this->model
            ->where('updated_at', '<', $threshold)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            return 0;
        }

        return $this->deleteByIds($ids);
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $table = $this->model->getTable();
        $query = $this->model->newQuery();

        if (! empty($filters['user_id'])) {
            $query->where("{$table}.user_id", (int) $filters['user_id']);
        }

        // 회원/비회원 구분 — 비회원 장바구니는 user_id 가 없고 cart_key 로만 묶인다.
        // 회원 검색과 같은 칸에 섞으면 "회원 검색으로 비회원 행이 사라지는" 혼란이 나므로 축을 따로 둔다.
        $owner = (string) ($filters['owner'] ?? 'all');
        if ($owner === 'member') {
            $query->whereNotNull("{$table}.user_id");
        } elseif ($owner === 'guest') {
            $query->whereNull("{$table}.user_id");
        }

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
                'productOption',
            ],
            resultCap: PaginationLimits::resultCap('admin.carts'),
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
     * {@inheritDoc}
     */
    public function deleteById(int $id): bool
    {
        return (bool) $this->model->where('id', $id)->delete();
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
