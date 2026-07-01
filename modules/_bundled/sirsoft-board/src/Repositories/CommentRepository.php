<?php

namespace Modules\Sirsoft\Board\Repositories;

use App\Helpers\PermissionHelper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 댓글 Repository
 *
 * 댓글 데이터 접근 계층을 담당합니다.
 */
class CommentRepository implements CommentRepositoryInterface
{
    use FormatsBoardDate;

    /**
     * 특정 게시글의 댓글 목록을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  bool  $withTrashed  삭제된 댓글 포함 여부 (기본값: false)
     * @param  string  $orderDirection  정렬 방향 (ASC 또는 DESC, 기본값: DESC)
     * @param  string|null  $scopePermission  권한 스코프 식별자 (Service에서 컨텍스트 기반 전달)
     * @param  int|null  $boardId  게시판 ID (전달 시 Board 재조회 생략)
     * @return Collection 부모-자식 관계로 정렬된 댓글 컬렉션
     */
    public function getByPostId(string $slug, int $postId, bool $withTrashed = false, string $orderDirection = 'DESC', ?string $scopePermission = null, ?int $boardId = null): Collection
    {
        // boardId가 전달되면 Board 모델 재조회 없이 직접 사용
        $resolvedBoardId = $boardId ?? Board::where('slug', $slug)->first()?->id;

        // 정렬 방향 정규화 (대소문자 무관하게 처리)
        $orderDirection = strtoupper($orderDirection) === 'ASC' ? 'asc' : 'desc';

        $query = Comment::query()
            ->where('board_id', $resolvedBoardId)
            ->with(['user', 'user.avatarAttachment', 'parent'])
            ->where('post_id', $postId);

        // 권한 스코프 필터링 (Service에서 컨텍스트 기반으로 전달)
        $permission = $scopePermission ?? "sirsoft-board.{$slug}.admin.comments.read";
        PermissionHelper::applyPermissionScope($query, $permission);

        // 삭제된 댓글 포함 여부
        if ($withTrashed) {
            // 토글 ON(관리자/매니저): 사용자 직접 삭제분 포함 전부 노출
            $query->withTrashed();
        } else {
            // 토글 OFF: 게시글 삭제로 함께 숨겨진(cascade) 댓글은 사용자가 지운 것이 아니므로
            // 항상 노출한다 (살아있는 것 + cascade). 사용자 직접 삭제분(user 등)은 제외.
            $query->withTrashed()
                ->where(function ($q) {
                    $q->whereNull('deleted_at')
                        ->orWhere('trigger_type', TriggerType::Cascade->value);
                });
        }

        $comments = $query->orderBy('created_at', $orderDirection)->get();

        // 일반 조회(withTrashed=false)에서 부모가 삭제되어 빠진 경우,
        // 살아있는 자식이 트리에서 통째로 누락된다.
        // 살아있는 자식이 있는 삭제 부모만 tombstone 으로 복구해 트리를 유지한다.
        // withTrashed=true(manager 토글 ON)는 이미 삭제 부모를 모두 포함하므로 건너뛴다.
        if (! $withTrashed && $resolvedBoardId) {
            $comments = $this->restoreTombstoneParents($comments, $resolvedBoardId, $postId);
        }

        // 부모-자식 관계로 정렬 (부모 댓글 다음에 답글이 오도록)
        $sorted = $this->sortByParentChild($comments, $orderDirection);

        // replies_count를 전체 하위 자손 수로 재계산
        $this->recalculateDescendantCounts($sorted);

        return $sorted;
    }

