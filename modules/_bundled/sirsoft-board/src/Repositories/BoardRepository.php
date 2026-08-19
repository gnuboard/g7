<?php

namespace Modules\Sirsoft\Board\Repositories;

use App\Enums\UserStatus;
use App\Helpers\PermissionHelper;
use App\Models\Attachment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 게시판 Repository
 *
 * 게시판 데이터 접근 계층을 담당합니다.
 */
class BoardRepository implements BoardRepositoryInterface
{
    use FormatsBoardDate;

    /**
     * 게시판을 생성합니다.
     *
     * @param  array  $data  게시판 생성 데이터
     * @return Board 생성된 게시판 모델
     */
    public function create(array $data): Board
    {
        $board = new Board;
        $board->fill($data);

        // created_by/updated_by는 guarded이므로 직접 할당
        if (Auth::check()) {
            $board->created_by = Auth::id();
            $board->updated_by = Auth::id();
        }

        $board->save();

        return $board;
    }

    /**
     * ID로 게시판을 조회합니다.
     *
     * @param  int  $id  게시판 ID
     * @return Board|null 게시판 모델 또는 null
     */
    public function find(int $id): ?Board
    {
        return Board::find($id);
    }

    /**
     * ID로 게시판을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  게시판 ID
     * @return Board 게시판 모델
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Board
    {
        return Board::findOrFail($id);
    }

    /**
     * 슬러그로 게시판을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return Board|null 게시판 모델 또는 null
     */
    public function findBySlug(string $slug): ?Board
    {
        return Board::where('slug', $slug)->first();
    }

    /**
     * 게시판을 수정합니다.
     *
     * @param  int  $id  게시판 ID
     * @param  array  $data  수정할 데이터
     * @return Board 수정된 게시판 모델
     *
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Board
    {
        $board = $this->findOrFail($id);

        // updated_by를 현재 인증된 사용자로 자동 설정
        if (Auth::check()) {
            $board->updated_by = Auth::id();
        }

        // slug 필드가 있으면 제거 (수정 불가)
        unset($data['slug']);

        $board->update($data);

        return $board->fresh();
    }

    /**
     * 게시판을 삭제합니다.
     *
     * @param  int  $id  게시판 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function delete(int $id): bool
    {
        $board = $this->findOrFail($id);

        return $board->delete();
    }

    /**
     * 게시판을 영구 삭제합니다.
     *
     * @param  int  $id  게시판 ID
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function forceDelete(int $id): bool
    {
        $board = $this->findOrFail($id);

        return (bool) $board->forceDelete();
    }

    /**
     * 모든 게시판을 조회합니다.
     *
     * @return Collection 게시판 컬렉션
     */
    public function all(): Collection
    {
        return Board::all();
    }

    /**
     * 게시판 목록을 페이지네이션하여 조회합니다.
     *
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이션된 게시판 목록
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        // 정렬 마지막의 기본키는 전순서 보장용이다 (created_at 동률에서 페이지 경계 흔들림 방지)
        $query = Board::orderBy('created_at', 'desc')->orderBy('id', 'desc');

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-board.boards.read');

        // audit:allow repository-paginate-column-pruning reason: 게시판 정의 테이블 —
        // 행 수가 운영자가 만든 게시판 수에 묶이고(글이 아니라 게시판), 넓은 컬럼이 없다
        return $query->paginate($perPage);
    }

    /**
     * 키워드로 게시판을 검색합니다. (name, slug 대상)
     *
     * @param  string  $keyword  검색 키워드
     * @return Collection 검색 결과 컬렉션
     */
    public function search(string $keyword): Collection
    {
        $query = Board::where('slug', 'like', "%{$keyword}%");

        foreach (config('app.translatable_locales', ['ko', 'en']) as $locale) {
            $query->orWhere("name->{$locale}", 'like', "%{$keyword}%");
        }

        return $query->get();
    }

    /**
     * 타입으로 게시판을 필터링합니다.
     *
     * @param  string  $type  게시판 타입
     * @return Collection 필터링된 게시판 컬렉션
     */
    public function filterByType(string $type): Collection
    {
        // audit:allow query-unbounded-get reason: boards 는 운영자 등록 설정성 테이블 — 행 수가 운영자 행위에 묶여 데이터 증가에 비례하지 않는다
        return Board::where('type', $type)->get();
    }

