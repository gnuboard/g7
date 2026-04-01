<?php

namespace App\Extension\Traits;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Services\CacheService;

/**
 * 템플릿 상태 캐시를 관리하는 Trait
 *
 * 활성화된 템플릿, 설치된 템플릿 목록을 캐시하여 DB 조회 오버헤드를 줄입니다.
 * TemplateManager, TemplateServiceProvider 등에서 사용됩니다.
 */
trait CachesTemplateStatus
{
    /**
     * 캐시 그룹
     */
    private static string $templateCacheGroup = 'templates';

    /**
     * 활성화된 템플릿 identifier 목록을 조회합니다.
     *
     * @return array<string> 활성화된 템플릿 identifier 배열
     */
    public static function getActiveTemplateIdentifiers(): array
    {
        return CacheService::remember(
            self::$templateCacheGroup,
            'active_identifiers',
            fn () => Template::where('status', ExtensionStatus::Active->value)
                ->pluck('identifier')
                ->toArray(),
            null,
            'templates'
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
        return CacheService::remember(
            self::$templateCacheGroup,
            "active_identifiers_{$type}",
            fn () => Template::where('status', ExtensionStatus::Active->value)
                ->where('type', $type)
                ->pluck('identifier')
                ->toArray(),
            null,
            'templates'
        );
    }

    /**
     * 설치된 템플릿 (active + inactive) identifier 목록을 조회합니다.
     *
     * @return array<string> 설치된 템플릿 identifier 배열
     */
    public static function getInstalledTemplateIdentifiers(): array
    {
        return CacheService::remember(
            self::$templateCacheGroup,
            'installed_identifiers',
            fn () => Template::whereIn('status', [
                ExtensionStatus::Active->value,
                ExtensionStatus::Inactive->value,
            ])->pluck('identifier')->toArray(),
            null,
            'templates'
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
        CacheService::forgetMany(self::$templateCacheGroup, [
            'active_identifiers',
            'active_identifiers_admin',
            'active_identifiers_user',
            'installed_identifiers',
        ]);
    }
}
