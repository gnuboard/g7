<?php

namespace Modules\Sirsoft\Board\Notifications;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Notifications\BaseNotification;
use Modules\Sirsoft\Board\Services\BoardMailTemplateService;

/**
 * 신고 접수 알림 (관리자 대상)
 *
 * 새 신고가 접수될 때 reports.manage 권한 보유자(없으면 super_admin)에게
 * 알림을 발송합니다.
 * report_policy.notify_admin_on_report 설정이 ON인 경우에만 발송됩니다.
 *
 * @param int $reportId 신고 ID
 * @param int $boardId 게시판 ID
 * @param string $slug 게시판 슬러그
 * @param string $boardName 게시판 이름
 * @param string $postTitle 게시글 제목
 * @param string $targetType 신고 대상 유형 (post, comment)
 * @param string $reasonType 신고 사유 유형
 */
class ReportReceivedAdminNotification extends BaseNotification
{
    /**
     * 신고 접수 알림을 생성합니다.
     *
     * @param int $reportId 신고 ID
     * @param int $boardId 게시판 ID
     * @param string $slug 게시판 슬러그
     * @param string $boardName 게시판 이름
     * @param string $postTitle 게시글 제목
     * @param string $targetType 신고 대상 유형 (post, comment)
     * @param string $reasonType 신고 사유 유형
     */
    public function __construct(
        private int $reportId,
        private int $boardId,
        private string $slug,
        private string $boardName,
        public readonly string $postTitle,
        public readonly string $targetType = 'post',
        public readonly string $reasonType = 'etc'
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
        return 'report_received_admin';
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
        $template = $service->resolveTemplate('report_received_admin');

        if (! $template) {
            return DbTemplateMail::skipped(
                recipientEmail: $notifiable->email,
                templateType: 'report_received_admin',
                extensionType: ExtensionOwnerType::Module,
                extensionIdentifier: 'sirsoft-board',
                recipientName: $notifiable->name ?? null,
            );
        }

        $targetTypeLabel = __("sirsoft-board::notification.report_action.target_types.{$this->targetType}");
        $reasonTypeLabel = __("sirsoft-board::notification.report_received_admin.reason_types.{$this->reasonType}");

        $variables = [
            'name' => $notifiable->name ?? '',
            'app_name' => config('app.name'),
            'board_name' => $this->boardName,
            'post_title' => $this->postTitle,
            'target_type' => $targetTypeLabel,
            'reason_type' => $reasonTypeLabel,
            'report_url' => config('app.url') . '/admin/boards/reports',
            'site_url' => config('app.url'),
        ];

        $rendered = $service->renderTemplate($template, $variables);

        return new DbTemplateMail(
            renderedSubject: $rendered['subject'],
            renderedBody: $rendered['body'],
            recipientEmail: $notifiable->email,
            templateType: 'report_received_admin',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            recipientName: $notifiable->name ?? null,
        );
    }
}
