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
     * DB 연결 + 테이블 존재 여부를 확인합니다 (인스톨러 안전성).
     *
     * 항상 실제 테이블 존재 여부를 확인합니다.
     * 결과는 정적 캐시에 저장되어 같은 요청 내에서 중복 Schema 조회를 방지합니다.
     * (신규 서버 배포 시 INSTALLER_COMPLETED=true 상태에서 마이그레이션 전 실행되는 경우 대응)
     */
    private static function isTemplateTableReady(): bool
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        try {
            DB::connection()->getPdo();
            $cache = Schema::hasTable('templates');
        } catch (\Throwable $e) {
            $cache = false;
        }

        return $cache;
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
