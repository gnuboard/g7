<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\CacheInterface;
use App\Enums\TotalRelation;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Search\SearchPagePolicy;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\ReplyDeletePolicy;
use Modules\Sirsoft\Board\Exceptions\PostHasRepliesException;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 게시글 관리 서비스 클래스
 *
 * 게시글의 생성, 수정, 삭제 등 비즈니스 로직을 담당하며,
 * 훅 시스템과 작업 이력 관리 기능을 제공합니다.
 */
class PostService
{
    /**
     * 검색 정렬 이름 → [실제 컬럼, 방향] 선언
     *
     * 코어({@see SearchPagePolicy})가 이 선언을 읽어 커서 적용 여부를 판정한다.
     * 여기에 없는 정렬 이름(관련도순 등)은 커서로 처리하지 않고 offset 을 유지한다.
     */
    public const SEARCH_SORT_MAP = [
        'latest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'views' => ['view_count', 'desc'],
        'popular' => ['view_count', 'desc'],
    ];

    /**
     * 커서(키셋) 경계로 쓸 수 있는 실제 컬럼 선언
     *
     * 커서는 정렬 키를 WHERE 절 경계로 삼으므로 계산값·별칭은 넣을 수 없다.
     */
    public const SEARCH_CURSOR_COLUMNS = ['created_at', 'view_count'];

    /**
     * PostService 생성자
     *
     * @param  PostRepositoryInterface  $postRepository  게시글 Repository
     * @param  BoardService  $boardService  게시판 서비스
     * @param  AttachmentRepositoryInterface  $attachmentRepository  첨부파일 Repository
     * @param  AttachmentService  $attachmentService  첨부파일 서비스
     * @param  CommentRepositoryInterface  $commentRepository  댓글 Repository
     * @param  CommentService  $commentService  댓글 서비스
     */
    public function __construct(
        private PostRepositoryInterface $postRepository,
        private BoardService $boardService,
        private AttachmentRepositoryInterface $attachmentRepository,
        private AttachmentService $attachmentService,
        private CommentRepositoryInterface $commentRepository,
        private CommentService $commentService,
        private CacheInterface $cache
    ) {}

    /**
     * 게시글 목록을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @param  string  $context  컨텍스트 (admin 또는 user)
     * @param  Board|null  $board  게시판 모델 (이미 조회된 경우 전달하여 중복 쿼리 방지)
     * @return LengthAwarePaginator 게시글 목록
     *
     * @throws ModelNotFoundException 게시판을 찾을 수 없는 경우
     */
    public function getPosts(string $slug, array $filters = [], int $perPage = 15, bool $withTrashed = false, string $context = 'admin', ?Board $board = null): Paginator
    {
        // 게시판 존재성 검증 (board가 전달되지 않은 경우에만)
        if (! $board) {
            $this->validateBoardExists($slug);
        }

        // 컨텍스트 기반 스코프 권한 식별자 설정
        $scopePermission = $context === 'admin'
            ? "sirsoft-board.{$slug}.admin.posts.read"
            : "sirsoft-board.{$slug}.posts.read";
        $filters['scope_permission'] = $scopePermission;

        return $this->postRepository->paginate($slug, $filters, $perPage, $withTrashed, $board);
    }

    /**
     * 전체 일반 게시글(원글) 수를 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $filters  필터 조건
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @param  string  $context  호출 컨텍스트 (admin/user — 권한 스코프 판정용)
     * @return BoundedCount 일반 게시글 수 + 정확도 (답글, 공지 제외)
     *
     * @throws ModelNotFoundException 게시판을 찾을 수 없는 경우
     */
    public function getTotalNormalPosts(string $slug, array $filters = [], bool $withTrashed = false, string $context = 'admin'): BoundedCount
    {
        // 게시판 존재성 검증
        $this->validateBoardExists($slug);

        // 컨텍스트 기반 스코프 권한 식별자 설정
        $scopePermission = $context === 'admin'
            ? "sirsoft-board.{$slug}.admin.posts.read"
            : "sirsoft-board.{$slug}.posts.read";
        $filters['scope_permission'] = $scopePermission;

        return $this->postRepository->countNormalPosts($slug, $filters, $withTrashed);
    }

    /**
     * 일반 게시글(원글) 수를 캐시에서 조회합니다.
     *
     * 필터가 없는 기본 목록은 캐시를 사용하고 (TTL: cache.default_ttl 설정값),
     * 필터(검색/카테고리 등)가 적용된 경우 실제 COUNT를 실행합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $boardId  게시판 ID
     * @param  array  $filters  필터 조건
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @param  string  $context  컨텍스트 (admin 또는 user)
     * @return BoundedCount 일반 게시글 수 + 정확도
     */
    public function getCachedNormalPostCount(string $slug, int $boardId, array $filters = [], bool $withTrashed = false, string $context = 'admin'): BoundedCount
    {
        // 필터가 적용된 경우 캐시 미사용 — 실제 COUNT 실행
        $hasActiveFilters = ! empty($filters['search'])
            || (isset($filters['category']) && $filters['category'] !== '' && $filters['category'] !== null)
            || ! empty($filters['status'])
            || ! empty($filters['user_id'])
            || ! empty($filters['created_at_from'])
            || ! empty($filters['created_at_to']);

        if ($hasActiveFilters) {
            return $this->getTotalNormalPosts($slug, $filters, $withTrashed, $context);
        }

        // 관리자 목록은 삭제글 포함(withTrashed=true)이 기본이라, 이 조건에서 캐시를 건너뛰면
        // 관리자가 목록을 열 때마다 전체 COUNT 가 실행된다. 포함 여부는 결과가 달라지는
        // 축이므로 캐시를 우회하는 대신 **키를 나눈다** — 두 값이 서로를 덮어쓰지 않는다.
        $cacheKey = $withTrashed
            ? "board_normal_count_{$boardId}_with_trashed"
            : "board_normal_count_{$boardId}";

        // 캐시에는 값 객체가 아니라 원시 배열을 담는다. 드라이버마다 직렬화 방식이 달라
        // 객체를 그대로 넣으면 복원 시 클래스 정의에 묶이고, 정확도 필드가 하나라도
        // 빠지면 잘린 건수가 정확한 것처럼 되살아난다.
        $cached = $this->cache->remember(
            $cacheKey,
            fn () => $this->getTotalNormalPosts($slug, $filters, $withTrashed, $context)->toArray(),
            (int) g7_core_settings('cache.default_ttl', 86400),
            tags: ['board-stats']
        );

        return new BoundedCount(
            (int) ($cached['total'] ?? 0),
            TotalRelation::tryFrom($cached['total_relation'] ?? '') ?? TotalRelation::Exact,
            $cached['result_cap'] ?? null,
        );
    }

