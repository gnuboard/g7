<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class QuoteIssued extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'quote_issued';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = [
            'title' => $this->inquiry->title,
            'version' => $this->params['version'] ?? '?',
            'total' => isset($this->params['total']) ? number_format((int) $this->params['total']) : '-',
        ];

        return (new MailMessage)
            ->subject(__('inquiry::notifications.quote_issued.subject'))
            ->line(__('inquiry::notifications.quote_issued.line', $params))
            ->action(__('inquiry::notifications.quote_issued.action'), $this->resolveUrl($notifiable));
    }
}
