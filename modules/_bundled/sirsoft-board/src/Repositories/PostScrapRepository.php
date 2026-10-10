<?php

namespace Modules\Sirsoft\Board\Repositories;

use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Search\KeywordSearch;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Models\PostScrap;
use Modules\Sirsoft\Board\Repositories\Contracts\PostScrapRepositoryInterface;

/**
 * 게시글 스크랩 Repository 구현체
 */
class PostScrapRepository implements PostScrapRepositoryInterface
{
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;

    /**
     * PostScrapRepository 생성자
     *
     * @param  PostScrap  $model  스크랩 모델
     */
    public function __construct(
        protected PostScrap $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function toggle(int $userId, int $postId, ?int $boardId = null): array
    {
        $existing = $this->model
            ->where('user_id', $userId)
            ->where('post_id', $postId)
            ->first();

        if ($existing) {
            $existing->delete();

            return ['added' => false, 'scrap' => null];
        }

        $scrap = $this->model->create([
            'user_id' => $userId,
            'post_id' => $postId,
            'board_id' => $boardId,
        ]);

        return ['added' => true, 'scrap' => $scrap];
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByIdAndUser(int $id, int $userId): ?PostScrap
    {
        $scrap = $this->model
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (! $scrap) {
            return null;
        }

        $scrap->delete();

        return $scrap;
    }

    /**
     * {@inheritDoc}
     */
    public function isScraped(int $userId, int $postId): bool
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateByUser(
        int $userId,
        int $perPage = 20,
        ?string $search = null,
        string $searchField = self::SEARCH_FIELD_DEFAULT
    ): LengthAwarePaginator {
        $query = $this->model
            ->where('user_id', $userId)
            // 원글이 소프트 삭제돼도 카드를 "삭제된 게시글" 로 표시하므로 withTrashed 로 함께 싣는다.
            ->with([
                'post' => fn ($q) => $q->withTrashed(),
                'post.user',
                'board',
            ]);

        if ($search !== null && trim($search) !== '') {
            $keyword = $this->escapeLikeKeyword(trim($search));
            $columns = match ($searchField) {
                'title' => ['title'],
                'content' => ['content'],
                default => ['title', 'content'],
            };

            // whereHas 는 기본적으로 소프트 삭제된 원글을 제외한다 — 삭제된 원글은 응답에서
            // title 자체가 노출되지 않으므로(PostScrapResource), 검색 대상에서도 자연스럽게 빠진다.
            // board_posts 에는 (title, content) 복합 FULLTEXT 만 있어 단일 컬럼 MATCH 가 불가능하므로
            // LIKE 로 매칭한다 — where(user_id) 로 한 회원의 스크랩까지 이미 좁혀진 집합이라 충분하다.
            $query->whereHas('post', fn (Builder $p) => $p->where(function (Builder $w) use ($columns, $keyword) {
                foreach ($columns as $column) {
                    $w->orWhere($column, 'like', "%{$keyword}%");
                }
            }));

            // 검색 결과에서는 스크랩이 중지된 게시판(비활성·use_scrap=false)을 제외한다.
            // 기본 목록에서는 배지로 구분해 남기지만, 검색은 "지금 유효한 스크랩" 을 찾는
            // 행위이므로 중지된 게시판의 글을 결과에 넣지 않는다.
            $query->whereHas('board', fn (Builder $b) => $b->where('is_active', true)->where('use_scrap', true));
        }

        return $query
            ->orderByDesc('created_at')
            // 전순서 보장 — 한 번에 여러 건을 담았을 때의 created_at 동률 대비
            ->orderByDesc('id')
            // audit:allow repository-paginate-column-pruning reason: 사용자 1명에 종속된 스크랩 목록 —
            // where(user_id) 로 이미 좁혀지고, 피벗 성격의 테이블이라 넓은 컬럼이 없다
            ->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteManyByIdsAndUser(array $ids, int $userId): Collection
    {
        $scraps = $this->model
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->get(['id', 'user_id', 'post_id', 'board_id']);

        if ($scraps->isEmpty()) {
            return $scraps;
        }

        $this->model->whereIn('id', $scraps->pluck('id')->all())->delete();

        return $scraps;
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): Paginator
    {
        $table = $this->model->getTable();
        $query = $this->model->newQuery();

        if (! empty($filters['user_id'])) {
            $query->where("{$table}.user_id", (int) $filters['user_id']);
        }

        if (! empty($filters['board_id'])) {
            $query->where("{$table}.board_id", (int) $filters['board_id']);
        }

        // 목록은 스크랩일을 보는 사람의 시간대로 그린다 — 같은 하루로 자른다.
        $this->applyViewerDateRangeFilter(
            $query,
            "{$table}.created_at",
            $filters['scrapped_at_from'] ?? null,
            $filters['scrapped_at_to'] ?? null
        );

        $this->applyAdminSearch($query, (string) ($filters['search_field'] ?? 'all'), $filters['search'] ?? null);

        $direction = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ["{$table}.*"],
            sort: [['column' => 'created_at', 'direction' => $direction]],
            perPage: $perPage,
            relations: [
                'user:id,uuid,name,email,status',
                'board:id,slug,name,is_active,use_scrap',
                'post' => fn ($p) => $p->withTrashed()->select(['id', 'board_id', 'user_id', 'title', 'status', 'author_name', 'deleted_at']),
                'post.user:id,name,status',
            ],
            resultCap: PaginationLimits::resultCap('admin.scraps'),
        );
    }

    /**
     * 관리자 목록의 검색 조건을 적용합니다.
     *
     * all 은 게시글 제목·내용 + 게시판명 + 회원(이름·이메일) + 작성자명을 OR 로 묶는다.
     * 단일 컬럼 분기는 board_posts 에 단일 컬럼 FULLTEXT 가 없어 LIKE 로 처리한다
     * (KeywordSearch 는 복합 FULLTEXT 가 있는 2컬럼 호출에만 쓴다).
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $field  검색 대상 (IndexScrapRequest::SEARCH_FIELDS)
     * @param  string|null  $search  검색어
     * @return void
     */
    private function applyAdminSearch(Builder $query, string $field, ?string $search): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        $raw = trim($search);
        $keyword = $this->escapeLikeKeyword($raw);

        match ($field) {
            'post_title' => $query->whereHas(
                'post',
                fn (Builder $p) => $p->withTrashed()->where('title', 'like', "%{$keyword}%")
            ),
            'post_content' => $query->whereHas(
                'post',
                fn (Builder $p) => $p->withTrashed()->where('content', 'like', "%{$keyword}%")
            ),
            // 게시판명은 로케일별 JSON 컬럼이라 LIKE 로 로케일 무관 매칭한다.
            'board_name' => $query->whereHas(
                'board',
                fn (Builder $b) => $b->where('name', 'like', "%{$keyword}%")
            ),
            'member' => $query->whereHas('user', fn (Builder $u) => $u->where(
                fn (Builder $w) => $w->where('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
            )),
            'author_name' => $query->whereHas('post', fn (Builder $p) => $p->withTrashed()->where(
                fn (Builder $w) => $w->where('author_name', 'like', "%{$keyword}%")
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$keyword}%"))
            )),
            default => $query->where(function (Builder $outer) use ($raw, $keyword) {
                $outer->whereHas('post', fn (Builder $p) => $p->withTrashed()->where(
                    function (Builder $w) use ($raw) {
                        KeywordSearch::apply($w, ['title', 'content'], $raw, 'or');
                    }
                ))
                    ->orWhereHas('board', fn (Builder $b) => $b->where('name', 'like', "%{$keyword}%"))
                    ->orWhereHas('user', fn (Builder $u) => $u->where(
                        fn (Builder $w) => $w->where('name', 'like', "%{$keyword}%")
                            ->orWhere('email', 'like', "%{$keyword}%")
                    ))
                    ->orWhereHas('post', fn (Builder $p) => $p->withTrashed()->where('author_name', 'like', "%{$keyword}%"));
            }),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?PostScrap
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteById(int $id): ?PostScrap
    {
        $scrap = $this->model->find($id);

        if (! $scrap) {
            return null;
        }

        $scrap->delete();

        return $scrap;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteManyByIds(array $ids): Collection
    {
        $scraps = $this->model
            ->whereIn('id', $ids)
            ->get(['id', 'user_id', 'post_id', 'board_id']);

        if ($scraps->isEmpty()) {
            return $scraps;
        }

        $this->model->whereIn('id', $scraps->pluck('id')->all())->delete();

        return $scraps;
    }

    /**
     * {@inheritDoc}
     */
    public function summaryByUser(int $userId): array
    {
        $row = $this->model
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as aggregate_count, MAX(created_at) as latest_scrapped_at')
            ->first();

        return [
            'count' => (int) ($row->aggregate_count ?? 0),
            'latest_scrapped_at' => $row?->latest_scrapped_at
                ? Carbon::parse($row->latest_scrapped_at)
                : null,
        ];
    }

    /**
     * LIKE 쿼리용 키워드를 이스케이프합니다.
     * MySQL LIKE 와일드카드 문자(%, _)를 이스케이프하여 특수문자 검색을 가능하게 합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @return string 이스케이프된 키워드
     */
    private function escapeLikeKeyword(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
    }
}
