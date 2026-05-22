<?php

namespace Modules\Sirsoft\Inquiry\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Events\InquiryMessagePosted;
use Modules\Sirsoft\Inquiry\Notifications\NewMessageNotification;

class DispatchInquiryMessageNotification
{
    public function handle(InquiryMessagePosted $event): void
    {
        $msg = $event->message;
        if ($msg->sender_role === SenderRole::System) {
            return; // 시스템 메시지는 알림 발송 안 함
        }

        $inquiry = $msg->inquiry;
        $sender = $msg->sender;

        if ($msg->sender_role === SenderRole::Client) {
            // 클라이언트 → 운영자 그룹
            $recipients = $this->operators();
        } else {
            // 운영자 → 클라이언트
            $recipients = [$inquiry->user];
        }

        Notification::send($recipients, new NewMessageNotification($inquiry, [
            'sender_name' => $sender?->name ?? '익명',
            'body_preview' => mb_substr((string) $msg->body, 0, 80),
        ]));
    }

    /** @return array<User> */
    private function operators(): array
    {
        $permission = config('inquiry.permissions.notify', 'inquiry.notify');
        return User::whereHas('roles.permissions', function ($q) use ($permission) {
            $q->where('identifier', $permission);
        })->get()->all();
    }
}
