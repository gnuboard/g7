<?php

namespace App\Extension\Helpers;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;

/**
 * 확장이 선언한 선언형 산출물의 키 목록 — 선언에서 빠진 항목 정리(`cleanupStale*`)와 그 미리보기(`planCleanup`)가 같은 키를 쓴다.
 *
 * 반환값이 null 이면 「그 종류를 선언하지 않음」이다 — 정리를 건너뛴다(빈 선언으로 DB 행을 모두 지우는 사고 방어).
 */
final class DeclaredExtensionKeys
{
    /**
     * 선언된 권한 식별자 (확장 루트 + 카테고리 + 액션 + 동적 식별자).
     *
     * @param  ModuleInterface|PluginInterface  $extension  확장 인스턴스
     * @return array<int, string>|null 식별자 목록 (카테고리 선언이 없으면 null)
     */
    public static function permissions(ModuleInterface|PluginInterface $extension): ?array
    {
        $config = $extension->getPermissions();

        if (! isset($config['categories'])) {
            return null;
        }

        $identifier = $extension->getIdentifier();
        $ids = [$identifier];

        foreach ($config['categories'] as $category) {
            $categoryIdentifier = $identifier.'.'.($category['identifier'] ?? '');
            if (! empty($category['identifier'])) {
                $ids[] = $categoryIdentifier;
            }
            foreach ($category['permissions'] ?? [] as $permission) {
                if (isset($permission['action'])) {
                    $ids[] = $categoryIdentifier.'.'.$permission['action'];
                }
            }
        }

        if (method_exists($extension, 'getDynamicPermissionIdentifiers')) {
            $ids = array_merge($ids, $extension->getDynamicPermissionIdentifiers());
        }

        return $ids;
    }

    /**
     * 선언된 역할 식별자 (정적 + 동적).
     *
     * @param  ModuleInterface|PluginInterface  $extension  확장 인스턴스
     * @return array<int, string>|null 식별자 목록 (둘 다 비면 null)
     */
    public static function roles(ModuleInterface|PluginInterface $extension): ?array
    {
        if (! method_exists($extension, 'getRoles')) {
            return null;
        }

        $static = array_column($extension->getRoles(), 'identifier');
        $dynamic = method_exists($extension, 'getDynamicRoleIdentifiers') ? $extension->getDynamicRoleIdentifiers() : [];

        return ($static === [] && $dynamic === []) ? null : array_merge($static, $dynamic);
    }

    /**
     * 선언된 관리자 메뉴 슬러그 (하위 메뉴 포함 + 동적).
     *
     * @param  ModuleInterface|PluginInterface  $extension  확장 인스턴스
     * @return array<int, string>|null 슬러그 목록 (비면 null)
     */
    public static function menus(ModuleInterface|PluginInterface $extension): ?array
    {
        if (! method_exists($extension, 'getAdminMenus')) {
            return null;
        }

        $slugs = app(ExtensionMenuSyncHelper::class)->collectSlugsRecursive($extension->getAdminMenus());
        if (method_exists($extension, 'getDynamicMenuSlugs')) {
            $slugs = array_merge($slugs, $extension->getDynamicMenuSlugs());
        }

        return $slugs === [] ? null : $slugs;
    }

    /**
     * 선언된 알림 정의 type.
     *
     * @param  ModuleInterface|PluginInterface  $extension  확장 인스턴스
     * @return array<int, string>|null type 목록 (비면 null)
     */
    public static function notificationTypes(ModuleInterface|PluginInterface $extension): ?array
    {
        if (! method_exists($extension, 'getNotificationDefinitions')) {
            return null;
        }

        $types = array_values(array_filter(array_column($extension->getNotificationDefinitions(), 'type'), 'is_string'));

        return $types === [] ? null : $types;
    }

    /**
     * 선언된 본인확인 정책 키.
     *
     * @param  ModuleInterface|PluginInterface  $extension  확장 인스턴스
     * @return array<int, string>|null 키 목록 (비면 null)
     */
    public static function identityPolicyKeys(ModuleInterface|PluginInterface $extension): ?array
    {
        if (! method_exists($extension, 'getIdentityPolicies')) {
            return null;
        }

        $keys = array_values(array_filter(array_column($extension->getIdentityPolicies(), 'key'), 'is_string'));

        return $keys === [] ? null : $keys;
    }
}
