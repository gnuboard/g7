<?php

namespace App\Extension\Traits;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Services\CacheService;

/**
 * 모듈 상태 캐시를 관리하는 Trait
 *
 * 활성화된 모듈, 설치된 모듈 목록을 캐시하여 DB 조회 오버헤드를 줄입니다.
 * ModuleManager, ModuleServiceProvider 등에서 사용됩니다.
 */
trait CachesModuleStatus
{
    /**
     * 캐시 그룹
     */
    private static string $cacheGroup = 'modules';

    /**
     * 활성화된 모듈 identifier 목록을 조회합니다.
     *
     * @return array<string> 활성화된 모듈 identifier 배열
     */
    public static function getActiveModuleIdentifiers(): array
    {
        return CacheService::remember(
            self::$cacheGroup,
            'active_identifiers',
            fn () => Module::where('status', ExtensionStatus::Active->value)
                ->pluck('identifier')
                ->toArray(),
            null,
            'modules'
        );
    }

    /**
     * 설치된 모듈 (active + inactive) identifier 목록을 조회합니다.
     *
     * @return array<string> 설치된 모듈 identifier 배열
     */
    public static function getInstalledModuleIdentifiers(): array
    {
        return CacheService::remember(
            self::$cacheGroup,
            'installed_identifiers',
            fn () => Module::whereIn('status', [
                ExtensionStatus::Active->value,
                ExtensionStatus::Inactive->value,
            ])->pluck('identifier')->toArray(),
            null,
            'modules'
        );
    }

    /**
     * 모듈 상태 캐시를 무효화합니다.
     * 모듈 상태 변경 시 (install, activate, deactivate, uninstall) 호출해야 합니다.
     *
     * @return void
     */
    public static function invalidateModuleStatusCache(): void
    {
        CacheService::forgetMany(self::$cacheGroup, [
            'active_identifiers',
            'installed_identifiers',
        ]);
    }
}
