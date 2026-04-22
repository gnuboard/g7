<?php

namespace Modules\Sirsoft\Board\Repositories;

use App\Helpers\PermissionHelper;
use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 게시글 Repository
 *
 * 게시글 데이터 접근 계층을 담당합니다.
 */
class PostRepository implements PostRepositoryInterface
{
    use ChecksBoardPermission;
    use FormatsBoardDate;

    /**
     * PostRepository 생성자
     */
    public function __construct() {}

    /**
     * 게시판의 게시글 목록을 페이지네이션하여 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수 (일반 게시글 기준)
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @return Paginator 페이지네이션된 게시글 목록 (simplePaginate — COUNT 쿼리 제거)
     */
    public function paginate(string $slug, array $filters = [], int $perPage = 15, bool $withTrashed = false, ?Board $board = null): Paginator
    {
        $currentPage = $filters['page'] ?? request()->input('page', 1);

        // 목록 전용 컬럼: content(본문 HTML) 제외 → content_preview로 대체
        $listColumns = [
            'id', 'board_id', 'user_id', 'parent_id', 'category',
            'title', 'author_name', 'content_mode',
            'is_notice', 'is_secret', 'status', 'depth',
            'view_count', 'comments_count', 'replies_count', 'attachments_count',
            'trigger_type', 'ip_address', 'created_at', 'updated_at', 'deleted_at',
            DB::raw('SUBSTRING(content, 1, 200) as content_preview_raw'),
        ];

        // buildSortedPostList를 사용하여 페이지네이션된 목록 조회
        // attachments, board 제거 — 목록에서는 has_attachment(attachments_count) 사용, board는 Controller에서 전달
        return $this->buildSortedPostList(
            slug: $slug,
            columns: $listColumns,
            withTrashed: $withTrashed,
            relations: ['user', 'user.avatarAttachment', 'thumbnailAttachment'],
            withCount: [],
            filters: $filters,
            perPage: $perPage,
            currentPage: $currentPage,
            board: $board,
        );
    }

