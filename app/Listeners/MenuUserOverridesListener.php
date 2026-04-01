<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Models\Menu;

/**
 * 메뉴 유저 수정 추적 리스너
 *
 * MenuService에서 발행하는 훅을 구독하여
 * menus.user_overrides 컬럼에 유저가 수정한 필드명을 자동으로 기록합니다.
 */
class MenuUserOverridesListener implements HookListenerInterface
{
    public function __construct(
        private MenuRepositoryInterface $menuRepository,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.menu.before_update' => ['method' => 'handleBeforeUpdate', 'priority' => 10],
            'core.menu.after_update_order' => ['method' => 'handleAfterUpdateOrder', 'priority' => 10],
            'core.menu.after_sync_roles' => ['method' => 'handleAfterSyncRoles', 'priority' => 10],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void {}

    /**
     * 메뉴 업데이트 전: 변경된 필드를 user_overrides에 기록
     *
     * @param  Menu  $menu  업데이트 대상 메뉴
     * @param  array  $data  업데이트 데이터
     */
    public function handleBeforeUpdate(Menu $menu, array $data): void
    {
        $userOverrides = $menu->user_overrides ?? [];
        $changed = false;
        $trackableFields = ['name', 'icon', 'order', 'url'];

        foreach ($trackableFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $menu->{$field}) {
                if (! in_array($field, $userOverrides, true)) {
                    $userOverrides[] = $field;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $this->menuRepository->update($menu, ['user_overrides' => $userOverrides]);
        }
    }

    /**
     * 메뉴 순서 변경 후: 관련 메뉴의 user_overrides에 "order" 기록
     *
     * $orderData 구조: ['parent_menus' => [['id' => N, 'order' => N], ...], 'child_menus' => [parentId => [['id' => N, 'order' => N], ...], ...]]
     *
     * @param  array  $orderData  계층 구조 순서 데이터 배열
     */
    public function handleAfterUpdateOrder(array $orderData): void
    {
        $menuIds = [];

        // 부모 메뉴 ID 수집
        if (isset($orderData['parent_menus'])) {
            foreach ($orderData['parent_menus'] as $item) {
                if (isset($item['id'])) {
                    $menuIds[] = $item['id'];
                }
            }
        }

        // 자식 메뉴 ID 수집
        if (isset($orderData['child_menus'])) {
            foreach ($orderData['child_menus'] as $children) {
                foreach ($children as $item) {
                    if (isset($item['id'])) {
                        $menuIds[] = $item['id'];
                    }
                }
            }
        }

        // 수집된 메뉴에 "order" 기록
        foreach ($menuIds as $menuId) {
            $menu = $this->menuRepository->findById($menuId);
            if (! $menu) {
                continue;
            }

            $userOverrides = $menu->user_overrides ?? [];
            if (! in_array('order', $userOverrides, true)) {
                $userOverrides[] = 'order';
                $this->menuRepository->update($menu, ['user_overrides' => $userOverrides]);
            }
        }
    }

    /**
     * 메뉴 역할 동기화 후: 변경된 개별 역할 식별자를 user_overrides에 기록
     *
     * Service가 전달하는 이전/이후 역할 식별자 목록을 비교하여
     * 추가되거나 제거된 역할 식별자를 개별적으로 기록합니다.
     *
     * @param  Menu  $menu  역할이 동기화된 메뉴
     * @param  array  $previousRoleIdentifiers  동기화 전 역할 식별자 배열
     * @param  array  $currentRoleIdentifiers  동기화 후 역할 식별자 배열
     */
    public function handleAfterSyncRoles(Menu $menu, array $previousRoleIdentifiers, array $currentRoleIdentifiers): void
    {
        // 추가된 역할 + 제거된 역할 = 유저가 변경한 역할
        $added = array_diff($currentRoleIdentifiers, $previousRoleIdentifiers);
        $removed = array_diff($previousRoleIdentifiers, $currentRoleIdentifiers);
        $changedRoles = array_merge($added, $removed);

        if (empty($changedRoles)) {
            return;
        }

        $userOverrides = $menu->user_overrides ?? [];
        $changed = false;

        foreach ($changedRoles as $roleIdentifier) {
            if (! in_array($roleIdentifier, $userOverrides, true)) {
                $userOverrides[] = $roleIdentifier;
                $changed = true;
            }
        }

        if ($changed) {
            $this->menuRepository->update($menu, ['user_overrides' => $userOverrides]);
        }
    }
}
