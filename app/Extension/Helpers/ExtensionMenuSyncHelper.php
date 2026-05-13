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
 * parent_id 는 항상 확장 정의값으로 업데이트됩니다.
 * is_active 는 확장 정의의 `is_active` 값을 따르며 (기본 true), user_overrides 에 등록되었으면 보존됩니다.
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
            // 신규 생성 — 정의의 is_active 값을 그대로 채택 (기본 true)
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
                    'is_active' => (bool) ($newAttributes['is_active'] ?? true),
                ]
            );

            // 관리자 역할 + 설치자 역할 자동 부여
            $this->grantDefaultRoles($menu);

            return $menu;
        }

        // 기존 메뉴 업데이트: user_overrides 에 없는 필드만 갱신.
        //
        // user_overrides 형식 호환: 컬럼명 단위(`'name'`, legacy) + dot-path sub-key 단위
        // (`'name.ko'`, `'name.en'`, beta.4 도입). 다국어 컬럼은 어느 형태로든 마킹되어
        // 있으면 컬럼 전체를 보존 대상으로 간주한다 (간소화 — sub-key 단위 부분 보존은
        // 별도 도메인 helper 에서 처리).
        $userOverrides = $existing->user_overrides ?? [];

        $isFieldOverridden = static function (string $column) use ($userOverrides): bool {
            if (in_array($column, $userOverrides, true)) {
                return true;
            }
            // dot-path 마킹 (`name.ko`, `name.en` 등) 도 컬럼 전체 보존으로 인정
            $prefix = $column.'.';
            foreach ($userOverrides as $entry) {
                if (is_string($entry) && str_starts_with($entry, $prefix)) {
                    return true;
                }
            }

            return false;
        };

        $updateData = [
            'parent_id' => $parentId,
        ];

        // is_active 는 운영자가 user_overrides 로 마킹한 경우 보존, 아니면 정의값으로 갱신
        if (! $isFieldOverridden('is_active')) {
            $updateData['is_active'] = (bool) ($newAttributes['is_active'] ?? true);
        }

        if (! $isFieldOverridden('name')) {
            $updateData['name'] = $newAttributes['name'] ?? [];
        }

        if (! $isFieldOverridden('icon')) {
            $updateData['icon'] = $newAttributes['icon'] ?? null;
        }

        if (! $isFieldOverridden('order')) {
            $updateData['order'] = $newAttributes['order'] ?? 0;
        }

        if (! $isFieldOverridden('url')) {
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
                // 정의의 is_active 값을 명시 전달 (기본 true). 미전달 시 syncMenu 가 true 로 폴백.
                'is_active' => $menuData['is_active'] ?? true,
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
     * 정책:
     *  - config/확장 정의에 없는 메뉴는 **user_overrides 유무 무관 삭제**
     *  - 필드 단위 보존은 **upsert 시점(`syncMenu()`)** 에서만 작동 (유지 row 에 한해 override 필드만 보존)
     *  - row 자체의 존재 여부는 config 기준으로만 결정 (사용자 수정 이력이 있어도 정의에서 제거되면 삭제)
     *
     * 자식 메뉴는 부모 삭제 시 함께 정리 (orphan 회피).
     *
     * 자동 호출 경로:
     *  - `CoreUpdateService::syncCoreMenus()` 말미 (완전 동기화 원칙)
     *  - `ModuleManager::updateModule()` 말미 (확장 동기화)
     *  - UpgradeStep 에서 명시 호출
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
            if (in_array($menu->slug, $currentSlugs, true)) {
                continue;
            }

            // role_menus 피벗 정리
            $menu->roles()->detach();

            // 자식 메뉴 먼저 삭제 (orphan 회피)
            foreach ($menu->children as $child) {
                $child->roles()->detach();
                $this->menuRepository->delete($child);
                $deleted++;
            }

            $this->menuRepository->delete($menu);
            $deleted++;
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
