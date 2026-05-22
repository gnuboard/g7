<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class InquiryCanceled extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'inquiry_canceled';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lineKey = ($this->params['actor'] ?? null) === 'operator'
            ? 'inquiry::notifications.inquiry_canceled.line_by_operator'
            : 'inquiry::notifications.inquiry_canceled.line_by_client';

        $params = [
            'title' => $this->inquiry->title,
        ];

        return (new MailMessage)
            ->subject(__('inquiry::notifications.inquiry_canceled.subject'))
            ->line(__($lineKey, $params))
            ->action(__('inquiry::notifications.inquiry_canceled.action'), $this->resolveUrl($notifiable));
    }
}
