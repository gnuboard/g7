<?php

namespace Modules\Sirsoft\Board\Services;

use App\Extension\HookManager;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
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
        private PostRepositoryInterface $postRepository
    ) {}

    /**
     * 특정 게시글의 댓글 목록을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string  $context  컨텍스트 (admin 또는 user)
     * @param  bool|null  $withTrashed  삭제된 댓글 포함 여부 (null이면 내부 권한 체크로 결정)
     * @return Collection 정렬된 댓글 컬렉션
     */
    public function getCommentsByPostId(string $slug, int $postId, string $context = 'admin', ?bool $withTrashed = null): Collection
    {
        // withTrashed가 외부에서 지정되지 않은 경우 권한으로 결정
        if ($withTrashed === null) {
            $withTrashed = $this->checkBoardPermission($slug, 'admin.control')
                || $this->checkBoardPermission($slug, 'admin.manage')
                || $this->checkBoardPermission($slug, 'manager', \App\Enums\PermissionType::User);
        }

        // 게시판 설정에서 댓글 정렬 순서 가져오기 (기본값: DESC - 최신순)
        $board = $this->boardRepository->findBySlug($slug);
        $commentOrder = $board?->comment_order;

        // Enum인 경우 value 추출, 아니면 기본값 DESC
        $orderDirection = $commentOrder instanceof \BackedEnum
            ? $commentOrder->value
            : ($commentOrder ?? 'DESC');

        // 컨텍스트 기반 스코프 권한 식별자 설정
        $scopePermission = $context === 'admin'
            ? "sirsoft-board.{$slug}.admin.comments.read"
            : "sirsoft-board.{$slug}.comments.read";

        return $this->commentRepository->getByPostId($slug, $postId, $withTrashed, $orderDirection, $scopePermission);
    }

    /**
     * ID로 댓글을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  댓글 ID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getComment(string $slug, int $id): Comment
    {
        return $this->commentRepository->findOrFail($slug, $id);
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
        // 1. 게시판 관리자 권한 확인 (admin.manage 권한)
        if ($slug && Auth::check() && $this->checkBoardPermission($slug, 'admin.manage')) {
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
        // 1. 게시판 관리자 권한 확인 (admin.manage 권한)
        if ($slug && Auth::check() && $this->checkBoardPermission($slug, 'admin.manage')) {
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
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 게시글을 찾을 수 없는 경우
     * @throws \Exception 블라인드/삭제된 게시글인 경우
     */
    public function validatePostForComment(string $slug, int $postId): bool
    {
        $post = $this->postRepository->findOrFail($slug, $postId);

        if ($post->status === PostStatus::Blinded) {
            throw new \Exception(__('sirsoft-board::messages.comment.post_blinded'));
        }

        if ($post->status === PostStatus::Deleted || $post->deleted_at) {
            throw new \Exception(__('sirsoft-board::messages.comment.post_deleted'));
        }

        return true;
    }

    /**
     * 댓글을 생성합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $data  댓글 생성 데이터
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
            $parentComment = $this->commentRepository->find($slug, $data['parent_id']);
            if ($parentComment) {
                // 부모 댓글의 depth + 1 (최대 5까지)
                $data['depth'] = min(($parentComment->depth ?? 0) + 1, 5);
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
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function updateComment(string $slug, int $id, array $data): Comment
    {
        $comment = $this->commentRepository->findOrFail($slug, $id);

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
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function deleteComment(string $slug, int $id, ?string $triggerType = null): bool
    {
        $comment = $this->commentRepository->findOrFail($slug, $id);

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
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function blindComment(string $slug, int $id, string $reason, ?string $triggerType = null): Comment
    {
        $comment = $this->commentRepository->findOrFail($slug, $id);

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
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function restoreComment(string $slug, int $id, ?string $reason = null, ?string $triggerType = null): Comment
    {
        $comment = $this->commentRepository->findOrFail($slug, $id);

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
     * @return \Illuminate\Pagination\LengthAwarePaginator 댓글 목록
     */
    public function getUserComments(int $userId, array $filters = [], int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->commentRepository->getUserComments($userId, $filters, $perPage);
    }

    /**
     * 통계 캐시를 무효화합니다.
     *
     * 댓글 생성/삭제 시 홈페이지 통계 캐시를 무효화합니다.
     */
    private function invalidateStatsCache(): void
    {
        CacheService::forget('sirsoft-board', 'stats');
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