    /**
     * 살아있는 자식이 있으나 부모가 삭제되어 결과셋에서 빠진 경우,
     * 해당 삭제 부모를 tombstone 노드로 복구해 컬렉션에 추가합니다.
     *
     * 고아 부모 ID 를 모아 1회 IN 조회로 가져오며(N+1 회피), 복구 대상이 없으면
     * 추가 쿼리 없이 원본 컬렉션을 그대로 반환합니다.
     * 자식 없는 삭제 부모는 고아 자식이 존재하지 않으므로 자연히 제외됩니다.
     *
     * @param  Collection  $comments  현재 조회된(삭제 부모 제외) 댓글 컬렉션
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @return Collection tombstone 부모가 보강된 댓글 컬렉션
     */
    private function restoreTombstoneParents(Collection $comments, int $boardId, int $postId): Collection
    {
        $presentIds = $comments->pluck('id')->all();

        // 부모가 결과셋에 없는 자식(고아)의 parent_id 수집
        $orphanParentIds = $comments
            ->filter(fn (Comment $c) => $c->parent_id !== null && ! in_array($c->parent_id, $presentIds, true))
            ->pluck('parent_id')
            ->unique()
            ->values()
            ->all();

        if (empty($orphanParentIds)) {
            return $comments;
        }

        // 고아의 조상 체인을 따라 올라가며 누락된 삭제 부모를 모두 복구
        // (다단계: 부모·자식이 연쇄 삭제되고 손자만 살아있는 경우 포함)
        $restored = collect();
        $knownIds = $presentIds;
        $pendingIds = $orphanParentIds;

        while (! empty($pendingIds)) {
            $tombstones = Comment::query()
                ->where('board_id', $boardId)
                ->where('post_id', $postId)
                ->withTrashed()
                ->with(['user', 'user.avatarAttachment', 'parent'])
                ->whereIn('id', $pendingIds)
                ->get();

            if ($tombstones->isEmpty()) {
                break;
            }

            $restored = $restored->merge($tombstones);
            $knownIds = array_merge($knownIds, $tombstones->pluck('id')->all());

            // 복구된 tombstone 의 부모도 결과셋에 없으면 다음 라운드에서 복구
            $pendingIds = $tombstones
                ->filter(fn (Comment $c) => $c->parent_id !== null && ! in_array($c->parent_id, $knownIds, true))
                ->pluck('parent_id')
                ->unique()
                ->values()
                ->all();
        }

        return $comments->merge($restored);
    }

    /**
     * 댓글 목록을 부모-자식 관계로 정렬합니다.
     *
     * 부모 댓글 바로 다음에 해당 부모의 모든 답글이 오도록 정렬합니다.
     * 각 그룹(부모 및 답글) 내에서는 생성 시간순으로 유지됩니다.
     *
     * @param  Collection  $comments  정렬할 댓글 컬렉션
     * @param  string  $orderDirection  정렬 방향 (asc 또는 desc)
     * @return Collection 정렬된 댓글 컬렉션
     */
    private function sortByParentChild(Collection $comments, string $orderDirection = 'desc'): Collection
    {
        $result = [];
        $grouped = $comments->groupBy('parent_id');

        // parent_id가 null인 최상위 댓글들을 먼저 처리
        foreach ($grouped->get(null, collect()) as $parent) {
            $result[] = $parent;

            // 해당 부모의 답글들을 바로 다음에 추가
            // 답글은 항상 오름차순 (오래된 순)으로 표시하여 대화 흐름 유지
            $this->addRepliesRecursively($result, $grouped, $parent->id);
        }

        // Eloquent Collection으로 변환하여 모델 속성 유지
        return $comments->make($result);
    }

    /**
     * 재귀적으로 답글들을 결과 배열에 추가합니다.
     *
     * @param  array  &$result  결과 배열 (참조)
     * @param  Collection  $grouped  parent_id로 그룹화된 댓글 컬렉션
     * @param  int  $parentId  부모 댓글 ID
     */
    private function addRepliesRecursively(array &$result, Collection $grouped, int $parentId): void
    {
        // 해당 parent_id를 가진 답글들 가져오기
        $replies = $grouped->get($parentId, collect());

        foreach ($replies as $reply) {
            $result[] = $reply;

            // 답글의 답글도 재귀적으로 추가
            $this->addRepliesRecursively($result, $grouped, $reply->id);
        }
    }

