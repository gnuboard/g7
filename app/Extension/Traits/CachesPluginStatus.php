<?php

namespace App\Extension\Traits;

use App\Enums\ExtensionStatus;
use App\Models\Plugin;
use App\Services\CacheService;

/**
 * 플러그인 상태 캐시를 관리하는 Trait
 *
 * 활성화된 플러그인, 설치된 플러그인 목록을 캐시하여 DB 조회 오버헤드를 줄입니다.
 * PluginManager, PluginServiceProvider 등에서 사용됩니다.
 */
trait CachesPluginStatus
{
    /**
     * 캐시 그룹
     */
    private static string $pluginCacheGroup = 'plugins';

    /**
     * 활성화된 플러그인 identifier 목록을 조회합니다.
     *
     * @return array<string> 활성화된 플러그인 identifier 배열
     */
    public static function getActivePluginIdentifiers(): array
    {
        return CacheService::remember(
            self::$pluginCacheGroup,
            'active_identifiers',
            fn () => Plugin::where('status', ExtensionStatus::Active->value)
                ->pluck('identifier')
                ->toArray(),
            null,
            'plugins'
        );
    }

    /**
     * 설치된 플러그인 (active + inactive) identifier 목록을 조회합니다.
     *
     * @return array<string> 설치된 플러그인 identifier 배열
     */
    public static function getInstalledPluginIdentifiers(): array
    {
        return CacheService::remember(
            self::$pluginCacheGroup,
            'installed_identifiers',
            fn () => Plugin::whereIn('status', [
                ExtensionStatus::Active->value,
                ExtensionStatus::Inactive->value,
            ])->pluck('identifier')->toArray(),
            null,
            'plugins'
        );
    }

    /**
     * 플러그인 상태 캐시를 무효화합니다.
     * 플러그인 상태 변경 시 (install, activate, deactivate, uninstall) 호출해야 합니다.
     *
     * @return void
     */
    public static function invalidatePluginStatusCache(): void
    {
        CacheService::forgetMany(self::$pluginCacheGroup, [
            'active_identifiers',
            'installed_identifiers',
        ]);
    }
}