    /**
     * 쿼리에 필터를 적용합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  array  $filters  필터 조건
     */
    private function applyFilters($query, array $filters): void
    {
        // 검색
        if (! empty($filters['search'])) {
            $keyword = $this->escapeLikeKeyword($filters['search']);
            $searchField = $filters['search_field'] ?? 'all';

            $query->where(function ($q) use ($keyword, $searchField) {
                // 제목+내용 검색: FULLTEXT 활용 (all, title_content)
                if ($searchField === 'all' || $searchField === 'title_content') {
                    if (DatabaseFulltextEngine::supportsFulltext()) {
                        $q->orWhereRaw('MATCH(`title`, `content`) AGAINST(? IN BOOLEAN MODE)', [$keyword]);
                    } else {
                        $q->orWhere('title', 'like', "%{$keyword}%")
                            ->orWhere('content', 'like', "%{$keyword}%");
                    }
                }

                // 작성자 검색
                if ($searchField === 'all' || $searchField === 'author' || $searchField === 'author_name') {
                    $q->orWhere('author_name', 'like', "%{$keyword}%")
                        ->orWhereHas('user', function ($uq) use ($keyword) {
                            $uq->where('name', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        });
                }
            });
        }

        // 상태 필터
        if (! empty($filters['status'])) {
            if ($filters['status'] === 'secret') {
                // 비밀글 필터
                $query->where('is_secret', true);
            } else {
                // 일반 상태 필터 (published, blinded, deleted)
                $query->where('status', $filters['status']);
            }
        }

        // 분류 필터
        if (isset($filters['category']) && $filters['category'] !== '' && $filters['category'] !== null) {
            if ($filters['category'] === 'unclassified') {
                // 미분류: category가 NULL/빈 문자열이거나, 게시판 설정에 등록되지 않은 분류
                $boardCategories = $filters['board_categories'] ?? [];
                $query->where(function ($q) use ($boardCategories) {
                    $q->whereNull('category')->orWhere('category', '');
                    if (! empty($boardCategories)) {
                        $q->orWhereNotIn('category', $boardCategories);
                    }
                });
            } else {
                $query->where('category', $filters['category']);
            }
        }

        // 공지사항 필터
        if (isset($filters['is_notice'])) {
            $query->where('is_notice', $filters['is_notice']);
        }

        // 작성자 필터
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        // 작성일 필터 (시작일~종료일, 시작일~, ~종료일 모두 가능)
        if (! empty($filters['created_at_from']) && $filters['created_at_from'] !== '') {
            // 시작일 00:00:00부터 검색
            $query->where('created_at', '>=', $filters['created_at_from'].' 00:00:00');
        }

        if (! empty($filters['created_at_to']) && $filters['created_at_to'] !== '') {
            // 종료일 23:59:59까지 검색
            $query->where('created_at', '<=', $filters['created_at_to'].' 23:59:59');
        }
    }

    /**
     * 게시글을 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $data  게시글 생성 데이터
     * @return Post 생성된 게시글 모델
     */
    public function create(string $slug, array $data): Post
    {
        return Post::create($data);
    }

    /**
     * ID로 게시글을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     */
    public function find(string $slug, int $id): ?Post
    {
        $board = Board::where('slug', $slug)->first();

        return Post::withTrashed()->with(['user'])->where('board_id', $board?->id)->find($id);
    }

    /**
     * ID로 게시글을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(string $slug, int $id): Post
    {
        $board = Board::where('slug', $slug)->firstOrFail();

        return Post::withTrashed()->with(['user'])->where('board_id', $board->id)->findOrFail($id);
    }

    /**
     * 게시글을 수정합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  array  $data  수정할 데이터
     *
     * @throws ModelNotFoundException
     */
    public function update(string $slug, int $id, array $data): Post
    {
        $post = $this->findOrFail($slug, $id);
        $post->update($data);

        return $post->fresh();
    }

    /**
     * 게시글을 삭제합니다 (소프트 삭제).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     *
     * @throws ModelNotFoundException
     */
    public function delete(string $slug, int $id): bool
    {
        $post = $this->findOrFail($slug, $id);

        return $post->delete();
    }

    /**
     * 게시글을 영구 삭제합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     *
     * @throws ModelNotFoundException
     */
    public function forceDelete(string $slug, int $id): bool
    {
        $post = $this->findOrFail($slug, $id);

        return $post->forceDelete();
    }

    /**
     * 게시글 상태를 변경합니다 (블라인드/삭제/복원).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  string  $status  변경할 상태 (published/blinded/deleted)
     * @param  array  $actionLog  작업 이력 데이터
     *
     * @throws ModelNotFoundException
     */
    public function updateStatus(string $slug, int $id, string $status, array $actionLog, ?string $triggerType = null): Post
    {
        $post = $this->findOrFail($slug, $id);

        // 기존 작업 이력 가져오기
        $actionLogs = $post->action_logs ?? [];
        $actionLogs[] = $actionLog;

        $updateData = [
            'status' => $status,
            'action_logs' => $actionLogs,
        ];

        // trigger_type이 지정된 경우 함께 업데이트
        if ($triggerType !== null) {
            $updateData['trigger_type'] = $triggerType;
        }

        $post->update($updateData);

        // deleted → published 또는 deleted → blinded 변경 시 deleted_at 복원
        if ($status !== 'deleted' && $post->trashed()) {
            $post->restore();
        }

        $post->refresh();

        return $post;
    }

    /**
     * 조회수를 증가시킵니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @return int 증가된 조회수
     */
    public function incrementViewCount(string $slug, int $id): int
    {
        $board = Board::where('slug', $slug)->first();

        Post::where('board_id', $board?->id)
            ->where('id', $id)
            ->increment('view_count');

        $post = $this->find($slug, $id);

        return $post?->view_count ?? 0;
    }

    /**
     * 해당 게시판의 게시글이 공지글인지 경량 조회합니다.
     *
     * 존재하지 않으면 null을 반환합니다. trashed(`deleted_at`) 여부는 고려하지 않으며
     * 스코프/권한 체크를 수행하지 않습니다.
     *
     * @param  int  $id  게시글 ID
     * @param  int  $boardId  게시판 ID
     * @return bool|null 공지 여부 또는 미존재 시 null
     */
    public function isNotice(int $id, int $boardId): ?bool
    {
        $value = Post::withTrashed()
            ->where('id', $id)
            ->where('board_id', $boardId)
            ->value('is_notice');

        return $value === null ? null : (bool) $value;
    }

    /**
     * 신고 처리를 위한 게시글 상태를 일괄 업데이트합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  array  $updates  업데이트할 데이터 (status, trigger_type, deleted_at, action_log)
     * @return Post 수정된 게시글
     *
     * @throws ModelNotFoundException
     */
    public function updateStatusBulk(string $slug, int $id, array $updates): Post
    {
        $board = Board::where('slug', $slug)->firstOrFail();

        $post = Post::withTrashed()->where('board_id', $board->id)->findOrFail($id);

        // action_log가 있으면 기존 이력에 추가
        if (isset($updates['action_log'])) {
            $actionLogs = $post->action_logs ?? [];
            $actionLogs[] = $updates['action_log'];
            $updates['action_logs'] = $actionLogs;
            unset($updates['action_log']);
        }

        // trigger_type 컬럼에 저장 (action_log 내 trigger 값 사용)
        if (isset($updates['trigger_type'])) {
            // trigger_type은 그대로 update에 포함됨
        }

        // deleted_at 처리 (SoftDeletes)
        $shouldDelete = isset($updates['deleted_at']) && $updates['deleted_at'] !== null;
        $shouldRestore = isset($updates['deleted_at']) && $updates['deleted_at'] === null;

        // deleted_at은 update에서 제외 (별도 처리)
        unset($updates['deleted_at']);

        // 상태 및 기타 필드 업데이트
        $post->update($updates);

        // SoftDelete 처리
        if ($shouldDelete && ! $post->trashed()) {
            $post->delete();
        } elseif ($shouldRestore && $post->trashed()) {
            $post->restore();
        }

        return $post->fresh();
    }

    /**
     * ID로 게시글을 조회하며 댓글/첨부파일 카운트를 포함합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @return Post|null 게시글 모델 (카운트 포함)
     */
    public function findWithCounts(string $slug, int $id, ?int $boardId = null): ?Post
    {
        // boardId가 전달되면 Board 모델 재조회 없이 직접 사용
        if (! $boardId) {
            $board = Board::where('slug', $slug)->first();
            $boardId = $board?->id;
        }

        $post = Post::withTrashed()
            ->where('board_id', $boardId)
            ->with([
                'user',
                'user.avatarAttachment',
                'board',
                'parent' => function ($query) {
                    $query->withTrashed()
                        ->with('user');
                },
                'attachments' => function ($query) use ($boardId) {
                    $query->where('board_id', $boardId);
                },
            ])
            ->find($id);

        // 모든 하위 답글을 재귀적으로 로드하여 트리 구조로 설정
        if ($post) {
            $hasDeletePermission = $this->checkBoardPermission($slug, 'admin.control')
                || $this->checkBoardPermission($slug, 'admin.manage');
            // loadAllDescendantReplies는 board_id만 필요하므로 Board 모델 대신 조회된 board 사용
            $board = $board ?? $post->board;
            $allReplies = $this->loadAllDescendantReplies($post->id, $board, $hasDeletePermission);
            $post->setRelation('replies', $allReplies);
        }

        return $post;
    }

    /**
     * 특정 게시글의 모든 하위 답글을 재귀적으로 로드합니다.
     *
     * 직접 자식뿐 아니라 손자, 증손자 등 모든 후손 답글을 한 번의 쿼리로 가져온 후
     * 트리 구조(직접 자식만)로 필터링하여 반환합니다.
     *
     * @param  int  $postId  부모 게시글 ID
     * @param  Board|null  $board  게시판 모델
     * @param  bool  $withTrashed  삭제된 답글 포함 여부 (관리자 권한 시 true)
     * @return \Illuminate\Database\Eloquent\Collection 직접 자식 답글 (각 답글에 하위 replies 관계 설정됨)
     */
    private function loadAllDescendantReplies(int $postId, ?Board $board, bool $withTrashed = false): \Illuminate\Database\Eloquent\Collection
    {
        // 모든 하위 답글을 한 번에 가져오기 (재귀 쿼리 대신 반복 방식)
        $allReplies = collect();
        $parentIds = [$postId];

        while (! empty($parentIds)) {
            $query = Post::query()
                ->whereIn('parent_id', $parentIds)
                ->when($board, fn ($q) => $q->where('board_id', $board->id))
                ->with('user');

            if ($withTrashed) {
                $query->withTrashed();
            }

            $batch = $query->get();

            if ($batch->isEmpty()) {
                break;
            }

            $allReplies = $allReplies->merge($batch);
            $parentIds = $batch->pluck('id')->toArray();
        }

        // 트리 구조로 조합: 각 답글에 하위 replies 관계 설정
        $grouped = $allReplies->groupBy('parent_id');

        foreach ($allReplies as $reply) {
            $children = $grouped->get($reply->id, collect());
            $reply->setRelation('replies', $children);
        }

        // 직접 자식만 반환 (PostResource에서 재귀적으로 replies를 직렬화)
        return new \Illuminate\Database\Eloquent\Collection(
            $grouped->get($postId, collect())->all()
        );
    }

    /**
     * 전체 일반 게시글(원글) 수를 조회합니다.
     * 필터가 적용된 경우 필터 조건을 만족하는 일반 게시글 수를 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $filters  필터 조건
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @return int 일반 게시글 수 (답글, 공지 제외)
     */
    public function countNormalPosts(string $slug, array $filters = [], bool $withTrashed = false): int
    {
        $board = Board::where('slug', $slug)->first();

        // 권한 스코프 필터링용 permission identifier (Service에서 컨텍스트 기반으로 전달)
        $scopePermission = $filters['scope_permission'] ?? "sirsoft-board.{$slug}.admin.posts.read";
        unset($filters['scope_permission']);

        $query = Post::query()
            ->where('board_id', $board?->id)
            ->where('is_notice', false)  // 공지글 제외
            ->whereNull('parent_id');    // 원글만 (답글 제외)

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, $scopePermission);

        if ($withTrashed) {
            $query->withTrashed();
        }

        // 필터 적용
        $this->applyFilters($query, $filters);

        return $query->count();
    }

