<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Models\Menu;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 확장 메뉴 동기화 헬퍼
 *
 * 확장(모듈/플러그인) 설치/업데이트 시 사용자 커스터마이징을 보존하면서
 * 메뉴를 안전하게 동기화합니다.
 *
 * user_overrides 컬럼에서 유저가 수정한 필드명 목록을 읽어,
 * 해당 필드는 건너뛰고 나머지만 갱신합니다.
 * parent_id와 is_active는 항상 확장 정의값으로 업데이트됩니다.
 */
class ExtensionMenuSyncHelper
{
    /**
     * @param  MenuRepositoryInterface  $menuRepository  메뉴 저장소
     * @param  RoleRepositoryInterface  $roleRepository  역할 저장소
     */
    public function __construct(
        private readonly MenuRepositoryInterface $menuRepository,
        private readonly RoleRepositoryInterface $roleRepository,
    ) {}

    /**
     * 메뉴를 동기화합니다.
     *
     * 신규: 생성 (user_overrides 없음)
     * 기존: user_overrides에 없는 필드만 업데이트
     *
     * @param  string  $slug  메뉴 슬러그
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array  $newAttributes  새 메뉴 속성 (name, icon, order, url)
     * @param  int|null  $parentId  부모 메뉴 ID
     * @return Menu 동기화된 메뉴 모델
     */
    public function syncMenu(
        string $slug,
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        array $newAttributes,
        ?int $parentId = null,
    ): Menu {
        $existing = $this->menuRepository->findBySlugAndExtension($slug, $extensionType, $extensionIdentifier);

        if (! $existing) {
            // 신규 생성
            $menu = $this->menuRepository->updateOrCreate(
                [
                    'slug' => $slug,
                    'extension_type' => $extensionType,
                    'extension_identifier' => $extensionIdentifier,
                ],
                [
                    'name' => $newAttributes['name'] ?? [],
                    'url' => $newAttributes['url'] ?? null,
                    'icon' => $newAttributes['icon'] ?? null,
                    'order' => $newAttributes['order'] ?? 0,
                    'parent_id' => $parentId,
                    'is_active' => true,
                ]
            );

            // 관리자 역할 + 설치자 역할 자동 부여
            $this->grantDefaultRoles($menu);

            return $menu;
        }

        // 기존 메뉴 업데이트: user_overrides에 없는 필드만 갱신
        $userOverrides = $existing->user_overrides ?? [];

        $updateData = [
            'parent_id' => $parentId,
            'is_active' => true,
        ];

        if (! in_array('name', $userOverrides, true)) {
            $updateData['name'] = $newAttributes['name'] ?? [];
        }

        if (! in_array('icon', $userOverrides, true)) {
            $updateData['icon'] = $newAttributes['icon'] ?? null;
        }

        if (! in_array('order', $userOverrides, true)) {
            $updateData['order'] = $newAttributes['order'] ?? 0;
        }

        if (! in_array('url', $userOverrides, true)) {
            $updateData['url'] = $newAttributes['url'] ?? null;
        }

        $this->menuRepository->update($existing, $updateData);

        return $existing->fresh();
    }

    /**
     * 메뉴 데이터를 재귀적으로 동기화합니다.
     *
     * createMenuRecursive()의 대체 메서드.
     * 다국어 name 역호환 처리 포함.
     *
     * @param  array  $menuData  메뉴 데이터 (slug, name, icon, order, url, children)
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  int|null  $parentId  부모 메뉴 ID
     * @return Menu 동기화된 메뉴 모델
     */
    public function syncMenuRecursive(
        array $menuData,
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        ?int $parentId = null,
    ): Menu {
        // 역호환성: 문자열 name을 다국어 배열로 변환
        $name = $menuData['name'];
        if (is_string($name)) {
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $nameArray = [];
            foreach ($locales as $locale) {
                $nameArray[$locale] = $name;
            }
            $name = $nameArray;
        }

        // slug는 문자열이어야 하므로 배열인 경우 첫 번째 값 사용
        $slug = $menuData['slug'] ?? (is_array($name) ? array_values($name)[0] : $name);

        $menu = $this->syncMenu(
            slug: $slug,
            extensionType: $extensionType,
            extensionIdentifier: $extensionIdentifier,
            newAttributes: [
                'name' => $name,
                'icon' => $menuData['icon'] ?? null,
                'order' => $menuData['order'] ?? 0,
                'url' => $menuData['url'] ?? null,
            ],
            parentId: $parentId,
        );

        // 하위 메뉴가 있는 경우 재귀 처리
        if (isset($menuData['children']) && is_array($menuData['children'])) {
            foreach ($menuData['children'] as $childMenuData) {
                $this->syncMenuRecursive($childMenuData, $extensionType, $extensionIdentifier, $menu->id);
            }
        }

        return $menu;
    }

