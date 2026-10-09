<?php

namespace Modules\Sirsoft\Board\Repositories;

use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Reaction;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Services\ReactionService;

/**
 * 반응 이력 Repository
 *
 * 반응 이력 데이터 접근 계층을 담당합니다.
 *
 * 대상(target)은 morph map 없는 폴리모픽이라 Eloquent 의 morphTo 로 일괄 로드할 수 없다 —
 * 목록 경로는 hydrateTargets() 가 타입별로 나눠 한 번씩 조회해 관계에 꽂는다.
 */
class ReactionRepository implements ReactionRepositoryInterface
{
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;

    /**
     * {@inheritDoc}
     */
    public function findByUserAndTarget(int $userId, string $targetType, int $targetId): ?Reaction
    {
        return Reaction::query()
            ->where('user_id', $userId)
            ->byTarget($targetType, $targetId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function lockPostForReaction(int $postId): void
    {
        $this->lockTargetForReaction(ReactionService::TARGET_POST, $postId);
    }

    /**
     * {@inheritDoc}
     */
    public function lockTargetForReaction(string $targetType, int $targetId): void
    {
        if ($targetType === ReactionService::TARGET_COMMENT) {
            // 삭제된 대상에도 잠금이 걸려야 한다 — 마이페이지·관리자 해제는 소프트 삭제된
            // 댓글의 반응도 지우고 그 대상의 카운트를 다시 집계한다.
            Comment::query()->withTrashed()->lockForUpdate()->findOrFail($targetId);

            return;
        }

        Post::query()->withTrashed()->lockForUpdate()->findOrFail($targetId);
    }

    /**
     * {@inheritDoc}
     */
    public function upsert(int $userId, string $targetType, int $targetId, int $reactionTypeId, ?int $boardId): Reaction
    {
        return Reaction::updateOrCreate(
            [
                'user_id' => $userId,
                'target_type' => $targetType,
                'target_id' => $targetId,
            ],
            [
                'reaction_type_id' => $reactionTypeId,
                'board_id' => $boardId,
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Reaction $reaction): bool
    {
        return (bool) $reaction->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function recalculatePostReactionCounts(int $postId): array
    {
        return $this->recalculateReactionCounts(ReactionService::TARGET_POST, $postId);
    }

    /**
     * {@inheritDoc}
     */
    public function recalculateReactionCounts(string $targetType, int $targetId): array
    {
        /** @var Post|Comment $target */
        $target = $targetType === ReactionService::TARGET_COMMENT
            ? Comment::query()->withTrashed()->lockForUpdate()->findOrFail($targetId)
            : Post::query()->withTrashed()->lockForUpdate()->findOrFail($targetId);

        // 이전에 캐시에 등장했던 유형은 반응이 0건이 되어도 키 자체는 유지한다
        // (API 응답 계약 — 한 번 노출된 유형의 카운트는 0으로라도 항상 존재).
        $zeroed = array_fill_keys(array_keys($target->reaction_counts ?? []), 0);

        $actual = Reaction::query()
            ->byTarget($targetType, $targetId)
            ->selectRaw('reaction_type_id, COUNT(*) as total')
            ->groupBy('reaction_type_id')
            ->pluck('total', 'reaction_type_id')
            ->mapWithKeys(fn ($total, $typeId) => [(string) $typeId => (int) $total])
            ->toArray();

        // array_merge 는 숫자형 키(반응 유형 ID)를 재색인해 순서/키를 잃는다
        // (예: [1=>1] 이 [0=>1] 로 바뀌어 JSON 직렬화 시 객체 대신 리스트가 됨).
        // + 연산자는 키를 그대로 보존하면서 좌측을 우선하므로 실제 COUNT($actual)로
        // zeroed 를 덮어쓰려면 우측에 두어야 한다.
        $counts = $actual + $zeroed;

        $target->reaction_counts = $counts;
        // 반응은 읽는 사람의 행동이라 글·댓글의 수정 시각(사이트맵 lastmod 포함)을 올리지 않는다
        $target->timestamps = false;
        $target->save();

        return $counts;
    }

    /**
     * {@inheritDoc}
     */
    public function countByReactionType(int $reactionTypeId): int
    {
        return Reaction::query()->where('reaction_type_id', $reactionTypeId)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function getUserReactionTypeIdsByTargets(int $userId, string $targetType, array $targetIds): array
    {
        if ($targetIds === []) {
            return [];
        }

        return Reaction::query()
            ->where('user_id', $userId)
            ->where('target_type', $targetType)
            ->whereIn('target_id', $targetIds)
            ->pluck('reaction_type_id', 'target_id')
            ->map(fn ($typeId) => (int) $typeId)
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function hydrateTargets(Collection $reactions): void
    {
        if ($reactions->isEmpty()) {
            return;
        }

        $byType = $reactions->groupBy('target_type');

        $postIds = ($byType[ReactionService::TARGET_POST] ?? collect())->pluck('target_id')->unique()->all();
        $commentIds = ($byType[ReactionService::TARGET_COMMENT] ?? collect())->pluck('target_id')->unique()->all();

        // 삭제된 대상도 목록에는 "삭제된 게시글/댓글" 행으로 남아야 하므로 withTrashed 로 싣는다.
        $posts = $postIds === [] ? collect() : Post::query()
            ->withTrashed()
            ->with('user:id,name,status')
            ->whereIn('id', $postIds)
            ->get(['id', 'board_id', 'user_id', 'title', 'status', 'author_name', 'deleted_at'])
            ->keyBy('id');

        $comments = $commentIds === [] ? collect() : Comment::query()
            ->withTrashed()
            ->with('user:id,name,status')
            ->whereIn('id', $commentIds)
            ->get(['id', 'board_id', 'post_id', 'user_id', 'content', 'status', 'author_name', 'deleted_at'])
            ->keyBy('id');

        // 댓글 대상은 부모 글의 제목·삭제 여부가 필요하다 (목록이 "어느 글의 댓글"인지 보여준다).
        $parentPostIds = $comments->pluck('post_id')->unique()->all();
        $parentPosts = $parentPostIds === [] ? collect() : Post::query()
            ->withTrashed()
            ->whereIn('id', $parentPostIds)
            ->get(['id', 'board_id', 'title', 'status', 'deleted_at'])
            ->keyBy('id');

        foreach ($reactions as $reaction) {
            if ($reaction->target_type === ReactionService::TARGET_COMMENT) {
                $comment = $comments->get($reaction->target_id);
                $reaction->setRelation('comment', $comment);
                $reaction->setRelation('post', $comment === null ? null : $parentPosts->get($comment->post_id));

                continue;
            }

            $reaction->setRelation('post', $posts->get($reaction->target_id));
            $reaction->setRelation('comment', null);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function paginateByUser(int $userId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Reaction::query()
            ->where('user_id', $userId)
            ->with([
                'board:id,slug,name,is_active,use_reaction,use_comment_reaction',
                'reactionType.iconAttachment:id,hash,original_filename,mime_type,size',
            ]);

        $this->applyTargetTypeFilter($query, $filters['target_type'] ?? null);

        if (! empty($filters['reaction_type_id'])) {
            $query->where('reaction_type_id', (int) $filters['reaction_type_id']);
        }

        if (! empty($filters['board_slug'])) {
            $query->whereHas('board', fn (Builder $b) => $b->where('slug', $filters['board_slug']));
        }

        $this->applyTargetSearch(
            $query,
            (string) ($filters['search_field'] ?? 'title_content'),
            $filters['search'] ?? null,
            excludeDeletedTargets: true,
        );

        $direction = ($filters['sort'] ?? 'latest') === 'oldest' ? 'asc' : 'desc';

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            // 목록이 실제로 쓰는 컬럼만 — 반응 행은 좁아서 지연 조인까지는 필요 없고,
            // 마이페이지는 본인 행만 보므로 OFFSET 이 크게 자라지 않는다.
            ->paginate($perPage, ['id', 'user_id', 'target_type', 'target_id', 'reaction_type_id', 'board_id', 'created_at']);

        $this->hydrateTargets($paginator->getCollection());

        return $paginator;
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): Paginator
    {
        $table = (new Reaction)->getTable();
        $query = Reaction::query();

        if (! empty($filters['user_id'])) {
            $query->where("{$table}.user_id", (int) $filters['user_id']);
        }

        if (! empty($filters['board_id'])) {
            $query->where("{$table}.board_id", (int) $filters['board_id']);
        }

        if (! empty($filters['reaction_type_id'])) {
            $query->where("{$table}.reaction_type_id", (int) $filters['reaction_type_id']);
        }

        $this->applyTargetTypeFilter($query, $filters['target_type'] ?? null, $table);

        // 목록은 반응일을 보는 사람의 시간대로 그린다 — 같은 하루로 자른다.
        $this->applyViewerDateRangeFilter(
            $query,
            "{$table}.created_at",
            $filters['reacted_at_from'] ?? null,
            $filters['reacted_at_to'] ?? null
        );

        // 관리자는 삭제된 대상의 반응도 봐야 한다 (정리 대상을 찾는 화면이므로).
        $this->applyTargetSearch(
            $query,
            (string) ($filters['search_field'] ?? 'all'),
            $filters['search'] ?? null,
            excludeDeletedTargets: false,
        );

        $direction = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $paginator = $this->paginateWithDeferredJoin(
            query: $query,
            columns: ["{$table}.*"],
            sort: [['column' => 'created_at', 'direction' => $direction]],
            perPage: $perPage,
            relations: [
                'user:id,uuid,name,email,status',
                'board:id,slug,name,is_active,use_reaction,use_comment_reaction',
                'reactionType.iconAttachment:id,hash,original_filename,mime_type,size',
            ],
            resultCap: PaginationLimits::resultCap('admin.reactions'),
        );

        $this->hydrateTargets($paginator->getCollection());

        return $paginator;
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?Reaction
    {
        return Reaction::query()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteById(int $id): ?Reaction
    {
        $reaction = Reaction::query()->find($id);

        if ($reaction === null) {
            return null;
        }

        $reaction->delete();

        return $reaction;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteManyByIds(array $ids): EloquentCollection
    {
        $reactions = Reaction::query()
            ->whereIn('id', $ids)
            ->get(['id', 'user_id', 'target_type', 'target_id', 'reaction_type_id', 'board_id']);

        if ($reactions->isEmpty()) {
            return $reactions;
        }

        Reaction::query()->whereIn('id', $reactions->pluck('id')->all())->delete();

        return $reactions;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByIdAndUser(int $id, int $userId): ?Reaction
    {
        $reaction = Reaction::query()->where('id', $id)->where('user_id', $userId)->first();

        if ($reaction === null) {
            return null;
        }

        $reaction->delete();

        return $reaction;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteManyByIdsAndUser(array $ids, int $userId): EloquentCollection
    {
        $reactions = Reaction::query()
            ->whereIn('id', $ids)
            ->where('user_id', $userId)
            ->get(['id', 'user_id', 'target_type', 'target_id', 'reaction_type_id', 'board_id']);

        if ($reactions->isEmpty()) {
            return $reactions;
        }

        Reaction::query()->whereIn('id', $reactions->pluck('id')->all())->delete();

        return $reactions;
    }

    /**
     * {@inheritDoc}
     */
    public function summaryByUser(int $userId): array
    {
        $row = Reaction::query()
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN target_type = ? THEN 1 ELSE 0 END) as post_total', [ReactionService::TARGET_POST])
            ->selectRaw('SUM(CASE WHEN target_type = ? THEN 1 ELSE 0 END) as comment_total', [ReactionService::TARGET_COMMENT])
            ->selectRaw('MAX(created_at) as latest_at')
            ->first();

        return [
            'count' => (int) ($row->total ?? 0),
            'post_count' => (int) ($row->post_total ?? 0),
            'comment_count' => (int) ($row->comment_total ?? 0),
            'latest_reacted_at' => $row->latest_at ?? null,
        ];
    }

    /**
     * 대상 타입 필터를 적용합니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string|null  $targetType  post|comment|all (all·null 이면 미적용)
     * @param  string|null  $table  컬럼 접두에 쓸 테이블명 (조인 쿼리용)
     * @return void
     */
    private function applyTargetTypeFilter(Builder $query, ?string $targetType, ?string $table = null): void
    {
        if ($targetType === null || $targetType === '' || $targetType === 'all') {
            return;
        }

        $column = $table === null ? 'target_type' : "{$table}.target_type";

        $query->where($column, $targetType);
    }

    /**
     * 대상(게시글·댓글) 기준 검색 조건을 적용합니다.
     *
     * morph map 이 없어 whereHas 로는 타입을 구분할 수 없다 — 타입별 whereExists 서브쿼리를
     * OR 로 묶는다. 게시글은 제목·내용, 댓글은 내용만 검색 대상이다(댓글에는 제목이 없다).
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $field  검색 대상 필드
     * @param  string|null  $search  검색어
     * @param  bool  $excludeDeletedTargets  삭제된 대상을 결과에서 뺄지 여부 (마이페이지 true)
     * @return void
     */
    private function applyTargetSearch(Builder $query, string $field, ?string $search, bool $excludeDeletedTargets): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        $postsTable = (new Post)->getTable();
        $commentsTable = (new Comment)->getTable();
        $reactionsTable = (new Reaction)->getTable();

        $keyword = addcslashes(trim($search), '%_\\');
        $like = "%{$keyword}%";

        $searchesTitle = in_array($field, ['title', 'title_content', 'all', 'target_title'], true);
        $searchesContent = in_array($field, ['content', 'title_content', 'all', 'target_content'], true);

        $query->where(function (Builder $outer) use ($like, $field, $searchesTitle, $searchesContent, $excludeDeletedTargets, $postsTable, $commentsTable, $reactionsTable) {
            if ($searchesTitle || $searchesContent) {
                $outer->orWhereExists(function ($sub) use ($like, $searchesTitle, $searchesContent, $excludeDeletedTargets, $postsTable, $reactionsTable) {
                    $sub->select(DB::raw(1))
                        ->from($postsTable)
                        ->whereColumn("{$postsTable}.id", "{$reactionsTable}.target_id")
                        ->where("{$reactionsTable}.target_type", ReactionService::TARGET_POST);

                    if ($excludeDeletedTargets) {
                        $sub->whereNull("{$postsTable}.deleted_at");
                    }

                    $sub->where(function ($w) use ($like, $searchesTitle, $searchesContent, $postsTable) {
                        if ($searchesTitle) {
                            $w->orWhere("{$postsTable}.title", 'like', $like);
                        }
                        if ($searchesContent) {
                            $w->orWhere("{$postsTable}.content", 'like', $like);
                        }
                    });
                });
            }

            // 댓글은 본문만 있다 — 제목 전용 검색에서는 댓글 분기를 켜지 않는다.
            if ($searchesContent) {
                $outer->orWhereExists(function ($sub) use ($like, $excludeDeletedTargets, $commentsTable, $reactionsTable) {
                    $sub->select(DB::raw(1))
                        ->from($commentsTable)
                        ->whereColumn("{$commentsTable}.id", "{$reactionsTable}.target_id")
                        ->where("{$reactionsTable}.target_type", ReactionService::TARGET_COMMENT)
                        ->where("{$commentsTable}.content", 'like', $like);

                    if ($excludeDeletedTargets) {
                        $sub->whereNull("{$commentsTable}.deleted_at");
                    }
                });
            }

            if ($field === 'board_name' || $field === 'all') {
                $outer->orWhereHas('board', fn (Builder $b) => $b->where('name', 'like', $like));
            }

            if ($field === 'member' || $field === 'all') {
                $outer->orWhereHas('user', fn (Builder $u) => $u->where(
                    fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)
                ));
            }

            if ($field === 'author_name' || $field === 'all') {
                $outer->orWhereExists(function ($sub) use ($like, $postsTable, $reactionsTable) {
                    $sub->select(DB::raw(1))
                        ->from($postsTable)
                        ->whereColumn("{$postsTable}.id", "{$reactionsTable}.target_id")
                        ->where("{$reactionsTable}.target_type", ReactionService::TARGET_POST)
                        ->where("{$postsTable}.author_name", 'like', $like);
                });

                $outer->orWhereExists(function ($sub) use ($like, $commentsTable, $reactionsTable) {
                    $sub->select(DB::raw(1))
                        ->from($commentsTable)
                        ->whereColumn("{$commentsTable}.id", "{$reactionsTable}.target_id")
                        ->where("{$reactionsTable}.target_type", ReactionService::TARGET_COMMENT)
                        ->where("{$commentsTable}.author_name", 'like', $like);
                });
            }
        });
    }
}
