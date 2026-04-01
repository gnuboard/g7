<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Notifications\NewCommentNotification;
use Modules\Sirsoft\Board\Notifications\NewPostAdminNotification;
use Modules\Sirsoft\Board\Notifications\PostActionNotification;
use Modules\Sirsoft\Board\Notifications\PostReplyNotification;
use Modules\Sirsoft\Board\Notifications\ReplyCommentNotification;
use Modules\Sirsoft\Board\Notifications\ReportActionNotification;
use Modules\Sirsoft\Board\Notifications\ReportReceivedAdminNotification;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\UserNotificationSettingService;

/**
 * 게시판 알림 발송 리스너
 *
 * 게시글/댓글 생성 및 관리자 처리 이벤트를 수신하여
 * 조건에 맞는 사용자에게 이메일 알림을 발송합니다.
 *
 * 설정 확인 우선순위:
 * 1. 게시판 레벨 설정 (Board.notify_*) — OFF이면 무조건 미발송
 * 2. 사용자 레벨 설정 (UserNotificationSetting.notify_*) — OFF이면 미발송
 * 3. 자기 자신 제외 — 작성자 == 수신자이면 미발송
 */
class BoardNotificationListener implements HookListenerInterface
{
    /**
     * 중복 실행 방지를 위한 처리 완료 키 저장소
     * HookManager가 addAction + Event::listen 이중 등록으로 인해
     * 동일 훅이 두 번 실행되는 문제를 방지합니다.
     *
     * @var array<string, bool>
     */
    private static array $processedKeys = [];

    /**
     * BoardNotificationListener 생성자
     *
     * @param UserNotificationSettingService $notificationSettingService 알림 설정 서비스
     * @param BoardRepositoryInterface $boardRepository 게시판 저장소
     * @param PostRepositoryInterface $postRepository 게시글 저장소
     * @param CommentRepositoryInterface $commentRepository 댓글 저장소
     */
    public function __construct(
        private UserNotificationSettingService $notificationSettingService,
        private BoardRepositoryInterface $boardRepository,
        private PostRepositoryInterface $postRepository,
        private CommentRepositoryInterface $commentRepository
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array<string, array{method: string, priority: int}>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.comment.after_create' => [
                'method' => 'afterCommentCreate',
                'priority' => 20,
            ],
            'sirsoft-board.post.after_create' => [
                'method' => 'afterPostCreate',
                'priority' => 20,
            ],
            'sirsoft-board.post.after_blind' => [
                'method' => 'afterPostBlind',
                'priority' => 20,
            ],
            'sirsoft-board.post.after_delete' => [
                'method' => 'afterPostDelete',
                'priority' => 20,
            ],
            'sirsoft-board.post.after_restore' => [
                'method' => 'afterPostRestore',
                'priority' => 20,
            ],
            'sirsoft-board.comment.after_blind' => [
                'method' => 'afterCommentBlind',
                'priority' => 20,
            ],
            'sirsoft-board.comment.after_delete' => [
                'method' => 'afterCommentDelete',
                'priority' => 20,
            ],
            'sirsoft-board.comment.after_restore' => [
                'method' => 'afterCommentRestore',
                'priority' => 20,
            ],
            'sirsoft-board.report.after_create' => [
                'method' => 'afterReportCreate',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (인터페이스 요구사항)
     *
     * @param mixed ...$args 전달 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 댓글 생성 후 알림을 처리합니다.
     *
     * 시나리오 1+5: 내 글에 댓글 → 글 작성자에게 알림
     * 시나리오 2: 내 댓글에 대댓글 → 부모 댓글 작성자에게 알림
     *
     * @param Comment $comment 생성된 댓글
     * @param string $slug 게시판 슬러그
     * @return void
     */
    public function afterCommentCreate(Comment $comment, string $slug): void
    {
        // 중복 실행 방지
        $key = "comment_create_{$slug}_{$comment->id}";
        if (isset(self::$processedKeys[$key])) {
            return;
        }
        self::$processedKeys[$key] = true;

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board) {
            return;
        }

        $post = $this->postRepository->find($slug, $comment->post_id);
        if (! $post) {
            return;
        }

        $commentAuthorName = $comment->user?->name ?? $comment->author_name ?? '';

        // 대댓글인 경우 대댓글 알림만 발송
        if (! empty($comment->parent_id)) {
            // 시나리오 2: 대댓글 알림
            $this->notifyParentCommentAuthor($board, $post, $comment, $slug, $commentAuthorName);
        } else {
            // 시나리오 1+5: 내 글에 댓글 알림 (일반 댓글만)
            $this->notifyPostAuthorOnComment($board, $post, $comment, $slug, $commentAuthorName);
        }
    }