    /**
     * replies_count를 전체 하위 자손 수로 재계산합니다.
     *
     * withCount('replies')는 직접 자식만 카운트하므로,
     * 모든 하위 자손(depth 2+)을 포함한 전체 답글 수로 재계산합니다.
     *
     * @param  Collection  $comments  정렬된 댓글 컬렉션
     */
    private function recalculateDescendantCounts(Collection $comments): void
    {
        $grouped = $comments->groupBy('parent_id');

        foreach ($comments as $comment) {
            $comment->replies_count = $this->countDescendants($grouped, $comment->id);
        }
    }

    /**
     * 재귀적으로 모든 하위 자손 수를 계산합니다.
     *
     * tombstone(삭제) 댓글은 트리 구조 유지를 위해 노드로는 남기지만,
     * "답글 N" 집계 모수에서는 제외한다(PO 확정). 단, 삭제 댓글의
     * 살아있는 하위 자손은 계속 카운트해 누락되지 않게 한다.
     *
     * @param  Collection  $grouped  parent_id로 그룹화된 댓글 컬렉션
     * @param  int  $parentId  부모 댓글 ID
     * @return int 전체 하위 자손 수 (tombstone 제외)
     */
    private function countDescendants(Collection $grouped, int $parentId): int
    {
        $children = $grouped->get($parentId, collect());

        // 삭제되지 않은 자식만 카운트 (tombstone 은 모수 제외)
        $count = $children->filter(fn (Comment $child) => $child->deleted_at === null)->count();

        foreach ($children as $child) {
            $count += $this->countDescendants($grouped, $child->id);
        }

        return $count;
    }

    /**
     * 댓글을 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $data  댓글 생성 데이터
     * @return Comment 생성된 댓글 모델
     */
    public function create(string $slug, array $data): Comment
    {
        return Comment::create($data);
    }

    /**
     * ID로 댓글을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @return Comment|null 댓글 또는 null
     */
    public function find(string $slug, int $id): ?Comment
    {
        $board = Board::where('slug', $slug)->first();

        return Comment::query()
            ->where('board_id', $board?->id)
            ->withTrashed()
            ->with(['user'])
            ->find($id);
    }

    /**
     * ID로 댓글을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @return Comment 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(string $slug, int $id): Comment
    {
        $board = Board::where('slug', $slug)->firstOrFail();

        return Comment::query()
            ->where('board_id', $board->id)
            ->withTrashed()
            ->with(['user'])
            ->findOrFail($id);
    }

    /**
     * 댓글을 수정합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  array  $data  수정할 데이터
     * @return Comment 수정된 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function update(string $slug, int $id, array $data): Comment
    {
        $comment = $this->findOrFail($slug, $id);
        $comment->update($data);
        $comment->refresh();

        return $comment;
    }

    /**
     * 댓글을 삭제합니다 (소프트 삭제).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function delete(string $slug, int $id): bool
    {
        $comment = $this->findOrFail($slug, $id);

        return $comment->delete();
    }

    /**
     * 댓글을 영구 삭제합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function forceDelete(string $slug, int $id): bool
    {
        $comment = $this->findOrFail($slug, $id);

        return $comment->forceDelete();
    }

    /**
     * 댓글 상태를 변경합니다 (블라인드/삭제/복원).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  string  $status  변경할 상태 (published/blinded/deleted)
     * @param  array  $actionLog  작업 이력 데이터
     * @param  string|null  $triggerType  트리거 유형 (admin/user/report 등, 지정 시 함께 갱신)
     * @return Comment 수정된 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function updateStatus(string $slug, int $id, string $status, array $actionLog, ?string $triggerType = null): Comment
    {
        $comment = $this->findOrFail($slug, $id);

        // 기존 작업 이력 가져오기
        $actionLogs = $comment->action_logs ?? [];
        $actionLogs[] = $actionLog;

        $updateData = [
            'status' => $status,
            'action_logs' => $actionLogs,
        ];

        // trigger_type이 지정된 경우 함께 업데이트
        if ($triggerType !== null) {
            $updateData['trigger_type'] = $triggerType;
        }

        $comment->update($updateData);

        // deleted → published 또는 deleted → blinded 변경 시 deleted_at 복원
        if ($status !== 'deleted' && $comment->trashed()) {
            $comment->restore();
        }

        $comment->refresh();

        return $comment;
    }

    /**
     * 신고 처리를 위한 댓글 상태를 일괄 업데이트합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  array  $updates  업데이트할 데이터 (status, trigger_type, deleted_at, action_log)
     * @return Comment 수정된 댓글
     *
     * @throws ModelNotFoundException
     */
    public function updateStatusBulk(string $slug, int $id, array $updates): Comment
    {
        $board = Board::where('slug', $slug)->firstOrFail();

        $comment = Comment::query()
            ->where('board_id', $board->id)
            ->withTrashed()
            ->findOrFail($id);

        // action_log가 있으면 기존 이력에 추가
        if (isset($updates['action_log'])) {
            $actionLogs = $comment->action_logs ?? [];
            $actionLogs[] = $updates['action_log'];
            $updates['action_logs'] = $actionLogs;
            unset($updates['action_log']);
        }

        // deleted_at 처리 (SoftDeletes)
        $shouldDelete = isset($updates['deleted_at']) && $updates['deleted_at'] !== null;
        $shouldRestore = isset($updates['deleted_at']) && $updates['deleted_at'] === null;

        // deleted_at은 update에서 제외 (별도 처리)
        unset($updates['deleted_at']);

        // 상태 및 기타 필드 업데이트
        $comment->update($updates);

        // SoftDelete 처리
        if ($shouldDelete && ! $comment->trashed()) {
            $comment->delete();
        } elseif ($shouldRestore && $comment->trashed()) {
            $comment->restore();
        }

        return $comment->fresh();
    }

