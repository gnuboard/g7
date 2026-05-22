<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class NewMessageNotification extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'new_message';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('inquiry::notifications.new_message.subject'))
            ->line(__('inquiry::notifications.new_message.line', [
                'title' => $this->inquiry->title,
                'sender' => $this->params['sender_name'] ?? '상대방',
            ]))
            ->action(__('inquiry::notifications.new_message.action'), $this->resolveUrl($notifiable));
    }
}