    /**
     * 게시글 생성 후 알림을 처리합니다.
     *
     * 시나리오 3: 답변글 → 원글 작성자에게 알림
     * 시나리오 6: 새 게시글 → 게시판 관리자에게 알림
     *
     * 외부 모듈이 `sirsoft-board.notification.skip_post_create` Filter 훅으로
     * true를 반환하면 알림 발송을 건너뜁니다.
     * (예: sirsoft-ecommerce — 이커머스 경로로 등록된 문의글은 이커머스 전용 알림으로 처리)
     *
     * @param Post $post 생성된 게시글
     * @param string $slug 게시판 슬러그
     * @param array $options 게시글 생성 옵션 (예: ['skip_notification' => true])
     * @return void
     */
    public function afterPostCreate(Post $post, string $slug, array $options = []): void
    {
        try {
            // 중복 실행 방지
            $key = "post_create_{$slug}_{$post->id}";
            if (isset(self::$processedKeys[$key])) {
                return;
            }
            self::$processedKeys[$key] = true;

            // 알림 SKIP 여부 확인 — 호출자가 $options['skip_notification']으로 명시적으로 전달
            if (($options['skip_notification'] ?? false) === true) {
                return;
            }

            $board = $this->boardRepository->findBySlug($slug);
            if (! $board) {
                return;
            }

            // 시나리오 3: 답변글 알림
            $this->notifyParentPostAuthorOnReply($board, $post, $slug);

            // 시나리오 6: 관리자 새글 알림
            $this->notifyAdminOnNewPost($board, $post, $slug);
        } catch (\Exception $e) {
            Log::error('BoardNotificationListener: 게시글 생성 후 알림 처리 실패', [
                'post_id' => $post->id ?? null,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 게시글 블라인드 처리 알림을 발송합니다.
     *
     * @param Post $post 처리된 게시글
     * @param string $slug 게시판 슬러그
     * @return void
     */
    public function afterPostBlind(Post $post, string $slug): void
    {
        $this->sendPostActionNotification($post, $slug, 'blind');
    }

    /**
     * 게시글 삭제 알림을 발송합니다.
     *
     * 외부 모듈이 `sirsoft-board.notification.skip_post_delete` Filter 훅으로
     * true를 반환하면 알림 발송을 건너뜁니다.
     * (예: sirsoft-ecommerce — 이커머스 경로로 삭제된 문의글은 알림 불필요)
     *
     * @param Post $post 처리된 게시글
     * @param string $slug 게시판 슬러그
     * @param array $options 게시글 삭제 옵션 (예: ['skip_notification' => true])
     * @return void
     */
    public function afterPostDelete(Post $post, string $slug, array $options = []): void
    {
        // 알림 SKIP 여부 확인 — 호출자가 $options['skip_notification']으로 명시적으로 전달
        if (($options['skip_notification'] ?? false) === true) {
            return;
        }

        $this->sendPostActionNotification($post, $slug, 'deleted');
    }

    /**
     * 게시글 복원 알림을 발송합니다.
     *
     * @param Post $post 처리된 게시글
     * @param string $slug 게시판 슬러그
     * @return void
     */
    public function afterPostRestore(Post $post, string $slug): void
    {
        $this->sendPostActionNotification($post, $slug, 'restored');
    }

    /**
     * 게시글 처리 알림을 발송합니다.
     *
     * 시나리오 4: 관리자 또는 시스템이 게시글 처리 → 글 작성자에게 알림
     * - TriggerType::Report / TriggerType::AutoHide → ReportActionNotification (신고 처리 알림)
     * - 그 외 (Admin 직권 등) → PostActionNotification (관리자 처리 알림)
     *
     * @param Post $post 처리된 게시글
     * @param string $slug 게시판 슬러그
     * @param string $actionType 처리 유형 (blind, deleted, restored)
     * @return void
     */
    private function sendPostActionNotification(Post $post, string $slug, string $actionType): void
    {
        // 중복 실행 방지
        $key = "post_action_{$actionType}_{$slug}_{$post->id}";
        if (isset(self::$processedKeys[$key])) {
            return;
        }
        self::$processedKeys[$key] = true;

        if (! $post->user_id) {
            return;
        }

        // 관리자가 처리한 경우에만 알림 발송 (본인이 직접 삭제한 경우 제외)
        $currentUserId = Auth::id();
        if ($currentUserId === $post->user_id) {
            return;
        }

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board) {
            return;
        }

        // 수신자 이메일 확인
        $recipient = $post->user;
        if (! $recipient || ! $recipient->email) {
            return;
        }

        // 신고 처리 vs 자동 블라인드 vs 관리자 직권 처리 분기
        if ($post->trigger_type === TriggerType::Report || $post->trigger_type === TriggerType::AutoHide) {
            $reportPolicy = g7_module_settings('sirsoft-board', 'report_policy', []);
            if (empty($reportPolicy['notify_author_on_report_action'])) {
                return;
            }

            try {
                $recipient->notify(new ReportActionNotification(
                    postId: $post->id,
                    boardId: $board->id,
                    slug: $slug,
                    postTitle: $post->title ?? '',
                    actionType: $actionType,
                    targetType: 'post'
                ));

                Log::info('게시판 알림 발송: 신고/자동 블라인드 처리 (게시글)', [
                    'type' => 'report_action',
                    'trigger_type' => $post->trigger_type?->value,
                    'action_type' => $actionType,
                    'board' => $slug,
                    'post_id' => $post->id,
                    'recipient_id' => $post->user_id,
                ]);
            } catch (\Exception $e) {
                Log::error('게시판 알림 발송 실패: 신고/자동 블라인드 처리 (게시글)', [
                    'type' => 'report_action',
                    'action_type' => $actionType,
                    'board' => $slug,
                    'post_id' => $post->id,
                    'recipient_id' => $post->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // 관리자 직권 처리 알림 (기존 로직)
            if (! $board->notify_author) {
                return;
            }

            $settings = $this->notificationSettingService->getByUserId($post->user_id);
            if (! $settings || ! $settings->notify_post_complete) {
                return;
            }

            try {
                $recipient->notify(new PostActionNotification(
                    postId: $post->id,
                    boardId: $board->id,
                    slug: $slug,
                    postTitle: $post->title ?? '',
                    actionType: $actionType
                ));

                Log::info('게시판 알림 발송: 관리자 처리', [
                    'type' => 'post_action',
                    'action_type' => $actionType,
                    'board' => $slug,
                    'post_id' => $post->id,
                    'recipient_id' => $post->user_id,
                ]);
            } catch (\Exception $e) {
                Log::error('게시판 알림 발송 실패: 관리자 처리', [
                    'type' => 'post_action',
                    'action_type' => $actionType,
                    'board' => $slug,
                    'post_id' => $post->id,
                    'recipient_id' => $post->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 댓글 블라인드 처리 알림을 발송합니다.
     *
     * @param Comment $comment 처리된 댓글
     * @param string $slug 게시판 슬러그
     * @return void
     */
    public function afterCommentBlind(Comment $comment, string $slug): void
    {
        $this->sendCommentActionNotification($comment, $slug, 'blind');
    }

    /**
     * 댓글 삭제 알림을 발송합니다.
     *
     * @param Comment $comment 처리된 댓글
     * @param string $slug 게시판 슬러그
     * @return void
     */
    public function afterCommentDelete(Comment $comment, string $slug): void
    {
        $this->sendCommentActionNotification($comment, $slug, 'deleted');
    }

    /**
     * 댓글 복원 알림을 발송합니다.
     *
     * @param Comment $comment 처리된 댓글
     * @param string $slug 게시판 슬러그
     * @return void
     */
    public function afterCommentRestore(Comment $comment, string $slug): void
    {
        $this->sendCommentActionNotification($comment, $slug, 'restored');
    }

    /**
     * 신고/자동 블라인드 처리 댓글 알림을 발송합니다.
     *
     * 신고 또는 자동 블라인드로 인해 처리(블라인드/삭제/복원)된 댓글의 작성자에게 알림을 발송합니다.
     * 댓글 직권 처리(Admin) 알림은 이번 범위 제외입니다.
     *
     * @param Comment $comment 처리된 댓글
     * @param string $slug 게시판 슬러그
     * @param string $actionType 처리 유형 (blind, deleted, restored)
     * @return void
     */
    private function sendCommentActionNotification(Comment $comment, string $slug, string $actionType): void
    {
        // 중복 실행 방지
        $key = "comment_action_{$actionType}_{$slug}_{$comment->id}";
        if (isset(self::$processedKeys[$key])) {
            return;
        }
        self::$processedKeys[$key] = true;

        if (! $comment->user_id) {
            return;
        }

        // 관리자가 처리한 경우에만 알림 발송 (본인이 직접 삭제한 경우 제외)
        $currentUserId = Auth::id();
        if ($currentUserId === $comment->user_id) {
            return;
        }

        // 신고 처리 또는 자동 블라인드인 경우에만 알림 발송 (댓글 직권 처리 알림은 별도 이슈)
        if ($comment->trigger_type !== TriggerType::Report && $comment->trigger_type !== TriggerType::AutoHide) {
            return;
        }

        $reportPolicy = g7_module_settings('sirsoft-board', 'report_policy', []);
        if (empty($reportPolicy['notify_author_on_report_action'])) {
            return;
        }

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board) {
            return;
        }

        $recipient = $comment->user;
        if (! $recipient || ! $recipient->email) {
            return;
        }

        try {
            $recipient->notify(new ReportActionNotification(
                postId: $comment->post_id,
                boardId: $board->id,
                slug: $slug,
                postTitle: $comment->post->title ?? '',
                actionType: $actionType,
                targetType: 'comment'
            ));

            Log::info('게시판 알림 발송: 신고/자동 블라인드 처리 (댓글)', [
                'type' => 'report_action',
                'trigger_type' => $comment->trigger_type?->value,
                'action_type' => $actionType,
                'board' => $slug,
                'comment_id' => $comment->id,
                'post_id' => $comment->post_id,
                'recipient_id' => $comment->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error('게시판 알림 발송 실패: 신고/자동 블라인드 처리 (댓글)', [
                'type' => 'report_action',
                'action_type' => $actionType,
                'board' => $slug,
                'comment_id' => $comment->id,
                'post_id' => $comment->post_id,
                'recipient_id' => $comment->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 글 작성자에게 새 댓글 알림을 발송합니다.
     *
     * @param Board $board 게시판
     * @param Post $post 게시글
     * @param Comment $comment 댓글
     * @param string $slug 게시판 슬러그
     * @param string $commentAuthorName 댓글 작성자 이름
     * @return void
     */
    private function notifyPostAuthorOnComment(
        Board $board,
        Post $post,
        Comment $comment,
        string $slug,
        string $commentAuthorName
    ): void {
        // 게시판 설정 확인
        if (! $board->notify_author) {
            return;
        }

        // 회원 게시글만
        if (! $post->user_id) {
            return;
        }

        // 자기 자신 제외
        if ($post->user_id === $comment->user_id) {
            return;
        }

        // 사용자 알림 설정 확인
        $settings = $this->notificationSettingService->getByUserId($post->user_id);
        if (! $settings || ! $settings->notify_comment) {
            return;
        }

        // 수신자 이메일 확인
        $recipient = $post->user;
        if (! $recipient || ! $recipient->email) {
            return;
        }

        try {
            $recipient->notify(new NewCommentNotification(
                commentId: $comment->id,
                boardId: $board->id,
                postId: $post->id,
                slug: $slug,
                commentAuthorName: $commentAuthorName
            ));

            Log::info('게시판 알림 발송: 새 댓글', [
                'type' => 'new_comment',
                'board' => $slug,
                'post_id' => $post->id,
                'comment_id' => $comment->id,
                'recipient_id' => $post->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error('게시판 알림 발송 실패: 새 댓글', [
                'type' => 'new_comment',
                'board' => $slug,
                'post_id' => $post->id,
                'comment_id' => $comment->id,
                'recipient_id' => $post->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 부모 댓글 작성자에게 대댓글 알림을 발송합니다.
     *
     * @param Board $board 게시판
     * @param Post $post 게시글
     * @param Comment $comment 대댓글
     * @param string $slug 게시판 슬러그
     * @param string $commentAuthorName 대댓글 작성자 이름
     * @return void
     */
    private function notifyParentCommentAuthor(
        Board $board,
        Post $post,
        Comment $comment,
        string $slug,
        string $commentAuthorName
    ): void {
        // 게시판 설정 확인
        if (! $board->notify_author) {
            return;
        }

        if (empty($comment->parent_id)) {
            return;
        }

        $parentComment = $this->commentRepository->find($slug, $comment->parent_id);
        if (! $parentComment || ! $parentComment->user_id) {
            return;
        }

        // 자기 자신 제외
        if ($parentComment->user_id === $comment->user_id) {
            return;
        }

        // 사용자 알림 설정 확인
        $settings = $this->notificationSettingService->getByUserId($parentComment->user_id);
        if (! $settings || ! $settings->notify_reply_comment) {
            return;
        }

        // 수신자 이메일 확인
        $recipient = $parentComment->user;
        if (! $recipient || ! $recipient->email) {
            return;
        }

        try {
            $recipient->notify(new ReplyCommentNotification(
                commentId: $comment->id,
                parentCommentId: $parentComment->id,
                boardId: $board->id,
                postId: $post->id,
                slug: $slug,
                commentAuthorName: $commentAuthorName
            ));

            Log::info('게시판 알림 발송: 대댓글', [
                'type' => 'reply_comment',
                'board' => $slug,
                'post_id' => $post->id,
                'comment_id' => $comment->id,
                'parent_comment_id' => $parentComment->id,
                'recipient_id' => $parentComment->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error('게시판 알림 발송 실패: 대댓글', [
                'type' => 'reply_comment',
                'board' => $slug,
                'post_id' => $post->id,
                'comment_id' => $comment->id,
                'parent_comment_id' => $parentComment->id,
                'recipient_id' => $parentComment->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 원글 작성자에게 답변글 알림을 발송합니다.
     *
     * @param Board $board 게시판
     * @param Post $post 답변글
     * @param string $slug 게시판 슬러그
     * @return void
     */
    private function notifyParentPostAuthorOnReply(Board $board, Post $post, string $slug): void
    {
        // 게시판 설정 확인
        if (! $board->notify_author) {
            return;
        }

        if (empty($post->parent_id)) {
            return;
        }

        $parentPost = $this->postRepository->find($slug, $post->parent_id);
        if (! $parentPost || ! $parentPost->user_id) {
            return;
        }

        // 자기 자신 제외
        if ($parentPost->user_id === $post->user_id) {
            return;
        }

        // 사용자 알림 설정 확인
        $settings = $this->notificationSettingService->getByUserId($parentPost->user_id);
        if (! $settings || ! $settings->notify_post_reply) {
            return;
        }

        // 수신자 이메일 확인
        $recipient = $parentPost->user;
        if (! $recipient || ! $recipient->email) {
            return;
        }

        try {
            $recipient->notify(new PostReplyNotification(
                replyPostId: $post->id,
                parentPostId: $parentPost->id,
                boardId: $board->id,
                slug: $slug,
                replyPostTitle: $post->title ?? ''
            ));

            Log::info('게시판 알림 발송: 답변글', [
                'type' => 'post_reply',
                'board' => $slug,
                'reply_post_id' => $post->id,
                'parent_post_id' => $parentPost->id,
                'recipient_id' => $parentPost->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error('게시판 알림 발송 실패: 답변글', [
                'type' => 'post_reply',
                'board' => $slug,
                'reply_post_id' => $post->id,
                'parent_post_id' => $parentPost->id,
                'recipient_id' => $parentPost->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 신고 생성 후 관리자 알림을 처리합니다.
     *
     * @param Report $report 생성된 신고
     * @return void
     */
    public function afterReportCreate(Report $report): void
    {
        $key = "report_create_{$report->id}";
        if (isset(self::$processedKeys[$key])) {
            return;
        }
        self::$processedKeys[$key] = true;

        $this->notifyAdminOnReport($report);
    }

    /**
     * 신고 접수 시 관리자에게 알림을 발송합니다.
     *
     * reports.manage 권한 보유자에게 발송하며, 권한자 미지정 시 알림을 발송하지 않습니다.
     *
     * @param Report $report 생성된 신고
     * @return void
     */
    private function notifyAdminOnReport(Report $report): void
    {
        $reportPolicy = g7_module_settings('sirsoft-board', 'report_policy', []);
        if (empty($reportPolicy['notify_admin_on_report'])) {
            return;
        }

        // 발송 범위 조건: per_case = 케이스당 1회 (신규 + 재활성화 첫 신고), per_report = 신고 건마다
        $scope = $reportPolicy['notify_admin_on_report_scope'] ?? 'per_case';
        if ($scope === 'per_case') {
            // last_activated_at 이후(재활성 포함) 로그 수가 2 이상이면 재신고 → 발송 안함
            $activeCycleLogCount = $report->logs()
                ->when($report->last_activated_at,
                    fn ($q) => $q->where('created_at', '>=', $report->last_activated_at)
                )->count();

            if ($activeCycleLogCount > 1) {
                return;
            }
        }

        // reports.manage 권한 보유자 조회 (역할→권한 경로), 미지정 시 알림 미발송
        $recipients = User::whereHas('roles.permissions', function ($q) {
            $q->where('identifier', 'sirsoft-board.reports.manage');
        })->get();

        if ($recipients->isEmpty()) {
            return;
        }

        // Board 정보 로드 (slug 획득용)
        $board = $report->board;
        if (! $board) {
            return;
        }

        // snapshot과 reason_type은 ReportLog에 저장됨 (Report에는 없음)
        $report->loadMissing(['logs' => fn ($q) => $q->latest()]);
        $log = $report->logs->first();
        $postTitle = $log?->snapshot['title'] ?? '';
        $boardName = $log?->snapshot['board_name'] ?? ($board->localizedName ?? $board->name);
        $targetType = $report->target_type instanceof \BackedEnum
            ? $report->target_type->value
            : (string) $report->target_type;
        $reasonType = $log?->reason_type instanceof \BackedEnum
            ? $log->reason_type->value
            : ($log?->reason_type ? (string) $log->reason_type : 'etc');

        foreach ($recipients as $recipient) {
            if (! $recipient->email) {
                continue;
            }

            try {
                $recipient->notify(new ReportReceivedAdminNotification(
                    reportId: $report->id,
                    boardId: $board->id,
                    slug: $board->slug,
                    boardName: $boardName,
                    postTitle: $postTitle,
                    targetType: $targetType,
                    reasonType: $reasonType,
                ));

                Log::info('게시판 알림 발송: 신고 접수 (관리자)', [
                    'type' => 'report_received_admin',
                    'board' => $board->slug,
                    'report_id' => $report->id,
                    'recipient_id' => $recipient->id,
                ]);
            } catch (\Exception $e) {
                Log::error('게시판 알림 발송 실패: 신고 접수 (관리자)', [
                    'type' => 'report_received_admin',
                    'board' => $board->slug,
                    'report_id' => $report->id,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyAdminOnNewPost(Board $board, Post $post, string $slug): void
    {
        if (! $board->notify_admin_on_post) {
            return;
        }

        // 게시판 관리자 역할의 사용자 조회, 없으면 최고관리자(super_admin) 폴백
        $managerRole = Role::where('identifier', "sirsoft-board.{$slug}.manager")->first();
        $managers = $managerRole ? $managerRole->users()->get() : collect();
        $isFallback = $managers->isEmpty();

        if ($isFallback) {
            $superAdmin = User::superAdmins()->first();
            $managers = $superAdmin ? collect([$superAdmin]) : collect();
        }

        foreach ($managers as $manager) {
            if (! $manager->email) {
                continue;
            }

            // 자기 자신 제외 (관리자가 직접 작성한 경우)
            if ($manager->id === $post->user_id) {
                continue;
            }

            try {
                $manager->notify(new NewPostAdminNotification(
                    postId: $post->id,
                    boardId: $board->id,
                    slug: $slug,
                    postTitle: $post->title ?? ''
                ));

                Log::info('게시판 알림 발송: 관리자 새글', [
                    'type' => 'new_post_admin',
                    'board' => $slug,
                    'post_id' => $post->id,
                    'recipient_id' => $manager->id,
                    'is_super_admin_fallback' => $isFallback,
                ]);
            } catch (\Exception $e) {
                Log::error('게시판 알림 발송 실패: 관리자 새글', [
                    'type' => 'new_post_admin',
                    'board' => $slug,
                    'post_id' => $post->id,
                    'recipient_id' => $manager->id,
                    'is_super_admin_fallback' => $isFallback,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
