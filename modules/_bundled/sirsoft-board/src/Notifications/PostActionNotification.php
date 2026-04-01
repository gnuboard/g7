<?php

namespace Modules\Sirsoft\Board\Notifications;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Notifications\BaseNotification;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardMailTemplateService;

/**
 * 관리자 처리 알림 (게시글 블라인드, 삭제, 복원 등)
 *
 * 게시글 작성자에게 관리자가 게시글을 처리했음을 알립니다.
 * 사용자 설정(notify_post_complete)이 ON인 경우 발송됩니다.
 *
 * @param int $postId 게시글 ID
 * @param int $boardId 게시판 ID
 * @param string $slug 게시판 슬러그
 * @param string $postTitle 게시글 제목
 * @param string $actionType 처리 유형 (blind, deleted, restored)
 */
class PostActionNotification extends BaseNotification
{
    /**
     * 관리자 처리 알림을 생성합니다.
     *
     * @param int $postId 게시글 ID
     * @param int $boardId 게시판 ID
     * @param string $slug 게시판 슬러그
     * @param string $postTitle 게시글 제목
     * @param string $actionType 처리 유형 (blind, deleted, restored)
     */
    public function __construct(
        private int $postId,
        private int $boardId,
        private string $slug,
        private string $postTitle,
        private string $actionType = 'blind'
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
        return 'post_action';
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
        $template = $service->resolveTemplate('post_action');

        if (! $template) {
            return DbTemplateMail::skipped(
                recipientEmail: $notifiable->email,
                templateType: 'post_action',
                extensionType: ExtensionOwnerType::Module,
                extensionIdentifier: 'sirsoft-board',
                recipientName: $notifiable->name ?? null,
            );
        }

        $board = app(BoardRepositoryInterface::class)->findOrFail($this->boardId);
        $post = app(PostRepositoryInterface::class)->find($this->slug, $this->postId);

        $actionTypeLabel = __("sirsoft-board::notification.post_action.action_types.{$this->actionType}");

        $variables = [
            'name' => $notifiable->name ?? '',
            'app_name' => config('app.name'),
            'board_name' => $board->localizedName ?? $board->name,
            'post_title' => $post->title ?? '',
            'action_type' => $actionTypeLabel,
            'post_url' => config('app.url') . "/board/{$board->slug}/{$post->id}",
            'site_url' => config('app.url'),
        ];

        $rendered = $service->renderTemplate($template, $variables);

        return new DbTemplateMail(
            renderedSubject: $rendered['subject'],
            renderedBody: $rendered['body'],
            recipientEmail: $notifiable->email,
            templateType: 'post_action',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            recipientName: $notifiable->name ?? null,
        );
    }
}
