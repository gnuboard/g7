<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\CacheInterface;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Exceptions\CommentDepthExceededException;
use Modules\Sirsoft\Board\Exceptions\PostNotCommentableException;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Support\SecretContentGate;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 댓글 관리 서비스 클래스
 *
 * 댓글의 생성, 수정, 삭제 등 비즈니스 로직을 담당하며,
 * 훅 시스템과 작업 이력 관리 기능을 제공합니다.
 */
class CommentService
{
    use ChecksBoardPermission;

    /**
     * CommentService 생성자
     *
     * @param  BoardRepositoryInterface  $boardRepository  게시판 리포지토리
     * @param  CommentRepositoryInterface  $commentRepository  댓글 리포지토리
     * @param  PostRepositoryInterface  $postRepository  게시글 리포지토리
     */
    public function __construct(
        private BoardRepositoryInterface $boardRepository,
        private CommentRepositoryInterface $commentRepository,
        private PostRepositoryInterface $postRepository,
        private CacheInterface $cache
    ) {}

    /**
     * 특정 게시글의 댓글 목록을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string  $context  컨텍스트 (admin 또는 user)
     * @param  bool|null  $withTrashed  삭제된 댓글 포함 여부 (null이면 내부 권한 체크로 결정)
     * @param  int|null  $boardId  게시판 ID (전달 시 Board 재조회 생략)
     * @param  Board|null  $board  게시판 모델 (전달 시 comment_order 조회를 위한 Board 재조회 방지)
     * @return Collection 정렬된 댓글 컬렉션
     */
    public function getCommentsByPostId(string $slug, int $postId, string $context = 'admin', ?bool $withTrashed = null, ?int $boardId = null, ?Board $board = null): Collection
    {
        // withTrashed가 외부에서 지정되지 않은 경우 권한으로 결정
        if ($withTrashed === null) {
            $withTrashed = $this->checkBoardPermission($slug, 'admin.control')
                || $this->checkBoardPermission($slug, 'admin.manage')
                || $this->checkBoardPermission($slug, 'manager', PermissionType::User);
        }

        // 게시판 설정에서 댓글 정렬 순서 가져오기 (기본값: DESC - 최신순)
        // Board 모델이 전달되면 재조회 없이 사용
        if (! $board) {
            $board = $boardId
                ? $this->boardRepository->find($boardId)
                : $this->boardRepository->findBySlug($slug);
        }
        $boardId = $boardId ?? $board?->id;
        $commentOrder = $board?->comment_order;

        // Enum인 경우 value 추출, 아니면 기본값 DESC
        $orderDirection = $commentOrder instanceof \BackedEnum
            ? $commentOrder->value
            : ($commentOrder ?? 'DESC');

        // 컨텍스트 기반 스코프 권한 식별자 설정
        $scopePermission = $context === 'admin'
            ? "sirsoft-board.{$slug}.admin.comments.read"
            : "sirsoft-board.{$slug}.comments.read";

        return $this->commentRepository->getByPostId($slug, $postId, $withTrashed, $orderDirection, $scopePermission, $boardId);
    }

    /**
     * 특정 게시글의 댓글을 원댓글 기준으로 페이지네이션해 조회합니다.
     *
     * 댓글이 상한을 넘는 글에서도 뒤쪽 댓글에 도달할 수 있게 하는 경로입니다.
     * 정렬 방향·권한 스코프 해석은 전량 조회와 동일합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  int  $perPage  페이지당 원댓글 수
     * @param  int  $page  현재 페이지
     * @param  string  $context  컨텍스트 (admin 또는 user)
     * @param  bool|null  $withTrashed  삭제된 댓글 포함 여부 (null이면 권한으로 결정)
     * @param  int|null  $boardId  게시판 ID
     * @param  Board|null  $board  게시판 모델
     * @return BoundedPage 원댓글 기준 페이지 (트리 정렬된 댓글 컬렉션)
     */
    public function paginateCommentsByPostId(
        string $slug,
        int $postId,
        int $perPage,
        int $page = 1,
        string $context = 'admin',
        ?bool $withTrashed = null,
        ?int $boardId = null,
        ?Board $board = null
    ): BoundedPage {
        if ($withTrashed === null) {
            $withTrashed = $this->checkBoardPermission($slug, 'admin.control')
                || $this->checkBoardPermission($slug, 'admin.manage')
                || $this->checkBoardPermission($slug, 'manager', PermissionType::User);
        }

        if (! $board) {
            $board = $boardId
                ? $this->boardRepository->find($boardId)
                : $this->boardRepository->findBySlug($slug);
        }
        $boardId = $boardId ?? $board?->id;
        $commentOrder = $board?->comment_order;

        $orderDirection = $commentOrder instanceof \BackedEnum
            ? $commentOrder->value
            : ($commentOrder ?? 'DESC');

        $scopePermission = $context === 'admin'
            ? "sirsoft-board.{$slug}.admin.comments.read"
            : "sirsoft-board.{$slug}.comments.read";

        return $this->commentRepository->paginateRootsByPostId(
            $slug,
            $postId,
            $perPage,
            $page,
            $withTrashed,
            $orderDirection,
            $scopePermission,
            $boardId
        );
    }

