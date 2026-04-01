<?php

namespace Modules\Sirsoft\Board\Notifications;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Notifications\BaseNotification;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardMailTemplateService;

/**
 * 신고 처리 알림 (피신고자 대상)
 *
 * 신고 누적으로 인해 게시글/댓글이 처리(블라인드, 삭제, 복원)될 때
 * 작성자에게 알림을 발송합니다.
 * report_policy.notify_author_on_report_action 설정이 ON인 경우에만 발송됩니다.
 *
 * @param int $postId 게시글 ID (댓글의 경우 상위 게시글 ID)
 * @param int $boardId 게시판 ID
 * @param string $slug 게시판 슬러그
 * @param string $postTitle 게시글 제목
 * @param string $actionType 처리 유형 (blind, deleted, restored)
 * @param string $targetType 처리 대상 유형 (post, comment)
 */
class ReportActionNotification extends BaseNotification
{
    /**
     * 신고 처리 알림을 생성합니다.
     *
     * @param int $postId 게시글 ID (댓글의 경우 상위 게시글 ID)
     * @param int $boardId 게시판 ID
     * @param string $slug 게시판 슬러그
     * @param string $postTitle 게시글 제목
     * @param string $actionType 처리 유형 (blind, deleted, restored)
     * @param string $targetType 처리 대상 유형 (post, comment)
     */
    public function __construct(
        private int $postId,
        private int $boardId,
        private string $slug,
        private string $postTitle,
        private string $actionType = 'blind',
        private string $targetType = 'post'
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
        return 'report_action';
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
        $template = $service->resolveTemplate('report_action');

        if (! $template) {
            return DbTemplateMail::skipped(
                recipientEmail: $notifiable->email,
                templateType: 'report_action',
                extensionType: ExtensionOwnerType::Module,
                extensionIdentifier: 'sirsoft-board',
                recipientName: $notifiable->name ?? null,
            );
        }

        $board = app(BoardRepositoryInterface::class)->findOrFail($this->boardId);

        $actionTypeLabel = __("sirsoft-board::notification.report_action.action_types.{$this->actionType}");
        $targetTypeLabel = __("sirsoft-board::notification.report_action.target_types.{$this->targetType}");

        $variables = [
            'name' => $notifiable->name ?? '',
            'app_name' => config('app.name'),
            'board_name' => $board->localizedName ?? $board->name,
            'post_title' => $this->postTitle,
            'action_type' => $actionTypeLabel,
            'target_type' => $targetTypeLabel,
            'post_url' => config('app.url') . "/board/{$this->slug}/{$this->postId}",
            'site_url' => config('app.url'),
        ];

        $rendered = $service->renderTemplate($template, $variables);

        return new DbTemplateMail(
            renderedSubject: $rendered['subject'],
            renderedBody: $rendered['body'],
            recipientEmail: $notifiable->email,
            templateType: 'report_action',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            recipientName: $notifiable->name ?? null,
        );
    }
}