    /**
     * 이전/다음 게시글을 조회합니다.
     * buildSortedPostList 메서드를 사용하여 목록 정렬 방식과 동일하게 처리합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  현재 게시글 ID
     * @param  array  $filters  정렬 파라미터 (order_by, order_direction)
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부 (기본: false)
     * @return array{prev: Post|null, next: Post|null} 이전/다음 게시글
     */
    public function getAdjacentPosts(string $slug, int $id, array $filters = [], bool $withTrashed = false, ?int $boardId = null): array
    {
        // boardId가 전달되면 Board 모델 재조회 없이 직접 사용
        if (! $boardId) {
            $board = Board::where('slug', $slug)->first();
            if (! $board) {
                return ['prev' => null, 'next' => null];
            }
            $boardId = $board->id;
        }

        $category = $filters['category'] ?? null;
        $orderBy = $filters['order_by'] ?? 'id';
        $orderDirection = $filters['order_direction'] ?? 'desc';

        // Enum 객체를 문자열로 변환 (buildSortedPostList와 동일 패턴)
        if ($orderBy instanceof \BackedEnum) {
            $orderBy = $orderBy->value;
        }
        if ($orderDirection instanceof \BackedEnum) {
            $orderDirection = $orderDirection->value;
        }

        // Enum 값 → 실제 DB 컬럼 매핑 (author → author_name)
        $columnMapping = [
            'author' => 'author_name',
        ];
        if (isset($columnMapping[$orderBy])) {
            $orderBy = $columnMapping[$orderBy];
        }

        // 허용된 정렬 컬럼 화이트리스트 (SQL 인젝션 방지)
        $allowedColumns = ['id', 'view_count', 'created_at', 'title', 'author_name'];
        if (! in_array($orderBy, $allowedColumns)) {
            $orderBy = 'id';
        }
        $orderDirection = in_array(strtolower($orderDirection), ['asc', 'desc']) ? strtolower($orderDirection) : 'desc';

        $currentPost = Post::find($id, [$orderBy, 'id']);
        if ($currentPost === null) {
            return ['prev' => null, 'next' => null];
        }

        $currentValue = $currentPost->{$orderBy};

        // 기본 조건: 공지 제외, 원글만, 게시 상태
        $baseQuery = fn () => Post::query()
            ->where('board_id', $boardId)
            ->where('is_notice', false)
            ->whereNull('parent_id')
            ->where('status', PostStatus::Published->value)
            ->when(! $withTrashed, fn ($q) => $q->whereNull('deleted_at'))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->select(['id', 'title']);

        // 이전/다음 글 조회 (2단계: strict 비교 → tie-breaking)
        // OR 조건은 MySQL 옵티마이저가 인덱스를 사용하지 못하므로
        // strict 비교(< or >)를 먼저 시도하고, 결과가 없으면 동일 값 tie-breaking 쿼리 실행
        $prev = $this->findAdjacentPost(
            $baseQuery, $orderBy, $orderDirection, $currentValue, $id, 'prev'
        );

        $next = $this->findAdjacentPost(
            $baseQuery, $orderBy, $orderDirection, $currentValue, $id, 'next'
        );

        return ['prev' => $prev, 'next' => $next];
    }