    /**
     * ID로 게시글을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  string  $context  호출 컨텍스트 (admin/user — 권한 스코프 판정용)
     * @return Post 게시글 모델
     *
     * @throws ModelNotFoundException 게시판 또는 게시글을 찾을 수 없는 경우
     */
    public function getPost(string $slug, int $id, string $context = 'admin'): Post
    {
        // 게시판 존재성 검증
        $this->validateBoardExists($slug);

        $post = $this->postRepository->findOrFail($slug, $id);

        // 컨텍스트 기반 스코프 접근 검사
        $scopePermission = $context === 'admin'
            ? "sirsoft-board.{$slug}.admin.posts.read"
            : "sirsoft-board.{$slug}.posts.read";

        if (! PermissionHelper::checkScopeAccess($post, $scopePermission)) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        return $post;
    }

    /**
     * 게시글을 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $data  게시글 생성 데이터
     * @param  array<UploadedFile>  $files  업로드할 파일 배열
     * @param  array<int>  $attachmentIds  첨부파일 ID 배열 (업로드 완료된 파일)
     * @param  array  $options  추가 옵션 (예: ['skip_notification' => true])
     * @return Post 생성된 게시글 모델
     *
     * @throws ModelNotFoundException 게시판을 찾을 수 없는 경우
     */
    public function createPost(string $slug, array $data, array $files = [], array $attachmentIds = [], array $options = []): Post
    {
        // 게시판 존재성 검증 및 board_id 설정 (작성은 스코프 체크 불필요)
        $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
        if (! $board) {
            throw new ModelNotFoundException(__('sirsoft-board::messages.errors.board_not_found'));
        }
        $data['board_id'] = $board->id;

        // temp_key 및 attachment_ids 추출
        $tempKey = $data['temp_key'] ?? null;
        unset($data['temp_key']);
        unset($data['attachment_ids']);

        DB::beginTransaction();
        try {
            // 답글인 경우 depth 자동 계산
            if (! empty($data['parent_id'])) {
                $parentPost = $this->postRepository->find($slug, (int) $data['parent_id']);
                $data['depth'] = $parentPost ? ($parentPost->depth + 1) : 1;
            } else {
                $data['depth'] = 0;
            }

            // 훅: before_create
            HookManager::doAction('sirsoft-board.post.before_create', $slug, $data);

            // 훅: filter_create_data
            $data = HookManager::applyFilters('sirsoft-board.post.filter_create_data', $data, $slug);

            // 게시글 생성
            $post = $this->postRepository->create($slug, $data);

            // 첨부파일 연결 처리
            $this->linkAttachments($slug, $post->id, $attachmentIds, $tempKey, $files, '생성');

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            // 업로드된 첨부파일 삭제 (롤백)
            if (! empty($attachmentIds)) {
                $this->attachmentService->rollbackUploadedFiles($slug, $attachmentIds);
            }

            throw $e;
        }

        // 훅: after_create (트랜잭션 외부 실행 — 알림/캐시 실패가 게시글 생성에 영향을 주지 않도록)
        try {
            HookManager::doAction('sirsoft-board.post.after_create', $post, $slug, $options);
        } catch (\Exception $e) {
            Log::error('PostService: after_create 훅 실행 실패 (게시글 생성은 성공)', [
                'post_id' => $post->id,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }

        // 캐시 무효화
        try {
            $this->invalidatePostCaches($slug);
        } catch (\Exception $e) {
            Log::error('PostService: 캐시 무효화 실패 (게시글 생성은 성공)', [
                'post_id' => $post->id,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }

        return $post;
    }

    /**
     * 게시글을 수정합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  array  $data  수정할 데이터
     * @param  array<int>  $attachmentIds  첨부파일 ID 배열 (새로 업로드된 파일)
     * @return Post 수정된 게시글 모델
     *
     * @throws ModelNotFoundException 게시판 또는 게시글을 찾을 수 없는 경우
     */
    public function updatePost(string $slug, int $id, array $data, array $attachmentIds = []): Post
    {
        // 게시판 존재성 검증
        $this->validateBoardExists($slug);

        $post = $this->postRepository->findOrFail($slug, $id);

        // temp_key 및 attachment_ids 추출
        $tempKey = $data['temp_key'] ?? null;
        unset($data['temp_key']);
        unset($data['attachment_ids']);

        DB::beginTransaction();
        try {
            // 훅: before_update
            HookManager::doAction('sirsoft-board.post.before_update', $post, $data, $slug);

            $snapshot = $post->toArray();

            // 훅: filter_update_data
            $data = HookManager::applyFilters('sirsoft-board.post.filter_update_data', $data, $post, $slug);

            // 게시글 수정
            $updatedPost = $this->postRepository->update($slug, $id, $data);

            // 첨부파일 연결 처리
            $this->linkAttachments($slug, $updatedPost->id, $attachmentIds, $tempKey, [], '수정');

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            // 업로드된 첨부파일 삭제 (롤백)
            if (! empty($attachmentIds)) {
                $this->attachmentService->rollbackUploadedFiles($slug, $attachmentIds);
            }

            throw $e;
        }

        // 훅: after_update (트랜잭션 외부 실행 — 알림/캐시 실패가 게시글 수정에 영향을 주지 않도록)
        try {
            HookManager::doAction('sirsoft-board.post.after_update', $updatedPost, $slug, $snapshot);
        } catch (\Exception $e) {
            Log::error('PostService: after_update 훅 실행 실패 (게시글 수정은 성공)', [
                'post_id' => $updatedPost->id,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }

        // 캐시 무효화
        try {
            $this->invalidatePostCaches($slug);
        } catch (\Exception $e) {
            Log::error('PostService: 캐시 무효화 실패 (게시글 수정은 성공)', [
                'post_id' => $updatedPost->id,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }

        return $updatedPost;
    }

    /**
     * 게시글을 삭제합니다 (상태를 deleted로 변경).
     *
     * 그누보드7 규정: DB CASCADE 금지 → 하위 데이터 명시적 연쇄 삭제
     * ① 게시글 상태 변경(deleted) + 소프트 삭제
     * ② 살아있는 댓글을 cascade 로 소프트 삭제 (trigger_type='cascade')
     * ③ 살아있는 첨부를 cascade 로 소프트 삭제 (trigger_type='cascade')
     * ④ 살아있는 자손 답글 전체를 cascade 로 소프트 삭제 (+ 그 답글들의 댓글/첨부)
     *
     * 게시판 설정 `reply_delete_policy` 가 'block' 이면 살아있는 직계 답글 존재 시
     * before_delete 훅 발화 전에 차단합니다(부수효과 0). 훅 경유 삭제는
     * `options['cascade_replies']=true` 로 정책을 우회해 cascade 를 고정합니다
     * (문의 답변은 시스템 생성이므로 block 정책에 막히면 안 됨).
     *
     * 이미 사용자가 직접 삭제한 답글/댓글/첨부(trigger_type='user' 등)는 영향을 받지
     * 않으며, 게시글 복원 시 cascade 로 지워진 항목만 선택 복원됩니다.
     * 위 단계는 단일 트랜잭션으로 묶여 부분 실패 시 전체 롤백됩니다.
     * after_delete 훅은 부모 게시글에 대해서만 발화합니다 (자손별 발화 시 알림 폭주).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  string|null  $triggerType  트리거 유형 (admin, report 등)
     * @param  array  $options  옵션 배열 (skip_notification: 알림 발송 SKIP, cascade_replies: 답글 삭제 정책 우회)
     * @return Post 삭제 처리된 게시글
     *
     * @throws ModelNotFoundException 게시판 또는 게시글을 찾을 수 없는 경우
     * @throws PostHasRepliesException block 정책 게시판에서 살아있는 답글이 있는 경우
     */
    public function deletePost(string $slug, int $id, ?string $triggerType = null, array $options = []): Post
    {
        // 게시판 존재성 검증 (정책 판정에 게시판 모델이 필요해 직접 조회)
        $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

        if (! $board) {
            throw new ModelNotFoundException(__('sirsoft-board::messages.errors.board_not_found'));
        }

        $post = $this->postRepository->findOrFail($slug, $id);

        // 답글 삭제 정책 판정 — 훅 경유 삭제(cascade_replies 옵션)는 시스템 생성 답글을
        // 함께 정리해야 하므로 게시판 정책과 무관하게 cascade 고정
        $policy = ($options['cascade_replies'] ?? false)
            ? ReplyDeletePolicy::Cascade
            : ($board->reply_delete_policy ?? ReplyDeletePolicy::Cascade);

        // block 정책: 살아있는 직계 답글이 있으면 before_delete 훅 발화 전에 차단
        // (차단 시 부수효과 0 — 훅 리스너의 외부 정리가 먼저 실행되면 되돌릴 수 없다)
        if ($policy === ReplyDeletePolicy::Block && $this->postRepository->hasAliveReplies($slug, $id)) {
            throw new PostHasRepliesException;
        }

        // 훅: before_delete
        HookManager::doAction('sirsoft-board.post.before_delete', $post, $slug);

        // 작업 이력 생성
        $actionLog = $this->buildActionLog('delete', null);

        // 게시글 + 하위 데이터(답글/댓글/첨부) 연쇄 소프트 삭제를 단일 트랜잭션으로 처리
        $deletedPost = DB::transaction(function () use ($slug, $id, $actionLog, $triggerType) {
            // ① 상태 변경 (deleted로 변경하고 소프트 삭제)
            $deletedPost = $this->postRepository->updateStatus($slug, $id, 'deleted', $actionLog, $triggerType);
            $deletedPost->delete();

            // ② 댓글 cascade 소프트 삭제 (사용자가 이미 삭제한 항목은 미영향)
            $this->commentRepository->softDeleteByPostId($slug, $id);

            // ③ 첨부 cascade 소프트 삭제 (사용자가 이미 삭제한 항목은 미영향)
            $this->attachmentRepository->softDeleteByPostId($slug, $id);

            // ④ 살아있는 자손 답글 cascade 소프트 삭제 — block 통과 시에도 실행
            //    (끊긴 체인 밑 과거 고아 답글 스윕. 살아있는 직계가 없어도 고아 자손은 존재 가능)
            $descendantIds = $this->postRepository->softDeleteCascadeByParentId($slug, $id);

            // ⑤ 함께 지워진 자손 답글들의 댓글/첨부도 cascade 소프트 삭제
            if ($descendantIds !== []) {
                $this->commentRepository->softDeleteByPostIds($slug, $descendantIds);
                $this->attachmentRepository->softDeleteByPostIds($slug, $descendantIds);
            }

            return $deletedPost;
        });

        // 훅: after_delete ($options 전달 — skip_notification 등 수신 리스너에서 활용)
        // 자손 답글별로는 발화하지 않는다 — 자손별 발화 시 알림이 폭주하며,
        // 이커머스 문의 피벗은 문의 원글에만 걸려 있어 부모 발화만으로 충분하다.
        HookManager::doAction('sirsoft-board.post.after_delete', $deletedPost, $slug, $options);

        // 캐시 무효화
        $this->invalidatePostCaches($slug);

        return $deletedPost;
    }

    /**
     * 게시글을 블라인드 처리합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  string  $reason  블라인드 사유
     * @param  string|null  $triggerType  트리거 유형 (admin, report 등)
     * @return Post 블라인드 처리된 게시글 모델
     *
     * @throws ModelNotFoundException 게시판 또는 게시글을 찾을 수 없는 경우
     */
    public function blindPost(string $slug, int $id, string $reason, ?string $triggerType = null): Post
    {
        // 게시판 존재성 검증
        $this->validateBoardExists($slug);

        $post = $this->postRepository->findOrFail($slug, $id);

        // 멱등성: 이미 블라인드 상태이면 중복 처리 방지
        if ($post->status === PostStatus::Blinded) {
            return $post;
        }

        // 훅: before_blind
        HookManager::doAction('sirsoft-board.post.before_blind', $post, $reason, $slug);

        // 작업 이력 생성
        $actionLog = $this->buildActionLog('blind', $reason);

        // 상태 변경
        $blindedPost = $this->postRepository->updateStatus($slug, $id, 'blinded', $actionLog, $triggerType);

        // 훅: after_blind
        HookManager::doAction('sirsoft-board.post.after_blind', $blindedPost, $slug);

        return $blindedPost;
    }

    /**
     * 블라인드 또는 삭제된 게시글을 복원합니다.
     *
     * 게시글 삭제 연쇄(cascade)로 함께 지워졌던 댓글/첨부만 선택적으로 복원합니다.
     * 사용자가 직접 삭제한 항목(trigger_type='user' 등)은 복원되지 않습니다.
     * 게시글 복원과 하위 데이터 복원은 단일 트랜잭션으로 묶입니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  string|null  $reason  복원 사유
     * @param  string|null  $triggerType  트리거 유형 (admin, report 등)
     * @return Post 복원된 게시글
     *
     * @throws ModelNotFoundException
     */
    public function restorePost(string $slug, int $id, ?string $reason = null, ?string $triggerType = null): Post
    {
        // 게시판 존재성 검증
        $this->validateBoardExists($slug);

        $post = $this->postRepository->findOrFail($slug, $id);

        // 멱등성: 이미 게시됨 상태이면 중복 처리 방지
        if ($post->status === PostStatus::Published) {
            return $post;
        }

        // 훅: before_restore
        HookManager::doAction('sirsoft-board.post.before_restore', $post, $reason, $slug);

        // 작업 이력 생성
        $actionLog = $this->buildActionLog('restore', $reason);

        // 게시글 + cascade 로 지워진 하위 데이터 복원을 단일 트랜잭션으로 처리
        $restoredPost = DB::transaction(function () use ($slug, $id, $actionLog, $triggerType) {
            // ① 상태 변경 (published로 복원 — updateStatus 가 게시글 deleted_at 까지 복원)
            $restoredPost = $this->postRepository->updateStatus($slug, $id, 'published', $actionLog, $triggerType);

            // ② cascade 로 지워진 댓글만 선택 복원
            $this->commentRepository->restoreCascadedByPostId($slug, $id);

            // ③ cascade 로 지워진 첨부만 선택 복원
            $this->attachmentRepository->restoreCascadedByPostId($slug, $id);

            // ④ cascade 로 지워진 자손 답글만 top-down 선택 복원 — 정책값과 무관하게 항상 실행
            //    (trigger_type='cascade' 로 마킹된 것만 복원되므로 안전. 사용자 직접 삭제 답글의
            //    서브트리는 복원되지 않는다)
            $restoredIds = $this->postRepository->restoreCascadedByParentId($slug, $id);

            // ⑤ 되살아난 자손 답글들의 댓글/첨부도 cascade 분만 복원
            if ($restoredIds !== []) {
                $this->commentRepository->restoreCascadedByPostIds($slug, $restoredIds);
                $this->attachmentRepository->restoreCascadedByPostIds($slug, $restoredIds);
            }

            return $restoredPost;
        });

        // 훅: after_restore
        HookManager::doAction('sirsoft-board.post.after_restore', $restoredPost, $slug);

        return $restoredPost;
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
        return $this->postRepository->incrementViewCount($slug, $id, $boardId);
    }

    /**
     * 캐시 기반으로 조회수를 한 번만 증가시킵니다.
     *
     * 로그인 사용자는 user_id, 비로그인은 IP 기반으로 식별하며,
     * 환경설정의 view_count_cache_ttl(기본 86400초) 내 동일 게시글 재조회 시 조회수가 증가하지 않습니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  int|null  $boardId  게시판 ID (전달 시 슬러그 재조회 생략)
     * @return bool 조회수가 증가했으면 true, 이미 조회한 경우 false
     */
    public function incrementViewCountOnce(string $slug, int $id, ?int $boardId = null): bool
    {
        $identifier = Auth::id() ?? request()->ip();
        $key = "post_view_{$slug}_{$id}_{$identifier}";

        if ($this->cache->has($key)) {
            return false;
        }

        $this->incrementViewCount($slug, $id, $boardId);
        $ttl = (int) g7_module_settings('sirsoft-board', 'spam_security.view_count_cache_ttl', 86400);
        $this->cache->put($key, true, $ttl);

        return true;
    }

    /**
     * 댓글/첨부파일 카운트를 포함하여 게시글을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  int|null  $boardId  이미 검증된 게시판 ID (중복 조회 방지용)
     * @param  Board|null  $board  이미 조회한 게시판 모델 (넘기면 관계 적재까지 생략)
     * @param  string|null  $context  스코프 검사 컨텍스트 ('user' | 'admin'). null 이면 검사하지 않는다
     * @return Post 게시글 모델 (카운트 포함)
     *
     * @throws ModelNotFoundException 게시판 또는 게시글을 찾을 수 없는 경우
     * @throws AccessDeniedHttpException 스코프 접근이 거부된 경우 ($context 지정 시)
     */
    public function getPostWithCounts(
        string $slug,
        int $id,
        ?int $boardId = null,
        ?Board $board = null,
        ?string $context = null
    ): Post {
        $boardId = $board?->id ?? $boardId;

        // boardId가 전달되면 이미 검증된 것이므로 중복 조회 방지
        if (! $boardId) {
            $this->validateBoardExists($slug);
        }

        $post = $this->postRepository->findWithCounts($slug, $id, $boardId, $board);

        if (! $post) {
            throw new ModelNotFoundException(__('sirsoft-board::messages.errors.post_not_found'));
        }

        // 컨텍스트가 주어지면 스코프 접근 검사를 이 인스턴스로 수행한다.
        // 종전에는 권한 판정 전용으로 같은 행을 한 번 더 읽었다 (#519 F3).
        if ($context !== null) {
            $scopePermission = $context === 'admin'
                ? "sirsoft-board.{$slug}.admin.posts.read"
                : "sirsoft-board.{$slug}.posts.read";

            if (! PermissionHelper::checkScopeAccess($post, $scopePermission)) {
                throw new AccessDeniedHttpException(__('auth.scope_denied'));
            }
        }

        return $post;
    }

    /**
     * 게시글의 공지 여부를 경량 조회합니다.
     *
     * 권한/스코프 체크를 수행하지 않으므로 이전/다음 네비게이션처럼
     * 목록과 동일한 범위의 메타 판별용으로만 사용해야 합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  int  $boardId  게시판 ID
     * @return bool|null 공지 여부 또는 미존재 시 null
     */
    public function isPostNotice(string $slug, int $id, int $boardId): ?bool
    {
        return $this->postRepository->isNotice($id, $boardId);
    }

    /**
     * navigation 판별에 필요한 게시글 메타(카테고리·부모 ID)를 경량 조회합니다.
     *
     * 이전/다음 글의 카테고리 필터(47-1)와 답글 제외(47-4) 판단에 사용합니다.
     * 권한/스코프 체크를 수행하지 않으므로 navigation 메타 판별용으로만 사용해야 합니다.
     *
     * @param  int  $id  게시글 ID
     * @param  int  $boardId  게시판 ID
     * @return array{category: string|null, parent_id: int|null}|null 메타 또는 미존재 시 null
     */
    public function getPostNavigationMeta(int $id, int $boardId): ?array
    {
        return $this->postRepository->getNavigationMeta($id, $boardId);
    }

    /**
     * 이전/다음 게시글을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  현재 게시글 ID
     * @param  array  $filters  정렬 파라미터 (order_by, order_direction)
     * @param  bool  $withTrashed  삭제된 게시글 포함 여부
     * @param  Board|null  $board  게시판 모델 (전달 시 재조회 방지)
     * @return array{prev: Post|null, next: Post|null} 이전/다음 게시글
     *
     * @throws ModelNotFoundException 게시판을 찾을 수 없는 경우
     */
    public function getAdjacentPosts(string $slug, int $id, array $filters = [], bool $withTrashed = false, ?Board $board = null): array
    {
        // 게시판 존재성 검증 및 조회 (이전/다음 조회는 스코프 체크 불필요)
        if (! $board) {
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
        }

        if (! $board) {
            throw new ModelNotFoundException(__('sirsoft-board::messages.errors.board_not_found'));
        }

        // 게시판 정렬 설정이 filters에 없으면 게시판 기본값 사용
        if (empty($filters['order_by'])) {
            $orderByDefault = $board->order_by;
            $filters['order_by'] = $orderByDefault instanceof \BackedEnum ? $orderByDefault->value : ($orderByDefault ?? 'id');
        }
        if (empty($filters['order_direction'])) {
            $orderDirDefault = $board->order_direction;
            $filters['order_direction'] = $orderDirDefault instanceof \BackedEnum ? $orderDirDefault->value : ($orderDirDefault ?? 'desc');
        }

        $ttl = (int) g7_core_settings('cache.default_ttl', 86400);
        $category = $filters['category'] ?? null;
        $orderBy = $filters['order_by'] ?? 'id';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $cacheKey = "adjacent_{$slug}_{$id}"
            .($category ? "_{$category}" : '')
            ."_{$orderBy}_{$orderDirection}"
            .($withTrashed ? '_trashed' : '');

        return $this->cache->remember(
            $cacheKey,
            fn () => $this->postRepository->getAdjacentPosts($slug, $id, $filters, $withTrashed, $board->id),
            $ttl,
            tags: ['board-posts']
        );
    }

    /**
     * 게시글 상세 정보를 로드합니다 (관리자용).
     *
     * 조회수 증가, 댓글 로드, 이전/다음 게시글 조회 등 상세 조회에 필요한
     * 비즈니스 로직을 처리합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  게시글 ID
     * @param  bool  $canViewDeleted  삭제된 게시글 조회 가능 여부
     * @param  string  $context  호출 컨텍스트 (admin/user — 권한 스코프 판정용)
     * @return Post 상세 정보가 포함된 게시글 모델
     *
     * @throws ModelNotFoundException 게시판 또는 게시글을 찾을 수 없는 경우
     */
    public function loadPostDetail(string $slug, int $id, bool $canViewDeleted = false, string $context = 'admin'): Post
    {
        // 게시판 정보 조회 (상세 조회는 게시글 레벨에서 스코프 체크)
        $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

        if (! $board) {
            throw new ModelNotFoundException(__('sirsoft-board::messages.errors.board_not_found'));
        }

        // 조회수 증가 (캐시 기반 중복 방지) — 이미 조회한 게시판 ID 를 넘겨 재조회를 막는다
        $this->incrementViewCountOnce($slug, $id, $board->id);

        // 댓글/첨부파일 카운트 포함하여 게시글 조회 + 컨텍스트 기반 스코프 접근 검사.
        // 이미 조회한 Board 를 넘겨 게시판 재조회와 board 관계 적재를 함께 생략한다.
        $post = $this->getPostWithCounts($slug, $id, board: $board, context: $context);

        // 댓글 로드 (게시판 comment_order 설정 적용, Board 객체 전달로 중복 조회 방지)
        $comments = $this->commentService->getCommentsByPostId($slug, $id, boardId: $board->id, board: $board);

        // 댓글의 post에 board 관계 수동 설정 (CommentResource의 권한 체크에 필요)
        foreach ($comments as $comment) {
            $comment->setRelation('post', $post);
        }

        // 정렬된 댓글을 post에 설정
        $post->setRelation('comments', $comments);

        // 이전/다음 게시글 조회 (게시판 정렬 설정 반영)
        $post->navigation = $this->getAdjacentPosts($slug, $id, filters: [
            'order_by' => $board->order_by instanceof \BackedEnum ? $board->order_by->value : $board->order_by,
            'order_direction' => $board->order_direction instanceof \BackedEnum ? $board->order_direction->value : $board->order_direction,
        ], withTrashed: $canViewDeleted, board: $board);

        return $post;
    }

    /**
     * 게시판 존재성을 검증합니다.
     *
     * @param  string  $slug  게시판 슬러그
     *
     * @throws ModelNotFoundException 게시판을 찾을 수 없는 경우
     */
    private function validateBoardExists(string $slug): void
    {
        $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

        if (! $board) {
            throw new ModelNotFoundException(__('sirsoft-board::messages.errors.board_not_found'));
        }
    }

    // =========================================================================
    // 첨부파일 처리 메서드
    // =========================================================================

    /**
     * 게시글에 첨부파일을 연결합니다.
     *
     * 세 가지 방식으로 첨부파일을 연결합니다:
     * 1. 첨부파일 ID 배열로 직접 연결 (신규 방식)
     * 2. temp_key로 임시 첨부파일 연결 (취소 안전 방식)
     * 3. 파일 직접 업로드 (기존 방식 호환)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  array<int>  $attachmentIds  첨부파일 ID 배열
     * @param  string|null  $tempKey  임시 업로드 키
     * @param  array<UploadedFile>  $files  업로드할 파일 배열
     * @param  string  $context  로그 컨텍스트 (생성/수정)
     */
    private function linkAttachments(
        string $slug,
        int $postId,
        array $attachmentIds = [],
        ?string $tempKey = null,
        array $files = [],
        string $context = ''
    ): void {
        // 방안 A: 업로드 완료된 첨부파일 ID로 연결 (신규 방식)
        if (! empty($attachmentIds)) {
            // 개수 상한 최종 방어선 (기존 연결분과 합산) — Request 는 files[] 만 세므로 여기서 다시 판정한다
            $this->attachmentService->assertAttachmentCountWithin($slug, $postId, count($attachmentIds));

            $linkedCount = $this->attachmentRepository->linkAttachmentsByIds($slug, $attachmentIds, $postId);
            if ($linkedCount > 0) {
                Log::info("{$context} 시 첨부파일 ID로 연결 완료", [
                    'board_slug' => $slug,
                    'post_id' => $postId,
                    'linked_count' => $linkedCount,
                ]);
            }
        }

        // 방안 B: 임시 첨부파일 연결 + 파일 이동 (temp_key 방식)
        if ($tempKey) {
            $linkedCount = $this->attachmentService->linkTempAttachmentsWithMove($slug, $tempKey, $postId);
        }

        // 방안 C: 파일이 함께 전송된 경우 첨부파일 업로드 처리 (기존 방식 호환)
        if (! empty($files)) {
            $uploadedCount = 0;
            foreach ($files as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $this->attachmentService->upload(
                        slug: $slug,
                        file: $file,
                        postId: $postId,
                        collection: 'attachments'
                    );
                    $uploadedCount++;
                }
            }

            if ($uploadedCount > 0) {
                Log::info("{$context} 시 첨부파일 업로드 완료", [
                    'board_slug' => $slug,
                    'post_id' => $postId,
                    'uploaded_count' => $uploadedCount,
                ]);
            }
        }
    }

    // =========================================================================
    // 목록 조회 헬퍼 메서드
    // =========================================================================

    /**
     * 게시글 목록 조회 파라미터를 빌드합니다.
     *
     * User/Admin 공통으로 사용하며, 컨텍스트에 따라 필터와 perPage를 구성합니다.
     *
     * @param  array  $requestParams  요청 파라미터 배열
     * @param  array  $options  옵션 배열
     *                          - context: 'user' | 'admin' (기본: 'user')
     *                          - board: 게시판 객체 (user 필수)
     *                          - userAgent: User-Agent 문자열 (user에서 모바일 감지용)
     *                          - defaultPerPage: 기본 페이지당 항목 수 (기본: 20)
     *                          - minPerPage: 최소 페이지당 항목 수 (기본: 1)
     *                          - maxPerPage: 최대 페이지당 항목 수 (기본: 100)
     * @return array{filters: array, perPage: int} 필터와 perPage
     */
    public function buildListParams(array $requestParams, array $options = []): array
    {
        $context = $options['context'] ?? 'user';
        $board = $options['board'] ?? null;
        $userAgent = $options['userAgent'] ?? null;
        $defaultPerPage = $options['defaultPerPage'] ?? ($context === 'admin' ? 15 : 20);
        $minPerPage = $options['minPerPage'] ?? ($context === 'admin' ? 10 : 1);
        $maxPerPage = $options['maxPerPage'] ?? 100;

        // 정렬 파라미터 추출 (게시판 설정을 폴백으로 사용)
        $sortParams = $this->extractSortParams($requestParams, $board);

        // 검색 파라미터 추출 (Admin은 filters 배열 형식)
        if ($context === 'admin') {
            $filtersParam = $requestParams['filters'] ?? [];
            $searchValue = $filtersParam[0]['value'] ?? null;
            $searchField = $filtersParam[0]['field'] ?? 'all';
        } else {
            $searchValue = $requestParams['search'] ?? null;
            $searchField = 'all';
        }

        // 공통 필터 구성
        $filters = [
            'search' => $searchValue === '' ? null : $searchValue,
            'search_field' => $searchField,
            'category' => $requestParams['category'] ?? null,
            'board_categories' => $board ? ($board->categories ?? []) : [],
            'order_by' => $sortParams['order_by'],
            'order_direction' => $sortParams['order_direction'],
        ];

        // 컨텍스트별 추가 필터
        if ($context === 'admin') {
            $filters['status'] = $requestParams['status'] ?? null;
            $filters['is_notice'] = $requestParams['is_notice'] ?? null;
            $filters['user_id'] = $requestParams['user_id'] ?? null;
            $filters['created_at_from'] = $requestParams['created_at_from'] ?? null;
            $filters['created_at_to'] = $requestParams['created_at_to'] ?? null;
        }
        // 사용자 컨텍스트에는 추가 필터가 없다. 예전에는 여기서 `exclude_blinded` 를 세웠지만
        // 저장소가 그 키를 읽지 않아 한 번도 적용되지 않았고, 뒤늦게 배선하면 블라인드 글이
        // 사용자 목록에서 통째로 사라진다 — 이 모듈은 블라인드 글을 **행은 남기고 본문만
        // 가리는** 방식으로 다루며(PostResource::getMaskedContentPreviewForList) 레이아웃도
        // 블라인드 배지를 그린다. 죽은 키를 살리는 대신 제거해 표시만 오해를 주던 상태를 없앤다.

        // 페이지당 항목 수 계산
        $requestedPerPage = isset($requestParams['per_page']) ? (int) $requestParams['per_page'] : null;

        if ($board) {
            // 게시판 설정 적용 (사용자: 모바일 감지 포함, 관리자: PC 설정만)
            $isMobile = ($context === 'user') ? $this->isMobileRequest($userAgent) : false;
            $perPage = $this->calculatePerPage(
                $requestedPerPage,
                $board->per_page ?? $defaultPerPage,
                ($context === 'user') ? $board->per_page_mobile : null,
                $isMobile,
                $minPerPage,
                $maxPerPage
            );
        } else {
            $perPage = $this->calculatePerPage(
                $requestedPerPage,
                $defaultPerPage,
                null,
                false,
                $minPerPage,
                $maxPerPage
            );
        }

        return [
            'filters' => $filters,
            'perPage' => $perPage,
        ];
    }

    /**
     * 요청에서 정렬 파라미터를 추출합니다.
     *
     * 우선순위: 쿼리 파라미터 (sort_by/sort_order) > 게시판 설정 (order_by/order_direction) > 기본값
     *
     * @param  array  $requestParams  요청 파라미터 배열
     * @param  Board|null  $board  게시판 객체 (설정 폴백용)
     * @return array{order_by: string, order_direction: string} 정렬 파라미터
     */
    public function extractSortParams(array $requestParams, ?Board $board = null): array
    {
        // 게시판 설정값 추출 (Enum이면 value 사용)
        $boardOrderBy = $board?->order_by;
        $boardOrderDirection = $board?->order_direction;

        // Enum 객체에서 값 추출
        if ($boardOrderBy instanceof \BackedEnum) {
            $boardOrderBy = $boardOrderBy->value;
        }
        if ($boardOrderDirection instanceof \BackedEnum) {
            $boardOrderDirection = $boardOrderDirection->value;
        }

        return [
            'order_by' => $requestParams['sort_by']
                ?? $requestParams['order_by']
                ?? $boardOrderBy
                ?? 'created_at',
            'order_direction' => $requestParams['sort_order']
                ?? $requestParams['order_direction']
                ?? $boardOrderDirection
                ?? 'desc',
        ];
    }

    /**
     * User-Agent를 기반으로 모바일 여부를 감지합니다.
     *
     * @param  string|null  $userAgent  User-Agent 문자열
     * @return bool 모바일 여부
     */
    public function isMobileRequest(?string $userAgent): bool
    {
        if (empty($userAgent)) {
            return false;
        }

        return (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
    }

    /**
     * 게시판 설정과 요청에 따른 페이지당 항목 수를 계산합니다.
     *
     * @param  int|null  $requestedPerPage  요청된 페이지당 항목 수
     * @param  int  $boardPerPage  게시판 기본 설정
     * @param  int|null  $boardPerPageMobile  게시판 모바일 설정
     * @param  bool  $isMobile  모바일 여부
     * @param  int  $min  최소값 (기본: 1)
     * @param  int  $max  최대값 (기본: 100)
     * @return int 계산된 페이지당 항목 수
     */
    public function calculatePerPage(
        ?int $requestedPerPage,
        int $boardPerPage,
        ?int $boardPerPageMobile = null,
        bool $isMobile = false,
        int $min = 1,
        int $max = 100
    ): int {
        $defaultPerPage = $isMobile
            ? ($boardPerPageMobile ?? $boardPerPage)
            : $boardPerPage;

        $perPage = $requestedPerPage ?? $defaultPerPage;

        return min(max($perPage, $min), $max);
    }

    // =========================================================================
    // 비밀번호 검증 메서드
    // =========================================================================

    /**
     * 비회원 게시글 비밀번호를 검증합니다.
     *
     * 비회원 비밀글 열람 시 사용됩니다.
     * 회원 비밀글은 로그인 후 본인/권한자만 열람 가능하므로 비밀번호 검증 대상이 아닙니다.
     *
     * @param  Post  $post  게시글 모델
     * @param  string  $password  입력된 비밀번호
     * @return array{success: bool, error_key: string|null, error_code: int|null} 검증 결과
     */
    public function verifyPassword(Post $post, string $password): array
    {
        // 회원 게시글은 비밀번호 검증 대상이 아님
        if ($post->user_id) {
            return [
                'success' => false,
                'error_key' => 'sirsoft-board::messages.posts.password_verify_not_allowed',
                'error_code' => 400,
            ];
        }

        // 비회원 게시글: 게시글의 password 필드로 검증
        if (! $post->password) {
            return [
                'success' => false,
                'error_key' => 'sirsoft-board::messages.posts.no_password_set',
                'error_code' => 400,
            ];
        }

        if (! Hash::check($password, $post->password)) {
            return [
                'success' => false,
                'error_key' => 'sirsoft-board::messages.posts.password_incorrect',
                'error_code' => 403,
            ];
        }

        return [
            'success' => true,
            'error_key' => null,
            'error_code' => null,
        ];
    }

    /**
     * 사용자의 게시글 활동 목록을 조회합니다.
     *
     * 사용자가 작성한 게시글, 댓글을 단 게시글을 통합하여 반환합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 조건 (board_slug, search, activity_type, sort)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 게시글 활동 목록
     */
    public function getUserActivities(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $activityType = $filters['activity_type'] ?? 'authored';
        $boardSlug = $filters['board_slug'] ?? '';
        $search = $filters['search'] ?? '';

        // 필터/검색 없는 기본 조회 시에만 COUNT 캐시 적용
        if (empty($boardSlug) && empty($search)) {
            $cacheKey = "user_activities_total_{$userId}_{$activityType}";
            $cachedTotal = $this->cache->get($cacheKey);

            if ($cachedTotal !== null) {
                $filters['cached_total'] = (int) $cachedTotal;
            }
        }

        // 본인 활동 화면(`/me/board-activities`)이므로 열람자 = 대상 본인이다.
        // 저장소는 이 값이 없으면 타인 관점으로 보고 비밀글·미발행글을 걸러낸다(fail-closed).
        $filters['viewer_id'] = $userId;

        $result = $this->postRepository->getUserActivities($userId, $filters, $perPage);

        // 캐시 미적중 시 paginate 결과의 total을 캐시에 저장
        if (empty($boardSlug) && empty($search) && $cachedTotal === null) {
            $ttl = (int) g7_core_settings('cache.default_ttl', 86400);
            $total = $result->total();
            $this->cache->remember($cacheKey, fn () => $total, $ttl, tags: ['board-stats']);
        }

        return $result;
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
        $ttl = (int) g7_core_settings('cache.default_ttl', 86400);

        return $this->cache->remember(
            "user_activity_stats_{$userId}",
            fn () => $this->postRepository->getUserActivityStats($userId),
            $ttl,
            tags: ['board-stats']
        );
    }

    /**
     * 사용자의 공개 게시글 목록을 조회합니다 (공개 프로필용).
     *
     * 기존 getUserActivities()를 재사용합니다. 이 목록은 본문 일부(content_plain)를 함께
     * 싣기 때문에, 열람자가 본인이 아니면 비밀글·블라인드 글의 **본문만** 비운다. 행과
     * 제목은 남는다 — 게시판 목록에서 이미 같은 수준으로 보이고(PostResource 의 목록 규칙:
     * 제목은 노출, 본문만 차단) 프로필 UI 가 그 배지를 그리므로, 행을 지우면 결함 차단에
     * 필요한 범위를 넘어 기능이 깎인다.
     * 판정은 저장소가 `viewer_id` 로 수행한다(fail-closed — 값이 없으면 타인 관점).
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 옵션 (board_slug, sort 등)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 사용자 게시글 목록
     */
    public function getUserPublicPosts(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->postRepository->getUserActivities($userId, array_merge($filters, [
            'activity_type' => 'authored',  // 작성글만
            'viewer_id' => Auth::id(),      // 비로그인은 null → 타인 관점
        ]), $perPage);
    }

    /**
     * 사용자의 공개 게시글/댓글 통계를 조회합니다 (공개 프로필용).
     *
     * status=published인 게시글과 댓글만 카운트합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return array{posts_count: int, comments_count: int} 공개 게시글/댓글 통계
     */
    public function getUserPublicStats(int $userId): array
    {
        $cacheKey = "user_public_stats_{$userId}";

        return $this->cache->remember(
            $cacheKey,
            fn () => $this->postRepository->getUserPublicStats($userId),
            g7_core_settings('cache.default_ttl', 86400),
            tags: ['board-stats']
        );
    }

    // =========================================================================
    // 통합 검색 메서드
    // =========================================================================

    /**
     * 정렬 옵션을 DB 컬럼/방향으로 변환합니다.
     *
     * @param  string  $sort  정렬 옵션 (latest, oldest, views, popular, relevance)
     * @return array{0: string, 1: string} [컬럼명, 방향]
     */
    public function resolveSortColumn(string $sort): array
    {
        return match ($sort) {
            'latest' => ['created_at', 'desc'],
            'oldest' => ['created_at', 'asc'],
            'views', 'popular' => ['view_count', 'desc'],
            default => ['created_at', 'desc'],
        };
    }

    /**
     * 키워드로 게시글을 검색합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $keyword  검색 키워드
     * @param  string  $sort  정렬 옵션
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  페이지 번호
     * @return BoundedPage 페이지 결과 (총 건수 정확도 포함)
     */
    public function searchByKeyword(string $slug, string $keyword, string $sort = 'latest', int $perPage = 10, int $page = 1): BoundedPage
    {
        [$orderBy, $direction] = $this->resolveSortColumn($sort);

        return $this->postRepository->searchByKeyword($slug, $keyword, $orderBy, $direction, $perPage, $page);
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
        return $this->postRepository->countByKeyword($slug, $keyword);
    }

    /**
     * 여러 게시판에서 키워드로 게시글을 검색합니다 (단일 쿼리, DB 페이지네이션).
     *
     * @param  array  $boardIds  검색 대상 게시판 ID 목록
     * @param  string  $keyword  검색 키워드
     * @param  string  $sort  정렬 옵션
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  페이지 번호
     * @return BoundedPage 페이지 결과 (총 건수 정확도 포함)
     */
    public function searchAcrossBoards(array $boardIds, string $keyword, string $sort = 'latest', int $perPage = 10, int $page = 1): BoundedPage
    {
        [$orderBy, $direction] = $this->resolveSortColumn($sort);

        return $this->postRepository->searchAcrossBoards($boardIds, $keyword, $orderBy, $direction, $perPage, $page);
    }

    /**
     * 여러 게시판에서 키워드로 게시글을 커서(키셋)로 검색합니다.
     *
     * 커서 적용 가능 여부는 코어({@see SearchPagePolicy})가 판정한다. 이 서비스는
     * 정렬 선언({@see self::SEARCH_SORT_MAP})만 제공하고 규칙을 다시 쓰지 않는다.
     * 적용할 수 없는 정렬(관련도순 등)이면 null 을 돌려주고 호출자는 offset 경로를 쓴다.
     *
     * @param  array  $boardIds  검색 대상 게시판 ID 목록
     * @param  string  $keyword  검색 키워드
     * @param  string  $sort  정렬 옵션
     * @param  int  $perPage  페이지당 항목 수
     * @param  string|null  $cursor  인코딩된 커서 (첫 페이지면 null)
     * @param  int  $page  요청이 지목한 페이지 번호 (딥링크 판정용, 없으면 1)
     * @return CursorPaginator|null 커서 페이지 결과 (커서 적용 불가 시 null)
     */
    public function searchAcrossBoardsByCursor(
        array $boardIds,
        string $keyword,
        string $sort = 'latest',
        int $perPage = 10,
        ?string $cursor = null,
        int $page = 1
    ): ?CursorPaginator {
        $sortKeys = SearchPagePolicy::sortKeys($sort, self::SEARCH_SORT_MAP);

        if (! SearchPagePolicy::usesCursor($cursor, $sortKeys, self::SEARCH_CURSOR_COLUMNS, $page)) {
            return null;
        }

        return $this->postRepository->searchAcrossBoardsByCursor(
            $boardIds,
            $keyword,
            $sortKeys,
            $perPage,
            $cursor
        );
    }

    /**
     * 여러 게시판에서 키워드와 일치하는 게시글 수를 조회합니다 (단일 쿼리).
     *
     * @param  array  $boardIds  검색 대상 게시판 ID 목록
     * @param  string  $keyword  검색 키워드
     * @return BoundedCount 일치하는 게시글 수 (정확도 포함)
     */
    public function countAcrossBoards(array $boardIds, string $keyword): BoundedCount
    {
        return $this->postRepository->countAcrossBoards($boardIds, $keyword);
    }

    // =========================================================================
    // 캐시 관리 메서드
    // =========================================================================

    /**
     * 게시글 관련 캐시를 무효화합니다.
     *
     * 게시글 생성/삭제 시 홈페이지 통계 및 최근 게시글 캐시를 무효화합니다.
     *
     * @param  string  $slug  게시판 슬러그
     */
    private function invalidatePostCaches(string $slug): void
    {
        // 게시판별 게시글 수는 개별 키로 관리 (slug별 독립)
        $this->cache->forget("posts_count_{$slug}");

        // tags 기반 일괄 무효화
        $this->cache->flushTags(['board-stats']);   // 통계 캐시 (getCachedStats)
        $this->cache->flushTags(['board-posts']);   // 최근/인기/이전·다음 게시글 캐시
        $this->cache->flushTags(['board-list']);    // 인기 게시판 캐시
    }

    /**
     * 게시글 작성 쿨다운을 캐시에 기록합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $identifier  사용자 ID 또는 IP
     * @param  int  $seconds  쿨다운 시간 (초)
     */
    public function recordPostCooldown(string $slug, string|int $identifier, int $seconds): void
    {
        $this->cache->put("post_cooldown_{$slug}_{$identifier}", true, $seconds);
    }

    /**
     * 게시글 비밀번호 검증 토큰을 캐시에 저장하고 만료 시각을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string  $token  검증 토큰
     * @return array{token: string, expires_at: string} 토큰 및 만료 시각
     */
    public function storeDeleteVerifyToken(string $slug, int $postId, string $token): array
    {
        $ttl = (int) g7_core_settings('cache.post_verify_token_ttl', 3600);
        $expiresAt = now()->addSeconds($ttl);
        $this->cache->put("board_post_verify_{$slug}_{$postId}_{$token}", true, $ttl);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * 게시글 비밀번호 검증 토큰의 유효성을 확인하고 소비합니다.
     *
     * 토큰이 유효하면 즉시 삭제하여 재사용을 방지합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string  $token  검증 토큰
     * @return bool 토큰 유효 여부
     */
    public function consumeDeleteVerifyToken(string $slug, int $postId, string $token): bool
    {
        $key = "board_post_verify_{$slug}_{$postId}_{$token}";
        if (! $this->cache->has($key)) {
            return false;
        }
        $this->cache->forget($key);

        return true;
    }

    /**
     * 관리자 작업 이력 배열을 생성합니다.
     *
     * @param  string  $action  작업 유형 (delete, blind, restore 등)
     * @param  string|null  $reason  작업 사유
     * @return array 작업 이력 배열
     */
    private function buildActionLog(string $action, ?string $reason): array
    {
        return [
            'action' => $action,
            'reason' => $reason,
            'admin_id' => Auth::id(),
            'admin_name' => Auth::user()?->name ?? 'Unknown',
            'ip_address' => request()->ip(),
            'created_at' => now()->toDateTimeString(),
        ];
    }
}