    /**
     * 게시판 ID와 댓글 ID로 댓글을 조회합니다 (삭제 포함).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $id  댓글 ID
     * @return Comment|null 댓글 또는 null
     */
    public function findByBoardId(int $boardId, int $id): ?Comment
    {
        return Comment::query()
            ->where('board_id', $boardId)
            ->withTrashed()
            ->with(['user'])
            ->find($id);
    }

    /**
     * 게시판 ID 기준으로 댓글을 일괄 영구 삭제합니다.
     *
     * 게시판 영구 삭제(deleteBoard) 시 사용합니다. 소프트 삭제와 달리
     * deleted_at 마킹이 아니라 레코드를 물리적으로 제거합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 댓글 수
     */
    public function forceDeleteByBoardId(int $boardId): int
    {
        return Comment::where('board_id', $boardId)->forceDelete();
    }

    /**
     * 게시판 ID 기준으로 댓글을 일괄 소프트 삭제합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 댓글 수
     */
    public function softDeleteByBoardId(int $boardId): int
    {
        return Comment::where('board_id', $boardId)->delete();
    }

    /**
     * 게시글 ID 기준으로 살아있는 댓글을 cascade 로 일괄 소프트 삭제합니다.
     *
     * 게시글 삭제 연쇄로 지워졌음을 trigger_type='cascade' 로 마킹합니다.
     * 이미 삭제된(trashed) 댓글은 Eloquent 기본 스코프가 제외하므로,
     * 사용자가 먼저 지운 댓글(trigger_type='user')은 영향을 받지 않습니다.
     * trigger_type 마킹과 deleted_at 을 단일 쿼리로 처리해 정합성을 보장합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 삭제된 댓글 수
     */
    public function softDeleteByPostId(string $slug, int $postId): int
    {
        $board = Board::where('slug', $slug)->first();

        return Comment::where('board_id', $board?->id)
            ->where('post_id', $postId)
            ->update([
                'trigger_type' => TriggerType::Cascade->value,
                'deleted_at' => now(),
            ]);
    }