    /**
     * 쿼리 빌더를 반환합니다. (체이닝 가능)
     *
     * @return Builder<Board>
     */
    public function query()
    {
        return Board::query();
    }

    /**
     * 최근 게시글을 조회합니다. (여러 게시판 통합)
     *
     * board_posts 단일 테이블에서 활성 게시판의 최근 게시글을 조회합니다.
     *
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getRecentPosts(int $limit): array
    {
        // audit:allow query-unbounded-get reason: boards 는 운영자 등록 설정성 테이블 — 행 수가 운영자 행위에 묶여 데이터 증가에 비례하지 않는다
        $activeBoardIds = Board::where('is_active', true)->pluck('id');

        if ($activeBoardIds->isEmpty()) {
            return [];
        }

        $columns = ['id', 'board_id', 'title', 'author_name', 'created_at', 'view_count', 'is_secret', 'comments_count'];

        // board_id IN (...) + ORDER BY created_at DESC → 풀스캔 방지
        // 각 board_id별 개별 쿼리(인덱스 활용) 후 UNION ALL로 합침
        $subQueries = $activeBoardIds->map(
            fn ($boardId) => Post::query()
                ->select($columns)
                ->where('board_id', $boardId)
                ->whereNull('deleted_at')
                ->whereNull('parent_id')
                ->where('status', 'published')
                // 비밀글도 제목은 공개한다(본문만 보호) — 2026-02-04 확정 정책. 목록/검색/홈과 동일.
                // 게시판별 열람 권한은 BoardService::getCachedRecentPosts 가 응답 시점에 적용한다.
                ->orderBy('created_at', 'desc')
                ->limit($limit)
        );

        $unionQuery = $subQueries->shift();
        foreach ($subQueries as $sub) {
            $unionQuery = $unionQuery->unionAll($sub);
        }

        $postIds = DB::query()
            ->fromSub($unionQuery, 'sub')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->pluck('id');

        $posts = Post::query()
            ->with('board:id,slug,name')
            ->select($columns)
            ->whereIn('id', $postIds)
            ->orderBy('created_at', 'desc')
            ->get();

        $newHours = g7_module_settings('sirsoft-board', 'basic_defaults.new_display_hours', 24);

        return $posts->map(fn ($post) => [
            'id' => $post->id,
            'board_slug' => $post->board?->slug,
            'board_name' => $post->board?->getLocalizedName(),
            'title' => $post->title,
            'author_name' => $post->author_name,
            'created_at' => $this->formatCreatedAt($post->created_at),
            'created_at_formatted' => $this->formatCreatedAtFormat($post->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
            'view_count' => $post->view_count,
            'comment_count' => $post->comments_count ?? 0,
            'is_secret' => (bool) $post->is_secret,
            'is_new' => $post->created_at && $post->created_at->diffInHours(now()) < $newHours,
        ])->toArray();
    }

    /**
     * 특정 게시판의 최근 게시물을 조회합니다.
     *
     * board_posts 단일 테이블에서 board_id 기반으로 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getBoardRecentPosts(string $slug, int $limit): array
    {
        $board = Board::where('slug', $slug)->first();

        if (! $board) {
            return [];
        }

        $posts = Post::query()
            ->select([
                'id',
                'title',
                'author_name',
                'created_at',
                'view_count',
                'category',
                'is_notice',
                'is_secret',
                'comments_count',
            ])
            ->where('board_id', $board->id)
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->where('status', 'published')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $newHours = g7_module_settings('sirsoft-board', 'basic_defaults.new_display_hours', 24);

        return $posts->map(fn ($post) => [
            'id' => $post->id,
            'title' => $post->title,
            'author_name' => $post->author_name,
            'created_at' => $this->formatCreatedAt($post->created_at),
            'created_at_formatted' => $this->formatCreatedAtFormat($post->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
            'view_count' => $post->view_count,
            'comment_count' => $post->comments_count ?? 0,
            'category' => $post->category,
            'is_notice' => $post->is_notice,
            'is_secret' => (bool) $post->is_secret,
            'is_new' => $post->created_at && $post->created_at->diffInHours(now()) < $newHours,
        ])->toArray();
    }

    /**
     * 게시판 ID로 최근 게시물을 조회합니다 (slug 재조회 없음).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getBoardRecentPostsById(int $boardId, int $limit): array
    {
        $posts = Post::query()
            ->select([
                'id',
                'title',
                'author_name',
                'created_at',
                'view_count',
                'category',
                'is_notice',
                'is_secret',
                'comments_count',
            ])
            ->where('board_id', $boardId)
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->where('status', 'published')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $newHours = g7_module_settings('sirsoft-board', 'basic_defaults.new_display_hours', 24);

        return $posts->map(fn ($post) => [
            'id' => $post->id,
            'title' => $post->title,
            'author_name' => $post->author_name,
            'created_at' => $this->formatCreatedAt($post->created_at),
            'created_at_formatted' => $this->formatCreatedAtFormat($post->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
            'view_count' => $post->view_count,
            'comment_count' => $post->comments_count ?? 0,
            'category' => $post->category,
            'is_notice' => $post->is_notice,
            'is_secret' => (bool) $post->is_secret,
            'is_new' => $post->created_at && $post->created_at->diffInHours(now()) < $newHours,
        ])->toArray();
    }

    /**
     * 게시판의 게시글 개수를 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return int 게시글 개수
     */
    public function getBoardPostsCount(string $slug): int
    {
        $board = Board::where('slug', $slug)->first();

        if (! $board) {
            return 0;
        }

        return Post::query()
            ->where('board_id', $board->id)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * 전체 게시판의 게시글 개수를 집계합니다.
     *
     * board_posts 단일 테이블에서 활성 게시판 board_id 기반 단일 쿼리로 집계합니다.
     *
     * @return int 전체 게시글 개수
     */
    public function getTotalPostsCount(): int
    {
        return (int) Board::where('is_active', true)->sum('posts_count');
    }

    /**
     * 전체 게시판의 댓글 개수를 집계합니다.
     *
     * board_comments 단일 테이블에서 활성 게시판 board_id 기반 단일 쿼리로 집계합니다.
     *
     * @return int 전체 댓글 개수
     */
    public function getTotalCommentsCount(): int
    {
        // audit:allow query-unbounded-get reason: boards 는 운영자 등록 설정성 테이블 — 행 수가 운영자 행위에 묶여 데이터 증가에 비례하지 않는다
        $activeBoardIds = Board::where('is_active', true)->pluck('id');

        return Comment::query()
            ->whereIn('board_id', $activeBoardIds)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * 인기 게시글을 조회합니다. (조회수 > 댓글 수 기준, 기간별 필터링)
     *
     * board_posts 단일 테이블에서 활성 게시판의 인기 게시글을 조회합니다.
     * - 정렬: 조회수 내림차순, 동일 조회수 시 댓글 수 내림차순
     * - 기간별 필터링 (today, week, month, year)
     * - idx_board_posts_board_status_created 인덱스로 기간 범위를 먼저 좁힌 후 filesort
     * - today/week: 수천 건 이내로 1회차부터 빠름
     * - month/year: 수만 건 이상이나 캐시(getCachedPopularPosts)가 커버
     *
     * @param  string  $period  기간 필터 (today, week, month, year)
     * @param  int  $limit  조회 개수
     * @return array<int, array<string, mixed>>
     */
    public function getPopularPosts(string $period, int $limit): array
    {
        // audit:allow query-unbounded-get reason: boards 는 운영자 등록 설정성 테이블 — 행 수가 운영자 행위에 묶여 데이터 증가에 비례하지 않는다
        $activeBoardIds = Board::where('is_active', true)->pluck('id');

        if ($activeBoardIds->isEmpty()) {
            return [];
        }

        // 기간별 날짜 필터 계산
        $dateFilter = match ($period) {
            'today' => now()->startOfDay(),
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'year' => now()->subYear(),
            default => now()->subYear(),
        };

        // 테이블명은 모델에서 얻는다 (문자열 하드코딩 금지). LEFT() 는 빌더에 대응 표현이
        // 없는 표준 SQL 함수라 이 컬럼 하나만 raw 로 두고, 프리픽스는 연결 설정에서 읽는다.
        $postsTable = (new Post)->getTable();
        $usersTable = (new User)->getTable();
        $attachmentsTable = (new Attachment)->getTable();
        $prefixedPostsTable = DB::getTablePrefix().$postsTable;

        $query = Post::query()
            ->with('board:id,slug,name')
            ->leftJoin($usersTable, "{$postsTable}.user_id", '=', "{$usersTable}.id")
            ->leftJoin($attachmentsTable, function ($join) use ($usersTable, $attachmentsTable) {
                $join->on("{$attachmentsTable}.attachmentable_id", '=', "{$usersTable}.id")
                    ->where("{$attachmentsTable}.attachmentable_type", '=', User::class)
                    ->where("{$attachmentsTable}.collection", '=', 'avatar');
            })
            ->select([
                'board_posts.id',
                'board_posts.board_id',
                'board_posts.title',
                DB::raw("LEFT(`{$prefixedPostsTable}`.`content`, 300) as content_raw"),
                'board_posts.user_id',
                'board_posts.author_name as guest_author_name',
                "{$usersTable}.name as user_name",
                "{$usersTable}.email as user_email",
                "{$usersTable}.status as user_status",
                "{$attachmentsTable}.hash as attachment_hash",
                'board_posts.view_count',
                'board_posts.comments_count',
                'board_posts.created_at',
            ])
            ->whereIn('board_posts.board_id', $activeBoardIds)
            ->whereNull('board_posts.deleted_at')
            ->whereNull('board_posts.parent_id')
            ->where('board_posts.status', 'published')
            ->where('board_posts.is_secret', false)
            ->where('board_posts.created_at', '>=', $dateFilter);

        $posts = $query
            ->orderBy('board_posts.view_count', 'desc')
            ->orderBy('board_posts.comments_count', 'desc')
            ->limit($limit)
            ->get();

        return $posts->map(function ($post) {
            $viewCount = $post->view_count ?? 0;
            $commentCount = $post->comments_count ?? 0;

            // Avatar URL 결정 — 코어 첨부 URL 조립은 코어 모델 단일 지점에 위임
            $avatarUrl = null;
            if ($post->user_id && $post->attachment_hash) {
                $avatarUrl = Attachment::urlForHash($post->attachment_hash);
            }

            // 사용자 상태 처리 (탈퇴한 사용자는 익명화)
            $userStatus = $post->user_id ? UserStatus::tryFrom($post->user_status) : null;
            $isWithdrawn = $userStatus === UserStatus::Withdrawn;
            $authorName = $post->user_id
                ? ($isWithdrawn ? __('user.withdrawn_user') : $post->user_name)
                : $post->guest_author_name;

            return [
                'id' => $post->id,
                'board_slug' => $post->board?->slug,
                'board_name' => $post->board?->getLocalizedName(),
                'title' => $post->title,
                'excerpt' => mb_substr(strip_tags((string) ($post->content_raw ?? '')), 0, 100),
                'author' => [
                    'id' => $post->user_id,
                    'name' => $authorName,
                    'email' => $isWithdrawn ? null : $post->user_email,
                    'is_guest' => ! $post->user_id,
                    'avatar' => $isWithdrawn ? null : $avatarUrl,
                    'status' => $post->user_status,
                    'status_label' => $userStatus?->label() ?? $post->user_status,
                ],
                'view_count' => $viewCount,
                'comment_count' => $commentCount,
                'created_at' => $this->formatCreatedAt(Carbon::parse($post->created_at)),
                'created_at_formatted' => $this->formatCreatedAtFormat($post->created_at, g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')),
            ];
        })->toArray();
    }

    /**
     * 활성화된 게시판 목록을 조회합니다.
     *
     * @param  string|null  $slug  특정 게시판 슬러그 (null이면 전체)
     * @return Collection 활성 게시판 컬렉션
     */
    public function getActiveBoards(?string $slug = null): Collection
    {
        $query = Board::where('is_active', true);

        if (! empty($slug)) {
            $query->where('slug', $slug);
        }

        return $query->get();
    }

    /**
     * Sitemap 생성용으로 활성 게시판을 순회자로 조회합니다.
     *
     * @param  int  $chunkSize  청크 크기
     * @return iterable<Board> 활성 게시판 순회자 (id, slug, updated_at 만 조회)
     */
    public function streamActiveForSitemap(int $chunkSize = 500): iterable
    {
        return Board::where('is_active', true)
            ->select(['id', 'slug', 'updated_at'])
            ->orderBy('id')
            ->lazyById($chunkSize);
    }

    /**
     * 필터용 전체 활성 게시판 목록을 반환합니다.
     *
     * @return array 게시판 목록 [{slug, name}, ...]
     */
    public function getActiveBoardsList(): Collection
    {
        // audit:allow query-unbounded-get reason: boards 는 운영자 등록 설정성 테이블 — 행 수가 운영자 행위에 묶여 데이터 증가에 비례하지 않는다
        return Board::where('is_active', true)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * 게시판을 일괄 업데이트합니다.
     *
     * @param  array<string, mixed>  $data  업데이트할 데이터
     * @param  bool  $applyAll  전체 적용 여부
     * @param  array<int>  $boardIds  특정 게시판 ID 목록 (applyAll=false일 때 사용)
     * @return int 업데이트된 게시판 수
     */
    public function bulkUpdate(array $data, bool $applyAll = true, array $boardIds = []): int
    {
        $query = Board::query();

        if (! $applyAll && ! empty($boardIds)) {
            $query->whereIn('id', $boardIds);
        }

        // query()->update()는 Eloquent 캐스트를 적용하지 않으므로
        // array 캐스트 필드는 JSON으로 직렬화하여 전달해야 함
        $arrayCastFields = array_keys(array_filter(
            (new Board)->getCasts(),
            fn (string $cast) => $cast === 'array'
        ));
        foreach ($arrayCastFields as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }

        return $query->update($data);
    }

    /**
     * 게시판 ID 목록별 게시글 개수를 조회합니다.
     *
     * @param  array<int>  $boardIds  게시판 ID 목록
     * @return array<int, int> board_id => count 매핑
     */
    public function getPostsCountByBoardIds(array $boardIds): array
    {
        return Post::whereIn('board_id', $boardIds)
            ->groupBy('board_id')
            ->selectRaw('board_id, COUNT(*) as count')
            ->pluck('count', 'board_id')
            ->toArray();
    }

    /**
     * 활성화된 게시판 목록을 정렬 옵션과 함께 조회합니다.
     *
     * @param  string  $orderBy  정렬 기준 컬럼
     * @param  string  $orderDirection  정렬 방향 (asc/desc)
     * @return Collection 활성 게시판 컬렉션
     */
    public function getActiveBoardsOrdered(string $orderBy = 'created_at', string $orderDirection = 'desc'): Collection
    {
        // audit:allow query-unbounded-get reason: boards 는 운영자 등록 설정성 테이블 — 행 수가 운영자 행위에 묶여 데이터 증가에 비례하지 않는다
        return Board::where('is_active', true)
            ->orderBy($orderBy, $orderDirection)
            ->get();
    }

    /**
     * 활성 게시판의 통계를 조회합니다 (게시판 수, 게시글 수, 댓글 수).
     *
     * boards 테이블의 카운팅 컬럼을 SUM하여 단일 쿼리로 집계합니다.
     *
     * @return object{boards_count: int, posts_total: int, comments_total: int}
     */
    public function getActiveBoardStats(): object
    {
        return Board::where('is_active', true)
            ->selectRaw('COUNT(*) as boards_count, COALESCE(SUM(posts_count), 0) as posts_total, COALESCE(SUM(comments_count), 0) as comments_total')
            ->first();
    }

    /**
     * 메뉴용 경량 게시판 목록을 조회합니다.
     *
     * id, name, slug 컬럼만 조회하여 메뉴 렌더링에 필요한 최소 데이터만 반환합니다.
     *
     * @return Collection 활성 게시판 컬렉션 (id, name, slug만 포함)
     */
    public function getActiveBoardsForMenu(): Collection
    {
        // audit:allow query-unbounded-get reason: boards 는 운영자 등록 설정성 테이블 — 행 수가 운영자 행위에 묶여 데이터 증가에 비례하지 않는다
        return Board::where('is_active', true)
            ->select(['id', 'name', 'slug'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * 특정 유형의 게시판 개수를 반환합니다.
     *
     * @param  string  $type  게시판 유형 slug
     * @return int 해당 유형의 게시판 개수
     */
    public function countByType(string $type): int
    {
        return Board::where('type', $type)->count();
    }

    /**
     * 게시판의 posts_count 컬럼을 활성 게시글 수로 재계산해 갱신합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 갱신된 카운트 값
     */
    public function recalculatePostsCount(int $boardId): int
    {
        $count = Post::where('board_id', $boardId)->whereNull('deleted_at')->count();
        Board::where('id', $boardId)->update(['posts_count' => $count]);

        return $count;
    }

    /**
     * 게시판의 comments_count 컬럼을 활성 댓글 수로 재계산해 갱신합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 갱신된 카운트 값
     */
    public function recalculateCommentsCount(int $boardId): int
    {
        $count = Comment::where('board_id', $boardId)->whereNull('deleted_at')->count();
        Board::where('id', $boardId)->update(['comments_count' => $count]);

        return $count;
    }
}
