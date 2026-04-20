<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 코어 알림 채널 리스너
 *
 * 코어 설정 기반으로 알림 채널 목록을 관리합니다.
 * 플러그인이 filter 훅으로 채널을 추가/제거할 때의 기본 채널 보장 역할.
 */
class CoreNotificationChannelListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록.
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.notification.filter_available_channels' => [
                'method' => 'ensureDefaultChannels',
                'priority' => 1,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param mixed ...$args
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 기본 채널(mail, database)이 항상 포함되도록 보장합니다.
     *
     * @param array $channels 현재 채널 목록
     * @return array 보장된 채널 목록
     */
    public function ensureDefaultChannels(array $channels): array
    {
        $defaultIds = array_column(config('notification.default_channels', []), 'id');
        $existingIds = array_column($channels, 'id');

        foreach (config('notification.default_channels', []) as $default) {
            if (! in_array($default['id'], $existingIds, true)) {
                array_unshift($channels, $default);
            }
        }

        return $channels;
    }
}
