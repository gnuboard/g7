<?php

namespace App\Notifications\Auth;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Notifications\BaseNotification;
use App\Services\MailTemplateService;

/**
 * 비밀번호 재설정 알림
 *
 * 비밀번호 재설정 링크를 사용자에게 발송합니다.
 * 훅을 통해 SMS, 카카오톡 등 다른 채널로 확장 가능합니다.
 */
class ResetPasswordNotification extends BaseNotification
{
    /**
     * 비밀번호 재설정 알림을 생성합니다.
     *
     * @param string $token 비밀번호 재설정 토큰
     * @param string|null $redirectPrefix 리다이렉트 경로 접두사 (예: 'admin')
     */
    public function __construct(
        private string $token,
        private ?string $redirectPrefix = null
    ) {}

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
        return 'reset_password';
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
        $template = $service->resolveTemplate('reset_password');

        if (! $template) {
            return DbTemplateMail::skipped(
                recipientEmail: $notifiable->email,
                templateType: 'reset_password',
                extensionType: ExtensionOwnerType::Core,
                extensionIdentifier: 'core',
                recipientName: $notifiable->name ?? null,
            );
        }

        $resetPath = $this->redirectPrefix
            ? '/' . $this->redirectPrefix . '/reset-password'
            : '/reset-password';
        $url = config('app.url') . $resetPath . '?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);
        $expireMinutes = config('auth.passwords.users.expire', 60);

        $variables = [
            'name' => $notifiable->name ?? '',
            'app_name' => config('app.name'),
            'action_url' => $url,
            'expire_minutes' => (string) $expireMinutes,
            'site_url' => config('app.url'),
        ];

        $rendered = $service->renderTemplate($template, $variables);

        return new DbTemplateMail(
            renderedSubject: $rendered['subject'],
            renderedBody: $rendered['body'],
            recipientEmail: $notifiable->email,
            templateType: 'reset_password',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            recipientName: $notifiable->name ?? null,
        );
    }

    /**
     * 비밀번호 재설정 토큰을 반환합니다.
     *
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }
}
