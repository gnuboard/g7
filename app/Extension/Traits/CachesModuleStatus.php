<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Cache\CoreCacheDriver;
use App\Models\Module;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 모듈 상태 캐시를 관리하는 Trait
 *
 * 활성화된 모듈, 설치된 모듈 목록을 캐시하여 DB 조회 오버헤드를 줄입니다.
 * ModuleManager, ModuleServiceProvider 등에서 사용됩니다.
 */
trait CachesModuleStatus
{
    /**
     * 활성화된 모듈 identifier 목록을 조회합니다.
     *
     * @return array<string> 활성화된 모듈 identifier 배열
     */
    public static function getActiveModuleIdentifiers(): array
    {
        if (! self::isExtensionTableReady('modules')) {
            return [];
        }

        return self::resolveStatusCache()->remember(
            'ext.modules.active_identifiers',
            fn () => Module::where('status', ExtensionStatus::Active->value)
                ->pluck('identifier')
                ->toArray(),
            (int) g7_core_settings('cache.extension_status_ttl', 86400),
            ['ext.status', 'ext.modules']
        );
    }

    /**
     * 설치된 모듈 (active + inactive) identifier 목록을 조회합니다.
     *
     * @return array<string> 설치된 모듈 identifier 배열
     */
    public static function getInstalledModuleIdentifiers(): array
    {
        if (! self::isExtensionTableReady('modules')) {
            return [];
        }

        return self::resolveStatusCache()->remember(
            'ext.modules.installed_identifiers',
            fn () => Module::whereIn('status', [
                ExtensionStatus::Active->value,
                ExtensionStatus::Inactive->value,
            ])->pluck('identifier')->toArray(),
            (int) g7_core_settings('cache.extension_status_ttl', 86400),
            ['ext.status', 'ext.modules']
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
        $cache = self::resolveStatusCache();
        $cache->forget('ext.modules.active_identifiers');
        $cache->forget('ext.modules.installed_identifiers');
    }

    /**
     * DB 연결 + 테이블 존재 여부를 확인합니다 (인스톨러 안전성).
     *
     * 설치 완료 상태(`config('app.installer_completed')`)일 때는 테이블 존재를
     * 전제로 하여 `Schema::hasTable()` 호출을 건너뜁니다. 인스톨러 이전 환경이나
     * 테스트에서는 기존 체크 경로로 폴백합니다.
     */
    private static function isExtensionTableReady(string $table): bool
    {
        if (config('app.installer_completed')) {
            return true;
        }

        try {
            DB::connection()->getPdo();

            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * CacheInterface 인스턴스를 lazy 조회합니다 (register 시점 안전).
     */
    private static function resolveStatusCache(): CacheInterface
    {
        try {
            return app(CacheInterface::class);
        } catch (\Throwable $e) {
            return new CoreCacheDriver(config('cache.default', 'array'));
        }
    }
}
