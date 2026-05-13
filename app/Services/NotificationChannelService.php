<?php

namespace App\Services;

use App\Extension\HookManager;
use Illuminate\Support\Arr;

class NotificationChannelService
{
    /**
     * 확장 × 채널 조합별 전역 활성 여부 메모이제이션 캐시.
     *
     * @var array<string, bool>
     */
    private array $channelEnabledCache = [];

    /**
     * 사용 가능한 알림 채널 목록을 반환합니다.
     *
     * config 기본 채널 + Filter 훅으로 플러그인 채널 확장 가능.
     *
     * @return array
     */
    public function getAvailableChannels(): array
    {
        $defaultChannels = config('notification.default_channels', []);

        $channels = HookManager::applyFilters(
            'core.notification.filter_available_channels',
            $defaultChannels
        );

        // name_key / description_key / source_label_key 를 활성 locale 기준으로 해석하여
        // name / description / source_label 키로 반환 (registry payload name_key 계약).
        return array_map(static function (array $channel): array {
            foreach (['name', 'description', 'source_label'] as $field) {
                $resolved = localized_payload($channel, $field);
                if ($resolved !== '') {
                    $channel[$field] = $resolved;
                }
            }

            return $channel;
        }, $channels);
    }

    /**
     * 특정 채널이 사용 가능한지 확인합니다.
     *
     * @param string $channelId
     * @return bool
     */
    public function isChannelAvailable(string $channelId): bool
    {
        $channels = $this->getAvailableChannels();

        return collect($channels)->contains('id', $channelId);
    }

    /**
     * 확장(core/module/plugin) 단위의 채널 전역 활성 여부를 확인합니다.
     *
     * 코어 환경설정 / 모듈 환경설정 / 플러그인 환경설정의 "알림 채널 관리"
     * 섹션에서 토글된 `notifications.channels[*].is_active` 값을 조회합니다.
     *
     * 엔트리 자체가 저장되어 있지 않으면 `true`(활성)를 반환합니다 —
     * 하위호환과 플러그인이 신규 채널을 추가했을 때 기본 활성을 보장합니다.
     *
     * `core.notification.channel_enabled` 필터 훅으로 재정의 가능합니다.
     *
     * @param string $extensionType 확장 타입 (core, module, plugin)
     * @param string|null $extensionIdentifier 확장 식별자 (core는 'core' 허용)
     * @param string $channelId 채널 식별자 (mail, database 등)
     * @return bool true = 발송 허용, false = 발송 차단
     */
    public function isChannelEnabledForExtension(
        string $extensionType,
        ?string $extensionIdentifier,
        string $channelId
    ): bool {
        $cacheKey = $extensionType.'|'.($extensionIdentifier ?? '').'|'.$channelId;

        if (array_key_exists($cacheKey, $this->channelEnabledCache)) {
            return $this->channelEnabledCache[$cacheKey];
        }

        $channels = $this->resolveExtensionChannels($extensionType, $extensionIdentifier);
        $enabled = $this->extractChannelActive($channels, $channelId);

        $enabled = HookManager::applyFilters(
            'core.notification.channel_enabled',
            $enabled,
            $extensionType,
            $extensionIdentifier,
            $channelId
        );

        $this->channelEnabledCache[$cacheKey] = (bool) $enabled;

        return $this->channelEnabledCache[$cacheKey];
    }

    /**
     * 메모이제이션 캐시를 초기화합니다. (테스트 및 설정 변경 후 사용)
     *
     * @return void
     */
    public function clearChannelEnabledCache(): void
    {
        $this->channelEnabledCache = [];
    }

    /**
     * 확장 타입별 notifications.channels 배열을 조회합니다.
     *
     * @param string $extensionType
     * @param string|null $extensionIdentifier
     * @return array
     */
    private function resolveExtensionChannels(string $extensionType, ?string $extensionIdentifier): array
    {
        try {
            switch ($extensionType) {
                case 'core':
                    $settingsService = app(SettingsService::class);
                    $value = $settingsService->getSetting('notifications.channels', []);

                    return is_array($value) ? $value : [];

                case 'module':
                    if (empty($extensionIdentifier)) {
                        return [];
                    }
                    $moduleSettings = app(ModuleSettingsService::class);
                    $value = $moduleSettings->get($extensionIdentifier, 'notifications.channels', []);

                    return is_array($value) ? $value : [];

                case 'plugin':
                    if (empty($extensionIdentifier)) {
                        return [];
                    }
                    $pluginSettings = app(PluginSettingsService::class);
                    $value = $pluginSettings->get($extensionIdentifier, 'notifications.channels', []);

                    return is_array($value) ? $value : [];
            }
        } catch (\Throwable $e) {
            // 설정 조회 실패 시 기본 활성 동작을 위해 빈 배열 반환
            return [];
        }

        return [];
    }

    /**
     * notifications.channels 배열에서 특정 채널의 is_active 값을 추출합니다.
     *
     * 엔트리가 없으면 true(기본 활성)를 반환합니다.
     *
     * @param array $channels
     * @param string $channelId
     * @return bool
     */
    private function extractChannelActive(array $channels, string $channelId): bool
    {
        foreach ($channels as $entry) {
            $id = Arr::get($entry, 'id');
            if ($id === $channelId) {
                return (bool) Arr::get($entry, 'is_active', true);
            }
        }

        return true;
    }
}
