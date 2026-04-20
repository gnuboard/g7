<?php

namespace App\Notifications;

use App\Extension\HookManager;
use App\Services\NotificationTemplateService;
use Illuminate\Notifications\NotificationSender;
use Illuminate\Support\Facades\Log;

/**
 * 알림 발송 공통 디스패처
 *
 * 모든 채널의 모든 알림이 이 sendToNotifiable()을 통과합니다.
 *
 * 책임:
 * 1. 채널별 독립 발송 보장 (한 채널 실패가 다른 채널에 영향 없음)
 * 2. 발송 전후 G7 훅 실행 (core.notification.before_channel_send / after_channel_send / channel_send_failed)
 * 3. 플러그인/모듈이 훅으로 발송 로깅, 커스텀 처리 가능
 */
class NotificationDispatcher extends NotificationSender
{
    /**
     * 개별 채널로 알림을 발송합니다.
     *
     * @param mixed $notifiable
     * @param string $id
     * @param mixed $notification
     * @param string $channel
     * @return mixed
     */
    protected function sendToNotifiable($notifiable, $id, $notification, $channel)
    {
        $context = $this->buildContext($notifiable, $notification, $channel);

        // Before 훅: 발송 전 처리 (로깅 준비, 필터링 등)
        HookManager::doAction('core.notification.before_channel_send', $channel, $context);

        try {
            $result = parent::sendToNotifiable($notifiable, $id, $notification, $channel);

            // After 훅: 발송 성공 (로깅, 통계 등)
            HookManager::doAction('core.notification.after_channel_send', $channel, $context);

            return $result;
        } catch (\Exception $e) {
            // Failed 훅: 발송 실패 (에러 로깅 등)
            $context['error'] = $e->getMessage();
            HookManager::doAction('core.notification.channel_send_failed', $channel, $context);

            Log::warning("알림 채널 '{$channel}' 발송 실패", [
                'channel' => $channel,
                'notification' => get_class($notification),
                'notifiable_id' => $notifiable->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 훅에 전달할 공통 컨텍스트를 구성합니다.
     *
     * @param mixed $notifiable
     * @param mixed $notification
     * @param string $channel
     * @return array
     */
    private function buildContext($notifiable, $notification, string $channel): array
    {
        $context = [
            'notifiable' => $notifiable,
            'notifiable_id' => $notifiable->getKey(),
            'notifiable_type' => get_class($notifiable),
            'notifiable_uuid' => $notifiable->uuid ?? null,
            'notification_class' => get_class($notification),
            'recipient_identifier' => $notifiable->email ?? (string) ($notifiable->getKey() ?? ''),
            'recipient_name' => $notifiable->name ?? null,
            'recipient_user_id' => $notifiable->getKey() ?? null,
        ];

        // GenericNotification인 경우 추가 메타데이터
        if ($notification instanceof GenericNotification) {
            $context['notification_type'] = $notification->getType();
            $context['extension_type'] = $notification->getExtensionType();
            $context['extension_identifier'] = $notification->getExtensionIdentifier();
            $context['data'] = $notification->getData();

            // 렌더링된 subject/body를 context에 포함 (로깅용)
            $rendered = $this->resolveRenderedContent($notification, $notifiable, $channel);
            if ($rendered) {
                $context['subject'] = $rendered['subject'] ?? null;
                $context['body'] = $rendered['body'] ?? null;
            }
        }

        return $context;
    }

    /**
     * 채널별 렌더링된 제목/본문을 조회합니다.
     *
     * @param GenericNotification $notification
     * @param mixed $notifiable
     * @param string $channel
     * @return array{subject: string|null, body: string|null}|null
     */
    private function resolveRenderedContent(GenericNotification $notification, $notifiable, string $channel): ?array
    {
        try {
            $templateService = app(NotificationTemplateService::class);
            $template = $templateService->resolve($notification->getType(), $channel);

            if (! $template || ! $template->is_active) {
                return null;
            }

            $locale = $notifiable->locale ?? app()->getLocale();

            return $template->replaceVariables($notification->getData(), $locale);
        } catch (\Throwable $e) {
            Log::debug('NotificationDispatcher: 렌더링된 콘텐츠 조회 실패', [
                'type' => $notification->getType(),
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