    /**
     * 게시글 ID 기준으로 cascade 로 지워진 댓글만 복원합니다.
     *
     * 게시글 복원 시, 게시글 삭제 연쇄로 지워진(trigger_type='cascade') 댓글만
     * 되살립니다. 사용자가 직접 지운(trigger_type='user') 댓글은 trashed 로 유지됩니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 복원된 댓글 수
     */
    public function restoreCascadedByPostId(string $slug, int $postId): int
    {
        $board = Board::where('slug', $slug)->first();

        return Comment::onlyTrashed()
            ->where('board_id', $board?->id)
            ->where('post_id', $postId)
            ->where('trigger_type', TriggerType::Cascade->value)
            ->update([
                'deleted_at' => null,
            ]);
    }

    /**
     * 사용자가 작성한 댓글 목록을 페이지네이션하여 조회합니다.
     *
     * 댓글과 연결된 게시글(post) 및 게시판(board) 정보를 함께 로드합니다.
     * 삭제된 댓글은 제외합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 조건 (board_slug, search, sort)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 댓글 목록
     */
    public function getUserComments(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $boardSlugFilter = $filters['board_slug'] ?? null;
        $search = $filters['search'] ?? null;
        $sort = $filters['sort'] ?? 'latest';
        $cachedTotal = $filters['cached_total'] ?? null;

        $orderDirection = $sort === 'oldest' ? 'asc' : 'desc';

        // 비활성 게시판 제외 — JOIN 대신 whereNotIn으로 인덱스 활용
        $inactiveBoardIds = Board::where('is_active', false)->pluck('id')->all();

        $query = Comment::query()
            ->select('board_comments.*')
            ->where('board_comments.user_id', $userId)
            // 삭제된 게시글 제외 — whereExists로 JOIN 대체
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('board_posts')
                    ->whereColumn('board_posts.id', 'board_comments.post_id')
                    ->whereNull('board_posts.deleted_at');
            })
            ->with(['post.board'])
            ->orderBy('board_comments.created_at', $orderDirection);

        // 비활성 게시판 제외
        if (! empty($inactiveBoardIds)) {
            $query->whereNotIn('board_comments.board_id', $inactiveBoardIds);
        }

        // 게시판 필터: board_slug → board_id 조회
        if ($boardSlugFilter) {
            $boardId = Board::where('slug', $boardSlugFilter)->value('id');
            if ($boardId) {
                $query->where('board_comments.board_id', $boardId);
            }
        }

        // 검색 (댓글 내용)
        if ($search) {
            $keyword = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where('board_comments.content', 'like', $keyword);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', null, $cachedTotal);

        // paginate 후 현재 페이지 항목에만 PHP 가공 적용
        $paginator->through(function (Comment $comment) {
            $post = $comment->post;
            $board = $post?->board;

            return [
                'id' => $comment->id,
                'board_slug' => $board?->slug ?? '',
                'board_name' => $board?->getLocalizedName() ?? '',
                'post_title' => $post?->title ?? '',
                'post_id_val' => $post?->id,
                'content' => $comment->content,
                'created_at' => $this->formatCreatedAt($comment->created_at),
                'created_at_formatted' => $this->formatCreatedAtFormat($comment->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
            ];
        });

        return $paginator;
    }

    /**
     * 부모 댓글의 replies_count 컬럼을 활성 대댓글 수로 재계산해 갱신합니다.
     *
     * @param  int  $parentCommentId  부모 댓글 ID
     * @return int 갱신된 카운트 값
     */
    public function recalculateRepliesCount(int $parentCommentId): int
    {
        $count = Comment::where('parent_id', $parentCommentId)->whereNull('deleted_at')->count();
        Comment::where('id', $parentCommentId)->update(['replies_count' => $count]);

        return $count;
    }

    /**
     * 특정 날짜에 작성된 전체 게시판의 댓글 수를 조회합니다 (대시보드 집계용).
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return int 해당 날짜 작성 댓글 수
     */
    public function countCreatedOnDate(string $date): int
    {
        $start = CarbonImmutable::parse($date)->startOfDay();
        $end = $start->addDay();

        return Comment::query()
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
    }
}
