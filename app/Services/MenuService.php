<?php

namespace App\Services;

use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /**
     * 메뉴 관리 화면용 최상위 메뉴 목록을 반환합니다 (활성 모듈/플러그인 식별자 기준 필터링).
     *
     * @param  User|null  $user  권한 평가 기준 사용자 (null 이면 권한 필터 미적용)
     * @return Collection 메뉴 관리 화면용 최상위 메뉴 컬렉션
     */
    public function getTopLevelMenusForManagement(?User $user = null): Collection
    {
        $activeModuleIdentifiers = $this->moduleService->getActiveModuleIdentifiers();
        $activePluginIdentifiers = $this->pluginService->getActivePluginIdentifiers();

        return $this->menuRepository->getTopLevelMenusForManagement($activeModuleIdentifiers, $activePluginIdentifiers, $user);
    }

    /**
     * 검색/필터 조건이 적용된 메뉴 관리 화면용 최상위 메뉴 목록을 반환합니다.
     *
     * @param  array  $filters  검색 조건 (예: q, source 등)
     * @param  User|null  $user  권한 평가 기준 사용자
     * @return Collection 필터링된 최상위 메뉴 컬렉션
     */
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
     * - 자기 자신·자손을 부모로 설정 방지: NotCircularParent Custom Rule
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

        // 역할 연결만 끊기고 메뉴는 남는 상태를 막기 위해 두 단계를 하나로 묶는다.
        $result = DB::transaction(function () use ($menu) {
            // Hook: 메뉴 삭제 전
            HookManager::doAction('core.menu.before_delete', $menu);

            // 역할 연결 해제 (명시적 삭제 - CASCADE 의존 금지)
            $menu->roles()->detach();

            return $this->menuRepository->delete($menu);
        });

        // Hook: 메뉴 삭제 후
        HookManager::doAction('core.menu.after_delete', $menu->id);

        return $result;
    }

    /**
     * 메뉴 순서를 업데이트합니다 (드래그 앤 드롭).
     *
     * 형제 `updateMenuOrderWithHierarchy` 와 같은 리소스를 같은 방식으로 쓰므로 스코프
     * 가드도 대칭이어야 한다. 코어 내 호출부가 없다는 사실은 방어가 아니다 — 이 서비스는
     * 확장이 주입받을 수 있고, 가드가 형제 경로에만 있으면 여기가 우회로가 된다.
     *
     * @param  array  $menuOrders  메뉴 ID와 순서 매핑 배열
     * @return bool 업데이트 성공 여부
     *
     * @throws AuthorizationException 스코프 밖 메뉴가 하나라도 포함된 경우
     */
    public function updateMenuOrder(array $menuOrders): bool
    {
        $this->assertMenusWithinScope(array_map('intval', array_values($menuOrders)));

        // 훅: 메뉴 순서 변경 전 (IDV 정책 가드 지점)
        HookManager::doAction('core.menu.before_update_order', $menuOrders);

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
        $this->assertOrderTargetsWithinScope($orderData);

        $result = $this->menuRepository->updateOrderWithHierarchy($orderData);

        // 훅: 메뉴 계층 순서 변경 후
        HookManager::doAction('core.menu.after_update_order', $orderData);

        return $result;
    }

    /**
     * 순서 변경 대상 메뉴가 액터의 스코프 안에 있는지 검사합니다.
     *
     * `PUT admin/menus/order` 는 라우트 모델이 없는 정적 경로라 PermissionMiddleware 의
     * 스코프 검사가 스킵된다(`{menu}` 파라미터 부재 → "목록 엔드포인트" 로 간주). 상세
     * 경로(`PUT menus/{menu}`)가 미들웨어로 강제하는 축이 이 경로에서만 비어 있었다.
     * 배포 기본 역할은 `core.menus.update` 를 글로벌로 주므로 기본값 노출은 아니지만,
     * 운영자가 역할 화면에서 스코프를 self/role 로 좁히는 순간 우회로가 된다.
     *
     * 첨부 순서 변경과 같은 이유로 **전량 거부**다 — 순서는 트리 전체에 대한 하나의
     * 배열이라 일부만 반영하면 나머지와 어긋난 계층이 저장된다.
     *
     * @param  array  $orderData  순서 데이터 (parent_menus / child_menus / moved_items)
     *
     * @throws AuthorizationException 스코프 밖 메뉴가 하나라도 포함된 경우
     */
    private function assertOrderTargetsWithinScope(array $orderData): void
    {
        $ids = [];

        foreach ($orderData['parent_menus'] ?? [] as $item) {
            $ids[] = (int) ($item['id'] ?? 0);
        }

        foreach ($orderData['child_menus'] ?? [] as $children) {
            foreach ($children as $item) {
                $ids[] = (int) ($item['id'] ?? 0);
            }
        }

        // 이동 항목은 **옮기는 메뉴 자신**만 확인한다. 새 부모까지 검사하면, 공용 상위 메뉴
        // 아래에 자기 메뉴를 다는 정상 사용이 막힌다 — 결함은 "남의 메뉴 순서를 바꾼다" 였지
        // "남의 메뉴 아래에 못 붙인다" 가 아니다.
        foreach ($orderData['moved_items'] ?? [] as $item) {
            $ids[] = (int) ($item['id'] ?? 0);
        }

        $this->assertMenusWithinScope($ids);
    }

    /**
     * 주어진 메뉴 ID 집합이 전부 액터의 스코프 안에 있는지 검사합니다.
     *
     * 순서 변경 두 경로(`updateMenuOrder` · `updateMenuOrderWithHierarchy`)가 공유하는
     * 판정부다. 한쪽만 검사하면 형제 경로가 우회로가 되므로 판정을 한 곳에 둔다.
     *
     * @param  array<int>  $ids  검사 대상 메뉴 ID 목록
     *
     * @throws AuthorizationException 스코프 밖 메뉴가 하나라도 포함된 경우
     */
    private function assertMenusWithinScope(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            return;
        }

        $menus = $this->menuRepository->findByIds($ids);

        // 판정은 상세 경로와 같은 SSoT 에 위임한다.
        $permitted = PermissionHelper::filterByScope($menus, 'core.menus.update');

        if (count($permitted) !== $menus->count()) {
            throw new AuthorizationException(__('auth.scope_denied'));
        }
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
