<?php

namespace Modules\Sirsoft\Inquiry\Listeners;

use App\Extension\HookManager;

class InquiryNotificationChannelListener
{
    /**
     * 알림 채널 정책: 항상 인앱(database) + 이메일(mail) 두 채널.
     * 사용자별 끄기는 v2 범위.
     */
    public function register(): void
    {
        HookManager::addFilter(
            'sirsoft-inquiry.notification.channels',
            function (array $channels, string $type, object $notifiable): array {
                return ['mail', 'database'];
            },
            10
        );
    }
}
