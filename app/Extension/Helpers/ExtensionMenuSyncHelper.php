<?php

namespace App\Extension\Helpers;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Extension\Helpers\Concerns\RecordsSyncDeletion;
use App\Extension\Support\LifecycleWarnings;
use App\Models\Menu;
use App\Models\Role;
use App\Services\MenuIconService;
use App\Support\MenuIcon\MenuIconDeclaration;
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
    use RecordsSyncDeletion;

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
        // 선언 아이콘 정규화 — 문자열(FA 클래스)·구조형(class·image 상대경로·svg path/value)을 저장 정규형으로.
        // 잘못된 선언은 설치를 막지 않고 null + 라이프사이클 경고(menu_icon) 로 남긴다 (#381)
        $icon = MenuIconDeclaration::normalize($newAttributes['icon'] ?? null, $extensionType, $extensionIdentifier, $slug);

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
                    'icon' => $icon,
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

        $updateData = [];

        // parent_id 도 운영자가 옮겼으면(user_overrides 에 기록) 보존한다 — 종전엔 무조건 정의값으로 덮어
        // 옮긴 메뉴가 업데이트마다 원위치됐다(#727 D5-③).
        if (! $isFieldOverridden('parent_id')) {
            $updateData['parent_id'] = $parentId;
        }

        // is_active 는 운영자가 user_overrides 로 마킹한 경우 보존, 아니면 정의값으로 갱신
        if (! $isFieldOverridden('is_active')) {
            $updateData['is_active'] = (bool) ($newAttributes['is_active'] ?? true);
        }

        if (! $isFieldOverridden('name')) {
            $updateData['name'] = $newAttributes['name'] ?? [];
        }

        if (! $isFieldOverridden('icon')) {
            $updateData['icon'] = $icon;
        }

        if (! $isFieldOverridden('order')) {
            $updateData['order'] = $newAttributes['order'] ?? 0;
        }

        if (! $isFieldOverridden('url')) {
            $updateData['url'] = $newAttributes['url'] ?? null;
        }

        // user_overrides 자동 마킹 비활성화 — 시스템 sync 컨텍스트는 사용자 변경이 아님.
        // HasUserOverrides::bootHasUserOverrides 의 updating 이벤트 hook 이 'seeding' 플래그를
        // 보고 자동 마킹을 건너뛴다. 미설정 시 동일한 module.php 정의값을 적용해도 icon/name/order
        // 등 trackable 필드가 dirty 로 잡혀 user_overrides 에 자동 추가되어, 이후 sync 가 차단되는
        // 결함이 발생한다.
        app()->instance('user_overrides.seeding', true);
        $previousIcon = $existing->getRawOriginal('icon');

        try {
            $this->menuRepository->update($existing, $updateData);
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }

        $fresh = $existing->fresh();

        // 선언값이 운영자 업로드 아이콘을 덮었으면 그 첨부를 커밋 뒤 지운다 — 저장 경로(MenuService)와 같은 정리 (#381)
        if ($fresh !== null && array_key_exists('icon', $updateData) && $fresh->getRawOriginal('icon') !== $previousIcon) {
            app(MenuIconService::class)->syncAttachmentBinding($fresh, $previousIcon);
        }

        return $fresh;
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
     * @return void 반환값 없음
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
            LifecycleWarnings::current()->add('menu_role', (string) ($menu->slug ?? $menu->id ?? ''), $e->getMessage());
        }
    }

    /**
     * 확장이 선언한 역할에 그 확장 메뉴 중 역할이 가진 권한으로 열 수 있는 메뉴를 붙입니다.
     *
     * 새 메뉴는 관리자 역할과 설치한 사람의 역할에만 자동으로 붙는다(`grantDefaultRoles()`). 그래서 확장이
     * 선언한 역할(예: 이커머스 관리자)만 가진 운영자는 그 확장 권한을 갖고도 관리자 사이드바가 비었다.
     * 이 메서드는 선언 역할마다 메뉴 정의를 훑어 다음 규칙으로 읽기 권한을 붙인다.
     *
     *  - 메뉴의 필요 권한은 정의의 `permission` 키다. 역할이 그 권한을 가지면(범위 제한 권한 포함) 붙인다.
     *  - 필요 권한이 없는 상위 메뉴는 붙는 하위 메뉴가 하나라도 있을 때 붙인다(하위 메뉴는 상위를 거쳐서만 보인다).
     *  - 필요 권한을 선언하지 않은 단독 메뉴는 판정할 근거가 없으므로 붙이지 않는다.
     *  - 운영자가 역할 관리·메뉴 관리에서 이 역할을 넣거나 뺀 메뉴(메뉴 `user_overrides` 에 역할 식별자)는 건드리지 않는다.
     *    그 상위 메뉴에서 역할을 뺀 상태면 하위 메뉴도 붙이지 않는다.
     *  - 붙이기만 하고 떼지 않는다. 이미 붙어 있으면 그대로 둔다(멱등).
     *
     * 설치·업데이트·활성 확장 재동기화가 모두 메뉴 동기화 뒤에 이 메서드를 부르므로, 기존 사이트도 다음 동기화 때 채워진다.
     *
     * @param  array  $menuDataArray  메뉴 정의 배열 (`getAdminMenus()` 형식)
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array<int, string>  $roleIdentifiers  확장이 선언한 역할 식별자 목록
     * @return int 새로 붙인 역할-메뉴 연결 수
     */
    public function grantMenusToDeclaredRoles(
        array $menuDataArray,
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        array $roleIdentifiers,
    ): int {
        $granted = 0;

        foreach (array_unique(array_filter($roleIdentifiers, 'is_string')) as $roleIdentifier) {
            // 관리자 역할은 새 메뉴마다 이미 붙는다
            if ($roleIdentifier === 'admin') {
                continue;
            }

            try {
                $role = $this->roleRepository->findByIdentifier($roleIdentifier);
                if ($role === null) {
                    continue;
                }

                $heldPermissions = $role->permissions()->pluck('permissions.identifier')->all();

                foreach ($menuDataArray as $menuData) {
                    if (is_array($menuData)) {
                        $this->grantOpenableMenuTree($menuData, $extensionType, $extensionIdentifier, $role, $heldPermissions, $granted);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('확장 선언 역할 메뉴 부여 실패', [
                    'extension_type' => $extensionType->value,
                    'extension_identifier' => $extensionIdentifier,
                    'role' => $roleIdentifier,
                    'error' => $e->getMessage(),
                ]);
                LifecycleWarnings::current()->add('menu_role', $roleIdentifier, $e->getMessage());
            }
        }

        return $granted;
    }

    /**
     * 메뉴 정의 하나(와 하위)를 훑어 역할이 열 수 있으면 붙이고, 그 역할에게 이 메뉴가 보이는지 돌려줍니다.
     *
     * @param  array  $menuData  메뉴 정의
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  Role  $role  대상 역할
     * @param  array<int, string>  $heldPermissions  역할이 가진 권한 식별자
     * @param  int  $granted  새로 붙인 연결 수 (누적)
     * @return bool 이 메뉴가 그 역할에 붙어 있으면(이번에 붙였거나 이미 있었으면) true
     */
    private function grantOpenableMenuTree(
        array $menuData,
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        Role $role,
        array $heldPermissions,
        int &$granted,
    ): bool {
        $menu = $this->menuRepository->findBySlugAndExtension($this->resolveSlug($menuData), $extensionType, $extensionIdentifier);
        if ($menu === null) {
            return false;
        }

        $isAttached = static fn (Menu $target): bool => $target->roles()
            ->wherePivot('permission_type', MenuPermissionType::Read->value)
            ->whereKey($role->id)
            ->exists();

        // 운영자가 이 메뉴에서 이 역할을 넣거나 뺐다 — 그 결정을 따른다. 뺀 상태면 하위도 보이지 않으므로 손대지 않는다.
        $operatorDecided = in_array($role->identifier, (array) ($menu->user_overrides ?? []), true);
        if ($operatorDecided && ! $isAttached($menu)) {
            return false;
        }

        $hasOpenableChild = false;
        foreach ($menuData['children'] ?? [] as $childData) {
            if (is_array($childData)
                && $this->grantOpenableMenuTree($childData, $extensionType, $extensionIdentifier, $role, $heldPermissions, $granted)) {
                $hasOpenableChild = true;
            }
        }

        if ($operatorDecided) {
            return true;
        }

        $requiredPermission = $menuData['permission'] ?? null;
        $openable = (is_string($requiredPermission) && $requiredPermission !== '' && in_array($requiredPermission, $heldPermissions, true))
            || $hasOpenableChild;

        if (! $openable) {
            return $isAttached($menu);
        }

        if (! $isAttached($menu)) {
            $menu->roles()->attach($role->id, ['permission_type' => MenuPermissionType::Read->value]);
            $granted++;
        }

        return true;
    }

    /**
     * 메뉴 정의의 슬러그를 구합니다 (`syncMenuRecursive()` 와 같은 규칙 — slug 가 없으면 첫 언어 이름).
     *
     * @param  array  $menuData  메뉴 정의
     * @return string 슬러그
     */
    private function resolveSlug(array $menuData): string
    {
        if (isset($menuData['slug'])) {
            return (string) $menuData['slug'];
        }

        $name = $menuData['name'] ?? '';

        return (string) (is_array($name) ? (array_values($name)[0] ?? '') : $name);
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
     * @see UpgradeStepInterface
     */
    public function cleanupStaleMenus(
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        array $currentSlugs,
    ): int {
        // 검수 서버(stage)는 공유 DB 의 선언 항목을 지우지 않고 대장에 미룬다 — 운영 서버가 extension:apply-deferred-cleanup 으로 같은 규칙대로 지운다
        if (DeferredDeclarativeCleanup::shouldDefer()) {
            return DeferredDeclarativeCleanup::defer($this, __FUNCTION__, func_get_args());
        }

        $existingMenus = $this->menuRepository->getMenusByExtension($extensionType, $extensionIdentifier);

        $deleted = 0;
        foreach ($existingMenus as $menu) {
            if (in_array($menu->slug, $currentSlugs, true)) {
                continue;
            }

            // 앞선 메뉴의 하위 트리로 이미 지워졌으면 건너뛴다.
            if ($this->menuRepository->findById((int) $menu->id) === null) {
                continue;
            }

            $deleted += $this->deleteMenuSubtree($menu, $extensionType, $extensionIdentifier, $currentSlugs);
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

    /**
     * 선언에서 빠진 메뉴 하나를 하위 트리(손자 포함)째 명시적으로 지웁니다.
     *
     * 순서: 역할 배정(`role_menus`)·메뉴 권한(`menu_permissions`) → 깊은 자식부터 → 본 메뉴. 운영자가 만든 자식(다른 출처)과
     * 새 판이 여전히 선언한 자식은 지우지 않고 최상위(부모 null)로 올린다 — 종전에는 손자까지 DB CASCADE 로 사라졌다.
     * 운영자 데이터(고친 필드·운영자가 만든 자식)가 있으면 지우기 직전 `menu.sync_deleted` 로 트리·역할 배정을 남긴다.
     *
     * @param  Menu  $menu  선언에서 빠진 메뉴
     * @param  ExtensionOwnerType  $extensionType  소유 확장 종류
     * @param  string  $extensionIdentifier  소유 확장 식별자
     * @param  array<int, string>  $currentSlugs  새 판이 선언한 슬러그
     * @return int 지운 메뉴 수
     */
    private function deleteMenuSubtree(Menu $menu, ExtensionOwnerType $extensionType, string $extensionIdentifier, array $currentSlugs): int
    {
        [$owned, $lifted] = $this->partitionMenuSubtree($menu, $extensionType, $extensionIdentifier, $currentSlugs);

        $operatorData = $lifted !== [] || collect($owned)->contains(fn (Menu $m) => $this->hasOperatorOverrides($m));
        if ($operatorData) {
            $this->recordSyncDeletion('menu', (string) $menu->slug, $extensionType->value, $extensionIdentifier, [
                'menus' => array_map(fn (Menu $m) => $m->toArray() + ['role_ids' => $m->roles()->pluck('roles.id')->all()], $owned),
                'lifted_children' => array_map(fn (Menu $m) => (string) $m->slug, $lifted),
            ]);
        }

        foreach ($lifted as $child) {
            $this->menuRepository->update($child, ['parent_id' => null]);
        }

        foreach (array_reverse($owned) as $node) {
            $node->roles()->detach();
            $this->menuRepository->deleteMenuPermissions($node);
            $this->menuRepository->delete($node);
        }

        return count($owned);
    }

    /**
     * 메뉴 하위 트리를 「같이 지울 것」과 「최상위로 올릴 것」으로 나눕니다 (부모 → 자식 순).
     *
     * @param  Menu  $menu  뿌리 메뉴
     * @param  ExtensionOwnerType  $extensionType  소유 확장 종류
     * @param  string  $extensionIdentifier  소유 확장 식별자
     * @param  array<int, string>  $currentSlugs  새 판이 선언한 슬러그
     * @return array{0: list<Menu>, 1: list<Menu>} [지울 메뉴(뿌리 포함), 올릴 자식]
     */
    private function partitionMenuSubtree(Menu $menu, ExtensionOwnerType $extensionType, string $extensionIdentifier, array $currentSlugs): array
    {
        $owned = [$menu];
        $lifted = [];
        $queue = [$menu];

        while ($queue !== []) {
            $node = array_shift($queue);

            foreach ($this->menuRepository->getChildrenByParent((int) $node->id) as $child) {
                $childType = $child->extension_type instanceof ExtensionOwnerType ? $child->extension_type : ExtensionOwnerType::tryFrom((string) $child->extension_type);
                $sameOwner = $childType === $extensionType && (string) $child->extension_identifier === $extensionIdentifier;

                if (! $sameOwner || in_array($child->slug, $currentSlugs, true)) {
                    $lifted[] = $child;

                    continue;
                }

                $owned[] = $child;
                $queue[] = $child;
            }
        }

        return [$owned, $lifted];
    }

    /**
     * 지워질 stale 메뉴를 미리 계산합니다 — 쓰기 없음, 실제 정리와 같은 판정식.
     *
     * @param  ExtensionOwnerType  $extensionType  소유 확장 종류
     * @param  string  $extensionIdentifier  소유 확장 식별자
     * @param  array<int, string>  $currentSlugs  새 판이 선언한 슬러그
     * @return list<array{type: string, key: string, has_operator_overrides: bool}> 삭제 예정
     */
    public function planCleanup(ExtensionOwnerType $extensionType, string $extensionIdentifier, array $currentSlugs): array
    {
        $plan = [];
        $seen = [];

        foreach ($this->menuRepository->getMenusByExtension($extensionType, $extensionIdentifier) as $menu) {
            if (in_array($menu->slug, $currentSlugs, true) || isset($seen[$menu->id])) {
                continue;
            }

            [$owned, $lifted] = $this->partitionMenuSubtree($menu, $extensionType, $extensionIdentifier, $currentSlugs);
            foreach ($owned as $node) {
                $seen[$node->id] = true;
            }

            $plan[] = ['type' => 'menu', 'key' => (string) $menu->slug, 'has_operator_overrides' => $lifted !== [] || collect($owned)->contains(fn (Menu $m) => $this->hasOperatorOverrides($m))];
        }

        return $plan;
    }
}
