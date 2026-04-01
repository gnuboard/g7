<?php

namespace Modules\Sirsoft\Board\Notifications;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Notifications\BaseNotification;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardMailTemplateService;

/**
 * 내 글에 답변글 알림
 *
 * 원글 작성자에게 답변글이 달렸음을 알립니다.
 * 사용자 설정(notify_post_reply)이 ON인 경우 발송됩니다.
 *
 * @param int $replyPostId 답변글 ID
 * @param int $parentPostId 원글 ID
 * @param int $boardId 게시판 ID
 * @param string $slug 게시판 슬러그
 * @param string $replyPostTitle 답변글 제목
 */
class PostReplyNotification extends BaseNotification
{
    /**
     * 답변글 알림을 생성합니다.
     *
     * @param int $replyPostId 답변글 ID
     * @param int $parentPostId 원글 ID
     * @param int $boardId 게시판 ID
     * @param string $slug 게시판 슬러그
     * @param string $replyPostTitle 답변글 제목
     */
    public function __construct(
        private int $replyPostId,
        private int $parentPostId,
        private int $boardId,
        private string $slug,
        private string $replyPostTitle
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
        return 'post_reply';
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
        $template = $service->resolveTemplate('post_reply');

        if (! $template) {
            return DbTemplateMail::skipped(
                recipientEmail: $notifiable->email,
                templateType: 'post_reply',
                extensionType: ExtensionOwnerType::Module,
                extensionIdentifier: 'sirsoft-board',
                recipientName: $notifiable->name ?? null,
            );
        }

        $board = app(BoardRepositoryInterface::class)->findOrFail($this->boardId);
        $parentPost = app(PostRepositoryInterface::class)->find($this->slug, $this->parentPostId);

        $variables = [
            'name' => $notifiable->name ?? '',
            'app_name' => config('app.name'),
            'board_name' => $board->localizedName ?? $board->name,
            'post_title' => $parentPost->title ?? '',
            'post_url' => config('app.url') . "/board/{$board->slug}/{$parentPost->id}",
            'site_url' => config('app.url'),
        ];

        $rendered = $service->renderTemplate($template, $variables);

        return new DbTemplateMail(
            renderedSubject: $rendered['subject'],
            renderedBody: $rendered['body'],
            recipientEmail: $notifiable->email,
            templateType: 'post_reply',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            recipientName: $notifiable->name ?? null,
        );
    }
}
