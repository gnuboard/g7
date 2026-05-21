<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Cache\CoreCacheDriver;
use App\Models\Plugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 플러그인 상태 캐시를 관리하는 Trait
 *
 * 활성화된 플러그인, 설치된 플러그인 목록을 캐시하여 DB 조회 오버헤드를 줄입니다.
 * PluginManager, PluginServiceProvider 등에서 사용됩니다.
 */
trait CachesPluginStatus
{
    /**
     * 활성화된 플러그인 identifier 목록을 조회합니다.
     *
     * @return array<string> 활성화된 플러그인 identifier 배열
     */
    public static function getActivePluginIdentifiers(): array
    {
        if (! self::isPluginTableReady()) {
            return [];
        }

        return self::resolvePluginStatusCache()->remember(
            'ext.plugins.active_identifiers',
            fn () => Plugin::where('status', ExtensionStatus::Active->value)
                ->pluck('identifier')
                ->toArray(),
            (int) g7_core_settings('cache.extension_status_ttl', 86400),
            ['ext.status', 'ext.plugins']
        );
    }

    /**
     * 설치된 플러그인 (active + inactive) identifier 목록을 조회합니다.
     *
     * @return array<string> 설치된 플러그인 identifier 배열
     */
    public static function getInstalledPluginIdentifiers(): array
    {
        if (! self::isPluginTableReady()) {
            return [];
        }

        return self::resolvePluginStatusCache()->remember(
            'ext.plugins.installed_identifiers',
            fn () => Plugin::whereIn('status', [
                ExtensionStatus::Active->value,
                ExtensionStatus::Inactive->value,
            ])->pluck('identifier')->toArray(),
            (int) g7_core_settings('cache.extension_status_ttl', 86400),
            ['ext.status', 'ext.plugins']
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
        $cache = self::resolvePluginStatusCache();
        $cache->forget('ext.plugins.active_identifiers');
        $cache->forget('ext.plugins.installed_identifiers');
    }

    /**
     * DB 연결 + 테이블 존재 여부를 확인합니다 (인스톨러 안전성).
     *
     * 항상 실제 테이블 존재 여부를 확인합니다.
     * 결과는 정적 캐시에 저장되어 같은 요청 내에서 중복 Schema 조회를 방지합니다.
     * (신규 서버 배포 시 INSTALLER_COMPLETED=true 상태에서 마이그레이션 전 실행되는 경우 대응)
     */
    private static function isPluginTableReady(): bool
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        try {
            DB::connection()->getPdo();
            $cache = Schema::hasTable('plugins');
        } catch (\Throwable $e) {
            $cache = false;
        }

        return $cache;
    }

    private static function resolvePluginStatusCache(): CacheInterface
    {
        try {
            return app(CacheInterface::class);
        } catch (\Throwable $e) {
            return new CoreCacheDriver(config('cache.default', 'array'));
        }
    }
}
