<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use App\Notifications\BaseNotification;
use Illuminate\Bus\Queueable;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

abstract class InquiryNotification extends BaseNotification
{
    use Queueable;

    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly array $params = [],
    ) {}

    protected function getHookPrefix(): string
    {
        return 'sirsoft-inquiry';
    }

    /**
     * 인앱 알림 페이로드 (database 채널).
     */
    public function toArray(object $notifiable): array
    {
        return [
            'inquiry_uuid' => $this->inquiry->uuid,
            'inquiry_title' => $this->inquiry->title,
            'type' => $this->getNotificationType(),
            'params' => $this->params,
            'url' => $this->resolveUrl($notifiable),
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    protected function resolveUrl(object $notifiable): string
    {
        // 운영자 화면이면 /admin/inquiry/{uuid}, 일반 사용자면 /inquiry/{uuid}
        $isOperator = method_exists($notifiable, 'can')
            && $notifiable->can(config('inquiry.permissions.manage', 'inquiry.manage'));
        $base = $isOperator ? '/admin/inquiry' : '/inquiry';
        return url($base . '/' . $this->inquiry->uuid);
    }
}