    /**
     * 이전 또는 다음 게시글을 조회합니다. (인덱스 최적화)
     *
     * OR 조건은 MySQL 옵티마이저가 인덱스를 사용하지 못하므로,
     * strict 비교를 먼저 시도하고 결과가 없으면 동일 값 tie-breaking 쿼리를 실행합니다.
     *
     * @param  \Closure  $baseQuery  기본 쿼리 팩토리
     * @param  string  $orderBy  정렬 컬럼
     * @param  string  $orderDirection  정렬 방향 (asc/desc)
     * @param  mixed  $currentValue  현재 게시글의 정렬 값
     * @param  int  $id  현재 게시글 ID
     * @param  string  $direction  조회 방향 (prev/next)
     * @return Post|null 이전/다음 게시글
     */
    private function findAdjacentPost(\Closure $baseQuery, string $orderBy, string $orderDirection, mixed $currentValue, int $id, string $direction): ?Post
    {
        $isPrev = $direction === 'prev';

        // prev: 정렬 기준으로 현재 글보다 앞 → desc면 >, asc면 <
        // next: 정렬 기준으로 현재 글보다 뒤 → desc면 <, asc면 >
        $strictOp = match (true) {
            $isPrev && $orderDirection === 'desc' => '>',
            $isPrev && $orderDirection === 'asc' => '<',
            ! $isPrev && $orderDirection === 'desc' => '<',
            default => '>',
        };
        $sortDir = $isPrev
            ? ($orderDirection === 'desc' ? 'asc' : 'desc')
            : $orderDirection;
        // DESC 정렬: 목록은 ORDER BY col DESC, id DESC
        //   prev(위쪽) = 같은 값 내에서 id > current → idOp='>', idSort='asc'(가장 가까운 것)
        //   next(아래쪽) = 같은 값 내에서 id < current → idOp='<', idSort='desc'(가장 가까운 것)
        // ASC 정렬: 목록은 ORDER BY col ASC, id ASC
        //   prev(위쪽) = 같은 값 내에서 id < current → idOp='<', idSort='desc'
        //   next(아래쪽) = 같은 값 내에서 id > current → idOp='>', idSort='asc'
        $idOp = ($isPrev xor $orderDirection === 'asc') ? '>' : '<';
        $idSort = $idOp === '>' ? 'asc' : 'desc';

        // 1단계: 동일 정렬 값 내 tie-breaking (id 비교)
        // 동일 값 내의 글이 정렬상 더 가까우므로 먼저 확인
        $tieQuery = $baseQuery()
            ->where($orderBy, $currentValue)
            ->where('id', $idOp, $id)
            ->orderBy('id', $idSort);

        $result = $tieQuery->first();

        if ($result) {
            return $result;
        }

        // 2단계: 동일 값 내에 없으면 다른 정렬 값으로 이동
        if ($orderBy === 'created_at') {
            // created_at: 서브쿼리 MAX/MIN으로 정확한 값을 먼저 찾고 등호 조회
            // → 콜드 스타트(버퍼 풀 미적재)에서도 ~2ms (range scan 대비 100배 이상 빠름)
            $aggregateFunc = $strictOp === '<' ? 'MAX' : 'MIN';
            $subQuery = $baseQuery()
                ->select(DB::raw("{$aggregateFunc}(`{$orderBy}`)"))
                ->where($orderBy, $strictOp, $currentValue);

            return $baseQuery()
                ->where($orderBy, DB::raw("({$subQuery->toSql()})"))
                ->mergeBindings($subQuery->getQuery())
                ->orderBy('id', $idSort)
                ->first();
        }

        // 그 외 컬럼: strict 비교 (인덱스 range scan)
        return $baseQuery()
            ->where($orderBy, $strictOp, $currentValue)
            ->orderBy($orderBy, $sortDir)
            ->orderBy('id', $idSort)
            ->first();
    }