    /**
     * 신규 메뉴에 관리자 역할과 현재 사용자(설치자) 역할을 자동 부여합니다.
     *
     * @param  Menu  $menu  생성된 메뉴
     */
    private function grantDefaultRoles(Menu $menu): void
    {
        try {
            $roleIds = [];

            // 관리자 역할
            $adminRole = $this->roleRepository->findByIdentifier('admin');
            if ($adminRole) {
                $roleIds[] = $adminRole->id;
            }

            // 현재 인증된 사용자(설치자)의 역할
            $currentUser = Auth::user();
            if ($currentUser) {
                $userRoleIds = $currentUser->roles()->pluck('roles.id')->toArray();
                foreach ($userRoleIds as $userRoleId) {
                    if (! in_array($userRoleId, $roleIds)) {
                        $roleIds[] = $userRoleId;
                    }
                }
            }

            // 역할 권한 부여
            foreach ($roleIds as $roleId) {
                $menu->roles()->syncWithoutDetaching([
                    $roleId => ['permission_type' => MenuPermissionType::Read->value],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('메뉴 기본 역할 부여 실패', [
                'menu_id' => $menu->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 현재 확장에 속하지 않는 stale 메뉴를 정리합니다.
     *
     * ⚠️ 주의: 이 메서드는 정적 정의(getAdminMenus()) 기반으로만 판단하므로,
     * 확장이 런타임에 동적으로 생성한 메뉴도 삭제됩니다.
     * 자동 호출은 폐기되었으며, 필요 시 UpgradeStep에서 명시적으로 호출하세요.
     *
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array  $currentSlugs  현재 유효한 메뉴 슬러그 목록
     * @return int 삭제된 메뉴 수
     *
     * @see \App\Contracts\Extension\UpgradeStepInterface
     */
    public function cleanupStaleMenus(
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        array $currentSlugs,
    ): int {
        $existingMenus = $this->menuRepository->getMenusByExtension($extensionType, $extensionIdentifier);

        $deleted = 0;
        foreach ($existingMenus as $menu) {
            if (! in_array($menu->slug, $currentSlugs, true)) {
                // role_menus 피벗 정리
                $menu->roles()->detach();

                // 자식 메뉴 먼저 삭제
                foreach ($menu->children as $child) {
                    $child->roles()->detach();
                    $this->menuRepository->delete($child);
                    $deleted++;
                }

                $this->menuRepository->delete($menu);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info('stale 메뉴 정리 완료', [
                'extension_type' => $extensionType->value,
                'extension_identifier' => $extensionIdentifier,
                'deleted' => $deleted,
            ]);
        }

        return $deleted;
    }

    /**
     * 메뉴 정의 배열에서 모든 slug를 재귀적으로 수집합니다.
     *
     * @param  array  $menuDataArray  메뉴 정의 배열
     * @return array slug 배열
     */
    public function collectSlugsRecursive(array $menuDataArray): array
    {
        $slugs = [];
        foreach ($menuDataArray as $menuData) {
            $name = $menuData['name'] ?? '';
            if (is_string($name)) {
                $locales = config('app.translatable_locales', ['ko', 'en']);
                $nameArray = [];
                foreach ($locales as $locale) {
                    $nameArray[$locale] = $name;
                }
                $name = $nameArray;
            }

            $slug = $menuData['slug'] ?? (is_array($name) ? array_values($name)[0] : $name);
            $slugs[] = $slug;

            if (isset($menuData['children']) && is_array($menuData['children'])) {
                $slugs = array_merge($slugs, $this->collectSlugsRecursive($menuData['children']));
            }
        }

        return $slugs;
    }
}
