<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Search\KeywordSearch;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;

/**
 * 상품 1:1 문의 Repository 구현체
 */
class ProductInquiryRepository implements ProductInquiryRepositoryInterface
{
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;

    /**
     * ProductInquiryRepository 생성자
     *
     * @param  ProductInquiry  $model  상품문의 모델
     */
    public function __construct(
        protected ProductInquiry $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?ProductInquiry
    {
        return $this->model->with(['product', 'user'])->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByProductId(int $productId): Collection
    {
        // audit:allow query-unbounded-get reason: 상품 삭제 시 딸린 문의를 함께 정리하기 위한
        // 내부 조회다. 상한을 걸면 남은 문의가 고아로 남는다. 화면용 목록은 같은 클래스의
        // paginateByProductId() 가 담당한다
        return $this->model->newQuery()
            ->where('product_id', $productId)
            ->orderBy('created_at', 'desc')
            // 전순서 보장 — created_at 동률의 순서가 호출마다 달라지지 않도록
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateByProductId(int $productId, int $perPage = 10, ?int $page = null): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('product_id', $productId)
            ->orderBy('created_at', 'desc')
            // 전순서 보장 — 같은 초에 등록된 문의가 페이지 경계에서 뒤섞이지 않도록
            ->orderBy('id', 'desc')
            // audit:allow repository-paginate-column-pruning reason: 상품 1건에 종속된 문의 목록 —
            // where(product_id) 로 이미 좁혀져 OFFSET 이 깊어질 수 없고, 목록이 본문을 그대로 쓴다
            // page 명시 하달 — HTTP `page` 파라미터 암묵 해석에 기대지 않는다 (#102 동형 예방)
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * {@inheritDoc}
     */
    public function findByInquirable(string $inquirableType, int $inquirableId): ?ProductInquiry
    {
        return $this->model->newQuery()
            ->where('inquirable_type', $inquirableType)
            ->where('inquirable_id', $inquirableId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserId(int $userId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['product'])
            ->where('user_id', $userId)
            // 전순서 보장 — created_at 동률에서 페이지 경계 흔들림 방지
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        // 답변 여부 필터
        if (isset($filters['is_answered']) && $filters['is_answered'] !== '') {
            $query->where('is_answered', (bool) $filters['is_answered']);
        }

        // 상품명 검색 (product_name_snapshot JSON 컬럼)
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $query->where(function ($q) use ($keyword, $locales) {
                foreach ($locales as $locale) {
                    $q->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(product_name_snapshot, '$.{$locale}')) LIKE ?",
                        ["%{$keyword}%"]
                    );
                }
            });
        }

        // audit:allow repository-paginate-column-pruning reason: 사용자 1명에 종속된 내 문의 목록 —
        // where(user_id) 로 이미 좁혀져 OFFSET 이 깊어질 수 없다
        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        // 상품명 검색
        if (! empty($filters['search_keyword'])) {
            $keyword = $filters['search_keyword'];
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $query->whereHas('product', function ($q) use ($keyword, $locales) {
                $q->where(function ($inner) use ($keyword, $locales) {
                    foreach ($locales as $locale) {
                        $inner->orWhere("name->{$locale}", 'like', "%{$keyword}%");
                    }
                });
            });
        }

        // 답변 여부 필터
        if (isset($filters['is_answered']) && $filters['is_answered'] !== '') {
            $query->where('is_answered', (bool) $filters['is_answered']);
        }

        // 기간 필터 — whereDate 는 컬럼에 함수를 씌워 인덱스를 무력화하므로 범위 조건으로 준다.
        // 작성일은 보는 사람의 시간대로 그리므로 그 하루로 자른다.
        $this->applyViewerDateRangeFilter($query, 'created_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        // 지연 조인: 전역 문의 목록은 무제한 성장하고 content(text)가 있어
        // OFFSET 이 훑는 구간에서 넓은 컬럼까지 읽힌다. inner 는 id 만 훑는다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: [['column' => 'created_at', 'direction' => 'desc']],
            perPage: $perPage,
            relations: ['product', 'user'],
            resultCap: PaginationLimits::resultCap('admin.product_inquiries'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ProductInquiry
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function markAsAnswered(ProductInquiry $inquiry): ProductInquiry
    {
        $inquiry->update([
            'is_answered' => true,
            // 최초 답변 시각 보존 — 답변 수정/재마킹이 시각을 덮어쓰면 안 된다 (선례: ProductReviewService)
            'answered_at' => $inquiry->answered_at ?? now(),
        ]);

        return $inquiry->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function unmarkAnswered(ProductInquiry $inquiry): ProductInquiry
    {
        $inquiry->update([
            'is_answered' => false,
            'answered_at' => null,
        ]);

        return $inquiry->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteById(int $id): bool
    {
        return $this->model->newQuery()->where('id', $id)->delete() > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByInquirableIds(string $inquirableType, array $inquirableIds): int
    {
        return $this->model->newQuery()
            ->where('inquirable_type', $inquirableType)
            ->whereIn('inquirable_id', $inquirableIds)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findByProductIdWithTrashed(int $productId): Collection
    {
        // audit:allow query-unbounded-get reason: 상품 삭제 정리 경로 — 단일 상품에 종속된 피벗만 조회
        return $this->model->newQuery()
            ->withTrashed()
            ->where('product_id', $productId)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function forceDeleteByProductId(int $productId): int
    {
        return $this->model->newQuery()
            ->withTrashed()
            ->where('product_id', $productId)
            ->forceDelete();
    }

    /**
     * {@inheritDoc}
     */
    public function restoreByInquirable(string $inquirableType, int $inquirableId): int
    {
        return $this->model->newQuery()
            ->onlyTrashed()
            ->where('inquirable_type', $inquirableType)
            ->where('inquirable_id', $inquirableId)
            ->restore();
    }

    /**
     * {@inheritDoc}
     */
    public function getPendingRecent(int $limit): Collection
    {
        return $this->model->newQuery()
            ->with(['product', 'user'])
            ->where('is_answered', false)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function countPending(): int
    {
        return $this->model->newQuery()
            ->where('is_answered', false)
            ->count();
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

        // 답변 여부는 false 가 「미답변만」 이라는 뜻이라 empty() 로 보면 안 된다.
        if (array_key_exists('is_answered', $filters) && $filters['is_answered'] !== null && $filters['is_answered'] !== '') {
            $query->where("{$table}.is_answered", (bool) $filters['is_answered']);
        }

        $this->applyViewerDateRangeFilter($query, "{$table}.created_at", $filters['start_date'] ?? null, $filters['end_date'] ?? null);
        $this->applyAdminSearch($query, (string) ($filters['search_field'] ?? 'member'), $filters['search_keyword'] ?? null);

        $direction = ($filters['sort'] ?? 'created_at_desc') === 'created_at_asc' ? 'asc' : 'desc';

        $paginator = $this->paginateWithDeferredJoin(
            query: $query,
            columns: ["{$table}.*"],
            sort: [['column' => 'created_at', 'direction' => $direction]],
            perPage: $perPage,
            // inquirable 은 쿼리 단계에서 eager load 하지 않는다 — 문의 본문은 게시판 모듈의 글에
            // morph 로 걸려 있고, 그 모듈이 없거나 morph 별칭이 등록되지 않은 환경에서는 관계를
            // 푸는 순간 "Class not found" 로 목록 전체가 죽는다. 아래에서 풀리는 행만 골라 싣는다.
            relations: ['user:id,uuid,name,email,status', 'product:id,name,display_status,sales_status'],
            resultCap: PaginationLimits::resultCap('admin.product_inquiries'),
        );

        $this->loadResolvableInquirables($paginator->getCollection());

        return $paginator;
    }

    /**
     * 실제 클래스로 풀리는 행에만 원글(inquirable)을 한 번에 싣습니다.
     *
     * 싣지 않으면 목록의 본문 요약이 게시판 모듈이 있는 상점에서도 언제나 「본문을 불러올 수
     * 없습니다」로 비었다(실측 2026-10-03). 타입별로 묶어 한 번씩만 읽으므로 행마다
     * 쿼리가 붙지 않고, 풀리지 않는 타입의 행은 건드리지 않아 목록이 죽지 않는다.
     *
     * @param  \Illuminate\Support\Collection  $rows  현재 페이지의 문의 행
     * @return void 반환값 없음
     */
    private function loadResolvableInquirables(\Illuminate\Support\Collection $rows): void
    {
        $loadable = $rows->filter(function ($row) {
            $type = $row->inquirable_type;
            $class = $type !== null ? (Relation::getMorphedModel($type) ?? $type) : null;

            return is_string($class) && class_exists($class);
        });

        if ($loadable->isNotEmpty()) {
            (new Collection($loadable->values()->all()))->load('inquirable');
        }
    }

    /**
     * 관리자 목록의 검색 조건을 적용합니다.
     *
     * 문의 본문은 게시판 글(inquirable)에 있으므로 content 검색은 그 관계를 탄다 —
     * 문의 테이블 자체에는 본문 컬럼이 없다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $field  검색 대상
     * @param  string|null  $keyword  검색어
     * @return void 반환값 없음
     */
    private function applyAdminSearch(Builder $query, string $field, ?string $keyword): void
    {
        if ($keyword === null || trim($keyword) === '') {
            return;
        }

        // `%`·`_` 를 글자 그대로 찾는다 — 이스케이프하지 않으면 「50%」 같은 검색어가 와일드카드로 읽혀 엉뚱한 행이 잡힌다.
        $needle = '%'.KeywordSearch::escapeLikeWildcards(trim($keyword)).'%';

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
            // 본문은 morph 로 연결된 글에 있다. 해석 가능한 타입만 골라 건네지 않으면
            // 미등록 별칭 하나가 쿼리 전체를 죽인다 — 해석 가능한 타입이 없으면 결과를 비운다.
            'content' => $this->applyContentSearch($query, $needle),
            default => $query->whereHas('user', fn (Builder $u) => $u->where(
                fn (Builder $w) => $w->where('name', 'like', $needle)->orWhere('email', 'like', $needle)
            )),
        };
    }

    /**
     * 문의 본문 검색을 적용합니다 (morph 로 연결된 글의 content).
     *
     * 문의 테이블에는 본문 컬럼이 없다 — 본문은 게시판 글에 있고 inquirable_type 은
     * 'board_post' 같은 별칭이다. 그 별칭을 실제 클래스로 풀 수 없는 환경(모듈 미설치 등)에서
     * whereHasMorph 에 넘기면 쿼리가 통째로 죽으므로, 해석되는 타입만 골라 넘긴다.
     * 하나도 해석되지 않으면 "본문으로는 찾을 수 없다" 가 정답이므로 빈 결과로 만든다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $needle  LIKE 패턴
     * @return void 반환값 없음
     */
    private function applyContentSearch(Builder $query, string $needle): void
    {
        // audit:allow query-unbounded-get reason: distinct 한 morph 타입 목록이라 행 수가 «등록된 문의 대상 종류» 수만큼으로 상한이 잡힌다(문의 건수와 무관)
        $types = $this->model->newQuery()
            ->select('inquirable_type')
            ->distinct()
            ->pluck('inquirable_type')
            ->filter()
            ->map(fn ($type) => Relation::getMorphedModel($type) ?? $type)
            ->filter(fn ($class) => is_string($class) && class_exists($class))
            ->unique()
            ->values()
            ->all();

        if ($types === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHasMorph('inquirable', $types, fn (Builder $q) => $q->where('content', 'like', $needle));
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

    /**
     * {@inheritDoc}
     */
    public function countPendingByUser(int $userId): int
    {
        return (int) $this->model->where('user_id', $userId)->where('is_answered', false)->count();
    }
}