    /**
     * ID로 댓글을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  int|null  $postId  상위 게시글 ID (전달 시 해당 게시글의 댓글로 조회 범위 제한)
     * @return Comment 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    /**
     * 특정 게시글의 댓글 총 건수를 조회합니다.
     *
     * 목록이 상한에서 끊긴 경우에만 부르도록 설계돼 있습니다 — 상한 이하면 이미 전량을
     * 받았으므로 세는 쿼리를 다시 실행할 이유가 없습니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  bool  $withTrashed  삭제 댓글 포함 여부
     * @param  int|null  $boardId  게시판 ID (전달 시 Board 재조회 생략)
     * @return BoundedCount 댓글 총 건수 (정확도 포함)
     */
    public function countCommentsByPostId(string $slug, int $postId, bool $withTrashed = false, ?int $boardId = null): BoundedCount
    {
        return $this->commentRepository->countByPostId($slug, $postId, $withTrashed, $boardId);
    }

    /**
     * 댓글 하나를 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  int|null  $postId  게시글 ID (전달 시 상위 스코프까지 검사)
     * @return Comment 조회된 댓글
     *
     * @throws ModelNotFoundException 댓글이 없거나 상위 스코프가 다를 때
     */
    public function getComment(string $slug, int $id, ?int $postId = null): Comment
    {
        return $this->commentRepository->findOrFail($slug, $id, $postId);
    }

    /**
     * 비회원 댓글의 비밀번호를 검증합니다.
     *
     * @param  Comment  $comment  댓글 인스턴스
     * @param  string  $password  입력된 비밀번호
     * @return bool 비밀번호 일치 여부
     */
    public function verifyGuestPassword(Comment $comment, string $password): bool
    {
        // 회원 댓글인 경우 false 반환
        if ($comment->user_id) {
            return false;
        }

        // 비밀번호가 없으면 false 반환
        if (! $comment->password) {
            return false;
        }

        // 해시된 비밀번호 검증
        return password_verify($password, $comment->password);
    }

    /**
     * 사용자가 댓글을 수정할 권한이 있는지 확인합니다.
     *
     * @param  Comment  $comment  댓글 인스턴스
     * @param  int|null  $userId  사용자 ID (null이면 비회원)
     * @param  string|null  $password  비회원 비밀번호 (비회원인 경우)
     * @param  string|null  $slug  게시판 슬러그 (관리자 권한 체크용)
     * @return bool 수정 권한 여부
     */
    public function canUpdate(Comment $comment, ?int $userId, ?string $password = null, ?string $slug = null): bool
    {
        // 1. 게시판 관리 권한 확인 (admin.manage 또는 사용자 페이지 manager 권한)
        if ($slug && Auth::check() && (
            $this->checkBoardPermission($slug, 'admin.manage')
            || $this->checkBoardPermission($slug, 'manager', PermissionType::User)
        )) {
            return true;
        }

        // 2. 회원인 경우: 본인 댓글이면 허용, 비회원 댓글이면 비밀번호로 검증
        if ($userId) {
            if ($comment->user_id === $userId) {
                return true;
            }
            if (! $comment->user_id && $password) {
                return $this->verifyGuestPassword($comment, $password);
            }

            return false;
        }

        // 3. 비회원인 경우: 비밀번호 검증
        if ($password) {
            return $this->verifyGuestPassword($comment, $password);
        }

        return false;
    }

