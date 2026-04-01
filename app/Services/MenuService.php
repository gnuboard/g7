<?php

namespace App\Services;

use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Extension\HookManager;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MenuService
{
    public function __construct(
        private MenuRepositoryInterface $menuRepository,
        private RoleRepositoryInterface $roleRepository,
        private ModuleService $moduleService,
        private PluginService $pluginService
    ) {}

    /**
     * 모든 메뉴 목록을 반환합니다.
     *
     * @return Collection 메뉴 컬렉션
     */
    public function getAllMenus(): Collection
    {
        return $this->menuRepository->getAll();
    }

    /**
     * 최상위 메뉴들을 반환합니다.
     *
     * @return Collection 최상위 메뉴 컬렉션
     */
    public function getTopLevelMenus(): Collection
    {
        return $this->menuRepository->getTopLevelMenus();
    }

    public function getTopLevelMenusForManagement(?User $user = null): Collection
    {
        $activeModuleIdentifiers = $this->moduleService->getActiveModuleIdentifiers();
        $activePluginIdentifiers = $this->pluginService->getActivePluginIdentifiers();

        return $this->menuRepository->getTopLevelMenusForManagement($activeModuleIdentifiers, $activePluginIdentifiers, $user);
    }

    public function getFilteredMenusForManagement(array $filters, ?User $user = null): Collection
    {
        $activeModuleIdentifiers = $this->moduleService->getActiveModuleIdentifiers();
        $activePluginIdentifiers = $this->pluginService->getActivePluginIdentifiers();

        return $this->menuRepository->getFilteredTopLevelMenus($filters, $activeModuleIdentifiers, $activePluginIdentifiers, $user);
    }

    /**
     * 활성화된 메뉴들을 반환합니다.
     *
     * @return Collection 활성화된 메뉴 컬렉션
     */
    public function getActiveMenus(): Collection
    {
        return $this->menuRepository->getActiveMenus();
    }

    /**
     * 새로운 메뉴를 생성합니다.
     *
     * 관리자 역할과 생성자 역할이 자동으로 부여됩니다.
     *
     * @param  array  $data  메뉴 생성 데이터
     * @return Menu 생성된 메뉴 모델
     */
    public function createMenu(array $data): Menu
    {
        // roles 데이터 추출
        $roles = $data['roles'] ?? [];
        unset($data['roles']);

        // Hook: 메뉴 생성 전
        HookManager::doAction('core.menu.before_create', $data);

        // Hook: 생성 데이터 필터링
        $data = HookManager::applyFilters('core.menu.filter_create_data', $data);

        // order가 설정되지 않은 경우, 같은 부모 내에서 마지막 order + 1로 설정
        if (! isset($data['order'])) {
            $parentId = $data['parent_id'] ?? null;
            $maxOrder = $this->menuRepository->getMaxOrder($parentId);
            $data['order'] = $maxOrder + 1;
        }
        $menu = $this->menuRepository->create($data);

        // 관리자 역할 + 생성자 역할 자동 추가
        $roles = $this->ensureAdminAndCreatorRoles($roles);

        // 역할 권한 동기화
        $this->syncMenuRoles($menu, $roles);

        // Hook: 메뉴 생성 후
        HookManager::doAction('core.menu.after_create', $menu);

        return $menu;
    }

    /**
     * 기존 메뉴를 업데이트합니다.
     *
     * 검증은 UpdateMenuRequest에서 수행됩니다.
     * - slug unique 검증: FormRequest rules (자기 자신 제외)
     * - parent_id exists 검증: FormRequest rules
     * - 자기 자신을 부모로 설정 방지: NotSelfParent Custom Rule
     *
     * @param  Menu  $menu  업데이트할 메뉴 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function updateMenu(Menu $menu, array $data): bool
    {
        // roles 데이터 추출
        $roles = null;
        if (array_key_exists('roles', $data)) {
            $roles = $data['roles'] ?? [];
            unset($data['roles']);
        }

        // Hook: 메뉴 업데이트 전
        HookManager::doAction('core.menu.before_update', $menu, $data);

        // 스냅샷 캡처 (ChangeDetector용)
        $snapshot = $menu->toArray();

        // Hook: 업데이트 데이터 필터링
        $data = HookManager::applyFilters('core.menu.filter_update_data', $data, $menu);

        $result = $this->menuRepository->update($menu, $data);

        // 역할 권한 동기화 (roles 필드가 전달된 경우에만)
        if ($roles !== null) {
            $this->syncMenuRoles($menu, $roles);
        }

        // Hook: 메뉴 업데이트 후 (스냅샷 전달)
        HookManager::doAction('core.menu.after_update', $menu, $snapshot);

        return $result;
    }

    /**
     * 메뉴를 삭제합니다.
     *
     * @param  Menu  $menu  삭제할 메뉴 모델
     * @return bool 삭제 성공 여부
     *
     * @throws ValidationException 자식 메뉴가 있을 때
     */
    public function deleteMenu(Menu $menu): bool
    {
        // 자식 메뉴가 있는지 확인 (비즈니스 로직으로 Service에서 처리)
        $children = $this->menuRepository->getChildrenByParent($menu->id);
        if ($children->count() > 0) {
            throw ValidationException::withMessages([
                'menu' => [__('menu.cannot_delete_menu_with_children')],
            ]);
        }

        // Hook: 메뉴 삭제 전
        HookManager::doAction('core.menu.before_delete', $menu);

        // 역할 연결 해제 (명시적 삭제 - CASCADE 의존 금지)
        $menu->roles()->detach();

        $result = $this->menuRepository->delete($menu);

        // Hook: 메뉴 삭제 후
        HookManager::doAction('core.menu.after_delete', $menu->id);

        return $result;
    }

    /**
     * 메뉴 순서를 업데이트합니다 (드래그 앤 드롭).
     *
     * @param  array  $menuOrders  메뉴 ID와 순서 매핑 배열
     * @return bool 업데이트 성공 여부
     */
    public function updateMenuOrder(array $menuOrders): bool
    {
        $result = $this->menuRepository->updateOrder($menuOrders);

        // 훅: 메뉴 순서 변경 후
        HookManager::doAction('core.menu.after_update_order', $menuOrders);

        return $result;
    }

    /**
     * 계층 구조를 고려한 메뉴 순서를 업데이트합니다.
     *
     * @param  array  $orderData  계층 구조 순서 데이터
     * @return bool 업데이트 성공 여부
     */
    public function updateMenuOrderWithHierarchy(array $orderData): bool
    {
        $result = $this->menuRepository->updateOrderWithHierarchy($orderData);

        // 훅: 메뉴 계층 순서 변경 후
        HookManager::doAction('core.menu.after_update_order', $orderData);

        return $result;
    }

    /**
     * 메뉴의 활성화 상태를 토글합니다.
     *
     * @param  Menu  $menu  대상 메뉴 모델
     * @return bool 업데이트 성공 여부
     */
    public function toggleMenuStatus(Menu $menu): bool
    {
        $newStatus = ! $menu->is_active;

        // Hook: 상태 변경 전
        HookManager::doAction('core.menu.before_toggle_status', $menu, $newStatus);

        $result = $this->menuRepository->update($menu, [
            'is_active' => $newStatus,
        ]);

        // Hook: 상태 변경 후
        HookManager::doAction('core.menu.after_toggle_status', $menu);

        return $result;
    }

    /**
     * 특정 확장에 속한 메뉴들을 조회합니다.
     *
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return Collection 확장에 속한 메뉴 컬렉션
     */
    public function getMenusByExtension(ExtensionOwnerType $type, string $identifier): Collection
    {
        return $this->menuRepository->getMenusByExtension($type, $identifier);
    }

    /**
     * 메뉴의 계층 구조를 Collection으로 반환합니다.
     *
     * @return Collection 메뉴 계층 구조 컬렉션
     */
    public function getMenuHierarchy(): Collection
    {
        return $this->menuRepository->getTopLevelMenus();
    }

    /**
     * 네비게이션용 활성화된 메뉴들을 계층 구조로 반환합니다.
     *
     * @return Collection 네비게이션용 메뉴 컬렉션
     */
    public function getNavigationMenus(): Collection
    {
        return $this->menuRepository->getActiveMenusWithChildren();
    }

    /**
     * 사용자가 접근 가능한 네비게이션용 메뉴들을 반환합니다.
     *
     * @param  User  $user  접근 권한을 확인할 사용자
     * @return Collection 사용자가 접근 가능한 네비게이션용 메뉴 컬렉션
     */
    public function getAccessibleNavigationMenus(User $user): Collection
    {
        return $this->menuRepository->getAccessibleNavigationMenus($user);
    }

    /**
     * 역할 ID 배열에 관리자 역할과 현재 사용자의 역할을 자동 추가합니다.
     *
     * @param  array  $roleIds  원본 역할 ID 배열
     * @return array 관리자 + 생성자 역할이 추가된 역할 ID 배열
     */
    private function ensureAdminAndCreatorRoles(array $roleIds): array
    {
        // 관리자 역할 자동 추가
        $adminRole = $this->roleRepository->findByIdentifier('admin');
        if ($adminRole && ! in_array($adminRole->id, $roleIds)) {
            $roleIds[] = $adminRole->id;
        }

        // 현재 인증된 사용자(생성자)의 역할 자동 추가
        $currentUser = Auth::user();
        if ($currentUser) {
            $userRoleIds = $currentUser->roles()->pluck('roles.id')->toArray();
            foreach ($userRoleIds as $userRoleId) {
                if (! in_array($userRoleId, $roleIds)) {
                    $roleIds[] = $userRoleId;
                }
            }
        }

        return $roleIds;
    }

    /**
     * 메뉴의 역할 권한을 동기화합니다.
     *
     * 기존 역할을 모두 제거하고 새로운 역할로 대체합니다.
     * 각 역할에 대해 read 권한을 기본으로 부여합니다.
     *
     * @param  Menu  $menu  메뉴 모델
     * @param  array  $roleIds  역할 ID 배열
     */
    private function syncMenuRoles(Menu $menu, array $roleIds): void
    {
        // 동기화 전 현재 역할 식별자 캡처 (Listener diff 계산용)
        $previousRoleIdentifiers = $menu->roles()->pluck('identifier')->toArray();

        // 역할이 비어있으면 모든 역할 권한 제거
        if (empty($roleIds)) {
            $menu->roles()->detach();

            $currentRoleIdentifiers = [];
            HookManager::doAction('core.menu.after_sync_roles', $menu, $previousRoleIdentifiers, $currentRoleIdentifiers);

            return;
        }

        // 각 역할에 read 권한 부여
        $syncData = [];
        foreach ($roleIds as $roleId) {
            $syncData[$roleId] = [
                'permission_type' => MenuPermissionType::Read->value,
            ];
        }

        $menu->roles()->sync($syncData);

        // 동기화 후 현재 역할 식별자
        $currentRoleIdentifiers = $menu->roles()->pluck('identifier')->toArray();

        // 훅: 역할 동기화 후 (이전/이후 식별자 모두 전달)
        HookManager::doAction('core.menu.after_sync_roles', $menu, $previousRoleIdentifiers, $currentRoleIdentifiers);
    }

    /**
     * 메뉴 트리 구조를 재귀적으로 생성합니다.
     *
     * @param  Menu  $menu  기준 메뉴 모델
     * @return array 메뉴 트리 구조 배열
     */
    private function buildMenuTree(Menu $menu): array
    {
        $children = $menu->activeChildren->map(function ($child) {
            return $this->buildMenuTree($child);
        })->toArray();

        return [
            'id' => $menu->id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'url' => $menu->url,
            'icon' => $menu->icon,
            'order' => $menu->order,
            'is_active' => $menu->is_active,
            'extension_type' => $menu->extension_type?->value,
            'extension_identifier' => $menu->extension_identifier,
            'children' => $children,
        ];
    }
}
