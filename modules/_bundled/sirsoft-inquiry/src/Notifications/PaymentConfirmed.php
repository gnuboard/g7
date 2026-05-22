<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class PaymentConfirmed extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'payment_confirmed';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = [
            'title' => $this->inquiry->title,
        ];

        return (new MailMessage)
            ->subject(__('inquiry::notifications.payment_confirmed.subject'))
            ->line(__('inquiry::notifications.payment_confirmed.line', $params))
            ->action(__('inquiry::notifications.payment_confirmed.action'), $this->resolveUrl($notifiable));
    }
}
