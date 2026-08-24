<?php

namespace Modules\Sirsoft\Board\Repositories;

use App\Enums\PermissionType;
use App\Helpers\PermissionHelper;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Search\KeywordSearch;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\KeysetPaginator;
use App\Support\Query\PaginationLimits;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Attachment;
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
    use PaginatesWithDeferredJoin;

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
     * @param  Board|null  $board  게시판 모델 (이미 조회된 경우 전달하여 중복 쿼리 방지)
     * @return Paginator 페이지네이션된 게시글 목록 (simplePaginate — COUNT 쿼리 제거)
     */
    public function paginate(string $slug, array $filters = [], int $perPage = 15, bool $withTrashed = false, ?Board $board = null): Paginator
    {
        $currentPage = $filters['page'] ?? request()->input('page', 1);

        // 목록 전용 컬럼: content(본문 HTML) 제외 → content_preview로 대체
        $listColumns = [
            'id', 'board_id', 'user_id', 'parent_id', 'category',
            'title', 'author_name', 'content_mode', 'content_thumbnail_url',
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
            // FULLTEXT 와 LIKE 는 이스케이프 규칙이 다르다. LIKE 용으로 이스케이프한 문자열을
            // MATCH 에 그대로 넘기면 백슬래시가 검색어의 일부로 들어간다.
            $rawKeyword = (string) $filters['search'];
            $likeKeyword = $this->escapeLikeKeyword($rawKeyword);
            $searchField = $filters['search_field'] ?? 'all';

            $query->where(function ($q) use ($rawKeyword, $likeKeyword, $searchField) {
                // 제목+내용 검색: FULLTEXT 활용 (all, title_content)
                // 코어 헬퍼를 거쳐야 BOOLEAN MODE 연산자(+ - * " 등) 입력이 500 이 되지 않는다.
                if ($searchField === 'all' || $searchField === 'title_content') {
                    KeywordSearch::apply($q, ['title', 'content'], $rawKeyword, 'or');
                }

                // 작성자 검색
                if ($searchField === 'all' || $searchField === 'author' || $searchField === 'author_name') {
                    $q->orWhere('author_name', 'like', "%{$likeKeyword}%")
                        ->orWhereHas('user', function ($uq) use ($likeKeyword) {
                            $uq->where('name', 'like', "%{$likeKeyword}%")
                                ->orWhere('email', 'like', "%{$likeKeyword}%");
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
     * @return Post|null 게시글 모델 (없으면 null)
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
     * @return Post 게시글 모델
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
     * @return Post 수정된 게시글 모델
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
     * @return bool 삭제 성공 여부
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
     * @return bool 영구 삭제 성공 여부
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
     * @param  string|null  $triggerType  트리거 유형 (admin, report 등)
     * @return Post 상태가 변경된 게시글 모델
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
     * @param  int|null  $boardId  게시판 ID (전달 시 슬러그 재조회 생략)
     * @return int 증가된 조회수
     */
    public function incrementViewCount(string $slug, int $id, ?int $boardId = null): int
    {
        // 상세 화면은 이미 게시판을 조회한 뒤 여기로 온다. 슬러그로 다시 찾으면 같은 요청에서
        // 게시판을 두 번 읽는다 — 호출자가 알고 있으면 그대로 받는다.
        if ($boardId === null) {
            $boardId = Board::where('slug', $slug)->value('id');
        }

        Post::where('board_id', $boardId)
            ->where('id', $id)
            ->increment('view_count');

        // 증가된 값만 필요하다. find() 는 게시판을 또 찾고 user 관계까지 적재하므로
        // 조회수 하나 읽자고 쓰기에는 과하다.
        return (int) (Post::withTrashed()
            ->where('board_id', $boardId)
            ->where('id', $id)
            ->value('view_count') ?? 0);
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
     * navigation 판별용 게시글 메타(카테고리·부모 ID)를 경량 조회합니다.
     *
     * isNotice 와 동일하게 trashed 포함, 스코프/권한 체크 없이 조회합니다.
     *
     * @param  int  $id  게시글 ID
     * @param  int  $boardId  게시판 ID
     * @return array{category: string|null, parent_id: int|null}|null 메타 또는 미존재 시 null
     */
    public function getNavigationMeta(int $id, int $boardId): ?array
    {
        $row = Post::withTrashed()
            ->where('id', $id)
            ->where('board_id', $boardId)
            ->first(['category', 'parent_id']);

        if ($row === null) {
            return null;
        }

        return [
            'category' => $row->category,
            'parent_id' => $row->parent_id !== null ? (int) $row->parent_id : null,
        ];
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
     * @param  int|null  $boardId  게시판 ID (전달 시 Board 재조회 생략)
     * @param  Board|null  $board  이미 조회한 게시판 모델 (전달 시 board 관계 적재까지 생략)
     * @return Post|null 게시글 모델 (카운트 포함)
     */
    public function findWithCounts(string $slug, int $id, ?int $boardId = null, ?Board $board = null): ?Post
    {
        // 호출자가 이미 손에 쥔 Board 를 넘기면 그 인스턴스를 그대로 쓴다 (#519 F3).
        // $board 는 아래 답글 로딩에서 참조하므로 어느 경로로 오든 정의돼 있어야 한다
        // (초기화가 없으면 boardId 를 받은 경로에서 미정의 변수 경고가 난다).
        $boardId = $board?->id ?? $boardId;

        if (! $boardId) {
            $board = Board::where('slug', $slug)->first();
            $boardId = $board?->id;
        }

        // 관리 권한자는 삭제된 게시글의 하위 데이터(답글/첨부)까지 열람 가능.
        // 첨부 eager load 클로저에서도 동일 권한 기준으로 cascade 삭제분을 포함하기 위해
        // 조회 이전에 권한을 계산한다 (댓글의 권한 기반 withTrashed 와 비대칭 제거).
        $hasDeletePermission = $this->checkBoardPermission($slug, 'admin.control')
            || $this->checkBoardPermission($slug, 'admin.manage')
            || $this->checkBoardPermission($slug, 'manager', PermissionType::User);

        // Board 를 이미 받았으면 관계로 같은 행을 다시 읽지 않는다 (조회 후 setRelation 으로 부착).
        $relations = $board ? ['user', 'user.avatarAttachment'] : ['user', 'user.avatarAttachment', 'board'];

        $post = Post::withTrashed()
            ->where('board_id', $boardId)
            ->with([
                ...$relations,
                'parent' => function ($query) {
                    $query->withTrashed()
                        ->with('user');
                },
                'attachments' => function ($query) use ($boardId, $hasDeletePermission) {
                    $query->where('board_id', $boardId);
                    // 관리 권한자는 게시글 삭제로 cascade soft delete 된 첨부까지 조회한다.
                    // 단, 사용자가 글 삭제 전에 직접 지운 첨부(trigger_type='user' 등)는 제외하고
                    // cascade 분만 포함한다 (살아있는 것 + cascade 만). 댓글의 cascade-only 노출과 일관.
                    if ($hasDeletePermission) {
                        $query->withTrashed()
                            ->where(function ($q) {
                                $q->whereNull('deleted_at')
                                    ->orWhere('trigger_type', TriggerType::Cascade->value);
                            });
                    }
                },
            ])
            ->find($id);

        // 모든 하위 답글을 재귀적으로 로드하여 트리 구조로 설정
        if ($post) {
            // 호출자가 넘긴 Board 는 관계로 적재하지 않았으므로 여기서 부착한다.
            if ($board) {
                $post->setRelation('board', $board);
            }

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
     * @return BoundedCount 일반 게시글 수 + 정확도 (답글, 공지 제외)
     */
    public function countNormalPosts(string $slug, array $filters = [], bool $withTrashed = false): BoundedCount
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

        // 총 건수는 상한까지만 센다. 검색어가 걸린 목록은 매칭 수가 데이터 증가에 비례하고,
        // 이 값은 목록 화면이 열릴 때마다 계산된다. 상한 이하면 지금과 값이 같고, 초과할
        // 때만 "이상" 으로 보고한다 — 페이지 이동은 simplePaginate 의 per_page + 1 실측이
        // 담당하므로 상한과 무관하게 끝까지 열려 있다.
        return BoundedPaginator::count($query, PaginationLimits::resultCap('board.posts'));
    }

    /**
     * 이전/다음 게시글을 조회합니다.
     * buildSortedPostList 메서드를 사용하여 목록 정렬 방식과 동일하게 처리합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  현재 게시글 ID
     * @param  array  $filters  정렬 파라미터 (order_by, order_direction)
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부 (기본: false)
     * @param  int|null  $boardId  게시판 ID (전달 시 Board 재조회 생략)
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
                ->select(DB::raw("{$aggregateFunc}({$orderBy})"))
                ->where($orderBy, $strictOp, $currentValue);

            // 서브쿼리는 빌더 그대로 넘긴다 — toSql() 로 문자열을 붙이고 mergeBindings 로
            // 바인딩을 손수 옮기면 조건 추가 순서에 따라 바인딩이 어긋날 수 있다.
            return $baseQuery()
                ->where($orderBy, '=', $subQuery)
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

            // 공지는 운영자가 등록하는 데이터라 통상 소수지만, 개수를 막는 장치가 없으면
            // 잘못 늘어난 게시판에서 한 페이지를 여는 것만으로 전량이 메모리에 올라온다.
            // `created_at desc` 정렬이므로 상한에 걸릴 때 최신 공지가 우선 보존된다 —
            // 잘린 공지는 후속 페이지에 노출될 경로가 없어 이 순서가 유일한 안전판이다.
            // 조정은 코어 필터 훅 `core.pagination.filter_result_cap` (context: board.notices).
            $noticeCap = PaginationLimits::resultCap('board.notices');
            if ($noticeCap !== null) {
                $noticeQuery->limit($noticeCap);
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

        // 정렬 스펙 (id를 2차 정렬로 추가 — 동일 값 내 순서를 결정론적으로 보장)
        // created_at은 초 단위라 실질적 중복이 드물지만, view_count/title/author_name은 중복이 많음
        $sort = [['column' => $orderBy, 'direction' => $orderDirection]];

        // 페이지네이션 여부에 따라 분기
        if ($perPage !== null) {
            // 지연 조인 — 이번 페이지의 원글 ID 를 먼저 구하고, 그 ID 에 대해서만 목록 컬럼과
            // 관계를 읽는다. OFFSET 이 건너뛰는 원글의 본문 앞부분(SUBSTRING)까지 읽던 비용이
            // 사라진다. COUNT 는 기존과 같이 수행하지 않는다(total 은 Service 캐시 카운트).
            $paginator = $this->paginateWithDeferredJoin(
                query: $parentQuery,
                columns: $columns,
                sort: $sort,
                perPage: $perPage,
                page: (int) $currentPage,
                relations: $relations,
                simple: true,
            );
            $parents = $paginator->getCollection();
        } else {
            // 전체 조회
            $parentQuery->orderBy($orderBy, $orderDirection)->orderBy('id', $orderDirection);

            if (! empty($relations)) {
                $parentQuery->with($relations);
            }

            $parents = $parentQuery->get($columns);
            $paginator = null;
        }

        // 3단계: 모든 하위 답글 조회 (모든 depth 처리 — depth-1만이 아닌 depth-2+ 포함)
        //
        // 깊이별로 한 번씩 조회하되 누적 건수에 상한을 둔다. 상한이 없으면 답글이 많은
        // 게시판에서 한 페이지를 여는 것만으로 그 게시판의 답글 전량을 메모리에 올린다.
        $parentIds = $parents->pluck('id')->toArray();
        $replyCap = PaginationLimits::resultCap('board.reply_tree');
        $allReplies = collect([]);

        if (! empty($parentIds)) {
            $currentLevelIds = $parentIds;
            $seenIds = array_flip($parentIds);

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

                if ($replyCap !== null) {
                    $levelQuery->limit(max(1, $replyCap - $allReplies->count()));
                }

                $levelReplies = $levelQuery->get($columns);

                if ($levelReplies->isEmpty()) {
                    break;
                }

                $allReplies = $allReplies->merge($levelReplies);
                $currentLevelIds = $levelReplies->pluck('id')->toArray();

                // 오염된 데이터(순환 참조)에서도 유한 종료를 보장한다.
                $currentLevelIds = array_values(array_filter(
                    $currentLevelIds,
                    function ($id) use (&$seenIds) {
                        if (isset($seenIds[$id])) {
                            return false;
                        }
                        $seenIds[$id] = true;

                        return true;
                    }
                ));

                if ($replyCap !== null && $allReplies->count() >= $replyCap) {
                    break;
                }
            }
        }

        $replies = $allReplies;

        // 4단계: 병합 (원글 + 모든 하위 답글을 깊이 우선 순으로)
        //
        // 부모별로 한 번만 그룹지어 둔다. 노드마다 전체 컬렉션을 where 로 훑으면
        // 답글 수의 제곱에 비례해 비교가 늘어난다.
        $repliesByParent = $replies->sortBy('id')->groupBy('parent_id');
        $mergedItems = collect([]);

        $appendReplies = function (int $postId) use (&$appendReplies, &$mergedItems, $repliesByParent): void {
            foreach ($repliesByParent->get($postId) ?? [] as $reply) {
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
        // comments_count 는 PostCountSyncListener 가 정확히 동기화하므로 SUM 으로 대체 (JOIN 제거)
        // 소프트 삭제된 게시글은 통계에서 제외 (이슈 #104 요구사항) — withTrashed 미사용
        $statsQuery = Post::where('user_id', $userId);
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
     * @param  array  $filters  필터 조건 (board_slug, search, activity_type, sort, viewer_id, exclude_board_slugs)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 게시글 활동 목록
     */
    public function getUserActivities(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $boardSlugFilter = $filters['board_slug'] ?? null;
        $search = $filters['search'] ?? null;
        $activityType = $filters['activity_type'] ?? 'authored';
        $sort = $filters['sort'] ?? 'latest'; // latest, oldest, views
        // 열람자 관점. 이 값이 대상 사용자와 다르면(비로그인 포함) **타인 관점**이므로
        // 비밀글·미발행글의 **본문(content_plain)만 비운다** — 행·제목·배지는 목록 화면과
        // 동일하게 유지한다(제목 공개 정책 2026-02-04, 행 제거가 아니라 본문 마스킹이 의도).
        //
        // 종전에는 `is_public` 옵트인 플래그로 이 판정을 했는데, 그 키를 설정하는 코드가
        // 저장소 어디에도 없어서 필터가 한 번도 적용되지 않았다(사문). 옵트인은 호출부가
        // 빠뜨리면 조용히 열리는 방향이라, 열람자 신원으로 판정하는 fail-closed 로 뒤집는다 —
        // viewer_id 가 없으면 자동으로 가장 좁은 가시성이 된다.
        $viewerId = $filters['viewer_id'] ?? null;
        $isOwnView = $viewerId !== null && $viewerId === $userId;
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

        // 분기 선택은 activity_type 만 본다. 가시성은 각 분기 안에서 처리한다 —
        // 여기서 열람자까지 보고 분기를 바꾸면 `commented` 요청이 조용히 `authored` 결과를
        // 돌려주게 되어 저장소 계약이 깨진다.
        if ($activityType === 'commented') {
            return $this->getUserCommentedActivities($userId, $boardIdFilter, $excludeBoardIds, $search, $orderColumn, $orderDirection, $perPage, $cachedTotal, $viewerId);
        }

        // authored (기본값, 공개 프로필 포함)
        return $this->getUserAuthoredActivities($userId, $boardIdFilter, $excludeBoardIds, $search, $isOwnView, $orderColumn, $orderDirection, $perPage, $cachedTotal);
    }

    /**
     * 사용자가 작성한 게시글 활동을 DB 레벨 페이지네이션으로 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  int|null  $boardIdFilter  게시판 ID 필터
     * @param  string|null  $search  검색 키워드
     * @param  bool  $isOwnView  열람자가 대상 본인인지 여부 (아니면 비밀글·미발행글 제외)
     * @param  string  $orderColumn  정렬 컬럼
     * @param  string  $orderDirection  정렬 방향
     * @param  int  $perPage  페이지당 항목 수
     */
    private function getUserAuthoredActivities(
        int $userId,
        ?int $boardIdFilter,
        array $excludeBoardIds,
        ?string $search,
        bool $isOwnView,
        string $orderColumn,
        string $orderDirection,
        int $perPage,
        ?int $cachedTotal = null
    ): LengthAwarePaginator {
        // JOIN 대신 whereNotIn으로 비활성 게시판 제외 — idx_board_posts_user_created 인덱스 활용
        $inactiveBoardIds = $this->getInactiveBoardIds();
        $allExcludeIds = array_unique(array_merge($excludeBoardIds, $inactiveBoardIds));

        // 관계/정렬은 지연 조인이 담당한다 (inner 는 키 컬럼만 조회)
        $query = Post::query()
            ->where('board_posts.user_id', $userId);

        if ($boardIdFilter) {
            $query->where('board_posts.board_id', $boardIdFilter);
        }

        if (! empty($allExcludeIds)) {
            $query->whereNotIn('board_posts.board_id', $allExcludeIds);
        }

        if ($search) {
            $keyword = $this->escapeLikeKeyword($search);
            $query->where(function ($q) use ($keyword) {
                $q->where('board_posts.title', 'like', "%{$keyword}%")
                    ->orWhere('board_posts.content', 'like', "%{$keyword}%");
            });
        }

        // 본문(content)은 목록 가공에 앞부분만 쓰이지만 태그 제거 후 길이가 줄어드는 것을 감안해
        // 넉넉히 잘라 읽는다. 잘라 읽기는 이번 페이지 분량(outer)에서만 일어난다.
        $listColumns = [
            'board_posts.id', 'board_posts.board_id', 'board_posts.user_id',
            'board_posts.title', 'board_posts.status', 'board_posts.is_secret',
            'board_posts.content_mode', 'board_posts.view_count', 'board_posts.comments_count',
            'board_posts.created_at', 'board_posts.updated_at', 'board_posts.deleted_at',
            // DB::raw 는 테이블 프리픽스가 적용되지 않는다. 단일 테이블 조회라 컬럼만 적으면 충분하다.
            DB::raw('SUBSTRING(content, 1, 1000) as content'),
        ];

        // 캐시된 total 을 그대로 통과시켜 COUNT 를 다시 수행하지 않는다
        $paginator = $this->paginateWithDeferredJoin(
            query: $query,
            columns: $listColumns,
            sort: [['column' => $orderColumn, 'direction' => $orderDirection]],
            perPage: $perPage,
            relations: ['board'],
            total: $cachedTotal,
        );

        // paginate 후 10건에만 PHP 가공 적용 (N+1 아님)
        $paginator->through(function ($post) use ($isOwnView) {
            // 이 목록은 본문 일부(content_plain)를 함께 싣는다. 타인이 볼 때 비밀글·블라인드
            // 글의 본문이 그대로 나가던 것이 결함이었다 — 행과 제목은 게시판 목록에서 이미
            // 같은 수준으로 보이므로(PostResource 의 목록 규칙: 제목은 노출, 본문만 차단)
            // 여기서도 **행은 남기고 본문만** 비운다. 행을 지우면 프로필의 비밀글/블라인드
            // 배지가 사문이 되어 필요 이상으로 기능이 깎인다.
            $hideContent = ! $isOwnView
                && ((bool) $post->is_secret || $post->status === PostStatus::Blinded);

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
                'comment_count' => (int) ($post->comments_count ?? 0),
                'created_at' => $this->formatCreatedAt($post->created_at),
                'created_at_formatted' => $this->formatCreatedAtFormat($post->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
                'content_plain' => $hideContent
                    ? ''
                    : (($post->content_mode ?? 'text') === 'html'
                        ? $this->stripHtmlToPlainText($post->content ?? '')
                        : ($post->content ?? '')),
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
        ?int $cachedTotal = null,
        ?int $viewerId = null
    ): LengthAwarePaginator {
        // 테이블명은 모델에서 얻는다 — 문자열로 박으면 테이블명이 바뀔 때 조용히 깨진다
        $postsTable = (new Post)->getTable();
        $commentsTable = (new Comment)->getTable();

        // 비활성 게시판 제외 — JOIN 없이 인덱스 활용
        $inactiveBoardIds = $this->getInactiveBoardIds();
        $allExcludeIds = array_unique(array_merge($excludeBoardIds, $inactiveBoardIds));

        /**
         * 내가 이 글에 단 댓글 조건 (검색어까지 반영 — 활동 판정 · 건수 집계 공통).
         *
         * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $q  댓글 하위 쿼리
         */
        $myComment = function ($q) use ($userId, $search) {
            $q->where('user_id', $userId)->whereNull('deleted_at');

            if ($search) {
                $q->where('content', 'like', '%'.$this->escapeLikeKeyword($search).'%');
            }
        };

        // "내가 댓글을 단 글" 집합은 EXISTS 로 정한다. 조인으로 행을 불린 뒤 groupBy 로 접는
        // 방식은 inner 가 읽는 행 수를 댓글 수만큼 늘리고, 그룹 쿼리라 총 건수도 서브쿼리로
        // 감싸야 한다. EXISTS 는 글 1건당 1행이라 둘 다 필요 없다.
        $query = Post::query()->whereHas('comments', $myComment);

        if ($boardIdFilter) {
            $query->where("{$postsTable}.board_id", $boardIdFilter);
        }

        if (! empty($allExcludeIds)) {
            $query->whereNotIn("{$postsTable}.board_id", $allExcludeIds);
        }

        // 목록 컬럼: 본문 전체 대신 앞부분만 (가공 후 표시 자수보다 넉넉히 잘라 읽는다).
        // SUBSTRING 은 빌더에 대응 표현이 없는 표준 SQL 함수라 이 한 곳만 raw 로 남긴다.
        // 테이블명·프리픽스는 문자열로 박지 않고 모델과 연결 설정에서 얻는다.
        $listColumns = [
            "{$postsTable}.id", "{$postsTable}.board_id", "{$postsTable}.user_id",
            "{$postsTable}.title", "{$postsTable}.status", "{$postsTable}.is_secret",
            "{$postsTable}.content_mode", "{$postsTable}.view_count", "{$postsTable}.comments_count",
            "{$postsTable}.created_at", "{$postsTable}.updated_at", "{$postsTable}.deleted_at",
            DB::raw('SUBSTRING('.DB::getTablePrefix().$postsTable.'.content, 1, 1000) as content'),
            'lc.content as comment_content',
            'lc.created_at as comment_created_at',
        ];

        // 정렬은 항상 board_posts 컬럼(작성일/조회수)이라 inner 에서 그대로 적용된다.
        // 캐시된 total 이 있으면 COUNT 를 건너뛴다. 검색/게시판 필터가 걸린 조회는 캐시를 쓰지
        // 않아 total 이 null 로 들어오며, 이때 trait 이 그룹 쿼리를 서브쿼리로 감싸 그룹 수를 센다.
        $paginator = $this->paginateWithDeferredJoin(
            query: $query,
            columns: $listColumns,
            sort: [['column' => $orderColumn, 'direction' => $orderDirection]],
            perPage: $perPage,
            relations: ['board'],
            keyName: 'id',
            total: $cachedTotal,
            // 최근 댓글 조회와 활동 건수 집계는 이번 페이지의 게시글에 대해서만 실행한다.
            // inner 에 두면 건너뛸 행 전체에 대해 상관 서브쿼리가 돌아간다.
            outerUsing: function ($outer) use ($userId, $myComment, $postsTable, $commentsTable) {
                // 글마다 "내가 단 댓글 중 가장 최근 1건" — 원래 raw SQL 로 쓰던 상관 서브쿼리를
                // 빌더로 옮겼다. created_at 동률에서 순서가 흔들리지 않도록 id 를 덧붙인다.
                $latest = DB::table("{$commentsTable} as bc_outer")
                    ->select('bc_outer.post_id', 'bc_outer.content', 'bc_outer.created_at')
                    ->where('bc_outer.user_id', $userId)
                    ->whereNull('bc_outer.deleted_at')
                    ->where('bc_outer.id', '=', function ($q) use ($userId, $commentsTable) {
                        $q->select('bc2.id')
                            ->from("{$commentsTable} as bc2")
                            ->whereColumn('bc2.post_id', 'bc_outer.post_id')
                            ->where('bc2.user_id', $userId)
                            ->whereNull('bc2.deleted_at')
                            ->orderByDesc('bc2.created_at')
                            ->orderByDesc('bc2.id')
                            ->limit(1);
                    });

                $outer->leftJoinSub($latest, 'lc', "{$postsTable}.id", '=', 'lc.post_id')
                    ->withCount(['comments as activity_count' => $myComment]);
            },
        );

        // paginate 후 10건에만 PHP 가공 적용
        $paginator->through(function ($post) use ($viewerId) {
            // 이 목록은 "내가 댓글 단 글" 이라 **타인이 쓴 비밀글**이 섞인다. 행은 내 활동
            // 기록이므로 남기되 본문은 내보내지 않는다 — 목록 미리보기를 빈 문자열로 만드는
            // PostResource::getMaskedContentPreviewForList 와 같은 규칙이다.
            // (블라인드 글도 동일: 상세·목록 어느 경로에서도 본문이 나가지 않는다.)
            // 열람자 미상($viewerId === null)이면 비밀글은 전부 가린다(fail-closed).
            $hideContent = ((bool) $post->is_secret && (int) $post->user_id !== $viewerId)
                || $post->status === PostStatus::Blinded;

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
                'comment_count' => (int) ($post->comments_count ?? 0),
                'created_at' => $this->formatCreatedAt($post->created_at),
                'created_at_formatted' => $this->formatCreatedAtFormat($post->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
                'content_plain' => $hideContent
                    ? ''
                    : (($post->content_mode ?? 'text') === 'html'
                        ? $this->stripHtmlToPlainText($post->content ?? '')
                        : ($post->content ?? '')),
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
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  페이지 번호
     * @return BoundedPage 페이지 결과 (총 건수 정확도 포함)
     */
    public function searchByKeyword(
        string $slug,
        string $keyword,
        string $orderBy = 'created_at',
        string $direction = 'desc',
        int $perPage = 10,
        int $page = 1
    ): BoundedPage {
        // 종전에는 같은 FULLTEXT 술어를 count() 로 한 번, get() 으로 또 한 번 실행했다.
        // 페이지네이터 한 번으로 합치고, 총 건수에는 상한을 건다.
        $query = $this->buildPublicSearchQuery($slug, $keyword)
            ->with('user')
            ->orderBy($orderBy, $direction)
            // 전순서 보장 — 정렬 컬럼이 비고유라 페이지 경계에서 행이 겹치거나 샐 수 있다
            ->orderBy('id', $direction === 'asc' ? 'asc' : 'desc');

        return BoundedPaginator::paginate(
            $query,
            perPage: $perPage,
            page: $page,
            resultCap: PaginationLimits::resultCap('search'),
        );
    }

    /**
     * 게시판에서 키워드와 일치하는 게시글 수를 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $keyword  검색 키워드
     * @return BoundedCount 일치하는 게시글 수 (정확도 포함)
     */
    public function countByKeyword(string $slug, string $keyword): BoundedCount
    {
        return BoundedPaginator::count(
            $this->buildPublicSearchQuery($slug, $keyword),
            PaginationLimits::resultCap('search')
        );
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
     * @return BoundedPage 페이지 결과 (총 건수 정확도 포함)
     */
    public function searchAcrossBoards(
        array $boardIds,
        string $keyword,
        string $orderBy = 'created_at',
        string $direction = 'desc',
        int $perPage = 10,
        int $page = 1
    ): BoundedPage {
        // count() + get() 이중 실행을 페이지네이터 한 번으로 합친다.
        // 다른 탭 조회 시 배지용 COUNT 까지 더해 같은 술어가 3회 실행되던 경로다.
        $query = $this->buildPublicSearchQueryByIds($boardIds, $keyword)
            ->with('user', 'board')
            ->orderBy($orderBy, $direction)
            ->orderBy('id', $direction === 'asc' ? 'asc' : 'desc');

        return BoundedPaginator::paginate(
            $query,
            perPage: $perPage,
            page: $page,
            resultCap: PaginationLimits::resultCap('search'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function searchAcrossBoardsByCursor(
        array $boardIds,
        string $keyword,
        array $sortKeys,
        int $perPage = 10,
        ?string $cursor = null
    ): CursorPaginator {
        // 커서 모드에는 OFFSET 이 없다. 깊은 페이지에서 건너뛸 행을 실제로 읽던 비용이
        // 사라지므로 상한 COUNT 도 이 경로에서는 하지 않는다 (총 건수는 배지 집계 담당).
        $query = $this->buildPublicSearchQueryByIds($boardIds, $keyword)
            ->with('user', 'board');

        return KeysetPaginator::paginate(
            query: $query,
            perPage: $perPage,
            sortKeys: $sortKeys,
            uniqueKey: 'id',
            cursor: $cursor,
        );
    }

    /**
     * 여러 게시판에서 키워드와 일치하는 게시글 수를 조회합니다 (단일 쿼리).
     *
     * @param  array  $boardIds  검색 대상 게시판 ID 목록
     * @param  string  $keyword  검색 키워드
     * @return BoundedCount 키워드와 일치하는 게시글 수 (정확도 포함)
     */
    public function countAcrossBoards(array $boardIds, string $keyword): BoundedCount
    {
        return BoundedPaginator::count(
            $this->buildPublicSearchQueryByIds($boardIds, $keyword),
            PaginationLimits::resultCap('search')
        );
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
     * 어떤 조건이 붙는지는 활성 검색 엔진이 정합니다. 저장소는 "이 컬럼들로 이 키워드를
     * 걸어라" 만 말하고 엔진 종류를 알지 않습니다 — 구체 엔진을 여기서 지목하면 플러그인이
     * 등록한 검색 엔진이 호출될 기회 자체를 잃습니다. 정제·폴백(LIKE, 와일드카드 escape
     * 포함)은 모두 코어 해석기가 단독으로 수행합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $keyword  검색 키워드
     */
    private function applyKeywordSearch(Builder $query, string $keyword): void
    {
        KeywordSearch::apply($query, ['title', 'content'], $keyword);
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
     * Sitemap 용으로 게시판의 공개 게시글을 스트리밍 조회합니다.
     *
     * lazyById 는 id 기준 키셋 페이징으로 청크를 순차 조회하므로,
     * 결과셋 전체가 메모리(및 DB 드라이버 버퍼)에 적재되지 않습니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $chunkSize  청크 크기
     * @return iterable<Post> 공개 게시글 순회자 (id, updated_at 만 조회)
     */
    public function streamPublishedForSitemap(int $boardId, int $chunkSize = 500): iterable
    {
        return Post::query()
            ->where('board_id', $boardId)
            ->where('status', PostStatus::Published)
            ->where('is_secret', false)
            ->select(['id', 'updated_at'])
            ->orderBy('id')
            ->lazyById($chunkSize);
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
     * 게시판 ID 기준으로 게시글을 일괄 영구 삭제합니다.
     *
     * 게시판 영구 삭제(deleteBoard) 시 사용합니다. 소프트 삭제와 달리
     * deleted_at 마킹이 아니라 레코드를 물리적으로 제거합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 게시글 수
     */
    public function forceDeleteByBoardId(int $boardId): int
    {
        return Post::where('board_id', $boardId)->forceDelete();
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
     * 부모 게시글 ID로 살아있는 답변(자식) 게시글 수를 조회합니다.
     *
     * SoftDeletes 전역 스코프가 삭제된 답변을 자동 제외하므로,
     * "삭제된 답변 후 재등록 허용" 판정의 근거로 사용됩니다.
     *
     * @param  int  $parentPostId  부모 게시글 ID
     * @return int 살아있는 자식 게시글 수
     */
    public function countRepliesByParentId(int $parentPostId): int
    {
        return Post::where('parent_id', $parentPostId)->count();
    }

    /**
     * 게시글에 살아있는 직계 답글이 있는지 확인합니다.
     *
     * 답글 삭제 정책(block) 의 차단 판정 기준입니다. 살아있는 자손은 살아있는
     * 부모 체인이 필요하므로 직계 검사만으로 판정이 완결됩니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return bool 살아있는 직계 답글 존재 여부
     */
    public function hasAliveReplies(string $slug, int $postId): bool
    {
        $board = Board::where('slug', $slug)->first();

        return Post::where('board_id', $board?->id)
            ->where('parent_id', $postId)
            ->exists();
    }

    /**
     * 게시글의 전체 자손(답글 트리) ID 를 수집합니다.
     *
     * 반복 BFS + 방문 ID 집합 가드로 순환 데이터에서도 유한 종료를 보장합니다(규정).
     * 삭제된 중간 노드 밑의 자손도 도달해야 하므로 순회는 withTrashed 로 수행합니다
     * (끊긴 체인 밑 과거 고아 스윕의 전제).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  루트 게시글 ID
     * @return array<int> 자손 게시글 ID 배열 (루트 미포함)
     */
    public function collectDescendantIds(string $slug, int $postId): array
    {
        $board = Board::where('slug', $slug)->first();

        if ($board === null) {
            return [];
        }

        $visited = [$postId => true];
        $frontier = [$postId];
        $descendants = [];

        while ($frontier !== []) {
            // audit:allow query-unbounded-get reason: 대상은 한 원글의 답글 트리 한 레벨 — 삭제/복원은 트리 전체가 하나의 의사표시라 단일 트랜잭션에서 전량 확보해야 하며(부분 반영 시 원글과 답글 상태가 어긋남), visited 가드가 유한 종료를 보장한다
            $children = Post::withTrashed()
                ->where('board_id', $board->id)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $next = [];
            foreach ($children as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $next[] = $childId;
                $descendants[] = $childId;
            }

            $frontier = $next;
        }

        return $descendants;
    }

    /**
     * 게시글의 살아있는 자손 답글 전체를 cascade 로 일괄 소프트 삭제합니다.
     *
     * 자손 중 살아있는 것만 단일 UPDATE 로 `status='deleted', trigger_type='cascade',
     * deleted_at=now()` 마킹합니다 (status 포함: 관리자 삭제 탭 필터가 status 기준).
     * 이미 trashed 인 자손(trigger 'user' 등)은 기본 스코프 밖이라 변조되지 않습니다.
     * 작업 이력(action_log)은 부모에만 남깁니다 — 댓글 cascade 와 동일한 규약입니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  부모 게시글 ID
     * @return array<int> 소프트 삭제된 자손 게시글 ID 배열
     */
    public function softDeleteCascadeByParentId(string $slug, int $postId): array
    {
        $descendantIds = $this->collectDescendantIds($slug, $postId);

        if ($descendantIds === []) {
            return [];
        }

        // 살아있는 자손만 선별 (기본 스코프가 trashed 제외)
        $aliveIds = Post::whereIn('id', $descendantIds)->pluck('id')->all();

        if ($aliveIds === []) {
            return [];
        }

        Post::whereIn('id', $aliveIds)->update([
            'status' => PostStatus::Deleted->value,
            'trigger_type' => TriggerType::Cascade->value,
            'deleted_at' => now(),
        ]);

        return $aliveIds;
    }

    /**
     * 게시글 복원 시, cascade 로 지워진 자손 답글만 top-down 으로 선택 복원합니다.
     *
     * 레벨 순회 + 방문 가드: 각 레벨에서 `parent_id ∈ (복원됨 ∪ alive)` 인
     * cascade-trashed 자손만 복원합니다. 사용자 직접 삭제('user')로 trashed 인
     * 중간 노드는 복원되지 않고 그 서브트리도 그대로 유지됩니다(고아 재생성 금지).
     *
     * 트레이드오프(의도): 삭제 전 blinded 였던 자손도 복원 시 published 가 됩니다 —
     * 부모 restorePost 의 기존 의미론(updateStatus published)과 동일합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  복원된 부모 게시글 ID
     * @return array<int> 복원된 자손 게시글 ID 배열
     */
    public function restoreCascadedByParentId(string $slug, int $postId): array
    {
        $board = Board::where('slug', $slug)->first();

        if ($board === null) {
            return [];
        }

        $visited = [$postId => true];
        $frontier = [$postId];
        $restored = [];

        while ($frontier !== []) {
            // 이미 살아있는 자식 — 더 깊은 레벨의 cascade 복원 통로로만 사용
            $aliveChildren = Post::where('board_id', $board->id)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            // cascade 로 지워진 자식만 복원 대상
            $toRestore = Post::onlyTrashed()
                ->where('board_id', $board->id)
                ->whereIn('parent_id', $frontier)
                ->where('trigger_type', TriggerType::Cascade->value)
                ->pluck('id')
                ->all();

            if ($toRestore !== []) {
                Post::onlyTrashed()->whereIn('id', $toRestore)->update([
                    'status' => PostStatus::Published->value,
                    'deleted_at' => null,
                ]);
                $restored = array_merge($restored, $toRestore);
            }

            $next = [];
            foreach (array_merge($aliveChildren, $toRestore) as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $next[] = $childId;
            }

            $frontier = $next;
        }

        return $restored;
    }

    /**
     * 게시판의 전체 게시글 ID 를 청크 단위로 순회하며 콜백에 전달합니다.
     *
     * 게시판 삭제 벌크 훅(board.posts.before_force_delete)의 페이로드 공급용입니다.
     * withTrashed 포함(삭제 대상은 trashed 도 물리 제거되므로), chunkById(키셋) 로
     * OFFSET 밀림 없이 순회합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $size  청크 크기
     * @param  callable  $callback  청크마다 호출될 콜백 (int[] $postIds)
     */
    public function eachIdChunkByBoardId(int $boardId, int $size, callable $callback): void
    {
        Post::withTrashed()
            ->where('board_id', $boardId)
            ->select('id')
            ->chunkById($size, function ($posts) use ($callback) {
                $callback($posts->pluck('id')->all());
            });
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
        // audit:allow query-unbounded-get reason: 게시판은 운영자가 만든 수만큼만 존재한다 (글 수와 무관)
        return Board::where('is_active', false)->pluck('id')->all();
    }

    /**
     * 게시글의 comments_count 컬럼을 활성 댓글 수로 재계산해 갱신합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return int 갱신된 카운트 값
     */
    public function recalculateCommentsCount(int $postId): int
    {
        $count = Comment::where('post_id', $postId)->whereNull('deleted_at')->count();
        Post::where('id', $postId)->update(['comments_count' => $count]);

        return $count;
    }

    /**
     * 게시글의 attachments_count 컬럼을 활성 첨부파일 수로 재계산해 갱신합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return int 갱신된 카운트 값
     */
    public function recalculateAttachmentsCount(int $postId): int
    {
        $count = Attachment::where('post_id', $postId)->whereNull('deleted_at')->count();
        Post::where('id', $postId)->update(['attachments_count' => $count]);

        return $count;
    }

    /**
     * 부모 게시글의 replies_count 컬럼을 활성 답글 수로 재계산해 갱신합니다.
     *
     * @param  int  $parentPostId  부모 게시글 ID
     * @return int 갱신된 카운트 값
     */
    public function recalculateRepliesCount(int $parentPostId): int
    {
        $count = Post::where('parent_id', $parentPostId)->whereNull('deleted_at')->count();
        Post::where('id', $parentPostId)->update(['replies_count' => $count]);

        return $count;
    }

    /**
     * 특정 날짜에 작성된 전체 게시판의 게시글 수를 조회합니다 (대시보드 집계용).
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return int 해당 날짜 작성 게시글 수
     */
    public function countCreatedOnDate(string $date): int
    {
        $start = CarbonImmutable::parse($date)->startOfDay();
        $end = $start->addDay();

        return Post::query()
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
    }

    /**
     * 전체 게시판에서 최신 게시글을 조회합니다 (대시보드 최신글 카드용).
     *
     * 답글(parent_id != null)은 본문 게시글이 아니므로 제외한다.
     * 다른 메서드(검색/인기/카테고리 목록 등)와 동일한 "원글만" 컨벤션 유지.
     *
     * @param  int  $limit  조회 건수
     * @return \Illuminate\Database\Eloquent\Collection<int, Post> 최신 게시글 컬렉션
     */
    public function getRecentAcrossBoards(int $limit): \Illuminate\Database\Eloquent\Collection
    {
        return Post::query()
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            // 노출 제한 필터 — 미발행(블라인드·삭제)·비활성 게시판 글은 대시보드 최신글에서 제외한다.
            // 비밀글은 제목 공개 정책(2026-02-04)에 따라 관리자에게 제목을 노출한다(제외하지 않음).
            ->where('status', PostStatus::Published->value)
            ->whereHas('board', fn ($q) => $q->where('is_active', true))
            ->with(['board', 'user'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
