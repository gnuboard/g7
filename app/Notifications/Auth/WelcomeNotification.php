<?php

namespace App\Notifications\Auth;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Notifications\BaseNotification;
use App\Services\MailTemplateService;

/**
 * 회원가입 완료 알림
 *
 * 회원가입이 완료되었음을 사용자에게 알립니다.
 * 훅을 통해 SMS, 카카오톡 등 다른 채널로 확장 가능합니다.
 */
class WelcomeNotification extends BaseNotification
{
    /**
     * {@inheritdoc}
     */
    protected function getHookPrefix(): string
    {
        return 'core.auth';
    }

    /**
     * {@inheritdoc}
     */
    protected function getNotificationType(): string
    {
        return 'welcome';
    }

    /**
     * 이메일 알림을 생성합니다.
     *
     * @param object $notifiable 수신자
     * @return DbTemplateMail DB 템플릿 기반 이메일 (비활성 시 스킵 인스턴스)
     */
    public function toMail(object $notifiable): DbTemplateMail
    {
        $service = app(MailTemplateService::class);
        $template = $service->resolveTemplate('welcome');

        if (! $template) {
            return DbTemplateMail::skipped(
                recipientEmail: $notifiable->email,
                templateType: 'welcome',
                extensionType: ExtensionOwnerType::Core,
                extensionIdentifier: 'core',
                recipientName: $notifiable->name ?? null,
            );
        }

        $variables = [
            'name' => $notifiable->name ?? '',
            'app_name' => config('app.name'),
            'action_url' => config('app.url') . '/login',
            'site_url' => config('app.url'),
        ];

        $rendered = $service->renderTemplate($template, $variables);

        return new DbTemplateMail(
            renderedSubject: $rendered['subject'],
            renderedBody: $rendered['body'],
            recipientEmail: $notifiable->email,
            templateType: 'welcome',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            recipientName: $notifiable->name ?? null,
        );
    }
}
