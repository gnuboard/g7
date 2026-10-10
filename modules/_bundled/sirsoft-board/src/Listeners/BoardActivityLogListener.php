<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use App\Models\Menu;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Services\ReactionService;

/**
 * 게시판 모듈 활동 로그 리스너
 *
 * 게시판 서비스에서 발행하는 훅을 구독하여
 * Log::channel('activity')를 통해 활동 로그를 기록합니다.
 *
 * Monolog 기반 아키텍처:
 * Service -> doAction -> BoardActivityLogListener -> Log::channel('activity') -> ActivityLogHandler -> DB
 */
class BoardActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * @param  ReportRepositoryInterface  $reportRepository  신고 bulk lookup
     * @param  ReactionTypeRepositoryInterface  $reactionTypeRepository  반응 유형 라벨 조회
     */
    public function __construct(
        protected ReportRepositoryInterface $reportRepository,
        protected ReactionTypeRepositoryInterface $reactionTypeRepository,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // ─── Board ───
            'sirsoft-board.board.after_create' => ['method' => 'handleBoardAfterCreate', 'priority' => 20],
            'sirsoft-board.board.after_update' => ['method' => 'handleBoardAfterUpdate', 'priority' => 20],
            'sirsoft-board.board.after_delete' => ['method' => 'handleBoardAfterDelete', 'priority' => 20],
            'sirsoft-board.board.after_add_to_menu' => ['method' => 'handleBoardAfterAddToMenu', 'priority' => 20],
            'sirsoft-board.board.after_remove_from_menu' => ['method' => 'handleBoardAfterRemoveFromMenu', 'priority' => 20],
            'sirsoft-board.settings.after_bulk_apply' => ['method' => 'handleSettingsAfterBulkApply', 'priority' => 20],
            'sirsoft-board.settings.after_bulk_apply_aborted' => ['method' => 'handleSettingsAfterBulkApplyAborted', 'priority' => 20],

            // ─── BoardType ───
            'sirsoft-board.board_type.after_create' => ['method' => 'handleBoardTypeAfterCreate', 'priority' => 20],
            'sirsoft-board.board_type.after_update' => ['method' => 'handleBoardTypeAfterUpdate', 'priority' => 20],
            'sirsoft-board.board_type.after_delete' => ['method' => 'handleBoardTypeAfterDelete', 'priority' => 20],

            // ─── Post ───
            'sirsoft-board.post.after_create' => ['method' => 'handlePostAfterCreate', 'priority' => 20],
            'sirsoft-board.post.after_update' => ['method' => 'handlePostAfterUpdate', 'priority' => 20],
            'sirsoft-board.post.after_delete' => ['method' => 'handlePostAfterDelete', 'priority' => 20],
            'sirsoft-board.post.after_blind' => ['method' => 'handlePostAfterBlind', 'priority' => 20],
            'sirsoft-board.post.after_restore' => ['method' => 'handlePostAfterRestore', 'priority' => 20],

            // ─── Comment ───
            'sirsoft-board.comment.after_create' => ['method' => 'handleCommentAfterCreate', 'priority' => 20],
            'sirsoft-board.comment.after_update' => ['method' => 'handleCommentAfterUpdate', 'priority' => 20],
            'sirsoft-board.comment.after_delete' => ['method' => 'handleCommentAfterDelete', 'priority' => 20],
            'sirsoft-board.comment.after_blind' => ['method' => 'handleCommentAfterBlind', 'priority' => 20],
            'sirsoft-board.comment.after_restore' => ['method' => 'handleCommentAfterRestore', 'priority' => 20],

            // ─── Attachment ───
            'sirsoft-board.attachment.after_upload' => ['method' => 'handleAttachmentAfterUpload', 'priority' => 20],
            'sirsoft-board.attachment.after_delete' => ['method' => 'handleAttachmentAfterDelete', 'priority' => 20],
            'sirsoft-board.attachment.after_download' => ['method' => 'handleAttachmentAfterDownload', 'priority' => 20],

            // ─── Report ───
            'sirsoft-board.report.after_create' => ['method' => 'handleReportAfterCreate', 'priority' => 20],
            'sirsoft-board.report.after_update_status' => ['method' => 'handleReportAfterUpdateStatus', 'priority' => 20],
            'sirsoft-board.report.after_bulk_update_status' => ['method' => 'handleReportAfterBulkUpdateStatus', 'priority' => 20],
            'sirsoft-board.report.after_delete' => ['method' => 'handleReportAfterDelete', 'priority' => 20],
            'sirsoft-board.report.after_restore_content' => ['method' => 'handleReportAfterRestoreContent', 'priority' => 20],
            'sirsoft-board.report.after_blind_content' => ['method' => 'handleReportAfterBlindContent', 'priority' => 20],
            'sirsoft-board.report.after_delete_content' => ['method' => 'handleReportAfterDeleteContent', 'priority' => 20],
            'sirsoft-board.report.after_cancel' => ['method' => 'handleReportAfterCancel', 'priority' => 20],

            // ─── Scrap ───
            'sirsoft-board.scrap.after_toggle' => ['method' => 'handleScrapAfterToggle', 'priority' => 20],
            // ─── Reaction ───
            'sirsoft-board.reaction.after_react' => ['method' => 'handleReactionAfterReact', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ═══════════════════════════════════════════
    // Board 핸들러
    // ═══════════════════════════════════════════

    /**
     * 게시판 생성 후 로그 기록
     *
     * @param  Board  $board  생성된 게시판
     * @param  array  $data  생성 데이터
     * @return void
     */
    public function handleBoardAfterCreate(Board $board, array $data): void
    {
        $this->logActivity('board.create', [
            'loggable' => $board,
            'description_key' => 'sirsoft-board::activity_log.description.board_create',
            'description_params' => ['board_name' => $board->name ?? ''],
            'properties' => ['name' => $board->name, 'slug' => $board->slug ?? ''],
        ]);
    }

    /**
     * 게시판 수정 후 로그 기록
     *
     * @param  Board  $updatedBoard  수정된 게시판
     * @param  array  $data  수정 데이터
     * @param  array|null  $snapshot  수정 전 스냅샷 (서비스에서 전달)
     * @return void
     */
    public function handleBoardAfterUpdate(Board $updatedBoard, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($updatedBoard, $snapshot);

        $this->logActivity('board.update', [
            'loggable' => $updatedBoard,
            'description_key' => 'sirsoft-board::activity_log.description.board_update',
            'description_params' => ['board_name' => $updatedBoard->name ?? ''],
            'changes' => $changes,
        ]);
    }

    /**
     * 게시판 삭제 후 로그 기록
     *
     * @param  Board  $board  삭제된 게시판
     * @return void
     */
    public function handleBoardAfterDelete(?Board $board): void
    {
        if ($board === null) {
            return;
        }

        $this->logActivity('board.delete', [
            'loggable' => $board,
            'description_key' => 'sirsoft-board::activity_log.description.board_delete',
            'description_params' => ['board_name' => $board->name ?? ''],
            'properties' => ['name' => $board->name, 'slug' => $board->slug ?? ''],
        ]);
    }

    /**
     * 게시판 메뉴 추가 후 로그 기록
     *
     * @param  Menu  $menu  추가된 메뉴
     * @param  Board  $board  대상 게시판
     * @return void
     */
    public function handleBoardAfterAddToMenu(Menu $menu, Board $board): void
    {
        $this->logActivity('board.add_to_menu', [
            'loggable' => $board,
            'description_key' => 'sirsoft-board::activity_log.description.board_add_to_menu',
            'description_params' => [
                'board_name' => $board->name ?? '',
                'menu_name' => $menu->name ?? '',
            ],
            'properties' => ['menu_id' => $menu->id, 'board_id' => $board->id],
        ]);
    }

    /**
     * 게시판 메뉴 제거 후 로그 기록
     *
     * @param  Menu  $menu  제거된 메뉴
     * @param  Board  $board  대상 게시판
     * @return void
     */
    public function handleBoardAfterRemoveFromMenu(Menu $menu, Board $board): void
    {
        $this->logActivity('board.remove_from_menu', [
            'loggable' => $board,
            'description_key' => 'sirsoft-board::activity_log.description.board_remove_from_menu',
            'description_params' => [
                'board_name' => $board->name ?? '',
                'menu_name' => $menu->name ?? '',
            ],
            'properties' => ['menu_id' => $menu->id, 'board_id' => $board->id],
        ]);
    }

    /**
     * 게시판 설정 일괄 적용 후 로그 기록
     *
     * @param  array  $fields  적용된 필드 목록
     * @param  int  $updatedCount  업데이트된 게시판 수
     * @return void
     */
    public function handleSettingsAfterBulkApply(array $fields, int $updatedCount): void
    {
        $this->logActivity('board_settings.bulk_apply', [
            'description_key' => 'sirsoft-board::activity_log.description.board_settings_bulk_apply',
            'description_params' => ['updated_count' => $updatedCount],
            'properties' => ['fields' => $fields, 'updated_count' => $updatedCount],
        ]);
    }

    /**
     * 게시판 설정 일괄 적용 중단(전체 롤백) 후 로그 기록
     *
     * 일괄 적용 중 한 지점이라도 실패하면 전체 변경이 롤백되며,
     * 그 중단 사실을 활동 이력에 남긴다. 컬럼 업데이트 실패(failed_board=null)는
     * 게시판 특정 불가이므로 실패 게시판명을 '-' 로 기록한다.
     *
     * 훅 인자는 큐 직렬화를 거치므로 Exception 객체가 아닌 배열(toLogContext)을 받는다.
     *
     * @param  array  $fields  적용 시도한 필드 목록
     * @param  array  $context  중단 정보 (failed_board, failed_at, total, failure_reason)
     * @return void
     */
    public function handleSettingsAfterBulkApplyAborted(array $fields, array $context): void
    {
        $failedBoard = $context['failed_board'] ?? null;

        $this->logActivity('board_settings.bulk_apply_aborted', [
            'description_key' => 'sirsoft-board::activity_log.description.board_settings_bulk_apply_aborted',
            'description_params' => [
                'failed_board_name' => $failedBoard['name'] ?? '-',
                'failed_at' => $context['failed_at'] ?? 0,
                'total' => $context['total'] ?? 0,
            ],
            'properties' => [
                'fields' => $fields,
                'failed_board' => $failedBoard,
                'failed_at' => $context['failed_at'] ?? null,
                'total' => $context['total'] ?? 0,
                'failure_reason' => $context['failure_reason'] ?? null,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // BoardType 핸들러
    // ═══════════════════════════════════════════

    /**
     * 게시판 유형 생성 후 로그 기록
     *
     * @param  BoardType  $boardType  생성된 게시판 유형
     * @param  array  $data  생성 데이터
     * @return void
     */
    public function handleBoardTypeAfterCreate(BoardType $boardType, array $data): void
    {
        $this->logActivity('board_type.create', [
            'loggable' => $boardType,
            'description_key' => 'sirsoft-board::activity_log.description.board_type_create',
            'description_params' => ['type_name' => $boardType->name ?? ''],
            'properties' => ['name' => $boardType->name],
        ]);
    }

    /**
     * 게시판 유형 수정 후 로그 기록
     *
     * @param  BoardType  $updatedBoardType  수정된 게시판 유형
     * @param  array  $data  수정 데이터
     * @param  array|null  $snapshot  수정 전 스냅샷 (서비스에서 전달)
     * @return void
     */
    public function handleBoardTypeAfterUpdate(BoardType $updatedBoardType, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($updatedBoardType, $snapshot);

        $this->logActivity('board_type.update', [
            'loggable' => $updatedBoardType,
            'description_key' => 'sirsoft-board::activity_log.description.board_type_update',
            'description_params' => ['type_name' => $updatedBoardType->name ?? ''],
            'changes' => $changes,
        ]);
    }

    /**
     * 게시판 유형 삭제 후 로그 기록
     *
     * @param  BoardType  $boardType  삭제된 게시판 유형
     * @return void
     */
    public function handleBoardTypeAfterDelete(?BoardType $boardType): void
    {
        if ($boardType === null) {
            return;
        }

        $this->logActivity('board_type.delete', [
            'loggable' => $boardType,
            'description_key' => 'sirsoft-board::activity_log.description.board_type_delete',
            'description_params' => ['type_name' => $boardType->name ?? ''],
            'properties' => ['name' => $boardType->name],
        ]);
    }

    // ═══════════════════════════════════════════
    // Post 핸들러
    // ═══════════════════════════════════════════

    /**
     * 게시글 생성 후 로그 기록
     *
     * @param  Post  $post  생성된 게시글
     * @param  string  $slug  게시판 슬러그
     * @return void
     */
    public function handlePostAfterCreate(Post $post, string $slug): void
    {
        $post->loadMissing('board');

        $this->logActivity('post.create', [
            'loggable' => $post,
            'description_key' => 'sirsoft-board::activity_log.description.post_create',
            'description_params' => [
                'title' => $post->title ?? '',
                'board_name' => $post->board?->name ?? '',
            ],
            'properties' => ['title' => $post->title, 'slug' => $slug, 'board_name' => $post->board?->name ?? ''],
        ]);
    }

    /**
     * 게시글 수정 후 로그 기록
     *
     * @param  Post  $updatedPost  수정된 게시글
     * @param  string  $slug  게시판 슬러그
     * @param  array|null  $snapshot  수정 전 스냅샷 (서비스에서 전달)
     * @return void
     */
    public function handlePostAfterUpdate(Post $updatedPost, string $slug, ?array $snapshot = null): void
    {
        $updatedPost->loadMissing('board');

        $changes = ChangeDetector::detect($updatedPost, $snapshot);

        $this->logActivity('post.update', [
            'loggable' => $updatedPost,
            'description_key' => 'sirsoft-board::activity_log.description.post_update',
            'description_params' => [
                'title' => $updatedPost->title ?? '',
                'board_name' => $updatedPost->board?->name ?? '',
            ],
            'changes' => $changes,
        ]);
    }

    /**
     * 게시글 삭제 후 로그 기록
     *
     * @param  Post  $deletedPost  삭제된 게시글
     * @param  string  $slug  게시판 슬러그
     * @return void
     */
    public function handlePostAfterDelete(?Post $deletedPost, string $slug): void
    {
        if ($deletedPost === null) {
            return;
        }

        $deletedPost->loadMissing('board');

        $this->logActivity('post.delete', [
            'loggable' => $deletedPost,
            'description_key' => 'sirsoft-board::activity_log.description.post_delete',
            'description_params' => [
                'post_id' => $deletedPost->id,
                'board_name' => $deletedPost->board?->name ?? '',
            ],
            'properties' => ['title' => $deletedPost->title, 'slug' => $slug, 'board_name' => $deletedPost->board?->name ?? ''],
        ]);
    }

    /**
     * 게시글 블라인드 처리 후 로그 기록
     *
     * @param  Post  $blindedPost  블라인드 처리된 게시글
     * @param  string  $slug  게시판 슬러그
     * @return void
     */
    public function handlePostAfterBlind(Post $blindedPost, string $slug): void
    {
        $blindedPost->loadMissing('board');

        $this->logActivity('post.blind', [
            'loggable' => $blindedPost,
            'description_key' => 'sirsoft-board::activity_log.description.post_blind',
            'description_params' => [
                'post_id' => $blindedPost->id,
                'board_name' => $blindedPost->board?->name ?? '',
            ],
            'properties' => ['title' => $blindedPost->title, 'slug' => $slug, 'board_name' => $blindedPost->board?->name ?? ''],
        ]);
    }

    /**
     * 게시글 복원 후 로그 기록
     *
     * @param  Post  $restoredPost  복원된 게시글
     * @param  string  $slug  게시판 슬러그
     * @return void
     */
    public function handlePostAfterRestore(Post $restoredPost, string $slug): void
    {
        $restoredPost->loadMissing('board');

        $this->logActivity('post.restore', [
            'loggable' => $restoredPost,
            'description_key' => 'sirsoft-board::activity_log.description.post_restore',
            'description_params' => [
                'post_id' => $restoredPost->id,
                'board_name' => $restoredPost->board?->name ?? '',
            ],
            'properties' => ['title' => $restoredPost->title, 'slug' => $slug, 'board_name' => $restoredPost->board?->name ?? ''],
        ]);
    }

    // ═══════════════════════════════════════════
    // Comment 핸들러
    // ═══════════════════════════════════════════

    /**
     * 댓글 생성 후 로그 기록
     *
     * @param  Comment  $comment  생성된 댓글
     * @param  string  $slug  게시판 슬러그
     * @return void
     */
    public function handleCommentAfterCreate(Comment $comment, string $slug): void
    {
        $comment->loadMissing('post.board');

        $this->logActivity('comment.create', [
            'loggable' => $comment,
            'description_key' => 'sirsoft-board::activity_log.description.comment_create',
            'description_params' => [
                'board_name' => $comment->post?->board?->name ?? '',
                'post_id' => $comment->post_id ?? null,
            ],
            'properties' => ['slug' => $slug, 'post_id' => $comment->post_id ?? null, 'board_name' => $comment->post?->board?->name ?? ''],
        ]);
    }

    /**
     * 댓글 수정 후 로그 기록
     *
     * @param  Comment  $updatedComment  수정된 댓글
     * @param  string  $slug  게시판 슬러그
     * @param  array|null  $snapshot  수정 전 스냅샷 (서비스에서 전달)
     * @return void
     */
    public function handleCommentAfterUpdate(Comment $updatedComment, string $slug, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($updatedComment, $snapshot);

        $this->logActivity('comment.update', [
            'loggable' => $updatedComment,
            'description_key' => 'sirsoft-board::activity_log.description.comment_update',
            'description_params' => ['comment_id' => $updatedComment->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 댓글 삭제 후 로그 기록
     *
     * @param  Comment  $deletedComment  삭제된 댓글
     * @param  string  $slug  게시판 슬러그
     * @return void
     */
    public function handleCommentAfterDelete(?Comment $deletedComment, string $slug): void
    {
        if ($deletedComment === null) {
            return;
        }

        $this->logActivity('comment.delete', [
            'loggable' => $deletedComment,
            'description_key' => 'sirsoft-board::activity_log.description.comment_delete',
            'description_params' => ['comment_id' => $deletedComment->id],
            'properties' => ['slug' => $slug, 'post_id' => $deletedComment->post_id ?? null],
        ]);
    }

    /**
     * 댓글 블라인드 처리 후 로그 기록
     *
     * @param  Comment  $blindedComment  블라인드 처리된 댓글
     * @param  string  $slug  게시판 슬러그
     * @return void
     */
    public function handleCommentAfterBlind(Comment $blindedComment, string $slug): void
    {
        $this->logActivity('comment.blind', [
            'loggable' => $blindedComment,
            'description_key' => 'sirsoft-board::activity_log.description.comment_blind',
            'description_params' => ['comment_id' => $blindedComment->id],
            'properties' => ['slug' => $slug, 'post_id' => $blindedComment->post_id ?? null],
        ]);
    }

    /**
     * 댓글 복원 후 로그 기록
     *
     * @param  Comment  $restoredComment  복원된 댓글
     * @param  string  $slug  게시판 슬러그
     * @return void
     */
    public function handleCommentAfterRestore(Comment $restoredComment, string $slug): void
    {
        $this->logActivity('comment.restore', [
            'loggable' => $restoredComment,
            'description_key' => 'sirsoft-board::activity_log.description.comment_restore',
            'description_params' => ['comment_id' => $restoredComment->id],
            'properties' => ['slug' => $slug, 'post_id' => $restoredComment->post_id ?? null],
        ]);
    }

    // ═══════════════════════════════════════════
    // Attachment 핸들러
    // ═══════════════════════════════════════════

    /**
     * 첨부파일 업로드 후 로그 기록
     *
     * @param  Attachment  $attachment  업로드된 첨부파일
     * @return void
     */
    public function handleAttachmentAfterUpload(?Attachment $attachment): void
    {
        if ($attachment === null) {
            return; // 큐 워커 시점에 모델이 이미 사라진 경우 스킵
        }

        $this->logActivity('attachment.upload', [
            'loggable' => $attachment,
            'description_key' => 'sirsoft-board::activity_log.description.board_attachment_upload',
            'description_params' => ['post_id' => $attachment->post_id ?? null],
            'properties' => [
                'original_filename' => $attachment->original_filename ?? '',
                'size' => $attachment->size ?? 0,
                'post_id' => $attachment->post_id ?? null,
            ],
        ]);
    }

    /**
     * 첨부파일 삭제 후 로그 기록
     *
     * @param  Attachment  $attachment  삭제된 첨부파일
     * @return void
     */
    public function handleAttachmentAfterDelete(?Attachment $attachment): void
    {
        if ($attachment === null) {
            return;
        }

        $this->logActivity('attachment.delete', [
            'loggable' => $attachment,
            'description_key' => 'sirsoft-board::activity_log.description.board_attachment_delete',
            'description_params' => ['post_id' => $attachment->post_id ?? null],
            'properties' => [
                'original_filename' => $attachment->original_filename ?? '',
                'post_id' => $attachment->post_id ?? null,
            ],
        ]);
    }

    /**
     * 첨부파일 다운로드 후 로그 기록
     *
     * @param  Attachment|null  $attachment  다운로드된 첨부파일
     * @param  string  $context  권한 컨텍스트 (user | admin) — 로그 부가정보
     * @return void
     */
    public function handleAttachmentAfterDownload(?Attachment $attachment, string $context = 'user'): void
    {
        if ($attachment === null) {
            return; // 큐 워커 시점에 모델이 이미 사라진 경우 스킵
        }

        $this->logActivity('attachment.download', [
            'loggable' => $attachment,
            'description_key' => 'sirsoft-board::activity_log.description.board_attachment_download',
            'description_params' => ['post_id' => $attachment->post_id ?? null],
            'properties' => [
                'original_filename' => $attachment->original_filename ?? '',
                'post_id' => $attachment->post_id ?? null,
                'context' => $context,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Report 핸들러
    // ═══════════════════════════════════════════

    /**
     * 신고 생성 후 로그 기록
     *
     * 사유는 케이스(`Report`)가 아니라 이번 신고자 로그(`ReportLog`)에 있다 — 케이스 모델에서
     * `reason`·`reportable_type` 을 읽던 동안 두 값이 늘 빈 문자열로 남았다(7.1.0 실측 O-K2-6).
     * 대상 유형은 케이스의 `target_type`(post|comment)이다. 속성 키 이름은 기존 행과 맞춰 둔다.
     *
     * @param  Report|null  $report  생성(또는 합류)된 신고 케이스
     * @param  ReportLog|null  $reportLog  이번 신고자 로그 (훅 둘째 인자)
     * @return void
     */
    public function handleReportAfterCreate(?Report $report, ?ReportLog $reportLog = null): void
    {
        if ($report === null) {
            return;
        }

        $reason = $reportLog?->reason_type;
        $targetType = $report->target_type;

        $this->logActivity('report.create', [
            'loggable' => $report,
            'description_key' => 'sirsoft-board::activity_log.description.report_create',
            'description_params' => ['report_id' => $report->id],
            'properties' => [
                'reason' => $reason instanceof \BackedEnum ? $reason->value : (string) ($reason ?? ''),
                'reportable_type' => $targetType instanceof \BackedEnum ? $targetType->value : (string) ($targetType ?? ''),
            ],
        ]);
    }

    /**
     * 신고 상태 변경 후 로그 기록
     *
     * @param  Report  $updatedReport  상태 변경된 신고
     * @return void
     */
    public function handleReportAfterUpdateStatus(?Report $updatedReport): void
    {
        if ($updatedReport === null) {
            return;
        }

        $this->logActivity('report.update_status', [
            'loggable' => $updatedReport,
            'description_key' => 'sirsoft-board::activity_log.description.report_update_status',
            'description_params' => ['report_id' => $updatedReport->id],
            'properties' => ['status' => $updatedReport->status?->value ?? (string) $updatedReport->status],
        ]);
    }

    /**
     * 신고 상태 일괄 변경 후 로그 기록
     *
     * @param  array  $ids  대상 신고 ID 목록
     * @param  array  $data  변경 데이터
     * @param  int  $affectedRows  영향받은 행 수
     * @param  array  $snapshots  수정 전 스냅샷 맵 (report_id => snapshot, 서비스에서 전달)
     * @return void
     */
    public function handleReportAfterBulkUpdateStatus(array $ids, array $data, int $affectedRows, array $snapshots = []): void
    {
        $reports = $this->reportRepository->findByIdsKeyed($ids);

        foreach ($ids as $reportId) {
            $report = $reports->get($reportId);
            if (! $report) {
                continue;
            }

            $snapshot = $snapshots[$reportId] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($report, $snapshot) : null;

            $this->logActivity('report.bulk_update_status', [
                'loggable' => $report,
                'description_key' => 'sirsoft-board::activity_log.description.report_bulk_update_status',
                'description_params' => ['count' => 1],
                'properties' => ['report_id' => $reportId, 'data' => $data],
                'changes' => $changes,
            ]);
        }
    }

    /**
     * 신고 삭제 후 로그 기록
     *
     * @param  Report  $report  삭제된 신고
     * @return void
     */
    public function handleReportAfterDelete(?Report $report): void
    {
        if ($report === null) {
            return;
        }

        $this->logActivity('report.delete', [
            'loggable' => $report,
            'description_key' => 'sirsoft-board::activity_log.description.report_delete',
            'description_params' => ['report_id' => $report->id],
        ]);
    }

    /**
     * 신고 대상 콘텐츠 복원 후 로그 기록
     *
     * @param  Report  $report  대상 신고
     * @return void
     */
    public function handleReportAfterRestoreContent(Report $report): void
    {
        $this->logActivity('report.restore_content', [
            'loggable' => $report,
            'description_key' => 'sirsoft-board::activity_log.description.report_restore_content',
            'description_params' => ['report_id' => $report->id],
        ]);
    }

    /**
     * 신고 대상 콘텐츠 블라인드 처리 후 로그 기록
     *
     * @param  Report  $report  대상 신고
     * @return void
     */
    public function handleReportAfterBlindContent(Report $report): void
    {
        $this->logActivity('report.blind_content', [
            'loggable' => $report,
            'description_key' => 'sirsoft-board::activity_log.description.report_blind_content',
            'description_params' => ['report_id' => $report->id],
        ]);
    }

    /**
     * 신고 대상 콘텐츠 삭제 처리 후 로그 기록
     *
     * @param  Report  $report  대상 신고
     * @return void
     */
    public function handleReportAfterDeleteContent(Report $report): void
    {
        $this->logActivity('report.delete_content', [
            'loggable' => $report,
            'description_key' => 'sirsoft-board::activity_log.description.report_delete_content',
            'description_params' => ['report_id' => $report->id],
        ]);
    }

    /**
     * 신고 취소(마이페이지 「내 신고」) 후 로그 기록
     *
     * 케이스의 마지막 로그가 사라지면 신고 케이스 자체가 forceDelete 되므로
     * ($caseDeleted=true) 그 뒤에는 `loggable` 로 모델을 넘길 수 없다 —
     * 스크랩 해제와 같은 방식으로 loggable_type/loggable_id 를 직접 지정한다.
     *
     * @param  int  $userId  신고를 취소한 회원 ID
     * @param  Report  $report  대상 신고 케이스(이미 삭제됐어도 id 는 유효)
     * @param  string|null  $reasonType  취소된 신고의 사유 유형
     * @param  bool  $caseDeleted  true=케이스까지 삭제됨(마지막 신고자였다)
     * @return void
     */
    public function handleReportAfterCancel(
        int $userId,
        Report $report,
        ?string $reasonType = null,
        bool $caseDeleted = false,
    ): void {
        $this->logActivity('report.cancel', [
            'loggable_type' => Report::class,
            'loggable_id' => $report->id,
            'description_key' => 'sirsoft-board::activity_log.description.report_cancel',
            'description_params' => ['report_id' => $report->id],
            'properties' => [
                'reason' => $reasonType ?? '',
                'case_deleted' => $caseDeleted,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Scrap 핸들러
    // ═══════════════════════════════════════════

    /**
     * 스크랩 토글 후 로그 기록
     *
     * $added 값에 따라 등록(scrap.scrap)/해제(scrap.unscrap)를 구분해 기록한다.
     * 원글이 삭제된 뒤 마이페이지에서 해제하는 경우에도 로그를 남겨야 하므로
     * Post 모델을 조회하지 않고 loggable_type/loggable_id 를 직접 지정한다
     * (리스너의 직접 DB 접근 금지 규정 + 삭제된 엔티티 대응).
     *
     * @param  int  $userId  스크랩을 토글한 회원 ID
     * @param  int  $postId  대상 게시글 ID
     * @param  bool  $added  true=등록, false=해제
     * @return void
     */
    public function handleScrapAfterToggle(int $userId, int $postId, bool $added): void
    {
        $action = $added ? 'scrap.scrap' : 'scrap.unscrap';
        $descriptionKey = $added
            ? 'sirsoft-board::activity_log.description.scrap_add'
            : 'sirsoft-board::activity_log.description.scrap_remove';

        $this->logActivity($action, [
            'loggable_type' => Post::class,
            'loggable_id' => $postId,
            'description_key' => $descriptionKey,
            'description_params' => ['post_id' => $postId],
        ]);
    }

    // ═══════════════════════════════════════════
    // Reaction 핸들러
    // ═══════════════════════════════════════════

    /**
     * 반응 등록/전환/해제 후 로그 기록
     *
     * 대상은 게시글(Post)과 댓글(Comment) 둘 다이며, 원본이 이미 삭제된 뒤
     * 마이페이지·관리자에서 반응만 해제하는 경로에서는 null 로 들어온다 —
     * 이 경우 스크랩 해제와 같은 방식으로 loggable_type/loggable_id 를 직접 지정해
     * 로그를 남긴다 (리스너의 직접 DB 접근 금지 + 삭제된 엔티티 대응).
     *
     * 인자 $targetType·$targetId 는 기존 시그니처 호환을 위해 **뒤에만** 추가한다.
     *
     * @param  int  $userId  반응한 사용자 ID
     * @param  Post|Comment|null  $target  대상 게시글·댓글 (삭제됐으면 null)
     * @param  int  $reactionTypeId  반응 유형 ID
     * @param  string  $action  수행된 동작 (add | change | remove)
     * @param  string  $targetType  대상 타입 (post | comment)
     * @param  int|null  $targetId  대상 ID ($target 가 null 일 때 로그 대상 지정용)
     * @return void
     */
    public function handleReactionAfterReact(
        int $userId,
        Post|Comment|null $target,
        int $reactionTypeId,
        string $action,
        string $targetType = ReactionService::TARGET_POST,
        ?int $targetId = null,
    ): void {
        $target?->loadMissing('board');

        $reactionType = $this->reactionTypeRepository->findById($reactionTypeId);
        $typeName = $reactionType?->getLocalizedName() ?? '';
        $isComment = $targetType === ReactionService::TARGET_COMMENT;

        $actionKey = match ($action) {
            'change' => $isComment ? 'comment_reaction.change' : 'reaction.change',
            'remove' => $isComment ? 'comment_reaction.remove' : 'reaction.remove',
            default => $isComment ? 'comment_reaction.add' : 'reaction.add',
        };

        $prefix = $isComment ? 'comment_reaction_' : 'reaction_';
        $descriptionKey = 'sirsoft-board::activity_log.description.'.$prefix.match ($action) {
            'change' => 'change',
            'remove' => 'remove',
            default => 'add',
        };

        $boardName = $target?->board?->name ?? '';

        if ($isComment) {
            /** @var Comment|null $target */
            $descriptionParams = [
                'post_id' => $target?->post_id ?? '',
                'comment_id' => $target?->id ?? $targetId ?? '',
                'reaction_type' => $typeName,
                'board_name' => $boardName,
            ];
            $properties = [
                'post_id' => $target?->post_id,
                'comment_id' => $target?->id ?? $targetId,
                'reaction_type_id' => $reactionTypeId,
                'reaction_type' => $typeName,
                'board_name' => $boardName,
            ];
        } else {
            /** @var Post|null $target */
            $descriptionParams = [
                'title' => $target?->title ?? '',
                'reaction_type' => $typeName,
                'board_name' => $boardName,
            ];
            $properties = [
                'title' => $target?->title,
                'reaction_type_id' => $reactionTypeId,
                'reaction_type' => $typeName,
                'board_name' => $boardName,
            ];
        }

        $context = [
            'description_key' => $descriptionKey,
            'description_params' => $descriptionParams,
            'properties' => $properties,
        ];

        if ($target !== null) {
            $context['loggable'] = $target;
        } else {
            // 대상이 이미 삭제된 경로 — 모델 없이 로그 대상을 직접 지정한다.
            $context['loggable_type'] = $isComment ? Comment::class : Post::class;
            $context['loggable_id'] = $targetId;
        }

        $this->logActivity($actionKey, $context);
    }
}
