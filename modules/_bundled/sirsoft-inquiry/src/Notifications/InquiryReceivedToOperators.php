<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class InquiryReceivedToOperators extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'inquiry_received';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = __('inquiry::notifications.inquiry_received.subject');
        $line = __('inquiry::notifications.inquiry_received.line', [
            'title' => $this->inquiry->title,
            'client' => $this->inquiry->user?->name ?? '익명',
        ]);

        return (new MailMessage)
            ->subject($subject)
            ->greeting(__('inquiry::notifications.inquiry_received.greeting'))
            ->line($line)
            ->action(__('inquiry::notifications.inquiry_received.action'), $this->resolveUrl($notifiable));
    }
}