    /**
     * 목록 정렬 방식대로 게시글 리스트를 생성합니다.
     * (공지 + 원글 + 답글을 정렬된 순서로 반환)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $columns  조회할 컬럼 목록
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @param  array  $relations  Eager Load 관계
     * @param  array  $withCount  카운트 관계
     * @param  array  $filters  필터 조건 (검색, 상태, 분류 등)
     * @param  int|null  $perPage  페이지당 원글 수 (null이면 전체 조회)
     * @param  int  $currentPage  현재 페이지 번호
     * @return Collection|LengthAwarePaginator 정렬된 게시글 컬렉션 또는 페이지네이터
     */
    private function buildSortedPostList(
        string $slug,
        array $columns = ['*'],
        bool $withTrashed = false,
        array $relations = [],
        array $withCount = [],
        array $filters = [],
        ?int $perPage = null,
        int $currentPage = 1,
        ?Board $board = null
    ) {
        // board가 전달되지 않은 경우에만 DB 조회 (하위 호환 유지)
        if (! $board) {
            $board = Board::where('slug', $slug)->first();
        }
        $boardId = $board?->id;

        // Eager loading(with)의 관계에 board_id 조건을 명시적으로 바인딩
        // (모델 관계 정의에서 $this->board_id를 사용하면 Eager loading 시 null이 되는 문제 해결)
        $relations = $this->bindBoardIdToRelations($relations, $boardId);

        // 권한 스코프 필터링용 permission identifier (Service에서 컨텍스트 기반으로 전달)
        $postPermission = $filters['scope_permission'] ?? "sirsoft-board.{$slug}.admin.posts.read";
        unset($filters['scope_permission']);

        // 1단계: 공지글 조회 (첫 페이지에만 표시, 필터 미적용)
        $notices = collect([]);
        if ($currentPage == 1) {
            $noticeQuery = Post::query()
                ->where('board_id', $boardId)
                ->where('is_notice', true)
                ->whereNull('parent_id')
                ->orderBy('created_at', 'desc');

            // 권한 스코프 필터링
            PermissionHelper::applyPermissionScope($noticeQuery, $postPermission);

            // withTrashed를 사용하지 않으면 삭제되지 않은 것만 조회
            if ($withTrashed) {
                $noticeQuery->withTrashed();
            } else {
                $noticeQuery->whereNull('deleted_at');
            }

            if (! empty($relations)) {
                $noticeQuery->with($relations);
            }

            $notices = $noticeQuery->get($columns);
        }

        // 2단계: 원글 조회 (공지 제외)
        $parentQuery = Post::query()
            ->where('board_id', $boardId)
            ->where('is_notice', false)
            ->whereNull('parent_id');

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($parentQuery, $postPermission);

        // withTrashed를 사용하지 않으면 삭제되지 않은 것만 조회
        if ($withTrashed) {
            $parentQuery->withTrashed();
        } else {
            $parentQuery->whereNull('deleted_at');
        }

        // 필터 적용 (원글만 검색, 답글은 3단계에서 별도 필터링)
        $this->applyFilters($parentQuery, $filters);

        // 정렬 (order_by 파라미터 사용, 기본값: id)
        $orderBy = $filters['order_by'] ?? 'id';
        $orderDirection = $filters['order_direction'] ?? 'desc';

        // Enum 객체를 문자열로 변환
        if ($orderBy instanceof \BackedEnum) {
            $orderBy = $orderBy->value;
        }
        if ($orderDirection instanceof \BackedEnum) {
            $orderDirection = $orderDirection->value;
        }

        // 허용된 정렬 컬럼 목록 (보안을 위한 화이트리스트)
        $allowedOrderColumns = ['id', 'view_count', 'created_at', 'title', 'author_name'];

        // Enum 값 → 실제 DB 컬럼 매핑 (author → author_name)
        $columnMapping = [
            'author' => 'author_name',
        ];
        if (isset($columnMapping[$orderBy])) {
            $orderBy = $columnMapping[$orderBy];
        }

        if (! in_array($orderBy, $allowedOrderColumns)) {
            $orderBy = 'id'; // 기본값으로 폴백
        }

        // 정렬 방향 검증 (asc 또는 desc만 허용)
        $orderDirection = strtolower($orderDirection);
        if (! in_array($orderDirection, ['asc', 'desc'])) {
            $orderDirection = 'desc'; // 기본값으로 폴백
        }

        // 정렬 적용 (id를 2차 정렬로 추가 — 동일 값 내 순서를 결정론적으로 보장)
        // created_at은 초 단위라 실질적 중복이 드물지만, view_count/title/author_name은 중복이 많음
        $parentQuery->orderBy($orderBy, $orderDirection)->orderBy('id', $orderDirection);

        if (! empty($relations)) {
            $parentQuery->with($relations);
        }

        // 페이지네이션 여부에 따라 분기
        if ($perPage !== null) {
            // simplePaginate 사용 — COUNT(*) 쿼리 제거로 대량 데이터 성능 개선
            // total은 Service 캐시 카운트로 별도 제공
            $paginator = $parentQuery->simplePaginate($perPage, $columns, 'page', $currentPage);
            $parents = $paginator->getCollection();
        } else {
            // 전체 조회
            $parents = $parentQuery->get($columns);
            $paginator = null;
        }

        // 3단계: 모든 하위 답글 조회 (모든 depth 처리 — depth-1만이 아닌 depth-2+ 포함)
        $parentIds = $parents->pluck('id')->toArray();
        $allReplies = collect([]);

        if (! empty($parentIds)) {
            $currentLevelIds = $parentIds;

            while (! empty($currentLevelIds)) {
                $levelQuery = Post::query()
                    ->where('board_id', $boardId)
                    ->whereIn('parent_id', $currentLevelIds)
                    ->orderBy('id', 'asc');

                if ($withTrashed) {
                    $levelQuery->withTrashed();
                } else {
                    $levelQuery->whereNull('deleted_at');
                }

                if (! empty($relations)) {
                    $levelQuery->with($relations);
                }

                $levelReplies = $levelQuery->get($columns);

                if ($levelReplies->isEmpty()) {
                    break;
                }

                $allReplies = $allReplies->merge($levelReplies);
                $currentLevelIds = $levelReplies->pluck('id')->toArray();
            }
        }

        $replies = $allReplies;

        // 4단계: 병합 (원글 + 모든 하위 답글을 깊이 우선 순으로)
        $mergedItems = collect([]);

        $appendReplies = function (int $postId) use (&$appendReplies, &$mergedItems, $replies): void {
            $directReplies = $replies->where('parent_id', $postId)->sortBy('id');
            foreach ($directReplies as $reply) {
                $mergedItems->push($reply);
                $appendReplies($reply->id);
            }
        };

        foreach ($parents as $parent) {
            $mergedItems->push($parent);
            $appendReplies($parent->id);
        }

        // 5단계: 공지글을 맨 앞에 추가
        $finalItems = $notices->merge($mergedItems);

        // 페이지네이션 사용 시 paginator에 최종 컬렉션 설정
        if ($paginator !== null) {
            $paginator->setCollection($finalItems);

            return $paginator;
        }

        // 전체 조회 시 컬렉션 반환
        return $finalItems;
    }

    /**
     * 사용자의 게시판 활동 통계를 조회합니다.
     *
     * 작성한 게시글 수, 작성한 댓글 수, 총 조회수를 반환합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return array{total_posts: int, total_comments: int, total_views: int} 활동 통계
     */
    public function getUserActivityStats(int $userId): array
    {
        // 비활성 게시판 제외
        $inactiveBoardIds = $this->getInactiveBoardIds();

        // 쿼리 1: COUNT — idx_board_posts_user_activity 커버링 (Using index)
        $postsQuery = Post::where('user_id', $userId);
        if (! empty($inactiveBoardIds)) {
            $postsQuery->whereNotIn('board_id', $inactiveBoardIds);
        }
        $totalPosts = $postsQuery->count();

        // 쿼리 2: SUM(comments_count) + SUM(view_count) — idx_board_posts_user_board_stats 커버링
        // withTrashed: deleted_at 조건 제거로 커버링 인덱스(Using index) 활용 → 테이블 접근 없음
        // comments_count는 PostCountSyncListener가 정확히 동기화하므로 SUM으로 대체 (JOIN 제거)
        // 삭제 게시글 포함해도 실질적 차이 미미 (33건/5,632건)
        $statsQuery = Post::withTrashed()->where('user_id', $userId);
        if (! empty($inactiveBoardIds)) {
            $statsQuery->whereNotIn('board_id', $inactiveBoardIds);
        }
        $sums = $statsQuery->selectRaw('COALESCE(SUM(comments_count), 0) as total_comments, COALESCE(SUM(view_count), 0) as total_views')
            ->first();

        return [
            'total_posts' => $totalPosts,
            'total_comments' => (int) $sums->total_comments,
            'total_views' => (int) $sums->total_views,
        ];
    }

    /**
     * 사용자의 공개 게시글/댓글 통계를 조회합니다 (공개 프로필용).
     *
     * 기존 getUserActivityStats()와 다른 점:
     * - status='published' 조건 적용
     * - comments_count = 실제 작성한 댓글 수 (댓글 단 게시글 수가 아님)
     *
     * @param  int  $userId  사용자 ID
     * @return array{posts_count: int, comments_count: int} 공개 게시글/댓글 통계
     */
    public function getUserPublicStats(int $userId): array
    {
        // 단일 테이블 단일 쿼리
        $postsCount = Post::where('user_id', $userId)
            ->where('status', PostStatus::Published->value)
            ->count();

        $commentsCount = Comment::where('user_id', $userId)
            ->where('status', PostStatus::Published->value)
            ->count();

        return [
            'posts_count' => $postsCount,
            'comments_count' => $commentsCount,
        ];
    }

