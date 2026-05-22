<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class QuoteRevoked extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'quote_revoked';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = [
            'title' => $this->inquiry->title,
            'version' => $this->params['version'] ?? '?',
        ];

        return (new MailMessage)
            ->subject(__('inquiry::notifications.quote_revoked.subject'))
            ->line(__('inquiry::notifications.quote_revoked.line', $params))
            ->action(__('inquiry::notifications.quote_revoked.action'), $this->resolveUrl($notifiable));
    }
}
