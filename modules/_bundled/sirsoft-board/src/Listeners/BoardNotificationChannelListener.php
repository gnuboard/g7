<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 알림 채널 설정 필터 리스너
 *
 * BaseNotification::via()에서 발행하는 sirsoft-board.notification.channels
 * 필터 훅을 수신하여, 환경설정에 저장된 채널 설정을 적용합니다.
 *
 * 알림 타입 매핑:
 * - new_post_admin → notify_admin_on_post_channels
 * - new_comment, post_reply, reply_comment, post_action → notify_author_channels
 * - report_action → notify_author_on_report_action_channels
 * - report_received_admin → notify_admin_on_report_channels
 */
class BoardNotificationChannelListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.notification.channels' => [
                'method' => 'filterChannels',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (사용하지 않음 - filterChannels로 대체)
     */
    public function handle(...$args): void {}

    /**
     * 알림 타입별 채널 설정을 적용합니다.
     *
     * @param array<string> $channels 기본 채널 배열 (['mail'])
     * @param string $type 알림 타입 (new_post_admin, new_comment 등), 빈 문자열이면 기본 채널 반환
     * @param object|null $notifiable 수신자
     * @return array<string> 적용된 채널 배열
     */
    public function filterChannels(array $channels, string $type = '', ?object $notifiable = null): array
    {
        $channelSettings = g7_module_settings('sirsoft-board', 'basic_defaults', []);

        $reportPolicy = g7_module_settings('sirsoft-board', 'report_policy', []);

        return match ($type) {
            'new_post_admin' => $channelSettings['notify_admin_on_post_channels'] ?? $channels,
            'new_comment', 'post_reply', 'reply_comment', 'post_action'
                => $channelSettings['notify_author_channels'] ?? $channels,
            'report_received_admin' => $reportPolicy['notify_admin_on_report_channels'] ?? $channels,
            'report_action' => $reportPolicy['notify_author_on_report_action_channels'] ?? $channels,
            default => $channels,
        };
    }
}