    /**
     * 사용자가 댓글을 삭제할 권한이 있는지 확인합니다.
     *
     * @param  Comment  $comment  댓글 인스턴스
     * @param  int|null  $userId  사용자 ID (null이면 비회원)
     * @param  string|null  $password  비회원 비밀번호 (비회원인 경우)
     * @param  string|null  $slug  게시판 슬러그 (관리자 권한 체크용)
     * @return bool 삭제 권한 여부
     */
    public function canDelete(Comment $comment, ?int $userId, ?string $password = null, ?string $slug = null): bool
    {
        // 1. 게시판 관리 권한 확인 (admin.manage 또는 사용자 페이지 manager 권한)
        if ($slug && Auth::check() && (
            $this->checkBoardPermission($slug, 'admin.manage')
            || $this->checkBoardPermission($slug, 'manager', PermissionType::User)
        )) {
            return true;
        }

        // 2. 회원인 경우: 본인 댓글이면 허용, 비회원 댓글이면 비밀번호로 검증
        if ($userId) {
            if ($comment->user_id === $userId) {
                return true;
            }
            if (! $comment->user_id && $password) {
                return $this->verifyGuestPassword($comment, $password);
            }

            return false;
        }

        // 3. 비회원인 경우: 비밀번호 검증
        if ($password) {
            return $this->verifyGuestPassword($comment, $password);
        }

        return false;
    }

    /**
     * 게시글이 댓글 작성 가능한 상태인지 확인합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return bool 댓글 작성 가능 여부
     *
     * @throws ModelNotFoundException 게시글을 찾을 수 없는 경우
     * @throws PostNotCommentableException 블라인드/삭제/비열람 비밀 게시글인 경우
     */
    public function validatePostForComment(string $slug, int $postId): bool
    {
        $post = $this->postRepository->findOrFail($slug, $postId);

        if ($post->status === PostStatus::Blinded) {
            throw PostNotCommentableException::blinded();
        }

        if ($post->status === PostStatus::Deleted || $post->deleted_at) {
            throw PostNotCommentableException::deleted();
        }

        // 비밀글 하위 쓰기 게이트 (KVE-2026-2044) — 요청 단계 규칙을 우회해도 여기서 막힌다.
        // 서비스가 최종 관문이므로 판정은 읽기와 같은 SecretContentGate(SSoT)를 쓴다.
        // 비밀글일 때만 board 를 붙인다: 게이트의 슬러그 해석이 라우트에 없으면 관계로
        // 폴백하는데, 미로딩이면 fail-closed 라 비-HTTP 호출에서 정상 흐름까지 막힌다.
        // 비밀글이 아니면 게이트는 언제나 통과하므로 그 조회를 하지 않는다.
        if ($post->is_secret) {
            if (! $post->relationLoaded('board')) {
                $post->load('board');
            }

            if (! app(SecretContentGate::class)->canWriteChild($post)) {
                throw PostNotCommentableException::secret();
            }
        }

        return true;
    }

    /**
     * 댓글을 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $data  댓글 생성 데이터
     * @return Comment 생성된 댓글 모델
     *
     * @throws \Exception 블라인드/삭제된 게시글에 댓글 작성 시
     */
    public function createComment(string $slug, array $data): Comment
    {
        // 게시판 존재성 검증 및 board_id 설정
        $board = $this->boardRepository->findBySlug($slug);
        if (! $board) {
            throw new ModelNotFoundException(__('sirsoft-board::messages.errors.board_not_found'));
        }
        $data['board_id'] = $board->id;

        // 게시글 블라인드/삭제 상태 확인
        $this->validatePostForComment($slug, $data['post_id']);

        // 훅: before_create
        HookManager::doAction('sirsoft-board.comment.before_create', $slug, $data);

        // 훅: filter_create_data
        $data = HookManager::applyFilters('sirsoft-board.comment.filter_create_data', $data, $slug);

        // depth 자동 계산 (답글인 경우)
        if (! empty($data['parent_id'])) {
            // 같은 게시글에 속한 부모 댓글만 인정 (교차 게시글 부모 차단)
            $parentComment = $this->commentRepository->find($slug, $data['parent_id'], (int) $data['post_id']);
            if ($parentComment) {
                $data['depth'] = ($parentComment->depth ?? 0) + 1;

                // 최종 불변조건 — `CommentValidationRule` 은 요청 단계 선차단이라 훅이나
                // Service 직접 호출 경로에는 걸리지 않는다. 클램프하지 않고 예외를 던진다:
                // 요청한 위치와 다른 자리에 조용히 붙으면 사용자가 알 수 없다.
                $board = $this->boardRepository->findBySlug($slug);
                $maxDepth = (int) ($board->max_comment_depth ?? 0);

                if ($data['depth'] > $maxDepth) {
                    throw new CommentDepthExceededException($maxDepth, $data['depth']);
                }
            } else {
                // 부모 댓글을 찾을 수 없으면 0으로 설정
                $data['depth'] = 0;
            }
        } else {
            // parent_id가 없으면 최상위 댓글이므로 depth = 0
            $data['depth'] = 0;
        }

        // 댓글 생성
        $comment = $this->commentRepository->create($slug, $data);

        // 훅: after_create
        HookManager::doAction('sirsoft-board.comment.after_create', $comment, $slug);

        // 통계 캐시 무효화 (댓글 수 변경됨)
        $this->invalidateStatsCache();

        return $comment;
    }