    /**
     * 사용자의 게시글 활동 목록을 조회합니다.
     *
     * 사용자가 작성한 게시글, 댓글을 단 게시글을 통합하여 반환합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 조건 (board_slug, search, activity_type, sort, is_public)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 게시글 활동 목록
     */
    public function getUserActivities(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $boardSlugFilter = $filters['board_slug'] ?? null;
        $search = $filters['search'] ?? null;
        $activityType = $filters['activity_type'] ?? 'authored';
        $sort = $filters['sort'] ?? 'latest'; // latest, oldest, views
        $isPublic = $filters['is_public'] ?? false; // 공개 프로필용 필터 (비밀글 제외, 공개 게시글만)
        $excludeBoardSlugs = $filters['exclude_board_slugs'] ?? [];

        // board_slug 필터용 board_id 조회
        $boardIdFilter = null;
        if ($boardSlugFilter) {
            $boardIdFilter = Board::where('slug', $boardSlugFilter)->value('id');
        }

        // exclude_board_slugs → board_id 목록으로 변환
        $excludeBoardIds = [];
        if (! empty($excludeBoardSlugs)) {
            $excludeBoardIds = Board::whereIn('slug', $excludeBoardSlugs)->pluck('id')->all();
        }

        // DB 레벨 정렬 컬럼 결정
        $orderColumn = match ($sort) {
            'views' => 'board_posts.view_count',
            'oldest' => 'board_posts.created_at',
            default => 'board_posts.created_at',
        };
        $orderDirection = $sort === 'oldest' ? 'asc' : 'desc';

        $cachedTotal = $filters['cached_total'] ?? null;

        if ($activityType === 'commented' && ! $isPublic) {
            return $this->getUserCommentedActivities($userId, $boardIdFilter, $excludeBoardIds, $search, $orderColumn, $orderDirection, $perPage, $cachedTotal);
        }

        // authored (기본값, 공개 프로필 포함)
        return $this->getUserAuthoredActivities($userId, $boardIdFilter, $excludeBoardIds, $search, $isPublic, $orderColumn, $orderDirection, $perPage, $cachedTotal);
    }

