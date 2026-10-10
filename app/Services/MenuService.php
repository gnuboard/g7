<?php

namespace App\Services;

use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuService
{
    /**
     * 메뉴 서비스를 생성합니다.
     *
     * @param  MenuRepositoryInterface  $menuRepository  메뉴 저장소
     * @param  RoleRepositoryInterface  $roleRepository  역할 저장소
     * @param  ModuleService  $moduleService  모듈 서비스
     * @param  PluginService  $pluginService  플러그인 서비스
     */
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

        $menus = $this->menuRepository->getFilteredTopLevelMenus($filters, $activeModuleIdentifiers, $activePluginIdentifiers, $user);

        // 목록 행마다 부여된 역할을 보이도록 역할 ID 만 붙인다 — 하위 메뉴의 역할 객체(이름·피벗)는
        // 목록에 싣지 않는 #76 정리를 유지하고, 이름은 화면이 활성 역할 목록에서 찾는다. 쿼리 1회.
        $menuIds = $menus->flatMap(fn (Menu $menu) => [$menu->id, ...$menu->children->pluck('id')->all()])->all();
        $roleIdsByMenu = $this->menuRepository->getRoleIdsByMenuIds($menuIds);
        foreach ($menus as $menu) {
            $menu->setAttribute('assigned_role_ids', $roleIdsByMenu[$menu->id] ?? []);
            foreach ($menu->children as $child) {
                $child->setAttribute('assigned_role_ids', $roleIdsByMenu[$child->id] ?? []);
            }
        }

        return $menus;
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

        // 역할 권한 동기화 (roles 필드가 전달된 경우에만). 관리자 역할은 항상 남긴다 — 역할을
        // 전부 빼고 저장하면 관리자 사이드바에서도 메뉴가 사라져 되돌릴 화면이 없어진다.
        // 생성 경로와 달리 저장한 사람의 역할은 더하지 않는다(수정은 운영자가 고른 대로).
        if ($roles !== null) {
            $this->syncMenuRoles($menu, $this->ensureAdminRole($roles));
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

        // 훅: 메뉴 순서 변경 후 — after_update_order 는 계층 경로(updateMenuOrderWithHierarchy)에서 계층 배열을
        // 싣는데 여기서는 ID 목록을 실어 모양이 달랐다. 서드파티 호환을 위해 기존 발화는 그대로 두고(이 자리의
        // 발화는 deprecated), ID 목록 모양을 이름에 드러낸 after_update_order_flat 을 나란히 발화한다(#138 후속).
        HookManager::doAction('core.menu.after_update_order', $menuOrders);
        HookManager::doAction('core.menu.after_update_order_flat', array_values($menuOrders));

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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * 액터가 관리할 수 있는 메뉴 ID 목록을 반환합니다.
     *
     * 메뉴 관리 화면이 보여 주는 집합과 같다 — 활성 확장의 메뉴 중 액터 역할로 접근 가능한
     * 최상위 메뉴와 그 자식 — 에서 `core.menus.update` 범위 밖을 뺀 것이다. 역할↔메뉴 일괄
     * 지정은 이 집합 안에서만 할당을 바꾸고, 밖의 기존 할당(비활성 플러그인 메뉴 등)은 둔다.
     *
     * @param  User|null  $actor  기준 액터 (null 이면 현재 인증 사용자, 둘 다 없으면 필터 없음)
     * @return array<int, int> 관리 가능한 메뉴 ID 목록
     */
    public function getManageableMenuIds(?User $actor = null): array
    {
        $actor ??= Auth::user();
        $actor = $actor instanceof User ? $actor : null;

        $flat = [];
        foreach ($this->getTopLevelMenusForManagement($actor) as $menu) {
            $flat[] = $menu;
            foreach ($menu->children as $child) {
                $flat[] = $child;
            }
        }

        if ($actor !== null) {
            $flat = PermissionHelper::filterByScope($flat, 'core.menus.update', $actor);
        }

        return array_values(array_unique(array_map(static fn (Menu $menu): int => (int) $menu->id, $flat)));
    }

    /**
     * 역할 하나가 접근할 메뉴 목록을 통째로 지정합니다 (역할 폼 「접근 가능 메뉴」).
     *
     * 요청은 액터가 관리할 수 있는 메뉴 집합 M 안에서의 **전체 목록**이다. M 안에서만 차분을
     * 적용하고(추가 = 요청 − 현재, 제거 = (현재 ∩ M) − 요청), M 밖의 기존 할당은 그대로 둔다.
     * 부모·자식은 서로 맞춘다 — 새로 넣은 자식의 부모를 함께 넣고, 뺀 부모의 자식도 함께 뺀다
     * (자식 메뉴는 부모를 거쳐서만 사이드바에 나오므로 따로 두면 보이지 않는 할당이 된다).
     *
     * @param  Role  $role  대상 역할
     * @param  array<int, int|string>  $menuIds  역할이 접근할 메뉴 ID 전체 목록
     * @return array{added_menu_ids: array<int, int>, removed_menu_ids: array<int, int>, auto_included_parent_ids: array<int, int>, auto_removed_child_ids: array<int, int>} 적용 결과
     *
     * @throws ValidationException 관리자 역할에서 메뉴를 빼거나, 관리할 수 없는 메뉴를 새로 넣을 때
     */
    public function syncRoleMenus(Role $role, array $menuIds): array
    {
        $requested = array_values(array_unique(array_map('intval', $menuIds)));
        $manageable = $this->getManageableMenuIds();
        $current = $role->menus()->pluck('menus.id')->map(fn ($id) => (int) $id)->all();

        // 이미 가진 관리 밖 메뉴는 화면이 값을 그대로 돌려보낸 것이라 받아 준다(변경 없음).
        // 관리 밖 메뉴를 새로 넣는 것만 거부한다.
        $unmanageable = array_values(array_diff($requested, $manageable, $current));
        if ($unmanageable !== []) {
            throw ValidationException::withMessages([
                'menu_ids' => [__('validation.role.menu_ids.unmanageable')],
            ]);
        }

        if ($role->identifier === 'admin' && array_diff($manageable, $requested) !== []) {
            throw ValidationException::withMessages([
                'menu_ids' => [__('menu.admin_role_required')],
            ]);
        }

        $requested = array_values(array_intersect($requested, $manageable));
        $currentManaged = array_values(array_intersect($current, $manageable));
        $parentOf = $this->parentMap($manageable);

        // W1 ① 새로 넣은 자식의 부모를 함께 넣는다.
        $autoIncludedParents = [];
        foreach (array_diff($requested, $current) as $childId) {
            $parentId = $parentOf[$childId] ?? null;
            if ($parentId !== null && ! in_array($parentId, $requested, true)) {
                $requested[] = $parentId;
                $autoIncludedParents[] = $parentId;
            }
        }

        // W1 ② 뺀 부모의 자식을 함께 뺀다.
        $autoRemovedChildren = [];
        $removedParents = array_diff($currentManaged, $requested);
        foreach ($requested as $index => $menuId) {
            $parentId = $parentOf[$menuId] ?? null;
            if ($parentId !== null && in_array($parentId, $removedParents, true)) {
                unset($requested[$index]);
                $autoRemovedChildren[] = $menuId;
            }
        }
        $requested = array_values($requested);

        $toAdd = array_values(array_diff($requested, $current));
        $toRemove = array_values(array_diff($currentManaged, $requested));

        foreach ($this->menuRepository->findByIds(array_merge($toAdd, $toRemove)) as $menu) {
            $this->applyMenuRoleDiff(
                $menu,
                in_array((int) $menu->id, $toAdd, true) ? [$role->id] : [],
                in_array((int) $menu->id, $toRemove, true) ? [$role->id] : [],
            );
        }

        return [
            'added_menu_ids' => $toAdd,
            'removed_menu_ids' => $toRemove,
            'auto_included_parent_ids' => array_values(array_unique($autoIncludedParents)),
            'auto_removed_child_ids' => array_values(array_unique($autoRemovedChildren)),
        ];
    }

    /**
     * 여러 메뉴에 역할을 한꺼번에 더하거나 뺍니다 (메뉴 관리 「여러 개 선택」).
     *
     * `PATCH admin/menus/bulk-roles` 는 라우트 모델이 없는 정적 경로라 미들웨어의 범위 검사가
     * 빠진다. 순서 변경과 같은 이유로 범위 밖 메뉴가 하나라도 섞이면 **전량 거부**한다.
     * 부모·자식은 역할 폼과 같은 규칙으로 맞춘다 — 추가면 고른 자식의 부모를 함께, 제거면
     * 고른 부모의 자식을 함께 대상으로 삼는다.
     *
     * @param  array<int, int|string>  $menuIds  대상 메뉴 ID 목록
     * @param  array<int, int|string>  $roleIds  더하거나 뺄 역할 ID 목록
     * @param  string  $mode  'add' | 'remove'
     * @return array{updated_count: int, auto_included_parent_ids: array<int, int>, auto_removed_child_ids: array<int, int>} 적용 결과
     *
     * @throws AuthorizationException 범위 밖 메뉴가 하나라도 포함된 경우
     * @throws ValidationException 관리자 역할을 빼거나, 관리할 수 없는 메뉴가 포함된 경우
     */
    public function bulkUpdateRoles(array $menuIds, array $roleIds, string $mode): array
    {
        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));

        HookManager::doAction('core.menu.before_bulk_update_roles', $menuIds, $roleIds, $mode);

        if ($mode === 'remove') {
            $adminRole = $this->roleRepository->findByIdentifier('admin');
            if ($adminRole && in_array((int) $adminRole->id, $roleIds, true)) {
                throw ValidationException::withMessages([
                    'role_ids' => [__('menu.admin_role_required')],
                ]);
            }
        }

        $this->assertMenusWithinScope($menuIds);

        $manageable = $this->getManageableMenuIds();
        if (array_diff($menuIds, $manageable) !== []) {
            throw ValidationException::withMessages([
                'menu_ids' => [__('menu.bulk_roles_unmanageable')],
            ]);
        }

        $parentOf = $this->parentMap($manageable);
        $targets = $menuIds;
        $autoIncludedParents = [];
        $autoRemovedChildren = [];

        if ($mode === 'add') {
            foreach ($menuIds as $menuId) {
                $parentId = $parentOf[$menuId] ?? null;
                if ($parentId !== null && ! in_array($parentId, $targets, true)) {
                    $targets[] = $parentId;
                    $autoIncludedParents[] = $parentId;
                }
            }
        } else {
            foreach ($parentOf as $childId => $parentId) {
                if (in_array($parentId, $menuIds, true) && ! in_array($childId, $targets, true)) {
                    $targets[] = $childId;
                    $autoRemovedChildren[] = $childId;
                }
            }
        }

        $updated = DB::transaction(function () use ($targets, $roleIds, $mode) {
            $count = 0;
            foreach ($this->menuRepository->findByIds($targets) as $menu) {
                $changed = $mode === 'add'
                    ? $this->applyMenuRoleDiff($menu, $roleIds, [])
                    : $this->applyMenuRoleDiff($menu, [], $roleIds);
                if ($changed) {
                    $count++;
                }
            }

            return $count;
        });

        $result = [
            'updated_count' => $updated,
            'auto_included_parent_ids' => array_values(array_unique($autoIncludedParents)),
            'auto_removed_child_ids' => array_values(array_unique($autoRemovedChildren)),
        ];

        HookManager::doAction('core.menu.after_bulk_update_roles', $menuIds, $roleIds, $mode, $result);

        return $result;
    }

    /**
     * 메뉴 하나에 역할을 더하고 빼는 차분을 적용합니다.
     *
     * 이미 있는 역할은 다시 붙이지 않고, 없는 역할은 빼지 않는다. 실제로 바뀐 경우에만 단건
     * 경로와 같은 `core.menu.after_sync_roles` 를 발화해 user_overrides·활동 로그가 메뉴마다
     * 같은 모양으로 남게 한다.
     *
     * @param  Menu  $menu  대상 메뉴
     * @param  array<int, int>  $addRoleIds  더할 역할 ID
     * @param  array<int, int>  $removeRoleIds  뺄 역할 ID
     * @return bool 바뀐 것이 있으면 true
     */
    private function applyMenuRoleDiff(Menu $menu, array $addRoleIds, array $removeRoleIds): bool
    {
        $previousRoleIdentifiers = $menu->roles()->pluck('identifier')->toArray();

        $readHolders = $menu->roles()
            ->wherePivot('permission_type', MenuPermissionType::Read->value)
            ->pluck('roles.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $holders = $menu->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();

        $attach = array_values(array_diff($addRoleIds, $readHolders));
        $detach = array_values(array_intersect($removeRoleIds, $holders));

        if ($attach === [] && $detach === []) {
            return false;
        }

        foreach ($attach as $roleId) {
            $menu->roles()->attach($roleId, ['permission_type' => MenuPermissionType::Read->value]);
        }

        if ($detach !== []) {
            $menu->roles()->detach($detach);
        }

        $this->dispatchRolesSynced($menu, $previousRoleIdentifiers);

        return true;
    }

    /**
     * 역할 동기화 완료 훅을 발화합니다 (단건·일괄 경로 공통).
     *
     * @param  Menu  $menu  대상 메뉴
     * @param  array<int, string>  $previousRoleIdentifiers  바꾸기 전 역할 식별자
     * @return void 반환값 없음
     */
    private function dispatchRolesSynced(Menu $menu, array $previousRoleIdentifiers): void
    {
        $currentRoleIdentifiers = $menu->roles()->pluck('identifier')->toArray();

        HookManager::doAction('core.menu.after_sync_roles', $menu, $previousRoleIdentifiers, $currentRoleIdentifiers);
    }

    /**
     * 주어진 메뉴 집합 안의 자식 → 부모 ID 대응표를 만듭니다.
     *
     * 부모도 같은 집합 안에 있을 때만 싣는다 — 관리할 수 없는 부모는 맞출 대상이 아니다.
     *
     * @param  array<int, int>  $menuIds  메뉴 ID 집합
     * @return array<int, int> [자식 ID => 부모 ID]
     */
    private function parentMap(array $menuIds): array
    {
        $map = [];
        foreach ($this->menuRepository->findByIds($menuIds) as $menu) {
            $parentId = $menu->parent_id !== null ? (int) $menu->parent_id : null;
            if ($parentId !== null && in_array($parentId, $menuIds, true)) {
                $map[(int) $menu->id] = $parentId;
            }
        }

        return $map;
    }

    /**
     * 역할 ID 배열에 관리자 역할을 더합니다.
     *
     * @param  array<int, int|string>  $roleIds  원본 역할 ID 배열
     * @return array<int, int|string> 관리자 역할이 포함된 역할 ID 배열
     */
    private function ensureAdminRole(array $roleIds): array
    {
        $adminRole = $this->roleRepository->findByIdentifier('admin');
        if ($adminRole && ! in_array($adminRole->id, $roleIds)) {
            $roleIds[] = $adminRole->id;
        }

        return $roleIds;
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
        $roleIds = $this->ensureAdminRole($roleIds);

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
     * @return void 반환값 없음
     */
    private function syncMenuRoles(Menu $menu, array $roleIds): void
    {
        // 동기화 전 현재 역할 식별자 캡처 (Listener diff 계산용)
        $previousRoleIdentifiers = $menu->roles()->pluck('identifier')->toArray();

        // 역할이 비어있으면 모든 역할 권한 제거
        if (empty($roleIds)) {
            $menu->roles()->detach();

            $this->dispatchRolesSynced($menu, $previousRoleIdentifiers);

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

        // 훅: 역할 동기화 후 (이전/이후 식별자 모두 전달)
        $this->dispatchRolesSynced($menu, $previousRoleIdentifiers);
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