    /**
     * 댓글을 수정합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  array  $data  수정할 데이터
     * @param  int|null  $postId  상위 게시글 ID (전달 시 해당 게시글의 댓글로 조회 범위 제한)
     * @return Comment 수정된 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function updateComment(string $slug, int $id, array $data, ?int $postId = null): Comment
    {
        $comment = $this->commentRepository->findOrFail($slug, $id, $postId);

        // 훅: before_update
        HookManager::doAction('sirsoft-board.comment.before_update', $comment, $data, $slug);

        $snapshot = $comment->toArray();

        // 훅: filter_update_data
        $data = HookManager::applyFilters('sirsoft-board.comment.filter_update_data', $data, $comment, $slug);

        // 댓글 수정
        $updatedComment = $this->commentRepository->update($slug, $id, $data);

        // 훅: after_update
        HookManager::doAction('sirsoft-board.comment.after_update', $updatedComment, $slug, $snapshot);

        return $updatedComment;
    }

    /**
     * 댓글을 삭제합니다 (소프트 삭제).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  string|null  $triggerType  트리거 유형 (admin, user, report 등)
     * @param  int|null  $postId  상위 게시글 ID (전달 시 해당 게시글의 댓글로 조회 범위 제한)
     * @return bool 삭제 성공 여부
     *
     * @throws ModelNotFoundException
     */
    public function deleteComment(string $slug, int $id, ?string $triggerType = null, ?int $postId = null): bool
    {
        $comment = $this->commentRepository->findOrFail($slug, $id, $postId);

        // 훅: before_delete
        HookManager::doAction('sirsoft-board.comment.before_delete', $comment, $slug);

        // 작업 이력 생성
        $actionLog = $this->buildActionLog('delete', null);

        // 상태 변경 (deleted로 변경, trigger_type 기록) 후 소프트 삭제
        $deletedComment = $this->commentRepository->updateStatus($slug, $id, 'deleted', $actionLog, $triggerType);
        $deletedComment->delete();

        // 훅: after_delete
        HookManager::doAction('sirsoft-board.comment.after_delete', $deletedComment, $slug);

        // 통계 캐시 무효화 (댓글 수 변경됨)
        $this->invalidateStatsCache();

        return true;
    }

    /**
     * 댓글을 블라인드 처리합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  string  $reason  블라인드 사유
     * @param  string|null  $triggerType  트리거 유형 (report, admin, auto_hide 등)
     * @param  int|null  $postId  상위 게시글 ID (전달 시 해당 게시글의 댓글로 조회 범위 제한)
     * @return Comment 블라인드 처리된 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function blindComment(string $slug, int $id, string $reason, ?string $triggerType = null, ?int $postId = null): Comment
    {
        $comment = $this->commentRepository->findOrFail($slug, $id, $postId);

        // 멱등성: 이미 블라인드 상태이면 중복 처리 방지
        if ($comment->status === PostStatus::Blinded) {
            return $comment;
        }

        // 훅: before_blind
        HookManager::doAction('sirsoft-board.comment.before_blind', $comment, $reason, $slug);

        // 작업 이력 생성
        $actionLog = $this->buildActionLog('blind', $reason);

        // 상태 변경
        $blindedComment = $this->commentRepository->updateStatus($slug, $id, 'blinded', $actionLog, $triggerType);

        // 훅: after_blind
        HookManager::doAction('sirsoft-board.comment.after_blind', $blindedComment, $slug);

        return $blindedComment;
    }

    /**
     * 블라인드 또는 삭제된 댓글을 복원합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     * @param  string|null  $reason  복원 사유
     * @param  string|null  $triggerType  트리거 유형 (report, admin, auto_hide 등)
     * @param  int|null  $postId  상위 게시글 ID (전달 시 해당 게시글의 댓글로 조회 범위 제한)
     * @return Comment 복원된 댓글 모델
     *
     * @throws ModelNotFoundException
     */
    public function restoreComment(string $slug, int $id, ?string $reason = null, ?string $triggerType = null, ?int $postId = null): Comment
    {
        $comment = $this->commentRepository->findOrFail($slug, $id, $postId);

        // 멱등성: 이미 게시됨 상태이면 중복 처리 방지
        if ($comment->status === PostStatus::Published) {
            return $comment;
        }

        // 훅: before_restore
        HookManager::doAction('sirsoft-board.comment.before_restore', $comment, $reason, $slug);

        // 작업 이력 생성
        $actionLog = $this->buildActionLog('restore', $reason);

        // 상태 변경 (published로 복원)
        $restoredComment = $this->commentRepository->updateStatus($slug, $id, 'published', $actionLog, $triggerType);

        // 훅: after_restore
        HookManager::doAction('sirsoft-board.comment.after_restore', $restoredComment, $slug);

        return $restoredComment;
    }