    /**
     * 사용자가 작성한 게시글 활동을 DB 레벨 페이지네이션으로 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  int|null  $boardIdFilter  게시판 ID 필터
     * @param  string|null  $search  검색 키워드
     * @param  bool  $isPublic  공개 프로필 여부 (비밀글 제외)
     * @param  string  $orderColumn  정렬 컬럼
     * @param  string  $orderDirection  정렬 방향
     * @param  int  $perPage  페이지당 항목 수
     */
    private function getUserAuthoredActivities(
        int $userId,
        ?int $boardIdFilter,
        array $excludeBoardIds,
        ?string $search,
        bool $isPublic,
        string $orderColumn,
        string $orderDirection,
        int $perPage,
        ?int $cachedTotal = null
    ): LengthAwarePaginator {
        // JOIN 대신 whereNotIn으로 비활성 게시판 제외 — idx_board_posts_user_created 인덱스 활용
        $inactiveBoardIds = $this->getInactiveBoardIds();
        $allExcludeIds = array_unique(array_merge($excludeBoardIds, $inactiveBoardIds));

        $query = Post::query()
            ->where('board_posts.user_id', $userId)
            ->with('board')
            ->orderBy($orderColumn, $orderDirection);

        if ($boardIdFilter) {
            $query->where('board_posts.board_id', $boardIdFilter);
        }

        if (! empty($allExcludeIds)) {
            $query->whereNotIn('board_posts.board_id', $allExcludeIds);
        }

        if ($isPublic) {
            $query->where('board_posts.status', PostStatus::Published->value)
                ->where('board_posts.is_secret', false);
        }

        if ($search) {
            $keyword = $this->escapeLikeKeyword($search);
            $query->where(function ($q) use ($keyword) {
                $q->where('board_posts.title', 'like', "%{$keyword}%")
                    ->orWhere('board_posts.content', 'like', "%{$keyword}%");
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', null, $cachedTotal);

        // paginate 후 10건에만 PHP 가공 적용 (N+1 아님)
        $paginator->through(function ($post) {
            return [
                'id' => $post->id,
                'board_slug' => $post->board?->slug,
                'board_name' => $post->board?->getLocalizedName() ?? '',
                'activity_type' => 'authored',
                'activity_count' => 0,
                'title' => $post->title,
                'is_secret' => (bool) $post->is_secret,
                'status' => $post->status?->value,
                'view_count' => $post->view_count,
                'comments_count' => (int) ($post->comments_count ?? 0),
                'created_at' => $this->formatCreatedAt($post->created_at),
                'created_at_formatted' => $this->formatCreatedAtFormat($post->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
                'content_plain' => ($post->content_mode ?? 'text') === 'html'
                    ? $this->stripHtmlToPlainText($post->content ?? '')
                    : ($post->content ?? ''),
            ];
        });

        return $paginator;
    }

    /**
     * 사용자가 댓글을 단 게시글 활동을 DB 레벨 페이지네이션으로 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  int|null  $boardIdFilter  게시판 ID 필터
     * @param  array  $excludeBoardIds  제외할 게시판 ID 목록
     * @param  string|null  $search  검색 키워드
     * @param  string  $orderColumn  정렬 컬럼
     * @param  string  $orderDirection  정렬 방향
     * @param  int  $perPage  페이지당 항목 수
     */
    private function getUserCommentedActivities(
        int $userId,
        ?int $boardIdFilter,
        array $excludeBoardIds,
        ?string $search,
        string $orderColumn,
        string $orderDirection,
        int $perPage,
        ?int $cachedTotal = null
    ): LengthAwarePaginator {
        // DB::raw() / whereRaw() 내부 raw SQL은 prefix 자동 적용이 안 되므로 명시적으로 처리
        $prefix = DB::getTablePrefix();
        $commentsTable = $prefix.'board_comments';
        $postsTable = $prefix.'board_posts';

        // 비활성 게시판 제외 — JOIN 없이 인덱스 활용
        $inactiveBoardIds = $this->getInactiveBoardIds();
        $allExcludeIds = array_unique(array_merge($excludeBoardIds, $inactiveBoardIds));

        $latestCommentSub = DB::table(DB::raw("{$commentsTable} as bc_outer"))
            ->selectRaw('bc_outer.post_id, bc_outer.content, bc_outer.created_at')
            ->whereRaw("bc_outer.id = (
                SELECT bc2.id FROM {$commentsTable} bc2
                WHERE bc2.post_id = bc_outer.post_id
                  AND bc2.user_id = ?
                  AND bc2.deleted_at IS NULL
                ORDER BY bc2.created_at DESC
                LIMIT 1
            ) AND bc_outer.user_id = ? AND bc_outer.deleted_at IS NULL", [$userId, $userId]);

        $query = Post::query()
            ->join(DB::raw("{$commentsTable} AS uc"), function ($join) use ($userId, $postsTable) {
                $join->on(DB::raw("{$postsTable}.id"), '=', DB::raw('uc.post_id'))
                    ->whereRaw('uc.user_id = ?', [$userId])
                    ->whereRaw('uc.deleted_at IS NULL');
            })
            ->leftJoinSub($latestCommentSub, 'lc', 'board_posts.id', '=', 'lc.post_id')
            ->select([
                'board_posts.*',
                DB::raw('COUNT(DISTINCT uc.id) as activity_count'),
                'board_posts.comments_count',
                'lc.content as comment_content',
                'lc.created_at as comment_created_at',
            ])
            ->with('board')
            ->groupBy('board_posts.id', 'board_posts.board_id', 'board_posts.comments_count', 'lc.content', 'lc.created_at')
            ->orderBy($orderColumn, $orderDirection);

        if ($boardIdFilter) {
            $query->where('board_posts.board_id', $boardIdFilter);
        }

        if (! empty($allExcludeIds)) {
            $query->whereNotIn('board_posts.board_id', $allExcludeIds);
        }

        if ($search) {
            $keyword = $this->escapeLikeKeyword($search);
            $query->where('uc.content', 'like', "%{$keyword}%");
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', null, $cachedTotal);

        // paginate 후 10건에만 PHP 가공 적용
        $paginator->through(function ($post) {
            return [
                'id' => $post->id,
                'board_slug' => $post->board?->slug,
                'board_name' => $post->board?->getLocalizedName() ?? '',
                'activity_type' => 'commented',
                'activity_count' => (int) ($post->activity_count ?? 0),
                'title' => $post->title,
                'is_secret' => (bool) $post->is_secret,
                'status' => $post->status?->value,
                'view_count' => $post->view_count,
                'comments_count' => (int) ($post->comments_count ?? 0),
                'created_at' => $this->formatCreatedAt($post->created_at),
                'created_at_formatted' => $this->formatCreatedAtFormat($post->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
                'content_plain' => ($post->content_mode ?? 'text') === 'html'
                    ? $this->stripHtmlToPlainText($post->content ?? '')
                    : ($post->content ?? ''),
            ];
        });

        return $paginator;
    }

    /**
     * 게시판에서 키워드로 게시글을 검색합니다.
     *
     * 공개 게시글(published, 비밀글 제외)만 대상으로 제목/본문 LIKE 검색을 수행합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $keyword  검색 키워드
     * @param  string  $orderBy  정렬 컬럼
     * @param  string  $direction  정렬 방향 (asc, desc)
     * @param  int  $limit  조회할 최대 항목 수
     * @return array{total: int, items: \Illuminate\Database\Eloquent\Collection}
     */
    public function searchByKeyword(string $slug, string $keyword, string $orderBy = 'created_at', string $direction = 'desc', int $limit = 10): array
    {
        $query = $this->buildPublicSearchQuery($slug, $keyword);
        $total = $query->count();

        $items = $query->with('user')
            ->orderBy($orderBy, $direction)
            ->limit($limit)
            ->get();

        return [
            'total' => $total,
            'items' => $items,
        ];
    }

    /**
     * 게시판에서 키워드와 일치하는 게시글 수를 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $keyword  검색 키워드
     * @return int 일치하는 게시글 수
     */
    public function countByKeyword(string $slug, string $keyword): int
    {
        return $this->buildPublicSearchQuery($slug, $keyword)->count();
    }

    /**
     * 여러 게시판에서 키워드로 게시글을 검색합니다 (단일 쿼리, DB 페이지네이션).
     *
     * @param  array  $boardIds  검색 대상 게시판 ID 목록
     * @param  string  $keyword  검색 키워드
     * @param  string  $orderBy  정렬 컬럼
     * @param  string  $direction  정렬 방향 (asc, desc)
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  페이지 번호
     * @return array{total: int, items: \Illuminate\Database\Eloquent\Collection}
     */
    public function searchAcrossBoards(array $boardIds, string $keyword, string $orderBy = 'created_at', string $direction = 'desc', int $perPage = 10, int $page = 1): array
    {
        $query = $this->buildPublicSearchQueryByIds($boardIds, $keyword);
        $total = $query->count();

        $items = (clone $query)
            ->with('user', 'board')
            ->orderBy($orderBy, $direction)
            ->forPage($page, $perPage)
            ->get();

        return [
            'total' => $total,
            'items' => $items,
        ];
    }

    /**
     * 여러 게시판에서 키워드와 일치하는 게시글 수를 조회합니다 (단일 쿼리).
     *
     * @param  array  $boardIds  검색 대상 게시판 ID 목록
     * @param  string  $keyword  검색 키워드
     */
    public function countAcrossBoards(array $boardIds, string $keyword): int
    {
        return $this->buildPublicSearchQueryByIds($boardIds, $keyword)->count();
    }

    /**
     * 공개 게시글 검색용 기본 쿼리를 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $keyword  검색 키워드
     * @return Builder
     */
    private function buildPublicSearchQuery(string $slug, string $keyword)
    {
        $board = Board::where('slug', $slug)->first();

        $query = Post::query()
            ->where('board_id', $board?->id)
            ->where('status', PostStatus::Published->value)
            ->where('is_secret', false);

        $this->applyKeywordSearch($query, $keyword);

        return $query;
    }

    /**
     * 여러 게시판 ID를 대상으로 공개 게시글 검색용 기본 쿼리를 생성합니다.
     *
     * @param  array  $boardIds  게시판 ID 목록
     * @param  string  $keyword  검색 키워드
     * @return Builder
     */
    private function buildPublicSearchQueryByIds(array $boardIds, string $keyword)
    {
        $query = Post::query()
            ->whereIn('board_id', $boardIds)
            ->where('status', PostStatus::Published->value)
            ->where('is_secret', false);

        $this->applyKeywordSearch($query, $keyword);

        return $query;
    }

    /**
     * 키워드 검색 조건을 쿼리에 적용합니다.
     *
     * FULLTEXT 인덱스가 지원되면 MATCH...AGAINST를, 아니면 LIKE fallback을 사용합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $keyword  검색 키워드
     */
    private function applyKeywordSearch(Builder $query, string $keyword): void
    {
        if (DatabaseFulltextEngine::supportsFulltext()) {
            $query->whereRaw('MATCH(`title`, `content`) AGAINST(? IN BOOLEAN MODE)', [$keyword]);
        } else {
            $escapedKeyword = $this->escapeLikeKeyword($keyword);
            $query->where(function ($q) use ($escapedKeyword) {
                $q->where('title', 'like', "%{$escapedKeyword}%")
                    ->orWhere('content', 'like', "%{$escapedKeyword}%");
            });
        }
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
        // MySQL LIKE 와일드카드 문자 이스케이프
        $keyword = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);

        return $keyword;
    }

    /**
     * HTML 콘텐츠를 일반 텍스트로 변환합니다.
     * 블록 요소(p, div, br, li 등)는 공백으로 치환하여 자연스러운 줄바꿈을 유지합니다.
     *
     * @param  string  $html  HTML 콘텐츠
     * @return string 일반 텍스트
     */
    private function stripHtmlToPlainText(string $html): string
    {
        // HTML 엔티티 디코딩
        $text = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        // 블록 요소 태그를 공백으로 치환 (줄바꿈 효과)
        $text = preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', ' ', $text);
        $text = preg_replace('/<br\s*\/?>/i', ' ', $text);

        // 나머지 HTML 태그 제거
        $text = strip_tags($text);

        // 연속된 공백을 하나로 정리
        $text = preg_replace('/\s+/', ' ', $text);

        // 앞뒤 공백 제거
        return trim($text);
    }

    /**
     * 게시판 ID와 게시글 ID로 게시글을 조회합니다 (삭제 포함).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $id  게시글 ID
     * @return Post|null 게시글 또는 null
     */
    public function findByBoardId(int $boardId, int $id): ?Post
    {
        return Post::query()
            ->where('board_id', $boardId)
            ->withTrashed()
            ->with(['user'])
            ->find($id);
    }

    /**
     * 게시판 ID 기준으로 게시글을 일괄 소프트 삭제합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 게시글 수
     */
    public function softDeleteByBoardId(int $boardId): int
    {
        return Post::where('board_id', $boardId)->delete();
    }

    /**
     * Eager loading 관계에 board_id 조건을 명시적으로 바인딩합니다.
     *
     * 모델 관계 정의에서 $this->board_id를 사용하면 Eager loading 시
     * null이 되는 문제를 해결하기 위해, 문자열 관계명을 클로저로 변환합니다.
     *
     * @param  array  $relations  Eager loading 관계 배열
     * @param  int|null  $boardId  게시판 ID
     * @return array board_id 조건이 바인딩된 관계 배열
     */
    private function bindBoardIdToRelations(array $relations, ?int $boardId): array
    {
        $boardIdRelations = ['comments', 'attachments', 'replies'];

        $result = [];
        foreach ($relations as $key => $value) {
            // 클로저가 이미 있는 경우 (예: 'comments' => function() {})는 그대로 유지
            if (is_string($key) && is_callable($value)) {
                $result[$key] = $value;

                continue;
            }

            // 문자열 관계명인 경우 board_id 조건 클로저로 변환
            if (is_string($value) && in_array($value, $boardIdRelations)) {
                $result[$value] = function ($query) use ($boardId) {
                    $query->where('board_id', $boardId);
                };

                continue;
            }

            // 그 외 (예: 'user')는 그대로 유지
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * ID로 게시글을 조회합니다 (게시판 슬러그 불필요, board 관계 포함).
     *
     * @param  int  $id  게시글 ID
     * @return Post|null 게시글 모델 (board 관계 포함) 또는 null
     */
    public function findWithBoard(int $id): ?Post
    {
        return Post::with('board')->find($id);
    }

    /**
     * ID 배열로 게시글 목록을 조회합니다 (board, user, attachments, replies 관계 포함).
     *
     * @param  array<int>  $ids  게시글 ID 배열
     * @return \Illuminate\Database\Eloquent\Collection<int, Post> 게시글 컬렉션
     */
    public function findByIdsWithRelations(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        return Post::whereIn('id', $ids)
            ->with(['board', 'user', 'attachments', 'replies'])
            ->get();
    }

    /**
     * 부모 게시글 ID로 첫 번째 자식(답변) 게시글을 조회합니다 (board 관계 포함).
     *
     * @param  int  $parentPostId  부모 게시글 ID
     * @return Post|null 첫 번째 자식 게시글 (board 관계 포함) 또는 null
     */
    public function findFirstReplyWithBoard(int $parentPostId): ?Post
    {
        return Post::with('board')
            ->where('parent_id', $parentPostId)
            ->oldest()
            ->first();
    }

    /**
     * 비활성 게시판 ID 목록을 조회합니다.
     *
     * boards 테이블은 소규모(~수십 건)이므로 단순 쿼리로 충분합니다.
     * JOIN 대신 whereNotIn 패턴에 사용하여 board_posts 인덱스 활용을 유도합니다.
     *
     * @return array<int> 비활성 게시판 ID 배열
     */
    private function getInactiveBoardIds(): array
    {
        return Board::where('is_active', false)->pluck('id')->all();
    }
}
