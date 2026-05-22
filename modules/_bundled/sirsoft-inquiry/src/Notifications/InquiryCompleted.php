<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class InquiryCompleted extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'inquiry_completed';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = [
            'title' => $this->inquiry->title,
        ];

        return (new MailMessage)
            ->subject(__('inquiry::notifications.inquiry_completed.subject'))
            ->line(__('inquiry::notifications.inquiry_completed.line', $params))
            ->action(__('inquiry::notifications.inquiry_completed.action'), $this->resolveUrl($notifiable));
    }
}