    /**
     * 사용자가 작성한 댓글 목록을 페이지네이션하여 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 조건 (board_slug, search, sort)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 댓글 목록
     */
    public function getUserComments(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $boardSlug = $filters['board_slug'] ?? '';
        $search = $filters['search'] ?? '';

        // 필터/검색 없는 기본 조회 시에만 COUNT 캐시 적용
        if (empty($boardSlug) && empty($search)) {
            $cacheKey = "user_comments_total_{$userId}";
            $cachedTotal = $this->cache->get($cacheKey);

            if ($cachedTotal !== null) {
                $filters['cached_total'] = (int) $cachedTotal;
            }
        }

        $result = $this->commentRepository->getUserComments($userId, $filters, $perPage);

        // 캐시 미적중 시 paginate 결과의 total을 캐시에 저장
        if (empty($boardSlug) && empty($search) && ($cachedTotal ?? null) === null) {
            $ttl = (int) g7_core_settings('cache.default_ttl', 86400);
            $total = $result->total();
            $this->cache->remember($cacheKey, fn () => $total, $ttl, tags: ['board-stats']);
        }

        return $result;
    }

    /**
     * 통계 캐시를 무효화합니다.
     *
     * 댓글 생성/삭제 시 홈페이지 통계 캐시를 무효화합니다.
     */
    private function invalidateStatsCache(): void
    {
        $this->cache->flushTags(['board-stats']);
    }

    /**
     * 댓글 작성 쿨다운을 캐시에 기록합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string|int  $identifier  사용자 ID 또는 IP
     * @param  int  $seconds  쿨다운 시간 (초)
     */
    public function recordCommentCooldown(string $slug, string|int $identifier, int $seconds): void
    {
        $this->cache->put("comment_cooldown_{$slug}_{$identifier}", true, $seconds);
    }

    /**
     * 댓글 비밀번호 검증 토큰을 캐시에 저장하고 만료 시각을 반환합니다.
     *
     * 게시글(PostService::storeDeleteVerifyToken)과 동형 — 비회원이 비밀번호를
     * 확인하면 1회용 토큰을 발급해, 이후 수정/삭제 요청에서 평문 비밀번호 재전송 대신
     * 이 토큰으로 본인 확인을 대체한다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $commentId  댓글 ID
     * @param  string  $token  검증 토큰
     * @return array{token: string, expires_at: string} 토큰 및 만료 시각
     */
    public function storeCommentVerifyToken(string $slug, int $commentId, string $token): array
    {
        $ttl = (int) g7_core_settings('cache.post_verify_token_ttl', 3600);
        $expiresAt = now()->addSeconds($ttl);
        $this->cache->put("board_comment_verify_{$slug}_{$commentId}_{$token}", true, $ttl);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * 댓글 비밀번호 검증 토큰의 유효성을 확인하고 소비합니다.
     *
     * 토큰이 유효하면 즉시 삭제하여 재사용을 방지합니다(단일 사용).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $commentId  댓글 ID
     * @param  string  $token  검증 토큰
     * @return bool 토큰 유효 여부
     */
    public function consumeCommentVerifyToken(string $slug, int $commentId, string $token): bool
    {
        $key = "board_comment_verify_{$slug}_{$commentId}_{$token}";
        if (! $this->cache->has($key)) {
            return false;
        }
        $this->cache->forget($key);

        return true;
    }

    /**
     * 관리자 작업 이력 배열을 생성합니다.
     *
     * @param  string  $action  작업 유형 (blind, restore 등)
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
