<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 알림 채널 설정 필터 리스너
 *
 * BaseNotification::via()에서 발행하는 sirsoft-board.notification.channels
 * 필터 훅을 수신하여, notifications.channels 환경설정을 적용합니다.
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
     * notifications.channels 설정 기반으로 활성화된 채널만 반환합니다.
     *
     * @param array<string> $channels 기본 채널 배열
     * @param string $type 알림 타입 (미사용, 향후 확장 대비)
     * @param object|null $notifiable 수신자
     * @return array<string> 활성화된 채널 배열
     */
    public function filterChannels(array $channels, string $type = '', ?object $notifiable = null): array
    {
        $notifSettings = g7_module_settings('sirsoft-board', 'notifications', []);
        $channelSettings = $notifSettings['channels'] ?? [];

        if (empty($channelSettings)) {
            return $channels;
        }

        $activeChannelIds = collect($channelSettings)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        return array_values(array_filter($channels, fn ($ch) => in_array($ch, $activeChannelIds)));
    }
}
