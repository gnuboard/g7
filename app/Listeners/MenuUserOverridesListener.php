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
    /**
     * 메뉴 사용자 수정 기록 리스너를 생성합니다.
     *
     * @param  MenuRepositoryInterface  $menuRepository  메뉴 저장소
     */
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
            // 순서 변경(계층·평탄 두 경로)은 여기서 기록하지 않는다 — 두 경로 모두 `Menu::where()->update()` 라
            // UserOverridesAwareBuilder 가 **실제로 순서가 바뀐 메뉴만** 기록한다. 종전 훅 핸들러는 payload 의 모든
            // 메뉴에 order 를 무조건 기록해, 드래그 한 번 뒤로 새 버전의 메뉴 순서 변경이 영영 안 들어왔다(#727 W11).
            'core.menu.after_sync_roles' => ['method' => 'handleAfterSyncRoles', 'priority' => 10],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 메뉴 업데이트 전: 변경된 필드를 user_overrides에 기록
     *
     * @param  Menu  $menu  업데이트 대상 메뉴
     * @param  array  $data  업데이트 데이터
     * @return void 반환값 없음
     */
    public function handleBeforeUpdate(Menu $menu, array $data): void
    {
        // Trait 의 calculateUserOverridesFor 가 trackableFields + translatableTrackableFields 를
        // 모두 인지하여 다국어 JSON 컬럼은 sub-key dot-path 단위로 user_overrides 를 누적함.
        $newOverrides = $menu->calculateUserOverridesFor($data);
        if ($newOverrides !== ($menu->user_overrides ?? [])) {
            $this->menuRepository->update($menu, ['user_overrides' => $newOverrides]);
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
     * @return void 반환값 없음
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
