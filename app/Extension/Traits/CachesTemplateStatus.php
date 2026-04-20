<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Cache\CoreCacheDriver;
use App\Models\Template;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 템플릿 상태 캐시를 관리하는 Trait
 *
 * 활성화된 템플릿, 설치된 템플릿 목록을 캐시하여 DB 조회 오버헤드를 줄입니다.
 * TemplateManager, TemplateServiceProvider 등에서 사용됩니다.
 */
trait CachesTemplateStatus
{
    /**
     * 활성화된 템플릿 identifier 목록을 조회합니다.
     *
     * @return array<string> 활성화된 템플릿 identifier 배열
     */
    public static function getActiveTemplateIdentifiers(): array
    {
        if (! self::isTemplateTableReady()) {
            return [];
        }

        return self::resolveTemplateStatusCache()->remember(
            'ext.templates.active_identifiers',
            fn () => Template::where('status', ExtensionStatus::Active->value)
                ->pluck('identifier')
                ->toArray(),
            (int) g7_core_settings('cache.extension_status_ttl', 86400),
            ['ext.status', 'ext.templates']
        );
    }

    /**
     * 특정 타입의 활성화된 템플릿 identifier 목록을 조회합니다.
     *
     * @param  string  $type  템플릿 타입 (admin, user)
     * @return array<string> 활성화된 템플릿 identifier 배열
     */
    public static function getActiveTemplateIdentifiersByType(string $type): array
    {
        if (! self::isTemplateTableReady()) {
            return [];
        }

        return self::resolveTemplateStatusCache()->remember(
            "ext.templates.active_identifiers_{$type}",
            fn () => Template::where('status', ExtensionStatus::Active->value)
                ->where('type', $type)
                ->pluck('identifier')
                ->toArray(),
            (int) g7_core_settings('cache.extension_status_ttl', 86400),
            ['ext.status', 'ext.templates']
        );
    }

    /**
     * 설치된 템플릿 (active + inactive) identifier 목록을 조회합니다.
     *
     * @return array<string> 설치된 템플릿 identifier 배열
     */
    public static function getInstalledTemplateIdentifiers(): array
    {
        if (! self::isTemplateTableReady()) {
            return [];
        }

        return self::resolveTemplateStatusCache()->remember(
            'ext.templates.installed_identifiers',
            fn () => Template::whereIn('status', [
                ExtensionStatus::Active->value,
                ExtensionStatus::Inactive->value,
            ])->pluck('identifier')->toArray(),
            (int) g7_core_settings('cache.extension_status_ttl', 86400),
            ['ext.status', 'ext.templates']
        );
    }

    /**
     * 템플릿 상태 캐시를 무효화합니다.
     * 템플릿 상태 변경 시 (install, activate, deactivate, uninstall) 호출해야 합니다.
     *
     * @return void
     */
    public static function invalidateTemplateStatusCache(): void
    {
        $cache = self::resolveTemplateStatusCache();
        $cache->forget('ext.templates.active_identifiers');
        $cache->forget('ext.templates.active_identifiers_admin');
        $cache->forget('ext.templates.active_identifiers_user');
        $cache->forget('ext.templates.installed_identifiers');
    }

    /**
     * 설치 완료 상태에서는 `Schema::hasTable()` 호출을 건너뜁니다.
     * 인스톨러 이전 환경이나 테스트에서는 기존 체크 경로로 폴백합니다.
     */
    private static function isTemplateTableReady(): bool
    {
        if (config('app.installer_completed')) {
            return true;
        }

        try {
            DB::connection()->getPdo();

            return Schema::hasTable('templates');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function resolveTemplateStatusCache(): CacheInterface
    {
        try {
            return app(CacheInterface::class);
        } catch (\Throwable $e) {
            return new CoreCacheDriver(config('cache.default', 'array'));
        }
    }
}
