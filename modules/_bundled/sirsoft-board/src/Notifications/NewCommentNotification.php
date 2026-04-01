<?php

namespace Modules\Sirsoft\Board\Notifications;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Notifications\BaseNotification;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardMailTemplateService;

/**
 * 내 글에 새 댓글 알림
 *
 * 게시글 작성자에게 새 댓글이 달렸음을 알립니다.
 * 게시판 설정(notify_author_on_comment)과 사용자 설정(notify_comment) 모두 ON인 경우 발송됩니다.
 *
 * @param int $commentId 댓글 ID
 * @param int $boardId 게시판 ID
 * @param int $postId 게시글 ID
 * @param string $slug 게시판 슬러그
 * @param string $commentAuthorName 댓글 작성자 이름
 */
class NewCommentNotification extends BaseNotification
{
    /**
     * 새 댓글 알림을 생성합니다.
     *
     * @param int $commentId 댓글 ID
     * @param int $boardId 게시판 ID
     * @param int $postId 게시글 ID
     * @param string $slug 게시판 슬러그
     * @param string $commentAuthorName 댓글 작성자 이름
     */
    public function __construct(
        private int $commentId,
        private int $boardId,
        private int $postId,
        private string $slug,
        private string $commentAuthorName
    ) {}

    /**
     * {@inheritdoc}
     */
    protected function getHookPrefix(): string
    {
        return 'sirsoft-board';
    }

    /**
     * {@inheritdoc}
     */
    protected function getNotificationType(): string
    {
        return 'new_comment';
    }

    /**
     * 이메일 알림을 생성합니다.
     *
     * @param object $notifiable 수신자
     * @return DbTemplateMail DB 템플릿 기반 이메일 (비활성 시 스킵 인스턴스)
     */
    public function toMail(object $notifiable): DbTemplateMail
    {
        $service = app(BoardMailTemplateService::class);
        $template = $service->resolveTemplate('new_comment');

        if (! $template) {
            return DbTemplateMail::skipped(
                recipientEmail: $notifiable->email,
                templateType: 'new_comment',
                extensionType: ExtensionOwnerType::Module,
                extensionIdentifier: 'sirsoft-board',
                recipientName: $notifiable->name ?? null,
            );
        }

        $board = app(BoardRepositoryInterface::class)->findOrFail($this->boardId);
        $post = app(PostRepositoryInterface::class)->find($this->slug, $this->postId);
        $comment = app(CommentRepositoryInterface::class)->find($this->slug, $this->commentId);

        $commentContent = $comment->content ?? '';
        if (mb_strlen($commentContent) > 200) {
            $commentContent = mb_substr($commentContent, 0, 200) . '...';
        }

        $variables = [
            'name' => $notifiable->name ?? '',
            'app_name' => config('app.name'),
            'board_name' => $board->localizedName ?? $board->name,
            'post_title' => $post->title ?? '',
            'comment_author' => $this->commentAuthorName,
            'comment_content' => $commentContent,
            'post_url' => config('app.url') . "/board/{$board->slug}/{$post->id}",
            'site_url' => config('app.url'),
        ];

        $rendered = $service->renderTemplate($template, $variables);

        return new DbTemplateMail(
            renderedSubject: $rendered['subject'],
            renderedBody: $rendered['body'],
            recipientEmail: $notifiable->email,
            templateType: 'new_comment',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            recipientName: $notifiable->name ?? null,
        );
    }
}
